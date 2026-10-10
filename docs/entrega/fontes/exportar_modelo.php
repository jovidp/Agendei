<?php
// Extrai somente o esquema versionado; não conecta ao banco.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 3) . '/models/ModeloBanco.php';
$pasta = dirname(__DIR__);
$modelos = [];
foreach (['mysql', 'pgsql'] as $dialeto) {
    $modelo = ModeloBanco::carregar($dialeto);
    $modelos[$dialeto] = $modelo + [
        'relacoes' => ModeloBanco::relacoes($modelo),
        'relacoes_desenho' => ModeloBanco::relacoes($modelo, true),
    ];
    file_put_contents($pasta . '/der_' . $dialeto . '_completo.mmd', ModeloBanco::mermaid($modelo));
}
file_put_contents($pasta . '/fontes/modelos.json', json_encode($modelos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Esquemas extraídos sem acesso ao banco.\n";
