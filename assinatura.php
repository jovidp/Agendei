<?php
/**
 * Contratacao do plano, acessivel mesmo quando a demonstracao expirou.
 *
 * A empresa escolhe o plano (preco e beneficios vem da tabela planos, que o
 * master edita) e a forma de pagamento. O pagamento ainda e confirmado pelo
 * financeiro (Assinatura::confirmarPagamento): nao ha gateway. Pix e boleto
 * sao gerados por Cobranca (demonstracao); do cartao so chegam os 4 ultimos
 * digitos, porque os campos do cartao nao tem name e nunca saem do navegador.
 */
require_once __DIR__ . '/config/config.php';

if (!estaLogado()) redirecionar('login.php');
if (!ehAdmin() || ehSimulacao()) {
    definirFlash('erro', 'Esta página é exclusiva do administrador da empresa.');
    redirecionar(painelDe(perfil()));
}

$idEstabelecimento = Contexto::id();
$planos = Plano::contrataveis();
$planosPorId = array_column($planos, null, 'id_plano');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $acao = post('acao');

    if ($acao === 'cancelar') {
        $valorPago = formatarMoeda(Plano::precoDe($idEstabelecimento));
        $resultado = Assinatura::cancelar($idEstabelecimento);
        LogMaster::registrar('assinatura_cancelada', ['estabelecimento' => $idEstabelecimento, 'detalhe' => $resultado ?: 'nada a cancelar']);
        $mensagens = [
            'imediato'    => ['sucesso', 'Assinatura cancelada. Nada foi cobrado e o acesso da empresa foi encerrado.'],
            'reembolso'   => ['sucesso', 'Assinatura cancelada dentro de ' . Assinatura::DIAS_ARREPENDIMENTO . ' dias do pagamento: o valor de ' . $valorPago . ' será devolvido integralmente pela mesma forma de pagamento.'],
            'fim_periodo' => ['sucesso', 'Assinatura cancelada. O acesso continua até o fim do período já pago e não será renovado.'],
            ''            => ['erro', 'Não há assinatura para cancelar.'],
        ];
        definirFlash(...$mensagens[$resultado]);
        redirecionar('assinatura.php');
    }

    $plano = $planosPorId[(int) post('id_plano')] ?? null;
    if ($plano === null) {
        definirFlash('erro', 'Escolha um dos planos.');
        redirecionar('assinatura.php');
    }

    $situacaoAtual = Assinatura::situacao($idEstabelecimento);
    // Assinatura em dia: so troca o plano; o preco novo vale a partir do proximo pagamento.
    if ($situacaoAtual['status'] === 'ativa' && empty($situacaoAtual['data_cancelamento'])) {
        Plano::atribuir($idEstabelecimento, (int) $plano['id_plano'], 'Escolhido pela empresa');
        definirFlash('sucesso', 'Plano alterado para ' . $plano['nome'] . '. Os benefícios já valem agora; o valor de ' . formatarMoeda($plano['preco_mensal']) . ' vale a partir do próximo pagamento.');
        redirecionar('assinatura.php');
    }

    if (!in_array(post('metodo'), ['pix', 'boleto', 'cartao'], true)) {
        definirFlash('erro', 'Escolha Pix, boleto ou cartão para continuar.');
        redirecionar('assinatura.php');
    }
    if (post('metodo') === 'cartao' && !preg_match('/^\d{4}$/', (string) post('cartao_final'))) {
        definirFlash('erro', 'Preencha os dados do cartão para continuar.');
        redirecionar('assinatura.php');
    }
    Plano::atribuir($idEstabelecimento, (int) $plano['id_plano'], 'Escolhido pela empresa');
    Assinatura::solicitarPagamento($idEstabelecimento, post('metodo'));
    $valor = formatarMoeda($plano['preco_mensal']);
    definirFlash('sucesso', 'Plano ' . $plano['nome'] . ' escolhido. ' . match (post('metodo')) {
        'pix'    => 'Pague ' . $valor . ' pelo QR code Pix abaixo.',
        'boleto' => 'O boleto de ' . $valor . ' está logo abaixo.',
        default  => 'O pagamento de ' . $valor . ' no cartão final ' . post('cartao_final') . ' está em análise.',
    });
    redirecionar('assinatura.php');
}

$assinatura = Assinatura::situacao($idEstabelecimento);
$planoAtual = Plano::doEstabelecimento($idEstabelecimento);
$emDia = $assinatura['status'] === 'ativa' && empty($assinatura['data_cancelamento']);
$preco = Plano::precoDe($idEstabelecimento);

$tituloPagina = 'Assinatura';
$subtituloTopo = $planoAtual ? 'Plano ' . $planoAtual['nome'] : 'Escolha o plano da sua empresa';
$cssExtra = ['agendamento.css'];
$jsExtra = ['assinatura.js'];
require RAIZ . '/includes/painel_header.php';
?>
<div class="cartao"><div class="cartao-cabecalho"><div><h3><?= $emDia ? 'Assinatura ativa' : 'Continue usando o Agendei' ?></h3><small><?= $planoAtual ? e($planoAtual['nome']) . ' · ' : '' ?><?= formatarMoeda($preco) ?> por mês</small></div><?= badgeStatus($assinatura['status']) ?></div>
<div class="cartao-corpo">
<?php if ($assinatura['status'] === 'demo' && !empty($assinatura['data_fim_demo'])): ?>
    <p>Sua demonstração termina em <strong><?= e(formatarData(substr((string) $assinatura['data_fim_demo'], 0, 10))) ?></strong>. Escolha o plano e a forma de pagamento abaixo para não interromper o acesso.</p>
<?php elseif ($assinatura['status'] === 'ativa' && !empty($assinatura['data_cancelamento'])): ?>
    <p>Assinatura cancelada. O acesso continua até <strong><?= e(formatarData(substr((string) Assinatura::acessoAte($assinatura), 0, 10))) ?></strong> e não será renovado. Para continuar depois disso, escolha um plano quando o acesso encerrar.</p>
<?php elseif ($emDia): ?>
    <p>O acesso da empresa está liberado. Você pode trocar de plano abaixo quando quiser.</p>
<?php elseif ($assinatura['status'] === 'pendente'): ?>
    <p>Recebemos sua escolha de pagamento por <strong><?= e(Assinatura::rotuloMetodo($assinatura['metodo_pagamento'] ?? null)) ?></strong>. O acesso é liberado assim que o pagamento for confirmado.</p>
    <?php if (($assinatura['metodo_pagamento'] ?? '') === 'pix'): $codigoPix = Cobranca::pix($preco, 'AGD' . $idEstabelecimento); ?>
        <div class="autenticador-configuracao">
            <div class="autenticador-qr"><?= QrCode::svg($codigoPix, 'QR code Pix de ' . formatarMoeda($preco)) ?></div>
            <div class="autenticador-chave">
                <p><strong>Abra o app do banco, escolha Pix e leia o QR code.</strong> Valor: <?= formatarMoeda($preco) ?>.</p>
                <label for="pix-copia" class="ajuda-campo">Ou use o Pix copia e cola:</label>
                <textarea id="pix-copia" rows="3" readonly><?= e($codigoPix) ?></textarea>
                <button type="button" class="btn btn-contorno margem-topo" data-copiar="pix-copia">Copiar código</button>
            </div>
        </div>
    <?php elseif (($assinatura['metodo_pagamento'] ?? '') === 'boleto'): $boleto = Cobranca::boleto($preco, $idEstabelecimento, strtotime((string) ($assinatura['data_solicitacao'] ?? 'now')) ?: null); ?>
        <p>Boleto de <strong><?= formatarMoeda($preco) ?></strong>, vencimento <strong><?= e(formatarData($boleto['vencimento'])) ?></strong>.</p>
        <label for="boleto-linha" class="ajuda-campo">Linha digitável:</label>
        <input type="text" id="boleto-linha" value="<?= e($boleto['linha']) ?>" readonly>
        <button type="button" class="btn btn-contorno margem-topo" data-copiar="boleto-linha">Copiar linha digitável</button>
    <?php elseif (($assinatura['metodo_pagamento'] ?? '') === 'cartao'): ?>
        <p>O pagamento no cartão está em análise pela operadora. Você não precisa fazer mais nada.</p>
    <?php endif; ?>
<?php else: ?>
    <p>A demonstração de <?= Assinatura::DIAS_DEMO ?> dias terminou. Escolha um plano e a forma de pagamento para reativar sua empresa.</p>
<?php endif; ?>
</div></div>

<?php if ($planos !== [] && !($assinatura['status'] === 'ativa' && !empty($assinatura['data_cancelamento']))): ?>
<form method="post" class="cartao">
    <?= campoCsrf() ?>
    <div class="cartao-cabecalho"><h3><?= $emDia ? 'Trocar de plano' : 'Escolha o plano' ?></h3></div>
    <div class="cartao-corpo">
        <div class="lista-opcoes planos-opcoes">
            <?php foreach ($planos as $plano): $id = (int) $plano['id_plano']; ?>
                <div class="opcao">
                    <input type="radio" name="id_plano" id="plano<?= $id ?>" value="<?= $id ?>" required
                        <?= ($planoAtual && (int) $planoAtual['id_plano'] === $id) ? 'checked' : '' ?>>
                    <label for="plano<?= $id ?>">
                        <span class="opcao-conteudo">
                            <strong><?= e($plano['nome']) ?><?= ($planoAtual && (int) $planoAtual['id_plano'] === $id) ? ' <span class="badge badge-ativo">Seu plano</span>' : '' ?></strong>
                            <span class="plano-preco"><?= formatarMoeda($plano['preco_mensal']) ?><small>/mês</small></span>
                            <p><?= e($plano['descricao'] ?? '') ?></p>
                            <ul class="plano-beneficios">
                                <?php foreach (Plano::itensDe($plano) as $item): ?>
                                    <li class="<?= $item['incluso'] ? 'incluso' : 'fora' ?>"><span class="sr-only"><?= $item['incluso'] ? 'Inclui:' : 'Não inclui:' ?></span><?= e($item['texto']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </span>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (!$emDia): ?>
            <fieldset class="grupo-campos margem-topo"><legend>Como você quer pagar?</legend>
                <div class="linha-campos"><label class="campo"><input type="radio" name="metodo" value="pix" required> <strong>Pix</strong><span class="ajuda-campo">Pagamento instantâneo.</span></label><label class="campo"><input type="radio" name="metodo" value="boleto"> <strong>Boleto</strong><span class="ajuda-campo">Pague pela internet ou banco.</span></label><label class="campo"><input type="radio" name="metodo" value="cartao"> <strong>Cartão</strong><span class="ajuda-campo">Crédito ou débito.</span></label></div>
                <div id="dados-cartao" class="margem-topo" hidden>
                    <?php /* Sem name: estes campos nunca sao enviados. O script so manda os 4 ultimos digitos. */ ?>
                    <div class="linha-campos">
                        <div class="campo"><label for="cartao-numero">Número do cartão</label><input type="text" id="cartao-numero" inputmode="numeric" autocomplete="cc-number" maxlength="23" placeholder="0000 0000 0000 0000"></div>
                        <div class="campo"><label for="cartao-nome">Nome impresso no cartão</label><input type="text" id="cartao-nome" autocomplete="cc-name"></div>
                    </div>
                    <div class="linha-campos">
                        <div class="campo"><label for="cartao-validade">Validade</label><input type="text" id="cartao-validade" inputmode="numeric" autocomplete="cc-exp" maxlength="5" placeholder="MM/AA"></div>
                        <div class="campo"><label for="cartao-cvv">CVV</label><input type="text" id="cartao-cvv" inputmode="numeric" autocomplete="cc-csc" maxlength="4" placeholder="123"></div>
                    </div>
                    <input type="hidden" name="cartao_final" id="cartao-final">
                    <p class="mensagem-campo" id="cartao-erro" role="alert"></p>
                </div>
                <p class="ajuda-campo">Ambiente de demonstração: os dados do cartão são conferidos no seu navegador e não são enviados nem guardados. Só os 4 últimos dígitos ficam registrados.</p>
            </fieldset>
        <?php endif; ?>

        <p class="ajuda-campo">Ao trocar para um plano com limites menores, nada é apagado: o que passar do limite só para de crescer.</p>
        <button class="btn" type="submit"><?= $emDia ? 'Trocar de plano' : 'Assinar plano' ?></button>
    </div>
</form>
<?php endif; ?>

<?php if (empty($assinatura['legado']) && $assinatura['status'] !== 'bloqueada' && empty($assinatura['data_cancelamento'])): ?>
<div class="cartao"><div class="cartao-cabecalho"><h3>Cancelar assinatura</h3></div>
<div class="cartao-corpo">
    <p>Você pode cancelar quando quiser, sem multa. Cancelando até <?= Assinatura::DIAS_ARREPENDIMENTO ?> dias depois do pagamento, o valor volta inteiro. Depois disso, o acesso segue até o fim do mês já pago e não renova. Veja a <a href="<?= url('legal.php?pagina=reembolso') ?>">política de cancelamento e reembolso</a>.</p>
    <form method="post"><?= campoCsrf() ?><input type="hidden" name="acao" value="cancelar">
        <button type="submit" class="btn btn-perigo" data-confirmar="Cancelar a assinatura do Agendei? Os dados da empresa continuam guardados e você pode assinar de novo depois." data-confirmar-titulo="Cancelar assinatura" data-confirmar-rotulo="Cancelar assinatura">Cancelar assinatura</button>
    </form>
</div></div>
<?php endif; ?>

<?php require RAIZ . '/includes/painel_footer.php'; ?>
