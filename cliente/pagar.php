<?php

/**
 * Pagamento online do cliente: mensalidade da assinatura (?mensalidade=id)
 * ou atendimento avulso (?agendamento=id), por Pix, boleto ou cartao.
 * Boleto e cartao sao de demonstracao (Cobranca); do cartao so chegam os 4
 * ultimos digitos (assets/js/assinatura.js). Quem confirma e o estabelecimento.
 */
require_once __DIR__ . '/../config/config.php';
exigirLogin('cliente');

if (!Plano::temRecurso('diferenciais')) {
    $recursoFaltante = 'diferenciais';
    $tituloPagina = 'Pagamento';
    require RAIZ . '/includes/fora_do_plano.php';
}

$idCliente = (int) perfilId();
$idMensalidade = (int) get('mensalidade');
$idAgendamento = (int) get('agendamento');
$voltar = $idMensalidade ? 'cliente/beneficios.php' : 'cliente/agendamentos.php';

/** O que se paga agora: [descricao, valor, metodo, final, referencia], ou null se nao for do cliente. */
function cobrancaAtual(int $idMensalidade, int $idAgendamento, int $idCliente): ?array
{
    if ($idMensalidade) {
        $c = AssinaturaCliente::cobrancaDoCliente($idMensalidade, $idCliente);
        return $c ? ['Mensalidade da assinatura', (float) $c['valor'], $c['metodo'], $c['cartao_final'], (string) $c['referencia']] : null;
    }
    $agendamento = Agendamento::porId($idAgendamento);
    if (!$agendamento || (int) $agendamento['id_cliente'] !== $idCliente) return null;
    $p = PagamentoAvulso::pendente($idAgendamento);
    $descricao = $agendamento['nome_servico'] . ' em ' . formatarData($agendamento['data_agendamento']) . ' às ' . formatarHora($agendamento['hora_inicio']);
    if ($p && $p['status'] === 'pendente') return [$descricao, (float) $p['valor'], $p['metodo'], $p['cartao_final'], (string) $p['referencia']];
    return PagamentoAvulso::pagavel($agendamento, $idCliente)
        ? [$descricao, PagamentoAvulso::valorAPagar($agendamento), null, null, 'AV' . $idAgendamento]
        : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    try {
        $idMensalidade
            ? AssinaturaCliente::escolherMetodo($idMensalidade, $idCliente, post('metodo'), post('cartao_final') ?: null)
            : PagamentoAvulso::solicitar($idAgendamento, $idCliente, post('metodo'), post('cartao_final') ?: null);
        definirFlash('sucesso', 'Forma de pagamento registrada. O estabelecimento confirma o pagamento.');
    } catch (InvalidArgumentException | DomainException $erro) {
        definirFlash('erro', $erro->getMessage());
    }
    redirecionar('cliente/pagar.php?' . ($idMensalidade ? 'mensalidade=' . $idMensalidade : 'agendamento=' . $idAgendamento));
}

$atual = cobrancaAtual($idMensalidade, $idAgendamento, $idCliente);
if ($atual === null) {
    definirFlash('erro', 'Não há pagamento pendente para este item.');
    redirecionar($voltar);
}
[$descricao, $valor, $metodo, $final, $referencia] = $atual;
$trocar = get('trocar') === '1' || $metodo === null;
$metodos = Cobranca::metodosDisponiveis();
$pix = Configuracao::obter('pix_chave');

$tituloPagina = 'Pagamento';
$subtituloTopo = $descricao;
$acoesTopo = '<a href="' . url($voltar) . '" class="btn btn-contorno btn-pequeno">Voltar</a>';
$cssExtra = ['agendamento.css'];
$jsExtra = ['assinatura.js'];
require RAIZ . '/includes/painel_header.php';
?>
<div class="cartao">
    <div class="cartao-cabecalho"><h3><?= e($descricao) ?></h3><strong><?= formatarMoeda($valor) ?></strong></div>
    <div class="cartao-corpo">
    <?php if ($trocar): ?>
        <form method="post">
            <?= campoCsrf() ?>
            <fieldset class="grupo-campos"><legend>Como você quer pagar?</legend>
                <div class="linha-campos">
                    <?php foreach ($metodos as $chave => $rotulo): ?>
                        <label class="campo"><input type="radio" name="metodo" value="<?= e($chave) ?>" required <?= $metodo === $chave ? 'checked' : '' ?>> <strong><?= e($rotulo) ?></strong></label>
                    <?php endforeach; ?>
                </div>
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
            <button class="btn">Continuar</button>
        </form>
    <?php else: ?>
        <?php if ($metodo === 'pix' && $pix !== ''): $codigoPix = Cobranca::pix($valor, $referencia, $pix, (string) (Contexto::dados()['nome'] ?? '')); ?>
            <div class="autenticador-configuracao">
                <div class="autenticador-qr"><?= QrCode::svg($codigoPix, 'QR code Pix de ' . formatarMoeda($valor)) ?></div>
                <div class="autenticador-chave">
                    <p><strong>Abra o app do banco, escolha Pix e leia o QR code.</strong></p>
                    <label for="pix-copia" class="ajuda-campo">Ou use o Pix copia e cola:</label>
                    <textarea id="pix-copia" rows="3" readonly><?= e($codigoPix) ?></textarea>
                    <button type="button" class="btn btn-contorno margem-topo" data-copiar="pix-copia">Copiar código</button>
                </div>
            </div>
        <?php elseif ($metodo === 'boleto'): $boleto = Cobranca::boleto($valor, Contexto::id()); ?>
            <p>Boleto de <strong><?= formatarMoeda($valor) ?></strong>, vencimento <strong><?= e(formatarData($boleto['vencimento'])) ?></strong>.</p>
            <label for="boleto-linha" class="ajuda-campo">Linha digitável:</label>
            <textarea id="boleto-linha" rows="2" readonly><?= e($boleto['linha']) ?></textarea>
            <button type="button" class="btn btn-contorno margem-topo" data-copiar="boleto-linha">Copiar linha</button>
        <?php elseif ($metodo === 'cartao'): ?>
            <p>O pagamento no <strong><?= e(Cobranca::rotuloPagamento($metodo, $final)) ?></strong> está em análise.</p>
        <?php else: ?>
            <div class="alerta alerta-aviso"><div><span class="alerta-texto">O Pix não está disponível no momento. Escolha outra forma.</span></div></div>
        <?php endif; ?>
        <p class="margem-topo">Depois do pagamento, o estabelecimento confirma e você vê aqui e em <?= $idMensalidade ? 'Meus benefícios' : 'Meus agendamentos' ?>.</p>
        <a class="btn btn-contorno btn-pequeno margem-topo" href="<?= url('cliente/pagar.php?' . ($idMensalidade ? 'mensalidade=' . $idMensalidade : 'agendamento=' . $idAgendamento) . '&trocar=1') ?>">Trocar forma de pagamento</a>
    <?php endif; ?>
    </div>
</div>
<?php require RAIZ . '/includes/painel_footer.php'; ?>
