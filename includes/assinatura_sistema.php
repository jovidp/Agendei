<?php
/**
 * Assinatura do produto no pe das telas de entrada.
 *
 * Fica fora do cartao de proposito: o cartao mostra a identidade da empresa
 * atendida e esta linha diz qual sistema esta por tras dela.
 *
 * Tambem e o caminho fixo de volta para a pagina inicial: toda tela de
 * entrada (login, cadastro, 2FA, senha, erro) tem este link no mesmo lugar,
 * mesmo quando o link do topo leva para outra etapa, como o login.
 */
?>
<p class="assinatura-sistema">
    Sistema de agendamento
    <?= marcaSistema(16, false, 'marca-sistema-discreta') ?>
    <span aria-hidden="true">&middot;</span>
    <a href="<?= e(urlInicio()) ?>">Página inicial</a>
</p>
