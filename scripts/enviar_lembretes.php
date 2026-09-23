<?php

/**
 * Tarefa periodica: gera e envia os lembretes de WhatsApp de todas as empresas.
 *
 * Rode a cada 10 ou 15 minutos pelo cron do servidor, por exemplo:
 *   0,15,30,45 * * * * php /caminho/do/agendei/scripts/enviar_lembretes.php
 *
 * Em hospedagem sem cron (Render, hospedagem compartilhada), use o gatilho
 * por URL: tarefas.php?chave=... (ver LEIAME.md, "Lembretes por WhatsApp").
 * Os dois caminhos executam exatamente o mesmo codigo: Lembrete::processarTodas().
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// A tarefa nao pertence a empresa nenhuma: ela percorre todas as ativas.
define('TAREFA_AGENDADA', true);

require_once __DIR__ . '/../config/config.php';

// O bootstrap instala um tratador que redireciona para a tela de erro.
// Na linha de comando a excecao precisa aparecer e derrubar o processo.
restore_exception_handler();

$resumo = Lembrete::processarTodas();

foreach ($resumo as $id => $empresa) {
    echo Lembrete::resumoTexto($id, $empresa) . "\n";
}

if ($resumo === []) {
    echo "Nenhuma empresa ativa.\n";
}
