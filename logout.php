<?php
/**
 * Encerra a sessão e mostra uma confirmação neutra, sem manter a identidade
 * visual do estabelecimento que estava associado à conta.
 */
require_once __DIR__ . '/config/config.php';

// Guarda apenas o endereço necessário para permitir um novo acesso voluntário.
$slugRetorno = Contexto::slug();
$urlEntrar = BASE_URL . '/login.php'
    . ($slugRetorno !== '' ? '?estabelecimento=' . rawurlencode($slugRetorno) : '');

encerrarSessao();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sessão encerrada | <?= e(NOME_SISTEMA) ?></title>
    <link rel="stylesheet" href="<?= e(BASE_URL . '/assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= e(BASE_URL . '/assets/css/login.css') ?>">
</head>
<body class="pagina-autenticacao">
<div class="autenticacao-area">
    <div class="autenticacao-caixa caixa-simples">
        <div class="autenticacao-formulario">
            <span class="marca">
                <span class="marca-simbolo">A</span>
                <?= e(NOME_SISTEMA) ?>
            </span>
            <h1 style="margin-top:24px">Sessão encerrada</h1>
            <p class="subtitulo">Você saiu da sua conta com segurança.</p>
            <a class="btn btn-bloco btn-grande" href="<?= e($urlEntrar) ?>">Entrar novamente</a>
        </div>
    </div>
</div>
</body>
</html>
