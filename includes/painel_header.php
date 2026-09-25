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
<body class="<?= ehSimulacao() ? 'com-simulacao' : '' ?>">

<?php /* Estrutura comum que reúne menu lateral, título e área de conteúdo da tela. */ ?><div class="painel">
    <?php require RAIZ . '/includes/sidebar.php'; ?>
    <div class="sidebar-fundo"></div>

    <div class="painel-conteudo">
<?php if (ehSimulacao()): $simulacao = simulacaoAtual(); ?>
        <?php /* O aviso acompanha a rolagem: ninguem deve esquecer que esta no painel de outra pessoa. */ ?>
        <div class="faixa-simulacao" role="status">
            <span>
                Voce esta no painel de <strong><?= e($estabelecimento['nome']) ?></strong>
                como <strong><?= e(usuarioNome()) ?></strong>, pela conta master de <?= e($simulacao['master_nome']) ?>.
            </span>
            <form method="post" action="<?= url('master/simular.php') ?>">
                <?= campoCsrf() ?>
                <input type="hidden" name="acao" value="sair">
                <button type="submit" class="btn btn-pequeno">Voltar para a administracao master</button>
            </form>
        </div>
<?php endif; ?>
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

            <?php /* Login e saida ficam no canto superior direito de todas as telas. */ ?>
            <div class="usuario-topo">
                <div class="usuario-topo-dados">
                    <span class="usuario-topo-login"><?= e(usuarioLogin()) ?></span>
                    <span class="usuario-topo-perfil"><?= e(perfilRotulo()) ?></span>
                </div>
                <span class="avatar"><?= e(iniciais(usuarioNome())) ?></span>
                <?php if (!ehMaster() && !ehSimulacao() && podeTrocarVinculo()): ?>
                    <a class="btn btn-contorno btn-pequeno" href="<?= url('trocar.php') ?>" title="Sua conta vale em mais de um lugar">Trocar</a>
                <?php endif; ?>
                <a class="btn btn-contorno btn-pequeno"
                   href="<?= url(ehMaster() ? 'master/logout.php' : 'logout.php') ?>">Sair</a>
            </div>
        </div>

        <div class="area-conteudo">
            <?php exibirFlash(); ?>
