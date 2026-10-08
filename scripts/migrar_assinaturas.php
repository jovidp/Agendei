<?php
/** Cria a estrutura de demonstracao e assinatura em bancos ja existentes. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Assinatura.php';

Assinatura::garantirEstrutura();
echo "Tabela de assinaturas pronta. Empresas existentes permanecem ativas; novas empresas recebem 7 dias de demonstracao.\n";
