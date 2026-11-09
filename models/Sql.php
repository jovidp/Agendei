<?php

/**
 * Trechos de SQL que divergem entre MySQL/MariaDB e PostgreSQL.
 *
 * O projeto escreve SQL portatil sempre que possivel: CURRENT_DATE, CURRENT_TIME,
 * CASE WHEN, COALESCE e NOW() valem nos dois bancos, assim como literais entre
 * aspas simples. Esta classe existe apenas para os poucos pontos em que nao ha
 * forma comum, e por isso e curta de proposito.
 *
 * Todo metodo devolve um fragmento de SQL ja pronto para concatenar. Os valores
 * continuam viajando por placeholders: nada aqui interpola dado de usuario.
 */
class Sql
{
    /**
     * Operador de busca por texto que ignora maiusculas nos dois bancos.
     *
     * No MySQL o LIKE ja ignora a caixa pela collation utf8mb4_unicode_ci.
     * No PostgreSQL o LIKE distingue maiuscula de minuscula; o ILIKE e que
     * ignora. Sem isto, procurar "beatriz" na Consulta de Usuario acharia o
     * registro no MySQL mas nao no Supabase. (O ILIKE ignora caixa, mas nao
     * acento — diferenca menor diante do ganho de comportamento igual.)
     */
    public static function como(): string
    {
        return Database::ehPostgres() ? 'ILIKE' : 'LIKE';
    }

    /** Soma (ou subtrai, com valor negativo) dias a uma expressao de data. */
    public static function somarDias(string $expressao, string $quantidade): string
    {
        if (Database::ehPostgres()) {
            return '(' . $expressao . ' + make_interval(days => (' . $quantidade . ')::int))::date';
        }

        return 'DATE_ADD(' . $expressao . ', INTERVAL ' . $quantidade . ' DAY)';
    }

    /** Soma horas a uma expressao de data e hora. O resultado mantem a hora. */
    public static function somarHoras(string $expressao, string $quantidade): string
    {
        if (Database::ehPostgres()) {
            return '(' . $expressao . ' + make_interval(hours => (' . $quantidade . ')::int))';
        }

        return 'DATE_ADD(' . $expressao . ', INTERVAL ' . $quantidade . ' HOUR)';
    }

    /** Diferenca em minutos entre dois horarios, do primeiro para o segundo. */
    public static function diferencaMinutos(string $inicio, string $fim): string
    {
        if (Database::ehPostgres()) {
            return '(EXTRACT(EPOCH FROM (' . $fim . ' - ' . $inicio . ')) / 60)';
        }

        return 'TIMESTAMPDIFF(MINUTE, ' . $inicio . ', ' . $fim . ')';
    }

    /** Junta uma coluna de data e uma de hora num unico instante. */
    public static function dataHora(string $data, string $hora): string
    {
        if (Database::ehPostgres()) {
            return '(' . $data . ' + ' . $hora . ')';
        }

        return 'TIMESTAMP(' . $data . ', ' . $hora . ')';
    }

    /**
     * Inicio de um INSERT que deve ignorar conflito de chave unica.
     * Use sempre em par com ignorarConflito(), que fecha a clausula no Postgres.
     */
    public static function inserirIgnorando(): string
    {
        return Database::ehPostgres() ? 'INSERT INTO' : 'INSERT IGNORE INTO';
    }

    /** Fecha o INSERT aberto por inserirIgnorando(). No MySQL nao rende nada. */
    public static function ignorarConflito(): string
    {
        return Database::ehPostgres() ? ' ON CONFLICT DO NOTHING' : '';
    }

    /**
     * Clausula de atualizacao quando a chave ja existe (upsert).
     *
     * $chaves sao as colunas do indice unico — o Postgres exige saber quais sao;
     * o MySQL descobre sozinho e por isso as ignora. $colunas recebem o valor
     * que viria do INSERT.
     */
    public static function aoDuplicar(array $chaves, array $colunas): string
    {
        if (Database::ehPostgres()) {
            $atribuicoes = array_map(
                static fn(string $coluna): string => $coluna . ' = EXCLUDED.' . $coluna,
                $colunas
            );

            return ' ON CONFLICT (' . implode(', ', $chaves) . ') DO UPDATE SET ' . implode(', ', $atribuicoes);
        }

        $atribuicoes = array_map(
            static fn(string $coluna): string => $coluna . ' = VALUES(' . $coluna . ')',
            $colunas
        );

        return ' ON DUPLICATE KEY UPDATE ' . implode(', ', $atribuicoes);
    }

    /**
     * Versao do upsert que grava um valor fixo em vez do valor do INSERT.
     * Ex.: voltar o status para 'aguardando' quando o registro ja existia.
     */
    public static function aoDuplicarComValores(array $chaves, array $colunas, array $fixos): string
    {
        $atribuicoes = [];

        foreach ($colunas as $coluna) {
            $atribuicoes[] = Database::ehPostgres()
                ? $coluna . ' = EXCLUDED.' . $coluna
                : $coluna . ' = VALUES(' . $coluna . ')';
        }

        foreach ($fixos as $coluna => $valor) {
            $atribuicoes[] = $coluna . ' = ' . $valor;
        }

        if (Database::ehPostgres()) {
            return ' ON CONFLICT (' . implode(', ', $chaves) . ') DO UPDATE SET ' . implode(', ', $atribuicoes);
        }

        return ' ON DUPLICATE KEY UPDATE ' . implode(', ', $atribuicoes);
    }
    /**
     * Acrescenta a coluna quando a tabela existe e ainda nao a tem. Serve as
     * atualizacoes que rodam na subida do container: no dialeto que for, sem
     * depender de ADD COLUMN IF NOT EXISTS (o MySQL 8 nao tem).
     */
    public static function garantirColuna(string $tabela, string $coluna, string $definicao): void
    {
        $esquema = Database::ehPostgres() ? 'current_schema()' : 'DATABASE()';
        $q = bd()->prepare("SELECT
            (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = $esquema AND table_name = ?),
            (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = $esquema AND table_name = ? AND column_name = ?)");
        $q->execute([$tabela, $tabela, $coluna]);
        [$tabelaExiste, $colunaExiste] = array_map('intval', $q->fetch(PDO::FETCH_NUM));
        if ($tabelaExiste && !$colunaExiste) {
            bd()->exec("ALTER TABLE $tabela ADD COLUMN $coluna $definicao");
        }
    }

    /** Tipo de data e hora anulavel de cada dialeto, para as colunas novas. */
    public static function tipoDataHora(): string
    {
        return Database::ehPostgres() ? 'TIMESTAMP NULL' : 'DATETIME NULL';
    }
}
