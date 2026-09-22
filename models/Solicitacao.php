<?php

/**
 * Solicitacoes de cadastro de empresa feitas pela pagina inicial.
 *
 * A empresa que se cadastra sozinha nasce inativa: a conta do responsavel
 * existe, mas ninguem entra ate o master aprovar. Aprovar ativa a empresa;
 * recusar apaga a empresa e a conta, e a solicitacao fica so como historico.
 *
 * A tabela guarda nome e endereco da empresa junto do pedido, e nao por JOIN,
 * pelo mesmo motivo dos logs: o historico continua legivel depois que uma
 * empresa recusada deixa de existir. Por isso nao ha chave estrangeira.
 *
 * A tabela e criada na primeira chamada, como a de planos: quem atualiza o
 * sistema nao precisa rodar migracao para o cadastro publico funcionar.
 */
class Solicitacao
{
    public const STATUS = [
        'pendente' => 'Aguardando aprovacao',
        'aprovada' => 'Aprovada',
        'recusada' => 'Recusada',
    ];

    /** Estado da tabela: null = ainda nao verificada, false = indisponivel. */
    private static ?bool $tabelaPronta = null;

    // -----------------------------------------------------------------
    // Abertura pela pagina publica
    // -----------------------------------------------------------------

    /**
     * Cria a empresa inativa, a conta do responsavel e a solicitacao.
     * Recebe os mesmos dados de Estabelecimento::contratar() mais 'telefone'
     * e 'mensagem'. Devolve o id da solicitacao.
     */
    public static function abrir(array $dados): int
    {
        self::exigirTabela();

        $idEmpresa = Estabelecimento::contratar($dados + ['status' => 'inativo']);

        try {
            $q = bd()->prepare('INSERT INTO solicitacoes_cadastro
                                    (id_estabelecimento, estabelecimento_nome, slug, responsavel, email, telefone, mensagem, status, ip, data_solicitacao)
                                VALUES (?, ?, ?, ?, ?, ?, ?, \'pendente\', ?, ?)');
            $q->execute([
                $idEmpresa,
                mb_substr($dados['estabelecimento'], 0, 120),
                $dados['slug'],
                mb_substr($dados['nome'], 0, 120),
                mb_substr($dados['email'], 0, 150),
                self::texto($dados['telefone'] ?? null, 20),
                self::texto($dados['mensagem'] ?? null, 255),
                mb_substr(ipCliente(), 0, 45) ?: null,
                date('Y-m-d H:i:s'),
            ]);
            return (int) bd()->lastInsertId();
        } catch (Throwable $erro) {
            // Sem a solicitacao a empresa ficaria inativa e invisivel para sempre.
            try {
                Estabelecimento::excluirGlobal($idEmpresa);
            } catch (Throwable $limpeza) {
                error_log('Falha ao desfazer cadastro de empresa: ' . $limpeza->getMessage());
            }
            throw $erro;
        }
    }

    /** O endereco exclusivo ja pertence a alguma empresa, ativa ou nao. */
    public static function slugEmUso(string $slug): bool
    {
        $q = bd()->prepare('SELECT 1 FROM estabelecimento WHERE slug = ? LIMIT 1');
        $q->execute([$slug]);
        return (bool) $q->fetchColumn();
    }

    // -----------------------------------------------------------------
    // Consulta
    // -----------------------------------------------------------------

    public static function porId(int $id): ?array
    {
        if (!self::disponivel()) return null;
        $q = bd()->prepare('SELECT * FROM solicitacoes_cadastro WHERE id_solicitacao = ? LIMIT 1');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    /** Solicitacoes ainda sem decisao, da mais antiga para a mais nova. */
    public static function pendentes(): array
    {
        if (!self::disponivel()) return [];
        return bd()->query('SELECT * FROM solicitacoes_cadastro WHERE status = \'pendente\' ORDER BY data_solicitacao, id_solicitacao')->fetchAll();
    }

    public static function contarPendentes(): int
    {
        if (!self::disponivel()) return 0;
        return (int) bd()->query('SELECT COUNT(*) FROM solicitacoes_cadastro WHERE status = \'pendente\'')->fetchColumn();
    }

    /** Ids das empresas com cadastro aguardando aprovacao, para marcar a listagem do master. */
    public static function empresasPendentes(): array
    {
        if (!self::disponivel()) return [];
        return array_map('intval', bd()->query('SELECT id_estabelecimento FROM solicitacoes_cadastro WHERE status = \'pendente\'')->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Solicitacao pendente do endereco informado, para explicar por que o login nao abre. */
    public static function pendentePorSlug(string $slug): ?array
    {
        if ($slug === '' || !self::disponivel()) return null;
        $q = bd()->prepare('SELECT * FROM solicitacoes_cadastro WHERE slug = ? AND status = \'pendente\' LIMIT 1');
        $q->execute([$slug]);
        return $q->fetch() ?: null;
    }

    // -----------------------------------------------------------------
    // Decisao do master
    // -----------------------------------------------------------------

    /** Ativa a empresa. Devolve false quando a solicitacao nao esta pendente. */
    public static function aprovar(int $id): bool
    {
        $solicitacao = self::porId($id);
        if (!$solicitacao || $solicitacao['status'] !== 'pendente') return false;

        $db = bd();
        $db->beginTransaction();
        try {
            $q = $db->prepare('UPDATE estabelecimento SET status = \'ativo\' WHERE id_estabelecimento = ?');
            $q->execute([(int) $solicitacao['id_estabelecimento']]);
            $q = $db->prepare('UPDATE solicitacoes_cadastro SET status = \'aprovada\', data_decisao = ? WHERE id_solicitacao = ? AND status = \'pendente\'');
            $q->execute([date('Y-m-d H:i:s'), $id]);
            $db->commit();
            return true;
        } catch (Throwable $erro) {
            if ($db->inTransaction()) $db->rollBack();
            throw $erro;
        }
    }

    /**
     * Apaga a empresa e a conta do responsavel e guarda a solicitacao como
     * recusada. Devolve false quando a solicitacao nao esta pendente.
     */
    public static function recusar(int $id): bool
    {
        $solicitacao = self::porId($id);
        if (!$solicitacao || $solicitacao['status'] !== 'pendente') return false;

        // A exclusao tem transacao propria; a marcacao vem depois para que uma
        // falha ao apagar deixe o pedido pendente, e nao "recusado" com a
        // empresa ainda no banco.
        Estabelecimento::excluirGlobal((int) $solicitacao['id_estabelecimento']);

        $q = bd()->prepare('UPDATE solicitacoes_cadastro SET status = \'recusada\', data_decisao = ? WHERE id_solicitacao = ?');
        $q->execute([date('Y-m-d H:i:s'), $id]);
        return true;
    }

    // -----------------------------------------------------------------
    // Estrutura
    // -----------------------------------------------------------------

    /** Indica se a tabela responde; cria-a na primeira chamada. */
    public static function disponivel(): bool
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
            error_log('Tabela de solicitacoes indisponivel: ' . $erro->getMessage());
            self::$tabelaPronta = false;
        }

        return self::$tabelaPronta;
    }

    private static function exigirTabela(): void
    {
        if (!self::disponivel()) {
            throw new RuntimeException('A tabela de solicitacoes nao esta disponivel neste banco.');
        }
    }

    /** Comandos de criacao da tabela em cada dialeto. */
    public static function ddl(): array
    {
        if (Database::ehPostgres()) {
            return [
                "CREATE TABLE IF NOT EXISTS solicitacoes_cadastro (
                    id_solicitacao       INTEGER GENERATED BY DEFAULT AS IDENTITY,
                    id_estabelecimento   INTEGER DEFAULT NULL,
                    estabelecimento_nome VARCHAR(120) NOT NULL,
                    slug                 VARCHAR(80) NOT NULL,
                    responsavel          VARCHAR(120) NOT NULL,
                    email                VARCHAR(150) NOT NULL,
                    telefone             VARCHAR(20) DEFAULT NULL,
                    mensagem             VARCHAR(255) DEFAULT NULL,
                    status               VARCHAR(10) NOT NULL DEFAULT 'pendente',
                    ip                   VARCHAR(45) DEFAULT NULL,
                    data_solicitacao     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    data_decisao         TIMESTAMP DEFAULT NULL,
                    CONSTRAINT pk_solicitacoes_cadastro PRIMARY KEY (id_solicitacao),
                    CONSTRAINT ck_solicitacoes_status CHECK (status IN ('pendente','aprovada','recusada'))
                )",
                'CREATE INDEX IF NOT EXISTS idx_solicitacoes_status ON solicitacoes_cadastro (status)',
                'CREATE INDEX IF NOT EXISTS idx_solicitacoes_slug ON solicitacoes_cadastro (slug)',
            ];
        }

        return [
            "CREATE TABLE IF NOT EXISTS solicitacoes_cadastro (
                id_solicitacao       INT UNSIGNED NOT NULL AUTO_INCREMENT,
                id_estabelecimento   INT UNSIGNED DEFAULT NULL,
                estabelecimento_nome VARCHAR(120) NOT NULL,
                slug                 VARCHAR(80) NOT NULL,
                responsavel          VARCHAR(120) NOT NULL,
                email                VARCHAR(150) NOT NULL,
                telefone             VARCHAR(20) DEFAULT NULL,
                mensagem             VARCHAR(255) DEFAULT NULL,
                status               ENUM('pendente','aprovada','recusada') NOT NULL DEFAULT 'pendente',
                ip                   VARCHAR(45) DEFAULT NULL,
                data_solicitacao     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                data_decisao         DATETIME DEFAULT NULL,
                PRIMARY KEY (id_solicitacao),
                KEY idx_solicitacoes_status (status),
                KEY idx_solicitacoes_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }

    /** Corta o texto no tamanho da coluna e transforma vazio em nulo. */
    private static function texto(?string $valor, int $limite): ?string
    {
        $valor = trim((string) $valor);
        return $valor === '' ? null : mb_substr($valor, 0, $limite);
    }
}
