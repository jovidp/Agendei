<?php
/**
 * Solicitacao de recuperacao de senha.
 *
 * O token e gravado no banco e o link de redefinicao e gerado aqui.
 * O envio por e-mail/WhatsApp deve ser plugado no ponto indicado abaixo,
 * sem alterar o restante do fluxo.
 */
require_once __DIR__ . '/config/config.php';

bloquearSeLogado();

$enviado = false;
$linkGerado = null;
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();

    $email = mb_strtolower(post('email'));

    if (!validarEmail($email)) {
        $erro = 'Informe um e-mail valido.';
    } else {
        $usuario = Usuario::porEmail($email);

        if ($usuario && $usuario['status'] === 'ativo') {
            $token = Usuario::gerarTokenRecuperacao((int) $usuario['id_usuario']);
            $link  = url('redefinir_senha.php?token=' . $token);

            // Ponto de integracao: envio do link por e-mail ou WhatsApp.
            if (AMBIENTE === 'desenvolvimento') {
                $linkGerado = $link;
            }
        }

        // Mensagem sempre igual, para nao revelar quais e-mails existem.
        $enviado = true;
    }
}

$estabelecimento = Estabelecimento::dados();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recuperar senha | <?= e($estabelecimento['nome']) ?></title>
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/login.css') ?>">
</head>
<body class="pagina-autenticacao">

<div class="autenticacao-area">
    <div class="autenticacao-caixa caixa-simples">
        <div class="autenticacao-formulario">
            <a href="<?= url('login.php') ?>" class="voltar-site">&larr; Voltar para o login</a>

            <h1>Recuperar senha</h1>
            <p class="subtitulo">Informe o e-mail cadastrado para receber o link de redefinicao.</p>

            <?php if ($erro !== ''): ?>
                <div class="alerta alerta-erro"><span class="alerta-texto"><?= e($erro) ?></span></div>
            <?php endif; ?>

            <?php if ($enviado): ?>
                <div class="alerta alerta-info">
                    <span class="alerta-texto">
                        Se este e-mail estiver cadastrado, o link de redefinicao sera enviado em instantes.
                    </span>
                </div>

                <?php if ($linkGerado !== null): ?>
                    <div class="credenciais-demo">
                        <strong>Ambiente de desenvolvimento</strong><br>
                        O envio por e-mail ainda nao esta configurado. Use o link abaixo:<br>
                        <a href="<?= e($linkGerado) ?>"><?= e($linkGerado) ?></a>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <form method="post" novalidate>
                    <?= campoCsrf() ?>

                    <div class="campo">
                        <label for="email">E-mail cadastrado</label>
                        <input type="email" id="email" name="email" autocomplete="email" required>
                        <span class="mensagem-campo"></span>
                    </div>

                    <button type="submit" class="btn btn-bloco btn-grande">Enviar link de recuperacao</button>
                </form>
            <?php endif; ?>

            <p class="autenticacao-rodape">
                Lembrou a senha? <a href="<?= url('login.php') ?>">Entrar</a>
            </p>
        </div>
    </div>
</div>

<div id="notificacoes"></div>
<script src="<?= url('assets/js/main.js') ?>"></script>
</body>
</html>
