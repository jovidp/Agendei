<?php
/** Tela de contratacao, acessivel mesmo quando a demonstracao expirou. */
require_once __DIR__ . '/config/config.php';

if (!estaLogado()) redirecionar('login.php');
if (!ehAdmin() || ehSimulacao()) {
    definirFlash('erro', 'Esta pagina e exclusiva do administrador da empresa.');
    redirecionar(painelDe(perfil()));
}

$idEstabelecimento = Contexto::id();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    if (Assinatura::solicitarPagamento($idEstabelecimento, post('metodo'))) {
        definirFlash('sucesso', 'Forma de pagamento registrada. O financeiro emitira a cobranca de R$ 149,00 por ' . Assinatura::rotuloMetodo(post('metodo')) . '.');
        redirecionar('assinatura.php');
    }
    definirFlash('erro', 'Escolha Pix, boleto ou cartao para continuar.');
}

$assinatura = Assinatura::situacao($idEstabelecimento);
$tituloPagina = 'Assinatura';
$subtituloTopo = 'Plano mensal de R$ 149,00';
require RAIZ . '/includes/painel_header.php';
?>
<div class="cartao"><div class="cartao-cabecalho"><div><h3><?= $assinatura['status'] === 'ativa' ? 'Assinatura ativa' : 'Continue usando o Agendei' ?></h3><small>R$ 149,00 por mes</small></div><?= badgeStatus($assinatura['status']) ?></div>
<div class="cartao-corpo">
<?php if ($assinatura['status'] === 'demo' && !empty($assinatura['data_fim_demo'])): ?>
    <p>Sua demonstracao termina em <strong><?= e(formatarData(substr((string) $assinatura['data_fim_demo'], 0, 10))) ?></strong>. Assine agora para nao interromper o acesso.</p>
<?php elseif ($assinatura['status'] === 'ativa'): ?>
    <p>O acesso da empresa esta liberado. Esta tela tambem mostra a ultima forma de pagamento escolhida.</p>
<?php elseif ($assinatura['status'] === 'pendente'): ?>
    <p>Recebemos sua preferencia de pagamento por <strong><?= e(Assinatura::rotuloMetodo($assinatura['metodo_pagamento'] ?? null)) ?></strong>. O acesso e liberado assim que o pagamento for confirmado.</p>
<?php else: ?>
    <p>A demonstracao de 7 dias terminou. Escolha uma forma de pagamento para reativar sua empresa.</p>
<?php endif; ?>

<?php if ($assinatura['status'] !== 'ativa'): ?><form method="post"><fieldset class="grupo-campos"><legend>Como voce quer pagar R$ 149,00?</legend>
    <div class="linha-campos"><label class="campo"><input type="radio" name="metodo" value="pix" required> <strong>Pix</strong><span class="ajuda-campo">Pagamento instantaneo.</span></label><label class="campo"><input type="radio" name="metodo" value="boleto"> <strong>Boleto</strong><span class="ajuda-campo">Pague pela internet ou banco.</span></label><label class="campo"><input type="radio" name="metodo" value="cartao"> <strong>Cartao</strong><span class="ajuda-campo">Credito ou debito.</span></label></div>
    <p class="ajuda-campo">Os dados financeiros nao sao coletados nesta aplicacao. A forma escolhida fica registrada para o financeiro emitir a cobranca.</p>
    <button class="btn" type="submit">Solicitar pagamento</button>
</fieldset></form><?php endif; ?>
</div></div>
<?php require RAIZ . '/includes/painel_footer.php'; ?>
