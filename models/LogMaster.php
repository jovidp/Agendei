<?php

/**
 * Trilha de auditoria da administracao master.
 *
 * A conta master cria empresas, desativa acessos e redefine senhas de qualquer
 * estabelecimento. Sem este registro, a acao de maior poder do sistema seria a
 * unica sem rastro: o log de autenticacao guarda quem entrou, nao o que foi
 * feito depois de entrar.
 *
 * Nome do master e nome do estabelecimento sao gravados junto do evento, e nao
 * lidos por JOIN, pelo mesmo motivo do log de autenticacao: o historico precisa
 * continuar legivel depois que a conta ou a empresa citada deixar de existir.
 * Pela mesma razao a tabela nao tem chave estrangeira.
 */
class LogMaster
{
    /** Acoes registradas, com o rotulo exibido na tela de auditoria. */
    public const ACOES = [
        'login'                  => 'Entrada na area master',
        'logout'                 => 'Saida da area master',
        'estabelecimento_criado' => 'Estabelecimento criado',
        'estabelecimento_status' => 'Acesso do estabelecimento alterado',
        'estabelecimento_excluido' => 'Estabelecimento excluído',
        'cadastro_solicitado'    => 'Cadastro de empresa solicitado',
        'cadastro_aprovado'      => 'Cadastro de empresa aprovado',
        'cadastro_recusado'      => 'Cadastro de empresa recusado',
        'admin_criado'           => 'Administrador local criado',
        'admin_status'           => 'Acesso do administrador alterado',
        'admin_senha'            => 'Senha de administrador redefinida',
        'master_criado'          => 'Conta master criada',
        'master_dados'           => 'Dados de conta master alterados',
        'master_status'          => 'Acesso de conta master alterado',
        'master_senha'           => 'Senha de conta master redefinida',
        'bloqueio_liberado'      => 'Bloqueio de acesso liberado',
        'simulacao_inicio'       => 'Entrou no painel do estabelecimento',
        'simulacao_fim'          => 'Saiu do painel do estabelecimento',
        'plano_criado'           => 'Plano criado',
        'plano_alterado'         => 'Plano alterado',
        'plano_atribuido'        => 'Plano do estabelecimento alterado',
    ];

    /** Estado da tabela: null = ainda nao verificada, false = indisponivel. */
    private static ?bool $tabelaPronta = null;

    // -----------------------------------------------------------------
    // Gravacao
    // -----------------------------------------------------------------

    /**
     * Anota uma acao do master.
     *
     * Chaves aceitas em $contexto:
     * 'estabelecimento'      id da empresa afetada, quando a acao tem uma;
     * 'estabelecimento_nome' nome da empresa no momento da acao;
     * 'alvo'                 quem ou o que sofreu a acao (e-mail, login, chave);
     * 'detalhe'              complemento curto, ex.: "de ativo para inativo".
     */
    public static function registrar(string $acao, array $contexto = []): void
    {
        if (!isset(self::ACOES[$acao])) {
            return;
        }

        $sql = 'INSERT INTO logs_master
                    (id_master, master_nome, master_email, acao, id_estabelecimento,
                     estabelecimento_nome, alvo, detalhe, ip, data_hora)
                VALUES
                    (:id_master, :master_nome, :master_email, :acao, :id_estabelecimento,
                     :estabelecimento_nome, :alvo, :detalhe, :ip, :data_hora)';

        try {
            if (!self::tabelaDisponivel()) {
                throw new RuntimeException('tabela ausente');
            }

            $consulta = bd()->prepare($sql);
            $consulta->execute([
                ':id_master'            => ((int) ($_SESSION['master_id'] ?? 0)) ?: null,
                ':master_nome'          => mb_substr((string) ($_SESSION['usuario_nome'] ?? ''), 0, 120),
                ':master_email'         => mb_substr((string) ($_SESSION['usuario_email'] ?? ''), 0, 150),
                ':acao'                 => $acao,
                ':id_estabelecimento'   => ((int) ($contexto['estabelecimento'] ?? 0)) ?: null,
                ':estabelecimento_nome' => self::texto($contexto['estabelecimento_nome'] ?? null, 120),
                ':alvo'                 => self::texto($contexto['alvo'] ?? null, 150),
                ':detalhe'              => self::texto($contexto['detalhe'] ?? null, 255),
                ':ip'                   => mb_substr(ipCliente(), 0, 45) ?: null,
                ':data_hora'            => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $erro) {
            // A auditoria nao pode derrubar a operacao que ela acompanha. Se o
            // banco recusar, o evento ainda fica no log do servidor, que e o
            // destino que existe em qualquer hospedagem.
            error_log(sprintf(
                '[auditoria-master] %s master=%s alvo=%s detalhe=%s (falha ao gravar: %s)',
                $acao,
                (string) ($_SESSION['usuario_email'] ?? '-'),
                (string) ($contexto['alvo'] ?? '-'),
                (string) ($contexto['detalhe'] ?? '-'),
                $erro->getMessage()
            ));
        }
    }

    /** Corta o texto no tamanho da coluna e transforma vazio em nulo. */
    private static function texto(?string $valor, int $limite): ?string
    {
        $valor = trim((string) $valor);
        return $valor === '' ? null : mb_substr($valor, 0, $limite);
    }

    // -----------------------------------------------------------------
    // Consulta
    // -----------------------------------------------------------------

    /**
     * Filtros aceitos: acao, estabelecimento (id), busca, limite, deslocamento.
     * A listagem sai sempre do evento mais recente para o mais antigo.
     */
    public static function listar(array $filtros = []): array
    {
        if (!self::tabelaDisponivel()) {
            return [];
        }

        [$where, $parametros] = self::montarFiltros($filtros);

        $sql = 'SELECT * FROM logs_master ' . $where . ' ORDER BY data_hora DESC, id_log_master DESC';

        if (!empty($filtros['limite'])) {
            $sql .= ' LIMIT ' . (int) $filtros['limite'] . ' OFFSET ' . (int) ($filtros['deslocamento'] ?? 0);
        }

        $consulta = bd()->prepare($sql);
        $consulta->execute($parametros);
        return $consulta->fetchAll();
    }

    /** Conta os registros com os mesmos filtros da listagem, sem paginacao. */
    public static function contar(array $filtros = []): int
    {
        if (!self::tabelaDisponivel()) {
            return 0;
        }

        [$where, $parametros] = self::montarFiltros($filtros);

        $consulta = bd()->prepare('SELECT COUNT(*) FROM logs_master ' . $where);
        $consulta->execute($parametros);
        return (int) $consulta->fetchColumn();
    }

    /** Separa as condicoes SQL dos valores enviados ao PDO. */
    private static function montarFiltros(array $filtros): array
    {
        $condicoes  = ['1 = 1'];
        $parametros = [];

        if (!empty($filtros['acao']) && isset(self::ACOES[$filtros['acao']])) {
            $condicoes[] = 'acao = :acao';
            $parametros[':acao'] = $filtros['acao'];
        }

        if (!empty($filtros['estabelecimento'])) {
            $condicoes[] = 'id_estabelecimento = :estabelecimento';
            $parametros[':estabelecimento'] = (int) $filtros['estabelecimento'];
        }

        $busca = trim((string) ($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $como = Sql::como();
            $condicoes[] = '(master_nome ' . $como . ' :busca'
                . ' OR master_email ' . $como . ' :buscaEmail'
                . ' OR alvo ' . $como . ' :buscaAlvo'
                . ' OR estabelecimento_nome ' . $como . ' :buscaEmpresa)';
            $parametros[':busca']        = '%' . $busca . '%';
            $parametros[':buscaEmail']   = '%' . $busca . '%';
            $parametros[':buscaAlvo']    = '%' . $busca . '%';
            $parametros[':buscaEmpresa'] = '%' . $busca . '%';
        }

        return ['WHERE ' . implode(' AND ', $condicoes), $parametros];
    }

    /** Rotulo legivel da acao. */
    public static function acaoTexto(string $acao): string
    {
        return self::ACOES[$acao] ?? $acao;
    }

    // -----------------------------------------------------------------
    // Estrutura
    // -----------------------------------------------------------------

    /**
     * Garante a tabela na primeira chamada da requisicao.
     *
     * O esquema completo ja traz logs_master, mas uma instalacao antiga nao tem
     * a tabela — e um sistema recem-atualizado e justamente o que mais precisa
     * da auditoria funcionando desde o primeiro acesso.
     */
    private static function tabelaDisponivel(): bool
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
            error_log('Tabela de auditoria master indisponivel: ' . $erro->getMessage());
            self::$tabelaPronta = false;
        }

        return self::$tabelaPronta;
    }

    /** Comandos de criacao da tabela em cada dialeto. */
    public static function ddl(): array
    {
        if (Database::ehPostgres()) {
            return [
                "CREATE TABLE IF NOT EXISTS logs_master (
                    id_log_master        BIGINT GENERATED BY DEFAULT AS IDENTITY,
                    id_master            INTEGER DEFAULT NULL,
                    master_nome          VARCHAR(120) NOT NULL DEFAULT '',
                    master_email         VARCHAR(150) NOT NULL DEFAULT '',
                    acao                 VARCHAR(40) NOT NULL,
                    id_estabelecimento   INTEGER DEFAULT NULL,
                    estabelecimento_nome VARCHAR(120) DEFAULT NULL,
                    alvo                 VARCHAR(150) DEFAULT NULL,
                    detalhe              VARCHAR(255) DEFAULT NULL,
                    ip                   VARCHAR(45) DEFAULT NULL,
                    data_hora            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT pk_logs_master PRIMARY KEY (id_log_master)
                )",
                'CREATE INDEX IF NOT EXISTS idx_logs_master_data ON logs_master (data_hora)',
                'CREATE INDEX IF NOT EXISTS idx_logs_master_acao ON logs_master (acao)',
                'CREATE INDEX IF NOT EXISTS idx_logs_master_empresa ON logs_master (id_estabelecimento, data_hora)',
            ];
        }

        return [
            "CREATE TABLE IF NOT EXISTS logs_master (
                id_log_master        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                id_master            INT UNSIGNED DEFAULT NULL,
                master_nome          VARCHAR(120) NOT NULL DEFAULT '',
                master_email         VARCHAR(150) NOT NULL DEFAULT '',
                acao                 VARCHAR(40) NOT NULL,
                id_estabelecimento   INT UNSIGNED DEFAULT NULL,
                estabelecimento_nome VARCHAR(120) DEFAULT NULL,
                alvo                 VARCHAR(150) DEFAULT NULL,
                detalhe              VARCHAR(255) DEFAULT NULL,
                ip                   VARCHAR(45) DEFAULT NULL,
                data_hora            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id_log_master),
                KEY idx_logs_master_data (data_hora),
                KEY idx_logs_master_acao (acao),
                KEY idx_logs_master_empresa (id_estabelecimento, data_hora)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
}
