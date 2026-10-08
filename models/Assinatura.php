<?php
/**
 * Ciclo comercial de cada estabelecimento.
 *
 * O cadastro da empresa nao depende de aprovacao humana: ela recebe sete dias
 * de demonstracao. A confirmacao de pagamento continua explicita, pois este
 * projeto nao guarda dados de cartao nem possui um gateway configurado.
 */
class Assinatura
{
    public const VALOR_MENSAL = 149.00;
    public const DIAS_DEMO = 7;

    private static bool $estruturaConferida = false;

    /** Cria a tabela em instalacoes anteriores sem alterar os dados existentes. */
    public static function garantirEstrutura(): void
    {
        if (self::$estruturaConferida) return;
        if (PHP_SAPI !== 'cli' && getenv('AGENDEI_ESTRUTURA_PRONTA') === '1') {
            self::$estruturaConferida = true;
            return;
        }

        $sql = Database::ehPostgres()
            ? 'CREATE TABLE IF NOT EXISTS assinaturas (
                    id_estabelecimento INTEGER NOT NULL PRIMARY KEY,
                    status VARCHAR(12) NOT NULL DEFAULT \'ativa\',
                    data_inicio_demo TIMESTAMP NULL,
                    data_fim_demo TIMESTAMP NULL,
                    metodo_pagamento VARCHAR(12) NULL,
                    data_solicitacao TIMESTAMP NULL,
                    data_pagamento TIMESTAMP NULL,
                    CONSTRAINT ck_assinaturas_status CHECK (status IN (\'demo\',\'pendente\',\'ativa\',\'bloqueada\')),
                    CONSTRAINT ck_assinaturas_metodo CHECK (metodo_pagamento IS NULL OR metodo_pagamento IN (\'pix\',\'boleto\',\'cartao\')),
                    CONSTRAINT fk_assinaturas_estabelecimento FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento(id_estabelecimento) ON DELETE CASCADE
                )'
            : 'CREATE TABLE IF NOT EXISTS assinaturas (
                    id_estabelecimento INT UNSIGNED NOT NULL,
                    status ENUM(\'demo\',\'pendente\',\'ativa\',\'bloqueada\') NOT NULL DEFAULT \'ativa\',
                    data_inicio_demo DATETIME NULL,
                    data_fim_demo DATETIME NULL,
                    metodo_pagamento ENUM(\'pix\',\'boleto\',\'cartao\') NULL,
                    data_solicitacao DATETIME NULL,
                    data_pagamento DATETIME NULL,
                    PRIMARY KEY (id_estabelecimento),
                    CONSTRAINT fk_assinaturas_estabelecimento FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento(id_estabelecimento) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        bd()->exec($sql);
        self::$estruturaConferida = true;
    }

    /** Inicia a avaliacao gratuita na criacao publica da empresa. */
    public static function iniciarDemo(int $idEstabelecimento): void
    {
        self::garantirEstrutura();
        $inicio = new DateTimeImmutable('now');
        $fim = $inicio->modify('+' . self::DIAS_DEMO . ' days');
        $q = bd()->prepare('INSERT INTO assinaturas (id_estabelecimento, status, data_inicio_demo, data_fim_demo)
                            VALUES (?, \'demo\', ?, ?)');
        $q->execute([$idEstabelecimento, $inicio->format('Y-m-d H:i:s'), $fim->format('Y-m-d H:i:s')]);
    }

    /** Empresas antigas, sem linha comercial, permanecem ativas. */
    public static function situacao(int $idEstabelecimento): array
    {
        self::garantirEstrutura();
        $q = bd()->prepare('SELECT * FROM assinaturas WHERE id_estabelecimento = ? LIMIT 1');
        $q->execute([$idEstabelecimento]);
        $assinatura = $q->fetch();
        if (!$assinatura) {
            return ['id_estabelecimento' => $idEstabelecimento, 'status' => 'ativa', 'legado' => true];
        }

        if (in_array($assinatura['status'], ['demo', 'pendente'], true)
            && !empty($assinatura['data_fim_demo'])
            && strtotime((string) $assinatura['data_fim_demo']) <= time()) {
            $q = bd()->prepare("UPDATE assinaturas SET status = 'bloqueada' WHERE id_estabelecimento = ? AND status IN ('demo', 'pendente')");
            $q->execute([$idEstabelecimento]);
            $assinatura['status'] = 'bloqueada';
        }

        return $assinatura;
    }

    public static function acessoBloqueado(int $idEstabelecimento): bool
    {
        return self::situacao($idEstabelecimento)['status'] === 'bloqueada';
    }

    /** Guarda somente a preferencia; nunca recebe nem persiste dados de cartao. */
    public static function solicitarPagamento(int $idEstabelecimento, string $metodo): bool
    {
        if (!in_array($metodo, ['pix', 'boleto', 'cartao'], true)) return false;
        self::garantirEstrutura();
        $q = bd()->prepare("UPDATE assinaturas
                            SET status = 'pendente', metodo_pagamento = ?, data_solicitacao = CURRENT_TIMESTAMP
                            WHERE id_estabelecimento = ? AND status IN ('demo', 'pendente', 'bloqueada')");
        return $q->execute([$metodo, $idEstabelecimento]);
    }

    /** Confirmacao usada pelo financeiro depois da liquidacao do pagamento. */
    public static function confirmarPagamento(int $idEstabelecimento): bool
    {
        self::garantirEstrutura();
        $q = bd()->prepare("UPDATE assinaturas
                            SET status = 'ativa', data_pagamento = CURRENT_TIMESTAMP
                            WHERE id_estabelecimento = ?");
        return $q->execute([$idEstabelecimento]);
    }

    public static function rotuloMetodo(?string $metodo): string
    {
        return match ($metodo) {
            'pix' => 'Pix', 'boleto' => 'Boleto', 'cartao' => 'Cartao', default => 'Nao escolhido',
        };
    }
}
