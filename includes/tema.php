<?php
/** Incluído por último no head: as mesmas variáveis personalizam todas as áreas da empresa. */
$dadosTema = Estabelecimento::dados();
?>
<?php /* Icone da aba: marca do sistema, com a cor da barra seguindo o tema da empresa. */ ?>
<?= faviconSistema(Tema::valores($dadosTema)['cor_primaria']) ?>
<link rel="stylesheet" href="<?= url('assets/css/tema.css') ?>">
<link rel="stylesheet" href="<?= url('assets/css/projeto.css') ?>">
<style id="tema-estabelecimento">:root{<?= Tema::estilo($dadosTema) ?>}</style>
<meta name="estabelecimento" content="<?= e(Contexto::slug()) ?>">
<?php /* Monta a barra de acessibilidade em todas as telas que carregam o tema. */ ?>
<script src="<?= url('assets/js/acessibilidade.js') ?>" defer></script>
