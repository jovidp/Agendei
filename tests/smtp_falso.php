<?php
/**
 * Servidor SMTP de mentira para o teste de envio de e-mail.
 *
 * Atende uma unica conexao, responde o dialogo basico e grava tudo o que o
 * cliente mandou no arquivo indicado. Uso: php tests/smtp_falso.php PORTA ARQUIVO [ok|auth_falha]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$porta   = (int) ($argv[1] ?? 0);
$arquivo = (string) ($argv[2] ?? '');
$modo    = (string) ($argv[3] ?? 'ok');

$servidor = stream_socket_server('tcp://127.0.0.1:' . $porta, $codigo, $erro);
if ($servidor === false) {
    fwrite(STDERR, "Nao foi possivel escutar: {$erro}\n");
    exit(1);
}

// Avisa o teste que ja esta escutando.
fwrite(STDOUT, "pronto\n");
fflush(STDOUT);

$conexao = stream_socket_accept($servidor, 15);
if ($conexao === false) {
    exit(1);
}

$recebido = '';
$emDados = false;
$etapaAuth = 0;

fwrite($conexao, "220 smtp.falso ESMTP\r\n");

while (($linha = fgets($conexao, 4096)) !== false) {
    $recebido .= $linha;

    if ($emDados) {
        if (rtrim($linha, "\r\n") === '.') {
            $emDados = false;
            fwrite($conexao, "250 OK mensagem aceita\r\n");
        }
        continue;
    }

    if ($etapaAuth === 1) {
        $etapaAuth = 2;
        fwrite($conexao, "334 UGFzc3dvcmQ6\r\n");
        continue;
    }
    if ($etapaAuth === 2) {
        $etapaAuth = 0;
        fwrite($conexao, $modo === 'auth_falha' ? "535 5.7.8 Authentication failed\r\n" : "235 2.7.0 Authentication successful\r\n");
        continue;
    }

    $comando = strtoupper(substr(trim($linha), 0, 4));
    switch ($comando) {
        case 'EHLO':
        case 'HELO':
            fwrite($conexao, "250-smtp.falso\r\n250-AUTH LOGIN PLAIN\r\n250 8BITMIME\r\n");
            break;
        case 'AUTH':
            $etapaAuth = 1;
            fwrite($conexao, "334 VXNlcm5hbWU6\r\n");
            break;
        case 'MAIL':
        case 'RCPT':
            fwrite($conexao, "250 OK\r\n");
            break;
        case 'DATA':
            $emDados = true;
            fwrite($conexao, "354 Pode mandar\r\n");
            break;
        case 'QUIT':
            fwrite($conexao, "221 Tchau\r\n");
            break 2;
        default:
            fwrite($conexao, "500 Comando desconhecido\r\n");
    }
}

fclose($conexao);
fclose($servidor);
file_put_contents($arquivo, $recebido);
