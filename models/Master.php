<?php
/** Conta global responsável pela administração dos estabelecimentos contratantes. */
class Master
{
    public static function porId(int $id): ?array
    {
        $q = bd()->prepare('SELECT * FROM administradores_master WHERE id_master = ? LIMIT 1');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    public static function porEmail(string $email): ?array
    {
        $q = bd()->prepare('SELECT * FROM administradores_master WHERE email = ? LIMIT 1');
        $q->execute([mb_strtolower(trim($email))]);
        return $q->fetch() ?: null;
    }

    public static function autenticar(string $email, string $senha): ?array
    {
        $master = self::porEmail($email);
        if (!$master || $master['status'] !== 'ativo' || !password_verify($senha, $master['senha_hash'])) return null;
        if (password_needs_rehash($master['senha_hash'], PASSWORD_DEFAULT)) self::atualizarSenha((int) $master['id_master'], $senha);
        bd()->prepare('UPDATE administradores_master SET ultimo_acesso = NOW() WHERE id_master = ?')->execute([$master['id_master']]);
        return $master;
    }

    public static function atualizarPerfil(int $id, string $nome, string $email): bool
    {
        $q = bd()->prepare('UPDATE administradores_master SET nome = ?, email = ? WHERE id_master = ?');
        return $q->execute([$nome, mb_strtolower(trim($email)), $id]);
    }

    public static function senhaConfere(int $id, string $senha): bool
    {
        $registro = self::porId($id);
        return $registro && password_verify($senha, $registro['senha_hash']);
    }

    public static function atualizarSenha(int $id, string $senha): bool
    {
        $q = bd()->prepare('UPDATE administradores_master SET senha_hash = ? WHERE id_master = ?');
        return $q->execute([password_hash($senha, PASSWORD_DEFAULT), $id]);
    }

    public static function aparencia(): array
    {
        $id = (int) ($_SESSION['master_id'] ?? 0);
        $registro = $id > 0 ? self::porId($id) : bd()->query('SELECT * FROM administradores_master WHERE status = "ativo" ORDER BY id_master LIMIT 1')->fetch();
        return $registro ?: ['nome' => 'Agendei Master'] + Tema::PADRAO;
    }

    public static function personalizar(int $id, string $nome, array $tema, ?string $logo): void
    {
        $q = bd()->prepare('UPDATE administradores_master SET nome = ?, cor_primaria = ?, cor_secundaria = ?, cor_fundo = ?, fonte = ?, logo = ? WHERE id_master = ?');
        $q->execute([$nome, $tema['cor_primaria'], $tema['cor_secundaria'], $tema['cor_fundo'], $tema['fonte'], $logo, $id]);
    }
}
