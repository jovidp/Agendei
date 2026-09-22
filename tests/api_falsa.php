<?php
/**
 * API de e-mail de mentira (imita a Brevo) para o teste de envio.
 *
 * Roda no servidor embutido: php -S 127.0.0.1:PORTA tests/api_falsa.php.
 * Grava o que recebeu em um arquivo temporario e responde como a Brevo:
 * 201 com messageId quando a chave e aceita, 401 quando a chave e "chave-errada".
 */
$cabecalhos = function_exists('getallheaders') ? getallheaders() : [];
$chave = '';
foreach ($cabecalhos as $nome => $valor) {
    if (strtolower($nome) === 'api-key') {
        $chave = $valor;
    }
}

file_put_contents(sys_get_temp_dir() . '/agendei_api_falsa.json', json_encode([
    'metodo'     => $_SERVER['REQUEST_METHOD'],
    'caminho'    => $_SERVER['REQUEST_URI'],
    'chave'      => $chave,
    'tipo'       => $_SERVER['CONTENT_TYPE'] ?? '',
    'corpo'      => json_decode((string) file_get_contents('php://input'), true),
]));

header('Content-Type: application/json');
if ($chave === 'chave-errada') {
    http_response_code(401);
    echo json_encode(['code' => 'unauthorized', 'message' => 'Key not found']);
    exit;
}
http_response_code(201);
echo json_encode(['messageId' => '<falsa@api.brevo>']);
