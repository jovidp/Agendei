<?php

/**
 * Gatilho por URL das tarefas periodicas (lembretes por WhatsApp).
 *
 * Serve para hospedagens sem cron, como o Render ou hospedagem compartilhada:
 * um agendador externo (cron-job.org, UptimeRobot, GitHub Actions) chama
 *   https://seu-dominio/tarefas.php?chave=SUA_CHAVE
 * a cada 10 ou 15 minutos. A chave vem da variavel de ambiente
 * AGENDEI_TOKEN_TAREFAS; sem ela definida, a pagina simplesmente nao existe.
 *
 * Faz o mesmo que "php scripts/enviar_lembretes.php": Lembrete::processarTodas().
 */

// A tarefa nao pertence a empresa nenhuma: ela percorre todas as ativas.
define('TAREFA_AGENDADA', true);

require_once __DIR__ . '/config/config.php';

$chaveEsperada = (string) (getenv('AGENDEI_TOKEN_TAREFAS') ?: '');
$chaveRecebida = get('chave') !== '' ? get('chave') : (string) ($_SERVER['HTTP_X_AGENDEI_CHAVE'] ?? '');

// Poucas tentativas por origem impedem que a chave seja descoberta por repeticao.
if (conferirBloqueio(['tarefas_ip' => ipCliente()]) !== '') {
    http_response_code(404);
    exit;
}

// Chave curta demais e o mesmo que chave nenhuma: a pagina fica desligada.
if (strlen($chaveEsperada) < 16 || !hash_equals($chaveEsperada, $chaveRecebida)) {
    anotarFalha('tarefas_ip', ipCliente());
    registrarEventoSeguranca('tarefas_recusado');
    atrasarResposta();
    http_response_code(404);
    exit;
}

limparFalhas('tarefas_ip', ipCliente());

try {
    $inicio = microtime(true);
    $resumo = Lembrete::processarTodas();
    // Retencao de dados (LGPD): uma vez por dia basta; a tarefa roda a cada 10 ou 15 minutos.
    $retencao = (int) date('G') === 3 ? Legal::limparDadosAntigos() : null;
    // Mensalidades das assinaturas de clientes que vencem em ate 3 dias, na mesma janela diaria.
    $renovacoes = (int) date('G') === 3 ? AssinaturaCliente::renovarTodas() : null;

    jsonResposta([
        'sucesso'      => true,
        'executado_em' => date('Y-m-d H:i:s'),
        'duracao_s'    => round(microtime(true) - $inicio, 2),
        'empresas'     => $resumo,
        'retencao'     => $retencao,
        'renovacoes'   => $renovacoes,
    ]);
} catch (Throwable $erro) {
    error_log('Tarefa de lembretes falhou: ' . $erro->getMessage());
    jsonResposta(['sucesso' => false, 'mensagem' => 'A tarefa falhou. Veja o log do servidor.'], 500);
}
