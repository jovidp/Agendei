<?php

/**
 * Cria as tabelas que a administracao master precisa: o contador do controle
 * de forca bruta, a trilha de auditoria e os planos de contratacao.
 *
 * Diferente das outras migracoes, esta vale nos dois dialetos: as tabelas
 * nasceram depois do esquema original e precisam existir tanto num MySQL
 * antigo quanto num Postgres ja importado de banco_postgres.sql.
 *
 * Executar de novo nao causa efeito: o CREATE TABLE e condicional.
 *
 *   php scripts/migrar_seguranca.php
 *
 * A aplicacao tambem cria as tabelas sozinha no primeiro uso (models/Tentativa.php,
 * models/LogMaster.php e models/Plano.php). Rodar este script antes de publicar
 * apenas evita depender da permissao de DDL do usuario do banco em producao, que
 * costuma ser mais restrita.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Tentativa.php';
require_once __DIR__ . '/../models/LogMaster.php';
require_once __DIR__ . '/../models/Plano.php';

$db = bd();
$dialeto = Database::ehPostgres() ? 'PostgreSQL' : 'MySQL/MariaDB';

foreach (Tentativa::ddl() as $comando) {
    $db->exec($comando);
}

// Confere que a tabela responde a uma consulta de verdade, e nao apenas que o
// CREATE nao reclamou: um usuario sem permissao de leitura falharia so aqui.
$db->query('SELECT COUNT(*) FROM tentativas_acesso')->fetchColumn();

echo "Tabela tentativas_acesso pronta em {$dialeto}.\n";
echo "O controle de forca bruta passa a contar as tentativas no banco.\n";

foreach (LogMaster::ddl() as $comando) {
    $db->exec($comando);
}

$db->query('SELECT COUNT(*) FROM logs_master')->fetchColumn();

echo "Tabela logs_master pronta em {$dialeto}.\n";
echo "As acoes da administracao master passam a ser registradas.\n";

foreach (Plano::ddl() as $comando) {
    $db->exec($comando);
}

$db->query('SELECT COUNT(*) FROM planos')->fetchColumn();
$db->query('SELECT COUNT(*) FROM estabelecimento_plano')->fetchColumn();

echo "Tabelas planos e estabelecimento_plano prontas em {$dialeto}.\n";
echo "Enquanto nenhum plano for atribuido, todo estabelecimento segue sem limite.\n";
