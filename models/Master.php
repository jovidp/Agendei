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

    // -----------------------------------------------------------------
    // Gestao das contas master
    //
    // Ate aqui o sistema so sabia criar a primeira conta, pelo instalador. Uma
    // conta unica significa que perder essa senha e perder a administracao da
    // plataforma inteira, sem caminho de volta pela interface.
    // -----------------------------------------------------------------

    /** Lista as contas globais para a tela de gestao, sem expor hashes. */
    public static function listar(): array
    {
        return bd()->query(
            'SELECT id_master, nome, email, status, ultimo_acesso, data_criacao
             FROM administradores_master ORDER BY nome'
        )->fetchAll();
    }

    /** Quantas contas master ainda podem entrar no sistema. */
    public static function contarAtivos(?int $ignorarId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM administradores_master WHERE status = \'ativo\'';
        $parametros = [];

        if ($ignorarId !== null) {
            $sql .= ' AND id_master <> ?';
            $parametros[] = $ignorarId;
        }

        $consulta = bd()->prepare($sql);
        $consulta->execute($parametros);
        return (int) $consulta->fetchColumn();
    }

    /** Indica se o e-mail ja pertence a outra conta master. */
    public static function emailEmUso(string $email, ?int $ignorarId = null): bool
    {
        $registro = self::porEmail($email);
        return $registro !== null && (int) $registro['id_master'] !== (int) $ignorarId;
    }

    /** Cria uma conta global adicional. Retorna o ID gerado. */
    public static function criar(array $dados): int
    {
        $consulta = bd()->prepare(
            'INSERT INTO administradores_master (nome, email, senha_hash) VALUES (?, ?, ?)'
        );
        $consulta->execute([
            $dados['nome'],
            mb_strtolower(trim($dados['email'])),
            password_hash($dados['senha'], PASSWORD_DEFAULT),
        ]);

        return (int) bd()->lastInsertId();
    }

    /**
     * Liga ou desliga o acesso de uma conta global.
     *
     * A ultima conta ativa nao pode ser desligada: o sistema ficaria sem
     * ninguem capaz de administrar os estabelecimentos.
     */
    public static function alterarStatus(int $id, string $status): bool
    {
        $status = $status === 'ativo' ? 'ativo' : 'inativo';

        if ($status === 'inativo' && self::contarAtivos($id) === 0) {
            return false;
        }

        $consulta = bd()->prepare('UPDATE administradores_master SET status = ? WHERE id_master = ?');
        return $consulta->execute([$status, $id]);
    }

    public static function aparencia(): array
    {
        $id = (int) ($_SESSION['master_id'] ?? 0);
        $registro = $id > 0 ? self::porId($id) : bd()->query('SELECT * FROM administradores_master WHERE status = \'ativo\' ORDER BY id_master LIMIT 1')->fetch();
        return $registro ?: ['nome' => 'Agendei Master'] + Tema::PADRAO;
    }

    public static function personalizar(int $id, string $nome, array $tema, ?string $logo): void
    {
        $q = bd()->prepare('UPDATE administradores_master SET nome = ?, cor_primaria = ?, cor_secundaria = ?, cor_fundo = ?, fonte = ?, logo = ? WHERE id_master = ?');
        $q->execute([$nome, $tema['cor_primaria'], $tema['cor_secundaria'], $tema['cor_fundo'], $tema['fonte'], $logo, $id]);
    }

    // -----------------------------------------------------------------
    // Segundo fator por codigo (TOTP)
    //
    // Mesma mecanica das contas de usuarios, em tabela propria. A diferenca
    // que importa: a conta master nao tem pergunta cadastral de reserva, entao
    // perder o celular tranca o acesso. A saida e scripts/liberar_2fa.php, que
    // roda no servidor e so serve a quem ja tem acesso a maquina.
    // -----------------------------------------------------------------

    /** Indica se a conta master ja concluiu o cadastro do aplicativo. */
    public static function totpAtivo(?array $master): bool
    {
        return !empty($master['totp_ativado_em']) && Totp::decifrar($master['totp_segredo'] ?? null) !== '';
    }

    /** Segredo aberto da conta master, ou string vazia quando nao ha nenhum. */
    public static function totpSegredo(?array $master): string
    {
        return Totp::decifrar($master['totp_segredo'] ?? null);
    }

    /** Guarda um segredo novo, ainda pendente de confirmacao. */
    public static function totpPreparar(int $id, string $segredo): void
    {
        $q = bd()->prepare(
            'UPDATE administradores_master
             SET totp_segredo = ?, totp_ativado_em = NULL, totp_ultimo_contador = NULL
             WHERE id_master = ?'
        );
        $q->execute([Totp::cifrar($segredo), $id]);
    }

    /** Confirma o cadastro: a partir daqui o login master pede o codigo. */
    public static function totpAtivar(int $id, int $contadorUsado): void
    {
        $q = bd()->prepare(
            'UPDATE administradores_master SET totp_ativado_em = NOW(), totp_ultimo_contador = ? WHERE id_master = ?'
        );
        $q->execute([$contadorUsado, $id]);
    }

    /** Desliga o codigo e apaga o segredo. */
    public static function totpDesativar(int $id): void
    {
        $q = bd()->prepare(
            'UPDATE administradores_master
             SET totp_segredo = NULL, totp_ativado_em = NULL, totp_ultimo_contador = NULL
             WHERE id_master = ?'
        );
        $q->execute([$id]);
    }

    /** Recusa a janela que ja foi aproveitada, impedindo reapresentar o codigo. */
    public static function totpContadorUsado(int $id, array $master, int $contador): bool
    {
        $ultimo = $master['totp_ultimo_contador'] ?? null;

        if ($ultimo !== null && $contador <= (int) $ultimo) {
            return false;
        }

        $q = bd()->prepare('UPDATE administradores_master SET totp_ultimo_contador = ? WHERE id_master = ?');
        $q->execute([$contador, $id]);

        return true;
    }

    /**
     * Confere o codigo do aplicativo, ja com a trava de reapresentacao.
     * Espelha conferirSegundoFator() das contas de usuarios.
     */
    public static function totpConfere(array $master, string $codigo): bool
    {
        $contadorUsado = null;

        if (!Totp::confere(self::totpSegredo($master), $codigo, $contadorUsado)) {
            return false;
        }

        if (!self::totpContadorUsado((int) $master['id_master'], $master, (int) $contadorUsado)) {
            registrarEventoSeguranca('totp_reapresentado', ['master' => (int) $master['id_master']]);
            return false;
        }

        return true;
    }
}
