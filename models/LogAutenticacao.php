<?php
/**
 * Log de autenticacao: entradas, falhas e resultado do segundo fator.
 *
 * Nome e CPF sao gravados junto do evento (e nao lidos por JOIN) para que o
 * historico continue legivel depois que o master excluir o usuario comum.
 */
class LogAutenticacao
{
    /** Eventos aceitos pela coluna ENUM da tabela. */
    private const EVENTOS = [
        'login_sucesso', 'login_falha', '2fa_sucesso', '2fa_falha', '2fa_bloqueio', 'logout',
    ];

    /** Perguntas disponiveis no segundo fator. */
    public const FATORES = [
        'nome_materno'    => 'Nome da mãe',
        'data_nascimento' => 'Data de nascimento',
        'cep'             => 'CEP do endereço',
    ];

    /**
     * Grava um evento. $usuario e o registro de usuarios (ou null quando o
     * login informado nao existe) e $cpf vem do perfil de cliente, quando houver.
     */
    public static function registrar(string $evento, string $loginInformado, ?array $usuario = null, ?string $fator = null, ?string $cpf = null): void
    {
        if (!in_array($evento, self::EVENTOS, true)) {
            return;
        }

        $sql = 'INSERT INTO logs_autenticacao
                    (id_estabelecimento, id_usuario, login_informado, nome, cpf, perfil, evento, fator_2fa, ip)
                VALUES
                    (' . Contexto::id() . ', :id_usuario, :login, :nome, :cpf, :perfil, :evento, :fator, :ip)';

        try {
            $consulta = bd()->prepare($sql);
            $consulta->execute([
                ':id_usuario' => $usuario['id_usuario'] ?? null,
                ':login'      => mb_substr($loginInformado, 0, 150),
                ':nome'       => mb_substr((string) ($usuario['nome'] ?? ''), 0, 120),
                ':cpf'        => $cpf !== null && $cpf !== '' ? apenasNumeros($cpf) : null,
                ':perfil'     => $usuario['tipo'] ?? null,
                ':evento'     => $evento,
                ':fator'      => isset(self::FATORES[(string) $fator]) ? $fator : null,
                ':ip'         => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            ]);
        } catch (Throwable $erro) {
            // O log e um registro auxiliar: uma falha aqui nao pode derrubar o login.
            error_log('Falha ao gravar log de autenticacao: ' . $erro->getMessage());
        }
    }

    /**
     * Filtros aceitos: campo (nome|cpf|todos), busca, evento, limite, deslocamento.
     * A listagem sai sempre da entrada mais recente para a mais antiga.
     */
    public static function listar(array $filtros = []): array
    {
        [$where, $parametros] = self::montarFiltros($filtros);

        $sql = 'SELECT * FROM logs_autenticacao ' . $where . ' ORDER BY data_hora DESC, id_log DESC';

        if (!empty($filtros['limite'])) {
            $sql .= ' LIMIT ' . (int) $filtros['limite'] . ' OFFSET ' . (int) ($filtros['deslocamento'] ?? 0);
        }

        $consulta = bd()->prepare($sql);
        $consulta->execute($parametros);
        return $consulta->fetchAll();
    }

    /** Conta os registros com os mesmos filtros da listagem, sem aplicar paginação. */
    public static function contar(array $filtros = []): int
    {
        [$where, $parametros] = self::montarFiltros($filtros);

        $consulta = bd()->prepare('SELECT COUNT(*) AS total FROM logs_autenticacao ' . $where);
        $consulta->execute($parametros);
        return (int) ($consulta->fetch()['total'] ?? 0);
    }

    // -----------------------------------------------------------------
    // Consulta global (area master)
    //
    // As consultas acima presas a Contexto::id() servem ao painel de cada
    // empresa. O master precisa do oposto: enxergar a plataforma inteira para
    // perceber uma varredura de senhas que toca varios estabelecimentos e que,
    // vista de dentro de um deles, pareceria um punhado de erros comuns.
    // -----------------------------------------------------------------

    /** Listagem sem recorte por empresa. Aceita os mesmos limites de paginacao. */
    public static function listarGlobal(array $filtros = []): array
    {
        [$where, $parametros] = self::montarFiltrosGlobais($filtros);

        $sql = 'SELECT l.*, e.nome AS estabelecimento_nome, e.slug AS estabelecimento_slug
                FROM logs_autenticacao l
                LEFT JOIN estabelecimento e ON e.id_estabelecimento = l.id_estabelecimento
                ' . $where . '
                ORDER BY l.data_hora DESC, l.id_log DESC';

        if (!empty($filtros['limite'])) {
            $sql .= ' LIMIT ' . (int) $filtros['limite'] . ' OFFSET ' . (int) ($filtros['deslocamento'] ?? 0);
        }

        $consulta = bd()->prepare($sql);
        $consulta->execute($parametros);
        return $consulta->fetchAll();
    }

    /** Conta os registros da listagem global com os mesmos filtros. */
    public static function contarGlobal(array $filtros = []): int
    {
        [$where, $parametros] = self::montarFiltrosGlobais($filtros);

        $consulta = bd()->prepare('SELECT COUNT(*) FROM logs_autenticacao l ' . $where);
        $consulta->execute($parametros);
        return (int) $consulta->fetchColumn();
    }

    /**
     * Resumo por evento dentro de uma janela de horas.
     * Alimenta os indicadores do painel de seguranca.
     */
    public static function resumoGlobal(int $horas = 24): array
    {
        $consulta = bd()->prepare(
            'SELECT evento, COUNT(*) AS total FROM logs_autenticacao
             WHERE data_hora > :limite GROUP BY evento'
        );
        $consulta->execute([':limite' => date('Y-m-d H:i:s', time() - $horas * 3600)]);

        $resumo = [];
        foreach ($consulta->fetchAll() as $linha) {
            $resumo[(string) $linha['evento']] = (int) $linha['total'];
        }
        return $resumo;
    }

    /** Condicoes da consulta global: empresa, evento e termo livre. */
    private static function montarFiltrosGlobais(array $filtros): array
    {
        $condicoes  = ['1 = 1'];
        $parametros = [];

        if (!empty($filtros['estabelecimento'])) {
            $condicoes[] = 'l.id_estabelecimento = :estabelecimento';
            $parametros[':estabelecimento'] = (int) $filtros['estabelecimento'];
        }

        if (!empty($filtros['evento']) && in_array($filtros['evento'], self::EVENTOS, true)) {
            $condicoes[] = 'l.evento = :evento';
            $parametros[':evento'] = $filtros['evento'];
        }

        $busca = trim((string) ($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $como = Sql::como();
            $condicoes[] = '(l.nome ' . $como . ' :busca'
                . ' OR l.login_informado ' . $como . ' :buscaLogin'
                . ' OR l.ip ' . $como . ' :buscaIp)';
            $parametros[':busca']      = '%' . $busca . '%';
            $parametros[':buscaLogin'] = '%' . $busca . '%';
            $parametros[':buscaIp']    = '%' . $busca . '%';
        }

        return ['WHERE ' . implode(' AND ', $condicoes), $parametros];
    }

    /** Separa as condições SQL dos valores enviados ao PDO para reutilizar os filtros. */
    private static function montarFiltros(array $filtros): array
    {
        $condicoes  = ['id_estabelecimento = ' . Contexto::id()];
        $parametros = [];

        $busca = trim((string) ($filtros['busca'] ?? ''));
        $campo = $filtros['campo'] ?? 'todos';

        if ($busca !== '') {
            if ($campo === 'nome') {
                $condicoes[] = 'nome ' . Sql::como() . ' :busca';
                $parametros[':busca'] = '%' . $busca . '%';
            } elseif ($campo === 'cpf') {
                $condicoes[] = 'cpf ' . Sql::como() . ' :busca';
                $parametros[':busca'] = '%' . (apenasNumeros($busca) ?: $busca) . '%';
            } else {
                // "Todos" procura no nome, no CPF e no identificador digitado na tela de login.
                $condicoes[] = '(nome ' . Sql::como() . ' :busca OR cpf ' . Sql::como() . ' :buscaCpf OR login_informado ' . Sql::como() . ' :buscaLogin)';
                $parametros[':busca']      = '%' . $busca . '%';
                $parametros[':buscaCpf']   = '%' . (apenasNumeros($busca) ?: $busca) . '%';
                $parametros[':buscaLogin'] = '%' . $busca . '%';
            }
        }

        if (!empty($filtros['evento']) && in_array($filtros['evento'], self::EVENTOS, true)) {
            $condicoes[] = 'evento = :evento';
            $parametros[':evento'] = $filtros['evento'];
        }

        return ['WHERE ' . implode(' AND ', $condicoes), $parametros];
    }

    /** Rotulo legivel do evento para a tela de log. */
    public static function eventoTexto(string $evento): string
    {
        return match ($evento) {
            'login_sucesso' => 'Login validado',
            'login_falha'   => 'Falha no login',
            '2fa_sucesso'   => 'Entrada concluída (2FA)',
            '2fa_falha'     => 'Resposta 2FA incorreta',
            '2fa_bloqueio'  => 'Bloqueio após 3 tentativas',
            'logout'        => 'Saída do sistema',
            default         => $evento,
        };
    }

    /** Rotulo da pergunta usada no segundo fator. */
    public static function fatorTexto(?string $fator): string
    {
        return self::FATORES[(string) $fator] ?? '-';
    }
}
