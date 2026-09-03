<?php
/**
 * Acesso a tabela usuarios (autenticacao e dados comuns a todos os perfis).
 */
class Usuario
{
    public static function porId(int $idUsuario): ?array
    {
        $sql = 'SELECT * FROM usuarios WHERE id_usuario = :id LIMIT 1';
        $consulta = bd()->prepare($sql);
        $consulta->execute([':id' => $idUsuario]);
        return $consulta->fetch() ?: null;
    }

    public static function porEmail(string $email): ?array
    {
        $sql = 'SELECT * FROM usuarios WHERE email = :email LIMIT 1';
        $consulta = bd()->prepare($sql);
        $consulta->execute([':email' => mb_strtolower(trim($email))]);
        return $consulta->fetch() ?: null;
    }

    public static function emailEmUso(string $email, ?int $ignorarIdUsuario = null): bool
    {
        $sql = 'SELECT id_usuario FROM usuarios WHERE email = :email';
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
        $sql = 'INSERT INTO usuarios (nome, email, senha_hash, telefone, tipo, status)
                VALUES (:nome, :email, :senha_hash, :telefone, :tipo, :status)';

        $consulta = bd()->prepare($sql);
        $consulta->execute([
            ':nome'       => $dados['nome'],
            ':email'      => mb_strtolower(trim($dados['email'])),
            ':senha_hash' => password_hash($dados['senha'], PASSWORD_DEFAULT),
            ':telefone'   => apenasNumeros($dados['telefone'] ?? '') ?: null,
            ':tipo'       => $dados['tipo'] ?? 'cliente',
            ':status'     => $dados['status'] ?? 'ativo',
        ]);

        return (int) bd()->lastInsertId();
    }

    public static function atualizar(int $idUsuario, array $dados): bool
    {
        $sql = 'UPDATE usuarios SET nome = :nome, email = :email, telefone = :telefone
                WHERE id_usuario = :id';

        $consulta = bd()->prepare($sql);
        return $consulta->execute([
            ':nome'     => $dados['nome'],
            ':email'    => mb_strtolower(trim($dados['email'])),
            ':telefone' => apenasNumeros($dados['telefone'] ?? '') ?: null,
            ':id'       => $idUsuario,
        ]);
    }

    public static function atualizarSenha(int $idUsuario, string $senhaPura): bool
    {
        $consulta = bd()->prepare('UPDATE usuarios SET senha_hash = :hash WHERE id_usuario = :id');
        return $consulta->execute([
            ':hash' => password_hash($senhaPura, PASSWORD_DEFAULT),
            ':id'   => $idUsuario,
        ]);
    }

    public static function senhaConfere(int $idUsuario, string $senha): bool
    {
        $consulta = bd()->prepare('SELECT senha_hash FROM usuarios WHERE id_usuario = :id LIMIT 1');
        $consulta->execute([':id' => $idUsuario]);
        $registro = $consulta->fetch();

        return $registro && password_verify($senha, $registro['senha_hash']);
    }

    public static function alterarStatus(int $idUsuario, string $status): bool
    {
        $status = $status === 'ativo' ? 'ativo' : 'inativo';
        $consulta = bd()->prepare('UPDATE usuarios SET status = :status WHERE id_usuario = :id');
        return $consulta->execute([':status' => $status, ':id' => $idUsuario]);
    }

    public static function registrarAcesso(int $idUsuario): void
    {
        $consulta = bd()->prepare('UPDATE usuarios SET ultimo_acesso = NOW() WHERE id_usuario = :id');
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

        $consulta = bd()->prepare("SELECT {$coluna} FROM {$tabela} WHERE id_usuario = :id LIMIT 1");
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
             SET token_recuperacao = :token, token_expiracao = DATE_ADD(NOW(), INTERVAL :horas HOUR)
             WHERE id_usuario = :id'
        );
        $consulta->execute([':token' => $token, ':horas' => $validadeHoras, ':id' => $idUsuario]);

        return $token;
    }

    public static function porTokenRecuperacao(string $token): ?array
    {
        $consulta = bd()->prepare(
            'SELECT * FROM usuarios
             WHERE token_recuperacao = :token AND token_expiracao > NOW() AND status = "ativo"
             LIMIT 1'
        );
        $consulta->execute([':token' => $token]);
        return $consulta->fetch() ?: null;
    }

    public static function limparTokenRecuperacao(int $idUsuario): void
    {
        $consulta = bd()->prepare(
            'UPDATE usuarios SET token_recuperacao = NULL, token_expiracao = NULL WHERE id_usuario = :id'
        );
        $consulta->execute([':id' => $idUsuario]);
    }

    public static function excluir(int $idUsuario): bool
    {
        $consulta = bd()->prepare('DELETE FROM usuarios WHERE id_usuario = :id');
        return $consulta->execute([':id' => $idUsuario]);
    }
}
