<?php
/**
 * Cópia de segurança do banco do Agendei (PostgreSQL ou MySQL/MariaDB).
 *
 * Usa as mesmas credenciais do sistema (variáveis AGENDEI_DB_* ou
 * config/database.local.php) e chama pg_dump ou mysqldump, que precisam estar
 * instalados na máquina. Grava backups/agendei-AAAAMMDD-HHMMSS.sql.gz e
 * mantém só os mais recentes.
 *
 *   php scripts/backup.php              # cópia nova, guarda as 14 últimas
 *   php scripts/backup.php --manter=30  # guarda as 30 últimas
 *
 * Restauração e rotina: ver BACKUP.md.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$manter = 14;
foreach ($argv as $argumento) {
    if (preg_match('/^--manter=(\d+)$/', $argumento, $m)) {
        $manter = max(1, (int) $m[1]);
    }
}

$p = Database::parametros();
$pasta = dirname(__DIR__) . '/backups';
if (!is_dir($pasta) && !mkdir($pasta, 0700, true)) {
    fwrite(STDERR, "Não foi possível criar a pasta $pasta.\n");
    exit(1);
}
$arquivo = $pasta . '/agendei-' . date('Ymd-His') . '.sql.gz';

// A senha vai por variável de ambiente do processo filho, nunca pela linha de comando (que aparece no ps).
if ($p['driver'] === 'pgsql') {
    $comando = ['pg_dump', '--no-owner', '--no-privileges', '-h', $p['host'], '-p', $p['porta'], '-U', $p['usuario'], '-d', $p['banco']];
    $ambiente = ['PGPASSWORD' => $p['senha']];
} else {
    $comando = ['mysqldump', '--single-transaction', '--routines', '--default-character-set=utf8mb4', '-h', $p['host'], '-P', $p['porta'], '-u', $p['usuario'], $p['banco']];
    $ambiente = ['MYSQL_PWD' => $p['senha']];
}

$processo = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos, null, $ambiente + getenv());
if (!is_resource($processo)) {
    fwrite(STDERR, "Não foi possível executar {$comando[0]}. Ele está instalado e no PATH?\n");
    exit(1);
}

$saida = gzopen($arquivo, 'wb9');
while (!feof($tubos[1])) {
    gzwrite($saida, (string) fread($tubos[1], 1 << 16));
}
gzclose($saida);
$erros = stream_get_contents($tubos[2]);
$codigo = proc_close($processo);

if ($codigo !== 0) {
    unlink($arquivo);
    fwrite(STDERR, "O backup falhou ({$comando[0]} saiu com código $codigo):\n$erros");
    exit(1);
}

// Retenção: os mais antigos saem, os $manter mais recentes ficam.
$copias = glob($pasta . '/agendei-*.sql.gz');
sort($copias);
foreach (array_slice($copias, 0, max(0, count($copias) - $manter)) as $antiga) {
    unlink($antiga);
}

printf("Backup gravado em %s (%.1f KB). Cópias guardadas: %d.\n", $arquivo, filesize($arquivo) / 1024, min(count($copias), $manter));
