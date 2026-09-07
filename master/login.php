<?php
/** Entrada exclusiva do administrador global. */
define('AREA_MASTER', true);
require_once __DIR__ . '/../config/config.php';
bloquearSeLogado();
$erros = [];
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $email = mb_strtolower(post('email'));
    $senha = post('senha');
    if (!validarEmail($email) || $senha === '') {
        $erros[] = 'Informe o e-mail e a senha da conta master.';
    } else {
        $master = Master::autenticar($email, $senha);
        if (!$master) $erros[] = 'E-mail ou senha incorretos.';
        else {
            registrarSessaoMaster($master);
            definirFlash('sucesso', 'Bem-vindo(a) à administração master.');
            redirecionar('master/dashboard.php');
        }
    }
}
$estabelecimento = Contexto::dados();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Administração master | <?= e($estabelecimento['nome']) ?></title>
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/login.css') ?>">
    <?php require RAIZ . '/includes/tema.php'; ?>
</head>
<body class="pagina-autenticacao">
<div class="autenticacao-area">
    <div class="autenticacao-caixa caixa-simples">
        <div class="autenticacao-formulario">
            <div class="marca"><?= Tema::marca($estabelecimento) ?> <?= e($estabelecimento['nome']) ?></div>
            <h1>Administração master</h1>
            <p class="subtitulo">Acesso global para gerenciar os estabelecimentos da plataforma.</p>
            <?php exibirFlash(); ?>
            <?php if ($erros): ?><div class="alerta alerta-erro" role="alert"><?= e($erros[0]) ?></div><?php endif; ?>
            <form method="post" id="formLogin" novalidate>
                <?= campoCsrf() ?>
                <div class="campo"><label for="email">E-mail</label><input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="email" required><span class="mensagem-campo"></span></div>
                <div class="campo"><label for="senha">Senha</label><input type="password" id="senha" name="senha" autocomplete="current-password" required><span class="mensagem-campo"></span></div>
                <button type="submit" class="btn btn-bloco btn-grande">Entrar como master</button>
            </form>
            <p class="autenticacao-rodape"><a href="<?= e(BASE_URL . '/index.php') ?>">Voltar ao sistema</a></p>
        </div>
    </div>
</div>
<script src="<?= url('assets/js/main.js') ?>"></script>
<script src="<?= url('assets/js/login.js') ?>"></script>
</body>
</html>
