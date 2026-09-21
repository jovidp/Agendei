<?php
/** Atualização aditiva: preserva os cadastros existentes e permite executar novamente. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/database.php';

// As migracoes aditivas existem para atualizar bancos MySQL nascidos em versoes
// anteriores. No PostgreSQL esse caso nao existe: a instalacao parte de
// banco_postgres.sql, que ja vem com o esquema completo.
if (Database::ehPostgres()) {
    fwrite(STDERR, "Este script atualiza apenas bancos MySQL/MariaDB.
"
        . "No PostgreSQL, importe banco_postgres.sql: ele ja traz o esquema completo.
");
    exit(1);
}
$db = bd();
$colunaExiste = static function (string $tabela, string $coluna) use ($db): bool {
    $ddl = $db->query("SHOW CREATE TABLE `$tabela`")->fetch(PDO::FETCH_NUM)[1];
    return (bool) preg_match('/^\s*`' . preg_quote($coluna, '/') . '`\s/m', $ddl);
};
$indiceExiste = static function (string $tabela, string $indice) use ($db): bool {
    $ddl = $db->query("SHOW CREATE TABLE `$tabela`")->fetch(PDO::FETCH_NUM)[1];
    return str_contains($ddl, 'KEY `' . $indice . '`');
};
$campos = [
    'slug' => 'VARCHAR(80) NULL',
    'status' => "ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo'",
    'cor_primaria' => "CHAR(7) NOT NULL DEFAULT '#1F4E5F'",
    'cor_secundaria' => "CHAR(7) NOT NULL DEFAULT '#5FAF8B'",
    'cor_fundo' => "CHAR(7) NOT NULL DEFAULT '#F5F1EA'",
    'fonte' => "VARCHAR(30) NOT NULL DEFAULT 'padrao'",
    'logo' => 'MEDIUMTEXT NULL',
];
foreach ($campos as $campo => $definicao) {
    if (!$colunaExiste('estabelecimento', $campo)) $db->exec("ALTER TABLE estabelecimento ADD COLUMN `$campo` $definicao");
}
$db->exec("UPDATE estabelecimento SET slug = CONCAT('estabelecimento-', id_estabelecimento) WHERE slug IS NULL OR slug = ''");
if (!$indiceExiste('estabelecimento', 'uk_estabelecimento_slug')) {
    $db->exec('ALTER TABLE estabelecimento MODIFY slug VARCHAR(80) NOT NULL, ADD UNIQUE KEY uk_estabelecimento_slug (slug)');
}
$idOriginal = (int) $db->query('SELECT MIN(id_estabelecimento) FROM estabelecimento')->fetchColumn();
if ($idOriginal < 1) {
    $db->exec("INSERT INTO estabelecimento (nome, slug) VALUES ('Agendei', 'agendei')");
    $idOriginal = (int) $db->lastInsertId();
}
$tabelas = [
    'usuarios' => 'id_usuario', 'clientes' => 'id_cliente', 'profissionais' => 'id_profissional',
    'administradores' => 'id_administrador', 'servicos' => 'id_servico',
    'profissional_servico' => null, 'horarios_profissionais' => 'id_horario',
    'bloqueios_agenda' => 'id_bloqueio', 'agendamentos' => 'id_agendamento', 'configuracoes' => 'id_configuracao',
];
foreach ($tabelas as $tabela => $pk) {
    if (!$colunaExiste($tabela, 'id_estabelecimento')) {
        $db->exec("ALTER TABLE `$tabela` ADD COLUMN id_estabelecimento INT UNSIGNED NOT NULL DEFAULT $idOriginal");
    }
    if (!$indiceExiste($tabela, 'idx_estabelecimento')) {
        $db->exec("ALTER TABLE `$tabela` ADD KEY idx_estabelecimento (id_estabelecimento), ADD CONSTRAINT `fk_{$tabela}_estabelecimento` FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento (id_estabelecimento)");
    }
    if ($pk && !$indiceExiste($tabela, 'uk_estabelecimento_registro')) {
        $db->exec("ALTER TABLE `$tabela` ADD UNIQUE KEY uk_estabelecimento_registro (id_estabelecimento, `$pk`)");
    }
    // Toda nova gravação deve identificar explicitamente o estabelecimento.
    $db->exec("ALTER TABLE `$tabela` MODIFY id_estabelecimento INT UNSIGNED NOT NULL");
}
foreach ([['usuarios', 'uk_usuarios_email', 'email'], ['clientes', 'uk_clientes_cpf', 'cpf'], ['configuracoes', 'uk_config_chave', 'chave']] as [$tabela, $antigo, $campo]) {
    $novo = 'uk_estabelecimento_' . $campo;
    if (!$indiceExiste($tabela, $novo)) $db->exec("ALTER TABLE `$tabela` ADD UNIQUE KEY `$novo` (id_estabelecimento, `$campo`)");
    // Localiza o índice global pelo campo para funcionar também em instalações antigas.
    $ddl = $db->query("SHOW CREATE TABLE `$tabela`")->fetch(PDO::FETCH_NUM)[1];
    preg_match_all('/UNIQUE KEY `([^`]+)` \(`' . preg_quote($campo, '/') . '`\)/', $ddl, $indices);
    foreach ($indices[1] as $nome) $db->exec("ALTER TABLE `$tabela` DROP INDEX `$nome`");
}
// Chaves compostas impedem relacionar clientes, serviços e profissionais de empresas diferentes.
$relacoes = [
    ['clientes', 'id_usuario', 'usuarios', 'CASCADE'],
    ['profissionais', 'id_usuario', 'usuarios', 'CASCADE'],
    ['administradores', 'id_usuario', 'usuarios', 'CASCADE'],
    ['profissional_servico', 'id_profissional', 'profissionais', 'CASCADE'],
    ['profissional_servico', 'id_servico', 'servicos', 'CASCADE'],
    ['horarios_profissionais', 'id_profissional', 'profissionais', 'CASCADE'],
    ['bloqueios_agenda', 'id_profissional', 'profissionais', 'CASCADE'],
    ['agendamentos', 'id_cliente', 'clientes', 'CASCADE'],
    ['agendamentos', 'id_profissional', 'profissionais', 'RESTRICT'],
    ['agendamentos', 'id_servico', 'servicos', 'RESTRICT'],
];
foreach ($relacoes as [$tabela, $campo, $pai, $exclusao]) {
    $nome = 'fk_tenant_' . $tabela . '_' . $campo;
    $ddl = $db->query("SHOW CREATE TABLE `$tabela`")->fetch(PDO::FETCH_NUM)[1];
    if (!str_contains($ddl, 'CONSTRAINT `' . $nome . '`')) $db->exec("ALTER TABLE `$tabela` ADD CONSTRAINT `$nome` FOREIGN KEY (id_estabelecimento, `$campo`) REFERENCES `$pai` (id_estabelecimento, `$campo`) ON DELETE $exclusao ON UPDATE CASCADE");
}
$db->exec("CREATE TABLE IF NOT EXISTS administradores_master (
    id_master INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL,
    senha_hash VARCHAR(255) NOT NULL,
    status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
    cor_primaria CHAR(7) NOT NULL DEFAULT '#252B36',
    cor_secundaria CHAR(7) NOT NULL DEFAULT '#6E8BFF',
    cor_fundo CHAR(7) NOT NULL DEFAULT '#F3F5F8',
    fonte VARCHAR(30) NOT NULL DEFAULT 'padrao',
    logo MEDIUMTEXT NULL,
    ultimo_acesso DATETIME NULL,
    data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_master),
    UNIQUE KEY uk_master_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
require_once __DIR__ . '/migrar_diferenciais.php';
migrarDiferenciais($db);
require_once __DIR__ . '/migrar_requisitos.php';
migrarRequisitos($db);

$masterCriado = false;
if (!(int) $db->query('SELECT COUNT(*) FROM administradores_master')->fetchColumn()) {
    $senhaMaster = 'agendei-master-2026';
    $q = $db->prepare('INSERT INTO administradores_master (nome, email, senha_hash) VALUES (?, ?, ?)');
    $q->execute(['Administrador Master', 'master@agendei.com.br', password_hash($senhaMaster, PASSWORD_DEFAULT)]);
    $masterCriado = true;
}
echo "Atualização concluída. Os dados existentes pertencem ao estabelecimento $idOriginal.\n";
if ($masterCriado) echo "Conta master criada: master@agendei.com.br / agendei-master-2026 (altere a senha no primeiro acesso).\n";
