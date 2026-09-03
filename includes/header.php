<?php
/**
 * Cabecalho das paginas publicas.
 * Variaveis opcionais: $tituloPagina, $cssExtra (array), $paginaAtiva.
 */
$estabelecimento = Estabelecimento::dados();
$tituloPagina    = $tituloPagina ?? $estabelecimento['nome'];
$cssExtra        = $cssExtra ?? [];
$paginaAtiva     = $paginaAtiva ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= e($estabelecimento['slogan'] ?? 'Agendamento de servicos online') ?>">
    <title><?= e($tituloPagina) ?></title>
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
    <?php foreach ($cssExtra as $arquivo): ?>
        <link rel="stylesheet" href="<?= url('assets/css/' . $arquivo) ?>">
    <?php endforeach; ?>
</head>
<body>

<header class="cabecalho-site">
    <div class="container cabecalho-conteudo">
        <a href="<?= url('index.php') ?>" class="marca">
            <span class="marca-simbolo"><?= e(mb_substr($estabelecimento['nome'], 0, 1)) ?></span>
            <?= e($estabelecimento['nome']) ?>
        </a>

        <nav class="menu-site" aria-label="Menu principal">
            <a href="<?= url('index.php') ?>" class="<?= $paginaAtiva === 'inicio' ? 'ativo' : '' ?>">Inicio</a>
            <a href="<?= url('index.php#servicos') ?>">Servicos</a>
            <a href="<?= url('index.php#como-funciona') ?>">Como funciona</a>
            <a href="<?= url('index.php#profissionais') ?>">Profissionais</a>
            <a href="<?= url('index.php#contato') ?>">Contato</a>
        </nav>

        <div class="acoes-cabecalho">
            <?php if (estaLogado()): ?>
                <a href="<?= url(painelDe(perfil())) ?>" class="btn btn-contorno btn-pequeno">Meu painel</a>
                <a href="<?= url('logout.php') ?>" class="btn btn-pequeno">Sair</a>
            <?php else: ?>
                <a href="<?= url('login.php') ?>" class="btn btn-contorno btn-pequeno">Entrar</a>
                <a href="<?= url('login.php?destino=agendar') ?>" class="btn btn-pequeno">Agendar agora</a>
            <?php endif; ?>
            <button type="button" class="botao-menu" aria-label="Abrir menu"><span></span></button>
        </div>
    </div>
</header>

<main>
