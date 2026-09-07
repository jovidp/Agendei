<?php
/**
 * Cabecalho dos paineis (admin, profissional e cliente).
 * Variaveis opcionais: $tituloPagina, $subtituloTopo, $acoesTopo (HTML), $cssExtra (array).
 */
$estabelecimento = Estabelecimento::dados();
// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina    = $tituloPagina ?? 'Painel';
// Recebe os estilos específicos da tela além dos estilos comuns do painel.
$cssExtra        = $cssExtra ?? [];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($tituloPagina) ?> | <?= e($estabelecimento['nome']) ?></title>
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/painel.css') ?>">
    <?php foreach ($cssExtra as $arquivo): ?>
        <link rel="stylesheet" href="<?= url('assets/css/' . $arquivo) ?>">
    <?php endforeach; ?>
    <?php require RAIZ . '/includes/tema.php'; ?>
</head>
<body>

<?php /* Estrutura comum que reúne menu lateral, título e área de conteúdo da tela. */ ?><div class="painel">
    <?php require RAIZ . '/includes/sidebar.php'; ?>
    <div class="sidebar-fundo"></div>

    <div class="painel-conteudo">
        <div class="topo-painel">
            <button type="button" class="botao-sidebar" aria-label="Abrir menu"><span></span></button>
            <div>
                <h1><?= e($tituloPagina) ?></h1>
                <?php if (!empty($subtituloTopo)): ?>
                    <span class="subtitulo-topo"><?= e($subtituloTopo) ?></span>
                <?php endif; ?>
            </div>
            <?php if (!empty($acoesTopo)): ?>
                <div class="topo-acoes"><?= $acoesTopo ?></div>
            <?php endif; ?>
        </div>

        <div class="area-conteudo">
            <?php exibirFlash(); ?>
