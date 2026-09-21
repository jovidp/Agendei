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
