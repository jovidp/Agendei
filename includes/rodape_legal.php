<?php
/**
 * Links dos documentos legais e identificação do fornecedor (CDC, art. 31;
 * Decreto 7.962/2013, art. 2º). Incluído no rodapé da página inicial, das
 * telas de entrada e dos painéis. A linha do fornecedor só aparece com os
 * dados configurados (Legal::fornecedor).
 */
$fornecedorRodape = Legal::fornecedor();
$identificacao = array_filter([$fornecedorRodape['nome'], $fornecedorRodape['documento'], $fornecedorRodape['endereco'], $fornecedorRodape['email']]);
?>
<nav class="rodape-legal" aria-label="Documentos legais">
    <a href="<?= url('legal.php?pagina=termos') ?>">Termos de Uso</a>
    <span aria-hidden="true">&middot;</span>
    <a href="<?= url('legal.php?pagina=privacidade') ?>">Privacidade</a>
    <span aria-hidden="true">&middot;</span>
    <a href="<?= url('legal.php?pagina=cookies') ?>">Cookies</a>
    <span aria-hidden="true">&middot;</span>
    <a href="<?= url('legal.php?pagina=reembolso') ?>">Cancelamento e reembolso</a>
    <?php if ($identificacao !== []): ?>
        <span class="rodape-fornecedor"><?= e(implode(' · ', $identificacao)) ?></span>
    <?php endif; ?>
</nav>
