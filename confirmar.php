<?php
/**
 * Confirmacao de presenca pelo link do lembrete.
 *
 * O cliente chega aqui sem login, pelo endereco assinado que o lembrete leva
 * (models/Confirmacao.php). A pagina mostra o atendimento e oferece duas
 * acoes: confirmar que vai ou avisar que nao vai. As duas exigem o clique num
 * botao (POST). Um GET nunca altera nada, porque os aplicativos de mensagem
 * abrem o link sozinhos para montar a previa.
 */
// Pagina de entrada local: nunca roda sob a identidade master (ver config.php).
define('ENTRADA_LOCAL', true);
require_once __DIR__ . '/config/config.php';

// Link errado em sequencia espera, como qualquer tentativa de adivinhacao.
if (conferirBloqueio(['confirmacao_ip' => ipCliente()]) !== '') {
    atrasarResposta();
    http_response_code(429);
    exit('Muitas tentativas. Aguarde alguns minutos e abra o link de novo.');
}

$ehPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$id     = (int) ($ehPost ? post('a') : get('a'));
$token  = $ehPost ? post('t') : get('t');

$agendamento = Confirmacao::porLink($id, $token);
if ($agendamento === null) {
    anotarFalha('confirmacao_ip', ipCliente());
    atrasarResposta();
    http_response_code(404);
} else {
    limparFalhas('confirmacao_ip', ipCliente());
}

if ($ehPost && $agendamento !== null) {
    // Confere o token da sessão antes de aceitar alterações enviadas pelo formulário.
    exigirCsrf();

    $acao = post('acao');
    if ($acao === 'confirmar') {
        if (Confirmacao::confirmar($agendamento)) {
            definirFlash('sucesso', 'Presença confirmada. Até lá!');
        } else {
            definirFlash('erro', 'Este agendamento não pode mais ser confirmado por aqui.');
        }
    } elseif ($acao === 'recusar') {
        if (Confirmacao::recusar($agendamento)) {
            definirFlash('sucesso', 'Aviso recebido: o horário foi liberado. Obrigado por avisar.');
        } else {
            definirFlash('erro', 'Este agendamento não pode mais ser alterado por aqui.');
        }
    }

    // Recarrega por GET para o botão "atualizar" do navegador não repetir a ação.
    redirecionar('confirmar.php?a=' . $id . '&t=' . rawurlencode($token));
}

$estabelecimento = Estabelecimento::dados();
$situacao        = $agendamento !== null ? Confirmacao::situacao($agendamento) : 'invalido';
$linkConta       = url('login.php');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Confirmar presença | <?= e($estabelecimento['nome']) ?></title>
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/login.css') ?>">
    <?php require RAIZ . '/includes/tema.php'; ?>
</head>
<body class="pagina-autenticacao">

<div class="autenticacao-area">
    <div class="autenticacao-caixa caixa-simples">
        <div class="autenticacao-formulario">
            <div class="marca"><?= Tema::marca($estabelecimento) ?> <?= e($estabelecimento['nome']) ?></div>

            <h1>Confirmar presença</h1>
            <?php exibirFlash(); ?>

            <?php if ($agendamento === null): ?>
                <p class="subtitulo">Este link não é válido ou o agendamento foi alterado.</p>
                <div class="alerta alerta-erro">
                    <span class="alerta-texto">Se você recebeu um lembrete mais recente, use o link dele. Em dúvida, entre na sua conta.</span>
                </div>
                <a href="<?= e($linkConta) ?>" class="btn btn-bloco">Entrar na minha conta</a>
            <?php else: ?>
                <p class="subtitulo">Olá, <?= e(explode(' ', trim((string) $agendamento['nome_cliente']))[0]) ?>! Confira o seu atendimento.</p>

                <ul class="resumo-confirmacao">
                    <li><span>Serviço</span><strong><?= e($agendamento['nome_servico']) ?></strong></li>
                    <li><span>Profissional</span><strong><?= e($agendamento['nome_profissional']) ?></strong></li>
                    <li><span>Data</span><strong><?= e(dataExtenso($agendamento['data_agendamento'])) ?></strong></li>
                    <li><span>Horário</span><strong><?= formatarHora($agendamento['hora_inicio']) ?> às <?= formatarHora($agendamento['hora_fim']) ?></strong></li>
                </ul>

                <?php if ($situacao === 'aberto' || $situacao === 'confirmado'): ?>
                    <?php if ($situacao === 'confirmado'): ?>
                        <div class="alerta alerta-sucesso"><span class="alerta-texto">Presença confirmada. Se algo mudar, avise por aqui.</span></div>
                    <?php else: ?>
                        <p>Você vai comparecer?</p>
                    <?php endif; ?>

                    <?php /* As duas acoes sao POST: o link em si nunca altera o agendamento. */ ?><form method="post" class="acoes-confirmacao" id="formConfirmacao">
                        <?= campoCsrf() ?>
                        <input type="hidden" name="a" value="<?= (int) $agendamento['id_agendamento'] ?>">
                        <input type="hidden" name="t" value="<?= e($token) ?>">
                        <?php if ($situacao === 'aberto'): ?>
                            <button type="submit" name="acao" value="confirmar" class="btn btn-bloco btn-grande">Sim, vou comparecer</button>
                        <?php endif; ?>
                        <button type="submit" name="acao" value="recusar" class="btn btn-bloco btn-contorno">Não poderei ir</button>
                        <span class="ajuda-campo">Ao avisar que não vai, o horário é liberado para outra pessoa.</span>
                    </form>
                <?php elseif ($situacao === 'cancelado'): ?>
                    <div class="alerta alerta-aviso"><span class="alerta-texto">Este agendamento foi cancelado. Se quiser, marque um novo horário pela sua conta.</span></div>
                <?php elseif ($situacao === 'concluido'): ?>
                    <div class="alerta alerta-sucesso"><span class="alerta-texto">Este atendimento já foi realizado. Obrigado!</span></div>
                <?php else: ?>
                    <div class="alerta alerta-aviso"><span class="alerta-texto">O horário deste agendamento já passou.</span></div>
                <?php endif; ?>

                <a href="<?= e($linkConta) ?>" class="voltar-site">Entrar na minha conta</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require RAIZ . '/includes/assinatura_sistema.php'; ?>
