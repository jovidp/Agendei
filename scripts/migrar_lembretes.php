<?php

/**
 * Migracao aditiva: envio automatico de lembretes pelo WhatsApp.
 *
 * Acrescenta em notificacoes as colunas tentativas e erro. A tarefa de envio
 * (models/Lembrete.php) usa a primeira para nao insistir para sempre numa
 * mensagem que falha e a segunda para mostrar ao administrador por que ela
 * nao saiu. Pode rodar de novo sem efeito colateral. Atende MySQL/MariaDB e
 * PostgreSQL (Supabase). Execute: php scripts/migrar_lembretes.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$db         = bd();
$ehPostgres = Database::ehPostgres();

// O information_schema existe nos dois dialetos; muda so o nome do esquema atual.
$esquemaAtual = $ehPostgres ? 'current_schema()' : 'DATABASE()';

$colunaExiste = static function (string $tabela, string $coluna) use ($db, $esquemaAtual): bool {
    $q = $db->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = $esquemaAtual AND table_name = ? AND column_name = ?"
    );
    $q->execute([$tabela, $coluna]);

    return (bool) $q->fetchColumn();
};

$colunas = [
    'tentativas' => $ehPostgres ? 'INTEGER NOT NULL DEFAULT 0' : 'INT UNSIGNED NOT NULL DEFAULT 0',
    'erro'       => 'VARCHAR(255) NULL',
];

$adicionadas = 0;
foreach ($colunas as $coluna => $definicao) {
    if (!$colunaExiste('notificacoes', $coluna)) {
        $db->exec("ALTER TABLE notificacoes ADD COLUMN $coluna $definicao");
        $adicionadas++;
    }
}

echo $adicionadas > 0
    ? "Lembretes: fila preparada para o envio automatico ($adicionadas coluna(s) nova(s)).\n"
    : "Lembretes: fila ja estava preparada.\n";
