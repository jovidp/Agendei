<?php
/** Incluído por último no head: as mesmas variáveis personalizam todas as áreas da empresa. */
$dadosTema = Estabelecimento::dados();
?>
<link rel="stylesheet" href="<?= url('assets/css/tema.css') ?>">
<style id="tema-estabelecimento">:root{<?= Tema::estilo($dadosTema) ?>}</style>
<meta name="estabelecimento" content="<?= e(Contexto::slug()) ?>">
