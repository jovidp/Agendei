<?php

/**
 * Webhook do bot de WhatsApp (Evolution API).
 * POST /api/whatsapp.php?estabelecimento=<slug>&chave=<chave do bot>
 *
 * A Evolution chama este endereco a cada evento da instancia. So interessam
 * mensagens de texto recebidas (messages.upsert, fromMe = false) de conversas
 * individuais. A resposta sai pela propria Evolution (WhatsApp::enviarTexto).
 * Responde sempre 200 a evento valido, mesmo ignorado: um erro faria a
 * Evolution repetir o mesmo evento.
 */
require_once __DIR__ . '/../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Chave errada conta como tentativa: impede adivinhar a chave por repeticao.
if (conferirBloqueio(['bot_whatsapp_ip' => ipCliente()]) !== '') {
    http_response_code(429);
    exit;
}
if (!hash_equals(Bot::chave(), get('chave'))) {
    anotarFalha('bot_whatsapp_ip', ipCliente());
    registrarEventoSeguranca('bot_whatsapp_recusado');
    http_response_code(404);
    exit;
}

$evento = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($evento)) {
    jsonResposta(['ok' => false, 'motivo' => 'corpo invalido'], 400);
}

// A 1.x manda "messages.upsert"; a 2.x pode mandar "MESSAGES_UPSERT".
$tipo = strtolower(str_replace('_', '.', (string) ($evento['event'] ?? '')));
if ($tipo !== 'messages.upsert' || !Bot::ligado()) {
    jsonResposta(['ok' => true, 'ignorado' => 'evento ou bot desligado']);
}

// Instancia diferente da configurada: outro numero usando a mesma chave.
$instancia = (string) ($evento['instance'] ?? '');
if ($instancia !== '' && $instancia !== WhatsApp::configuracao()['instancia']) {
    jsonResposta(['ok' => true, 'ignorado' => 'instancia']);
}

$mensagens = isset($evento['data']['key']) ? [$evento['data']] : (array) ($evento['data'] ?? []);
$respondidas = 0;

foreach ($mensagens as $mensagem) {
    $chave = $mensagem['key'] ?? [];
    $jid = (string) ($chave['remoteJid'] ?? '');
    if (!empty($chave['fromMe']) || !str_contains($jid, '@')) {
        continue;
    }
    // Grupo, lista de transmissao e status ficam de fora; o bot so conversa no privado.
    [$usuarioJid, $dominio] = explode('@', $jid, 2);
    if ($dominio === 'lid') {
        // Identificador anonimo do WhatsApp: o numero real vem em senderPn, quando vem.
        $usuarioJid = explode('@', (string) ($chave['senderPn'] ?? $mensagem['senderPn'] ?? ''))[0];
    } elseif ($dominio !== 's.whatsapp.net') {
        continue;
    }
    $numero = preg_replace('/\D/', '', $usuarioJid);

    $conteudo = $mensagem['message'] ?? [];
    $texto = (string) ($conteudo['conversation'] ?? $conteudo['extendedTextMessage']['text'] ?? '');
    if ($numero === '' || trim($texto) === '') {
        continue;
    }

    try {
        $resposta = Bot::atender($numero, $texto, (string) ($chave['id'] ?? ''));
        if ($resposta !== null) {
            WhatsApp::enviarTexto($numero, $resposta);
            $respondidas++;
        }
    } catch (Throwable $erro) {
        // A mensagem do cliente fica sem resposta, mas o evento nao volta: repetir nao conserta.
        error_log('Bot de WhatsApp falhou para ' . $numero . ': ' . $erro->getMessage());
    }
}

limparFalhas('bot_whatsapp_ip', ipCliente());
jsonResposta(['ok' => true, 'respondidas' => $respondidas]);
