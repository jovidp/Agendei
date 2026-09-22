<?php
/**
 * Acesso a tabela usuarios (autenticacao e dados comuns a todos os perfis).
 */
class Usuario
{
    /** Busca o registro pelo identificador; retorna null quando ele não existe. */
    public static function porId(int $idUsuario): ?array
    {
        $sql = 'SELECT * FROM usuarios WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_usuario = :id LIMIT 1';
        $consulta = bd()->prepare($sql);
        $consulta->execute([':id' => $idUsuario]);
        return $consulta->fetch() ?: null;
    }

    /** Busca a conta pelo e-mail para autenticação e recuperação de acesso. */
    public static function porEmail(string $email): ?array
    {
        $sql = 'SELECT * FROM usuarios WHERE id_estabelecimento = ' . Contexto::id() . ' AND email = :email LIMIT 1';
        $consulta = bd()->prepare($sql);
        $consulta->execute([':email' => mb_strtolower(trim($email))]);
        return $consulta->fetch() ?: null;
    }

    /** Busca a conta pelo login de acesso (exatamente 6 letras). */
    public static function porLogin(string $login): ?array
    {
        $sql = 'SELECT * FROM usuarios WHERE id_estabelecimento = ' . Contexto::id() . ' AND login = :login LIMIT 1';
        $consulta = bd()->prepare($sql);
        $consulta->execute([':login' => mb_strtolower(trim($login))]);
        return $consulta->fetch() ?: null;
    }

    /**
     * Ponto de entrada da autenticacao: aceita o login de 6 letras ou o e-mail.
     * As contas criadas antes do campo login continuam entrando pelo e-mail.
     */
    public static function porLoginOuEmail(string $identificador): ?array
    {
        $identificador = trim($identificador);

        if (validarLogin($identificador)) {
            $usuario = self::porLogin($identificador);
            if ($usuario !== null) {
                return $usuario;
            }
        }

        return self::porEmail($identificador);
    }

    /** Verifica login duplicado, ignorando a própria conta quando o ID é informado. */
    public static function loginEmUso(string $login, ?int $ignorarIdUsuario = null): bool
    {
        $login = trim($login);
        if ($login === '') {
            return false;
        }

        $sql = 'SELECT id_usuario FROM usuarios WHERE id_estabelecimento = ' . Contexto::id() . ' AND login = :login';
        $parametros = [':login' => mb_strtolower($login)];

        if ($ignorarIdUsuario !== null) {
            $sql .= ' AND id_usuario <> :id';
            $parametros[':id'] = $ignorarIdUsuario;
        }

        $consulta = bd()->prepare($sql . ' LIMIT 1');
        $consulta->execute($parametros);
        return (bool) $consulta->fetch();
    }

    /** Verifica e-mail duplicado, ignorando a própria conta quando o ID é informado. */
    public static function emailEmUso(string $email, ?int $ignorarIdUsuario = null): bool
    {
        $sql = 'SELECT id_usuario FROM usuarios WHERE id_estabelecimento = ' . Contexto::id() . ' AND email = :email';
        $parametros = [':email' => mb_strtolower(trim($email))];

        if ($ignorarIdUsuario !== null) {
            $sql .= ' AND id_usuario <> :id';
            $parametros[':id'] = $ignorarIdUsuario;
        }

        $consulta = bd()->prepare($sql . ' LIMIT 1');
        $consulta->execute($parametros);
        return (bool) $consulta->fetch();
    }

    /**
     * Cria o registro base de autenticacao.
     * Use os models Cliente/Profissional para criar o perfil completo.
     */
    public static function criar(array $dados): int
    {
        $sql = 'INSERT INTO usuarios (id_estabelecimento, nome, email, senha_hash, telefone, tipo, status,
                                      login, sexo, nome_materno, data_nascimento, telefone_fixo,
                                      cep, logradouro, numero, complemento, bairro, cidade, uf)
                VALUES (' . Contexto::id() . ', :nome, :email, :senha_hash, :telefone, :tipo, :status,
                        :login, :sexo, :nome_materno, :data_nascimento, :telefone_fixo,
                        :cep, :logradouro, :numero, :complemento, :bairro, :cidade, :uf)';

        $consulta = bd()->prepare($sql);
        $consulta->execute([
            ':nome'       => $dados['nome'],
            ':email'      => mb_strtolower(trim($dados['email'])),
            ':senha_hash' => password_hash($dados['senha'], PASSWORD_DEFAULT),
            ':telefone'   => apenasNumeros($dados['telefone'] ?? '') ?: null,
            ':tipo'       => $dados['tipo'] ?? 'cliente',
            ':status'     => $dados['status'] ?? 'ativo',
        ] + self::parametrosPessoais($dados));

        return (int) bd()->lastInsertId();
    }

    /**
     * Campos do cadastro completo exigido pela especificacao.
     * Ficam em usuarios para que o master tambem responda as perguntas do 2FA.
     */
    private static function parametrosPessoais(array $dados): array
    {
        $login = trim((string) ($dados['login'] ?? ''));
        $cep   = apenasNumeros($dados['cep'] ?? '');

        return [
            ':login'           => $login !== '' ? mb_strtolower($login) : null,
            ':sexo'            => in_array($dados['sexo'] ?? '', ['F', 'M', 'O'], true) ? $dados['sexo'] : null,
            ':nome_materno'    => trim((string) ($dados['nome_materno'] ?? '')) ?: null,
            ':data_nascimento' => $dados['data_nascimento'] ?? null,
            ':telefone_fixo'   => apenasNumeros($dados['telefone_fixo'] ?? '') ?: null,
            ':cep'             => $cep !== '' ? $cep : null,
            ':logradouro'      => trim((string) ($dados['logradouro'] ?? '')) ?: null,
            ':numero'          => trim((string) ($dados['numero'] ?? '')) ?: null,
            ':complemento'     => trim((string) ($dados['complemento'] ?? '')) ?: null,
            ':bairro'          => trim((string) ($dados['bairro'] ?? '')) ?: null,
            ':cidade'          => trim((string) ($dados['cidade'] ?? '')) ?: null,
            ':uf'              => mb_strtoupper(trim((string) ($dados['uf'] ?? ''))) ?: null,
        ];
    }

    /** Atualiza somente os dados pessoais do cadastro completo. */
    public static function atualizarDadosPessoais(int $idUsuario, array $dados): bool
    {
        $sql = 'UPDATE usuarios SET
                    login = :login, sexo = :sexo, nome_materno = :nome_materno,
                    data_nascimento = :data_nascimento, telefone_fixo = :telefone_fixo,
                    cep = :cep, logradouro = :logradouro, numero = :numero,
                    complemento = :complemento, bairro = :bairro, cidade = :cidade, uf = :uf
                WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_usuario = :id';

        $consulta = bd()->prepare($sql);
        return $consulta->execute(self::parametrosPessoais($dados) + [':id' => $idUsuario]);
    }

    /** Persiste os campos editáveis do cadastro identificado pelo ID. */
    public static function atualizar(int $idUsuario, array $dados): bool
    {
        $sql = 'UPDATE usuarios SET nome = :nome, email = :email, telefone = :telefone
                WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_usuario = :id';

        $consulta = bd()->prepare($sql);
        return $consulta->execute([
            ':nome'     => $dados['nome'],
            ':email'    => mb_strtolower(trim($dados['email'])),
            ':telefone' => apenasNumeros($dados['telefone'] ?? '') ?: null,
            ':id'       => $idUsuario,
        ]);
    }

    /** Gera um novo hash antes de substituir a senha armazenada. */
    public static function atualizarSenha(int $idUsuario, string $senhaPura): bool
    {
        $consulta = bd()->prepare('UPDATE usuarios SET senha_hash = :hash WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_usuario = :id');
        return $consulta->execute([
            ':hash' => password_hash($senhaPura, PASSWORD_DEFAULT),
            ':id'   => $idUsuario,
        ]);
    }

    /** Compara a senha fornecida com o hash da conta usando password_verify. */
    public static function senhaConfere(int $idUsuario, string $senha): bool
    {
        $consulta = bd()->prepare('SELECT senha_hash FROM usuarios WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_usuario = :id LIMIT 1');
        $consulta->execute([':id' => $idUsuario]);
        $registro = $consulta->fetch();

        return $registro && password_verify($senha, $registro['senha_hash']);
    }

    /** Atualiza a situação do registro identificado pelo ID. */
    public static function alterarStatus(int $idUsuario, string $status): bool
    {
        $status = $status === 'ativo' ? 'ativo' : 'inativo';
        $consulta = bd()->prepare('UPDATE usuarios SET status = :status WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_usuario = :id');
        return $consulta->execute([':status' => $status, ':id' => $idUsuario]);
    }

    /** Atualiza a data do último acesso após o login. */
    public static function registrarAcesso(int $idUsuario): void
    {
        $consulta = bd()->prepare('UPDATE usuarios SET ultimo_acesso = NOW() WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_usuario = :id');
        $consulta->execute([':id' => $idUsuario]);
    }

    /** Retorna o id da tabela especifica do perfil (clientes/profissionais/administradores). */
    public static function idDoPerfil(int $idUsuario, string $tipo): ?int
    {
        $mapa = [
            'cliente'      => ['clientes', 'id_cliente'],
            'profissional' => ['profissionais', 'id_profissional'],
            'admin'        => ['administradores', 'id_administrador'],
        ];

        if (!isset($mapa[$tipo])) {
            return null;
        }

        [$tabela, $coluna] = $mapa[$tipo];

        $consulta = bd()->prepare("SELECT {$coluna} FROM {$tabela} WHERE id_estabelecimento = " . Contexto::id() . " AND id_usuario = :id LIMIT 1");
        $consulta->execute([':id' => $idUsuario]);
        $registro = $consulta->fetch();

        return $registro ? (int) $registro[$coluna] : null;
    }

    // -----------------------------------------------------------------
    // Recuperacao de senha
    // -----------------------------------------------------------------

    /** Gera e grava um token de recuperacao valido por algumas horas. */
    public static function gerarTokenRecuperacao(int $idUsuario, int $validadeHoras = 2): string
    {
        $token = bin2hex(random_bytes(32));

        $consulta = bd()->prepare(
            'UPDATE usuarios
             SET token_recuperacao = :token, token_expiracao = ' . Sql::somarHoras('NOW()', ':horas') . '
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_usuario = :id'
        );
        $consulta->execute([':token' => $token, ':horas' => $validadeHoras, ':id' => $idUsuario]);

        return $token;
    }

    /** Busca a conta associada ao token de recuperação ainda válido. */
    public static function porTokenRecuperacao(string $token): ?array
    {
        $consulta = bd()->prepare(
            'SELECT * FROM usuarios
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND token_recuperacao = :token AND token_expiracao > NOW() AND status = \'ativo\'
             LIMIT 1'
        );
        $consulta->execute([':token' => $token]);
        return $consulta->fetch() ?: null;
    }

    /** Invalida o token após a recuperação para impedir sua reutilização. */
    public static function limparTokenRecuperacao(int $idUsuario): void
    {
        $consulta = bd()->prepare(
            'UPDATE usuarios SET token_recuperacao = NULL, token_expiracao = NULL WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_usuario = :id'
        );
        $consulta->execute([':id' => $idUsuario]);
    }

    /** Executa a exclusão pelo ID; as restrições do banco continuam sendo aplicadas. */
    public static function excluir(int $idUsuario): bool
    {
        $consulta = bd()->prepare('DELETE FROM usuarios WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_usuario = :id');
        return $consulta->execute([':id' => $idUsuario]);
    }

    // -----------------------------------------------------------------
    // Segundo fator por codigo (TOTP)
    // -----------------------------------------------------------------

    /** Indica se a conta ja concluiu o cadastro do aplicativo autenticador. */
    public static function totpAtivo(?array $usuario): bool
    {
        return !empty($usuario['totp_ativado_em']) && Totp::decifrar($usuario['totp_segredo'] ?? null) !== '';
    }

    /** Segredo aberto da conta, ou string vazia quando nao ha nenhum guardado. */
    public static function totpSegredo(?array $usuario): string
    {
        return Totp::decifrar($usuario['totp_segredo'] ?? null);
    }

    /**
     * Guarda um segredo novo ainda nao confirmado.
     *
     * O cadastro so vale depois que o usuario digita um codigo gerado por ele
     * (totpAtivar). Ate la o segredo fica no banco sem data de ativacao, entao
     * o login continua pedindo a pergunta cadastral: ninguem se tranca fora da
     * conta por ter aberto a tela de cadastro e desistido no meio.
     */
    public static function totpPreparar(int $idUsuario, string $segredo): void
    {
        $consulta = bd()->prepare(
            'UPDATE usuarios
             SET totp_segredo = :segredo, totp_ativado_em = NULL, totp_ultimo_contador = NULL
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_usuario = :id'
        );
        $consulta->execute([':segredo' => Totp::cifrar($segredo), ':id' => $idUsuario]);
    }

    /** Confirma o cadastro do aplicativo: a partir daqui o login pede o codigo. */
    public static function totpAtivar(int $idUsuario, int $contadorUsado): void
    {
        $consulta = bd()->prepare(
            'UPDATE usuarios
             SET totp_ativado_em = NOW(), totp_ultimo_contador = :contador
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_usuario = :id'
        );
        $consulta->execute([':contador' => $contadorUsado, ':id' => $idUsuario]);
    }

    /** Desliga o codigo e apaga o segredo; a conta volta a pergunta cadastral. */
    public static function totpDesativar(int $idUsuario): void
    {
        $consulta = bd()->prepare(
            'UPDATE usuarios
             SET totp_segredo = NULL, totp_ativado_em = NULL, totp_ultimo_contador = NULL
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_usuario = :id'
        );
        $consulta->execute([':id' => $idUsuario]);
    }

    /**
     * Aceita a janela usada apenas se ela for posterior a ultima aproveitada.
     *
     * Sem esta trava, um codigo visto por cima do ombro — ou capturado num
     * computador comprometido — continuaria valendo pelo resto dos 30 segundos
     * e ainda pela janela de tolerancia. Devolve false quando o codigo ja foi
     * usado, e a tela trata como codigo invalido.
     */
    public static function totpContadorUsado(int $idUsuario, array $usuario, int $contador): bool
    {
        $ultimo = $usuario['totp_ultimo_contador'] ?? null;

        if ($ultimo !== null && $contador <= (int) $ultimo) {
            return false;
        }

        $consulta = bd()->prepare(
            'UPDATE usuarios SET totp_ultimo_contador = :contador
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_usuario = :id'
        );
        $consulta->execute([':contador' => $contador, ':id' => $idUsuario]);

        return true;
    }

    // ---------------------------------------------------------------------
    // Consulta global (painel master)
    //
    // O vinculo conta -> empresa e a coluna usuarios.id_estabelecimento. As
    // buscas acima passam pelo Contexto e so enxergam a empresa da sessao; o
    // master nao tem empresa, e precisa responder "este e-mail e de quem?".
    // O e-mail e unico apenas dentro de cada empresa, por isso o retorno e uma
    // linha por vinculo: a mesma pessoa pode aparecer em varios estabelecimentos.
    // ---------------------------------------------------------------------

    /** Tipos de conta local, na ordem em que aparecem nos filtros. */
    public const TIPOS = ['admin' => 'Administrador', 'profissional' => 'Profissional', 'cliente' => 'Cliente'];

    /** Monta o WHERE e os parametros da consulta global a partir dos filtros. */
    private static function filtrosGlobais(array $filtros): array
    {
        $condicoes = [];
        $parametros = [];

        $busca = trim((string) ($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $como = Sql::como();
            $condicoes[] = '(u.email ' . $como . ' :busca_email OR u.nome ' . $como . ' :busca_nome OR u.login = :busca_login)';
            $parametros[':busca_email'] = '%' . $busca . '%';
            $parametros[':busca_nome'] = '%' . $busca . '%';
            $parametros[':busca_login'] = mb_strtolower($busca);
        }

        $tipo = (string) ($filtros['tipo'] ?? '');
        if (isset(self::TIPOS[$tipo])) {
            $condicoes[] = 'u.tipo = :tipo';
            $parametros[':tipo'] = $tipo;
        }

        $empresa = (int) ($filtros['estabelecimento'] ?? 0);
        if ($empresa > 0) {
            $condicoes[] = 'u.id_estabelecimento = :estabelecimento';
            $parametros[':estabelecimento'] = $empresa;
        }

        return [$condicoes ? ' WHERE ' . implode(' AND ', $condicoes) : '', $parametros];
    }

    /** Conta as contas locais de todas as empresas que atendem aos filtros. */
    public static function contarGlobal(array $filtros = []): int
    {
        [$where, $parametros] = self::filtrosGlobais($filtros);
        $consulta = bd()->prepare('SELECT COUNT(*) FROM usuarios u' . $where);
        $consulta->execute($parametros);
        return (int) $consulta->fetchColumn();
    }

    /**
     * Lista contas locais de qualquer empresa com o estabelecimento ao lado.
     * Nunca devolve hash de senha nem segredos de 2FA.
     */
    public static function buscarGlobal(array $filtros = []): array
    {
        [$where, $parametros] = self::filtrosGlobais($filtros);
        $sql = 'SELECT u.id_usuario, u.nome, u.email, u.login, u.tipo, u.status, u.telefone,
                       u.ultimo_acesso, u.data_criacao, u.id_estabelecimento,
                       e.nome AS estabelecimento_nome, e.slug AS estabelecimento_slug, e.status AS estabelecimento_status
                FROM usuarios u
                JOIN estabelecimento e ON e.id_estabelecimento = u.id_estabelecimento'
            . $where
            . ' ORDER BY u.email, e.nome, u.id_usuario';

        $limite = (int) ($filtros['limite'] ?? 0);
        if ($limite > 0) {
            $sql .= ' LIMIT ' . $limite . ' OFFSET ' . max(0, (int) ($filtros['deslocamento'] ?? 0));
        }

        $consulta = bd()->prepare($sql);
        $consulta->execute($parametros);
        return $consulta->fetchAll();
    }

    /**
     * Contas de um e-mail em qualquer empresa, com o hash de senha.
     * Uso exclusivo da autenticacao pela entrada geral (autenticarGlobal):
     * as telas devem usar vinculosPorEmail(), que nao devolve segredos.
     */
    public static function porEmailGlobal(string $email): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return [];
        }
        $consulta = bd()->prepare('SELECT u.*, e.nome AS estabelecimento_nome, e.slug AS estabelecimento_slug, e.status AS estabelecimento_status
                                   FROM usuarios u
                                   JOIN estabelecimento e ON e.id_estabelecimento = u.id_estabelecimento
                                   WHERE u.email = :email ORDER BY e.nome, u.id_usuario');
        $consulta->execute([':email' => $email]);
        return $consulta->fetchAll();
    }

    /** Todos os vinculos de um e-mail, em qualquer empresa. Resposta direta para "esta conta e de qual estabelecimento?". */
    public static function vinculosPorEmail(string $email): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return [];
        }
        $consulta = bd()->prepare('SELECT u.id_usuario, u.nome, u.tipo, u.status, u.id_estabelecimento,
                                          e.nome AS estabelecimento_nome, e.slug AS estabelecimento_slug
                                   FROM usuarios u
                                   JOIN estabelecimento e ON e.id_estabelecimento = u.id_estabelecimento
                                   WHERE u.email = :email ORDER BY e.nome, u.id_usuario');
        $consulta->execute([':email' => $email]);
        return $consulta->fetchAll();
    }
}
