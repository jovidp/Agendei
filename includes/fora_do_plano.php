<?php
/**
 * Tela de aviso para recurso que o plano da empresa nao inclui.
 * Variaveis: $recursoFaltante (chave de Plano::BENEFICIOS), $tituloPagina.
 * Para o administrador aponta Assinatura; para os demais, so explica. Encerra a pagina.
 */
$subtituloTopo = 'Recurso fora do plano';
require RAIZ . '/includes/painel_header.php';
$planoSugerido = Plano::menorCom($recursoFaltante);
?>
<div class="cartao">
    <div class="estado-vazio">
        <strong><?= e(Plano::BENEFICIOS[$recursoFaltante]) ?> não faz parte do plano atual</strong>
        <?php if (ehAdmin()): ?>
            <p><?= $planoSugerido
                ? 'Está disponível a partir do plano ' . e($planoSugerido['nome']) . ', por ' . formatarMoeda($planoSugerido['preco_mensal']) . ' por mês.'
                : 'Fale com a administração do Agendei para incluir este recurso.' ?></p>
            <a href="<?= url('assinatura.php') ?>" class="btn margem-topo">Ver planos</a>
        <?php else: ?>
            <p>Este estabelecimento ainda não oferece este recurso.</p>
        <?php endif; ?>
    </div>
</div>
<?php
require RAIZ . '/includes/painel_footer.php';
exit;
