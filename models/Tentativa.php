<?php

/**
 * Contador de tentativas usado pelo controle de forca bruta.
 *
 * Guarda apenas o hash da chave (IP ou identificador digitado), o escopo do
 * fluxo e o instante. Nada de senha, nada de e-mail em texto claro: o que
 * interessa e contar quantas falhas a mesma origem acumulou na janela.
 *
 * A gravacao tenta o banco primeiro, porque so ele serve quando a hospedagem
 * roda mais de uma instancia do container. Se o banco nao aceitar criar a
 * tabela (usuario sem permissao de DDL), a contagem cai para arquivos no
 * diretorio temporario: protecao mais fraca que a do banco, porem muito
 * melhor do que deixar o login sem limite nenhum.
 */
class Tentativa
{
    /** Tempo que um registro permanece util antes da limpeza automatica. */
    private const RETENCAO_SEGUNDOS = 86400;

    /** Estado da tabela: null = ainda nao verificada, false = indisponivel. */
    private static ?bool $tabelaPronta = null;

    // -----------------------------------------------------------------
    // API usada pelo controle de acesso
    // -----------------------------------------------------------------

    /**
     * Converte o valor observado (IP, login, e-mail) no identificador gravado.
     *
     * O hash cumpre dois papeis: mantem o tamanho fixo na coluna e evita que a
     * tabela de tentativas vire uma lista de e-mails validos do sistema caso o
     * banco vaze. O sufixo fixo impede montar um dicionario reverso trivial.
     */
    public static function chave(string $escopo, string $valor): string
    {
        return hash('sha256', $escopo . '|' . mb_strtolower(trim($valor)) . '|agendei-tentativas');
    }

    /** Quantas falhas a chave acumulou dentro da janela informada. */
    public static function contar(string $escopo, string $chave, int $janelaSegundos): int
    {
        $limite = date('Y-m-d H:i:s', time() - $janelaSegundos);

        if (self::bancoDisponivel()) {
            try {
                $consulta = bd()->prepare(
                    'SELECT COUNT(*) FROM tentativas_acesso
                     WHERE escopo = :escopo AND chave = :chave AND data_hora > :limite'
                );
                $consulta->execute([':escopo' => $escopo, ':chave' => $chave, ':limite' => $limite]);
                return (int) $consulta->fetchColumn();
            } catch (Throwable $erro) {
                self::$tabelaPronta = false;
                error_log('Contagem de tentativas pelo banco falhou: ' . $erro->getMessage());
            }
        }

        $corte = time() - $janelaSegundos;
        return count(array_filter(
            self::lerArquivo($escopo, $chave),
            static fn (int $instante): bool => $instante > $corte
        ));
    }

    /** Anota mais uma falha para a chave. */
    public static function registrar(string $escopo, string $chave): void
    {
        if (self::bancoDisponivel()) {
            try {
                $consulta = bd()->prepare(
                    'INSERT INTO tentativas_acesso (escopo, chave, data_hora) VALUES (:escopo, :chave, :agora)'
                );
                $consulta->execute([':escopo' => $escopo, ':chave' => $chave, ':agora' => date('Y-m-d H:i:s')]);
                self::limpezaOcasional();
                return;
            } catch (Throwable $erro) {
                self::$tabelaPronta = false;
                error_log('Registro de tentativa no banco falhou: ' . $erro->getMessage());
            }
        }

        $registros = self::lerArquivo($escopo, $chave);
        $registros[] = time();
        self::gravarArquivo($escopo, $chave, $registros);
    }

    /** Zera o contador da chave: chamado quando a credencial finalmente confere. */
    public static function limpar(string $escopo, string $chave): void
    {
        if (self::bancoDisponivel()) {
            try {
                $consulta = bd()->prepare('DELETE FROM tentativas_acesso WHERE escopo = :escopo AND chave = :chave');
                $consulta->execute([':escopo' => $escopo, ':chave' => $chave]);
            } catch (Throwable $erro) {
                error_log('Limpeza de tentativas no banco falhou: ' . $erro->getMessage());
            }
        }

        $arquivo = self::caminhoArquivo($escopo, $chave);
        if (is_file($arquivo)) {
            @unlink($arquivo);
        }
    }

    /**
     * Instante da falha mais antiga ainda dentro da janela.
     * E a partir dele que o bloqueio conta quanto tempo ainda falta.
     */
    public static function maisAntiga(string $escopo, string $chave, int $janelaSegundos): ?int
    {
        $limite = time() - $janelaSegundos;

        if (self::bancoDisponivel()) {
            try {
                $consulta = bd()->prepare(
                    'SELECT MIN(data_hora) FROM tentativas_acesso
                     WHERE escopo = :escopo AND chave = :chave AND data_hora > :limite'
                );
                $consulta->execute([
                    ':escopo' => $escopo,
                    ':chave'  => $chave,
                    ':limite' => date('Y-m-d H:i:s', $limite),
                ]);
                $valor = $consulta->fetchColumn();
                return $valor ? (int) strtotime((string) $valor) : null;
            } catch (Throwable $erro) {
                self::$tabelaPronta = false;
                error_log('Consulta da tentativa mais antiga falhou: ' . $erro->getMessage());
            }
        }

        $validas = array_filter(
            self::lerArquivo($escopo, $chave),
            static fn (int $instante): bool => $instante > $limite
        );

        return $validas === [] ? null : min($validas);
    }

    // -----------------------------------------------------------------
    // Leitura para o painel de seguranca
    // -----------------------------------------------------------------

    /**
     * Chaves que estao cumprindo bloqueio agora, em todos os escopos.
     *
     * A chave gravada e um hash: a tela mostra quantas falhas houve, em que
     * fluxo e quanto falta para liberar, sem revelar o e-mail ou o IP por tras
     * dela. Para o master isso basta, porque a acao possivel — liberar — nao
     * depende de saber quem e. Cada item traz:
     * 'escopo', 'chave', 'total', 'primeira' (timestamp) e 'restante' (segundos).
     */
    public static function bloqueiosAtivos(): array
    {
        $agora = time();
        $bloqueios = [];

        foreach (politicaLimites() as $escopo => $regra) {
            foreach (self::acumuladas((string) $escopo, (int) $regra['janela'], (int) $regra['limite']) as $item) {
                $restante = ($item['primeira'] + (int) $regra['espera']) - $agora;
                if ($restante <= 0) {
                    continue;
                }

                $bloqueios[] = [
                    'escopo'   => (string) $escopo,
                    'chave'    => $item['chave'],
                    'total'    => $item['total'],
                    'primeira' => $item['primeira'],
                    'restante' => $restante,
                ];
            }
        }

        // O bloqueio que vence por ultimo aparece primeiro: e o caso que ainda
        // pode gerar chamado de suporte.
        usort($bloqueios, static fn (array $a, array $b): int => $b['restante'] <=> $a['restante']);

        return $bloqueios;
    }

    /** Chaves de um escopo que ja atingiram o limite dentro da janela. */
    private static function acumuladas(string $escopo, int $janela, int $limite): array
    {
        $corte = time() - $janela;

        if (self::bancoDisponivel()) {
            try {
                $consulta = bd()->prepare(
                    'SELECT chave, COUNT(*) AS total, MIN(data_hora) AS primeira
                     FROM tentativas_acesso
                     WHERE escopo = :escopo AND data_hora > :limite
                     GROUP BY chave
                     HAVING COUNT(*) >= ' . max(1, $limite)
                );
                $consulta->execute([':escopo' => $escopo, ':limite' => date('Y-m-d H:i:s', $corte)]);

                return array_map(static fn (array $linha): array => [
                    'chave'    => (string) $linha['chave'],
                    'total'    => (int) $linha['total'],
                    'primeira' => (int) strtotime((string) $linha['primeira']),
                ], $consulta->fetchAll());
            } catch (Throwable $erro) {
                self::$tabelaPronta = false;
                error_log('Leitura dos bloqueios pelo banco falhou: ' . $erro->getMessage());
            }
        }

        // Reserva em arquivo: o nome do arquivo guarda escopo e chave, ja sem
        // os caracteres que caminhoArquivo() descarta.
        $encontradas = [];
        $escopoLimpo = preg_replace('/[^a-z0-9]/i', '', $escopo) ?? '';
        foreach (glob(self::pasta() . DIRECTORY_SEPARATOR . $escopoLimpo . '-*.json') ?: [] as $arquivo) {
            $chave = (string) preg_replace('/^' . preg_quote($escopoLimpo, '/') . '-|\.json$/', '', basename($arquivo));
            $instantes = array_filter(
                self::lerArquivo($escopo, $chave),
                static fn (int $instante): bool => $instante > $corte
            );

            if (count($instantes) >= $limite) {
                $encontradas[] = [
                    'chave'    => $chave,
                    'total'    => count($instantes),
                    'primeira' => min($instantes),
                ];
            }
        }

        return $encontradas;
    }

    /** Rotulo do fluxo protegido, para a tela de seguranca. */
    public static function escopoTexto(string $escopo): string
    {
        return match ($escopo) {
            'login_conta'   => 'Login (por conta)',
            'login_ip'      => 'Login (por origem)',
            'master_ip'     => 'Area master',
            'fator_ip'      => 'Segundo fator',
            'recuperar_ip'  => 'Recuperacao de senha',
            'cadastro_ip'   => 'Cadastro publico',
            'instalador_ip' => 'Chave do instalador',
            default         => $escopo,
        };
    }

    // -----------------------------------------------------------------
    // Armazenamento em banco
    // -----------------------------------------------------------------

    /**
     * Garante a tabela na primeira chamada da requisicao.
     *
     * O CREATE TABLE IF NOT EXISTS evita depender de alguem lembrar de rodar a
     * migracao antes de publicar: uma instalacao nova ja sobe protegida.
     */
    private static function bancoDisponivel(): bool
    {
        if (self::$tabelaPronta !== null) {
            return self::$tabelaPronta;
        }

        try {
            foreach (self::ddl() as $comando) {
                bd()->exec($comando);
            }
            self::$tabelaPronta = true;
        } catch (Throwable $erro) {
            error_log('Tabela de tentativas indisponivel, usando arquivo: ' . $erro->getMessage());
            self::$tabelaPronta = false;
        }

        return self::$tabelaPronta;
    }

    /** Comandos de criacao da tabela em cada dialeto. */
    public static function ddl(): array
    {
        if (Database::ehPostgres()) {
            return [
                'CREATE TABLE IF NOT EXISTS tentativas_acesso (
                    id_tentativa BIGSERIAL PRIMARY KEY,
                    escopo VARCHAR(30) NOT NULL,
                    chave CHAR(64) NOT NULL,
                    data_hora TIMESTAMP NOT NULL
                )',
                'CREATE INDEX IF NOT EXISTS idx_tentativa_busca ON tentativas_acesso (escopo, chave, data_hora)',
            ];
        }

        return [
            'CREATE TABLE IF NOT EXISTS tentativas_acesso (
                id_tentativa BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                escopo VARCHAR(30) NOT NULL,
                chave CHAR(64) NOT NULL,
                data_hora DATETIME NOT NULL,
                PRIMARY KEY (id_tentativa),
                KEY idx_tentativa_busca (escopo, chave, data_hora)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }

    /**
     * Descarta registros vencidos de vez em quando.
     * Uma chance em cinquenta por gravacao mantem a tabela curta sem transformar
     * cada tentativa de login em um DELETE varrendo o indice.
     */
    private static function limpezaOcasional(): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }

        try {
            $consulta = bd()->prepare('DELETE FROM tentativas_acesso WHERE data_hora < :limite');
            $consulta->execute([':limite' => date('Y-m-d H:i:s', time() - self::RETENCAO_SEGUNDOS)]);
        } catch (Throwable $erro) {
            error_log('Limpeza periodica de tentativas falhou: ' . $erro->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // Armazenamento em arquivo (reserva)
    // -----------------------------------------------------------------

    /** Pasta dos contadores de reserva, criada sob demanda. */
    private static function pasta(): string
    {
        $pasta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'agendei-tentativas';
        if (!is_dir($pasta)) {
            @mkdir($pasta, 0700, true);
        }
        return $pasta;
    }

    /** Caminho do arquivo que guarda os instantes de uma chave. */
    private static function caminhoArquivo(string $escopo, string $chave): string
    {
        $escopoLimpo = preg_replace('/[^a-z0-9]/i', '', $escopo) ?? '';
        $chaveLimpa  = preg_replace('/[^a-f0-9]/i', '', $chave) ?? '';

        return self::pasta() . DIRECTORY_SEPARATOR . $escopoLimpo . '-' . $chaveLimpa . '.json';
    }

    /** Instantes ainda dentro da retencao gravados para a chave. */
    private static function lerArquivo(string $escopo, string $chave): array
    {
        $arquivo = self::caminhoArquivo($escopo, $chave);
        if (!is_file($arquivo)) {
            return [];
        }

        $conteudo = @file_get_contents($arquivo);
        $dados = $conteudo === false ? [] : json_decode($conteudo, true);
        if (!is_array($dados)) {
            return [];
        }

        $corte = time() - self::RETENCAO_SEGUNDOS;
        return array_values(array_filter(
            array_map('intval', $dados),
            static fn (int $instante): bool => $instante > $corte
        ));
    }

    /** Regrava a lista de instantes, com trava para nao perder escritas simultaneas. */
    private static function gravarArquivo(string $escopo, string $chave, array $registros): void
    {
        // Mais de 200 marcas na mesma chave nao mudam nenhuma decisao: todos os
        // limites do sistema sao bem menores, e o corte evita crescimento infinito.
        if (count($registros) > 200) {
            $registros = array_slice($registros, -200);
        }

        @file_put_contents(
            self::caminhoArquivo($escopo, $chave),
            json_encode(array_values($registros)),
            LOCK_EX
        );
    }
}
