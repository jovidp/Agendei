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
<?php /* Sidebar recolhida vem do cookie (main.js grava): a pagina ja abre no estado certo, sem piscar. */ ?>
<html lang="pt-BR"<?= ($_COOKIE['agendei_sidebar'] ?? '') === 'recolhida' ? ' class="sidebar-recolhida"' : '' ?>>
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
                Você está no painel de <strong><?= e($estabelecimento['nome']) ?></strong>
                como <strong><?= e(usuarioNome()) ?></strong>, pela conta master de <?= e($simulacao['master_nome']) ?>.
            </span>
            <form method="post" action="<?= url('master/simular.php') ?>">
                <?= campoCsrf() ?>
                <input type="hidden" name="acao" value="sair">
                <button type="submit" class="btn btn-pequeno">Voltar para a administração master</button>
            </form>
        </div>
<?php endif; ?>
        <?php
        // Trilha "Area > Pagina" acima do titulo; o inicio de cada perfil e o seu dashboard.
        $areaPainel = [
            'master' => ['Administração master', 'master/dashboard.php'],
            'admin' => ['Administração', 'admin/dashboard.php'],
            'profissional' => ['Área do profissional', 'profissional/dashboard.php'],
            'cliente' => ['Área do cliente', 'cliente/dashboard.php'],
        ][perfil()] ?? null;
        $contaPainel = [
            'master' => 'master/perfil.php', 'profissional' => 'profissional/perfil.php', 'cliente' => 'cliente/perfil.php',
        ][perfil()] ?? null;
        ?>
        <header class="topo-painel">
            <button type="button" class="botao-sidebar" aria-label="Abrir menu" aria-controls="sidebarPainel"><span></span></button>
            <div class="topo-titulo">
                <?php if ($areaPainel): ?>
                    <nav class="trilha" aria-label="Você está em">
                        <a href="<?= url($areaPainel[1]) ?>"><?= e($areaPainel[0]) ?></a>
                        <span aria-hidden="true">/</span>
                        <span aria-current="page"><?= e($tituloPagina) ?></span>
                    </nav>
                <?php endif; ?>
                <h1><?= e($tituloPagina) ?><?php if (!empty($subtituloTopo)): ?> <span class="subtitulo-topo"><?= e($subtituloTopo) ?></span><?php endif; ?></h1>
            </div>
            <?php if (!empty($acoesTopo)): ?>
                <div class="topo-acoes"><?= $acoesTopo ?></div>
            <?php endif; ?>

            <?php /* Conta, troca de vinculo e saida ficam no menu do avatar, no canto superior direito. */ ?>
            <details class="conta-topo">
                <summary aria-label="Menu da conta de <?= e(usuarioNome()) ?>">
                    <span class="avatar"><?= e(iniciais(usuarioNome())) ?></span>
                    <span class="conta-topo-dados">
                        <span class="conta-topo-login"><?= e(usuarioLogin()) ?></span>
                        <span class="conta-topo-perfil"><?= e(perfilRotulo()) ?></span>
                    </span>
                    <?= icone('descer', 'conta-topo-seta') ?>
                </summary>
                <div class="conta-menu">
                    <div class="conta-menu-cabecalho">
                        <strong><?= e(usuarioNome()) ?></strong>
                        <span><?= e(perfilRotulo()) ?></span>
                    </div>
                    <?php if ($contaPainel && !ehSimulacao()): ?>
                        <a href="<?= url($contaPainel) ?>"><?= icone('perfil') ?> Meu perfil</a>
                    <?php endif; ?>
                    <?php if (!ehSimulacao()): ?>
                        <a href="<?= url(ehMaster() ? 'master/autenticador.php' : 'autenticador.php') ?>"><?= icone('bloqueio') ?> Verificação em 2 etapas</a>
                    <?php endif; ?>
                    <?php if (!ehMaster() && !ehSimulacao() && podeTrocarVinculo()): ?>
                        <a href="<?= url('trocar.php') ?>" title="Sua conta vale em mais de um lugar"><?= icone('clientes') ?> Trocar de estabelecimento</a>
                    <?php endif; ?>
                    <a class="conta-menu-sair" href="<?= url(ehMaster() ? 'master/logout.php' : 'logout.php') ?>"><?= icone('sair') ?> Sair</a>
                </div>
            </details>
        </header>

        <div class="area-conteudo">
            <?php exibirFlash(); ?>
            <?php /* Lembrete de confirmar o e-mail ate a pessoa digitar o codigo (VerificacaoEmail). */ ?>
            <?php if (!ehSimulacao() && empty($ocultarAvisoEmail) && VerificacaoEmail::precisaConfirmar()): ?>
                <div class="alerta alerta-info aviso-confirmar-email"><span class="alerta-texto"><strong>Confirme seu e-mail</strong> (<?= e(VerificacaoEmail::mascarar((string) ($_SESSION['usuario_email'] ?? ''))) ?>). <a href="<?= url('verificar_email.php') ?>">Confirmar agora</a></span></div>
            <?php endif; ?>
