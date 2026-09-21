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
}
