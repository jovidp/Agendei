<?php
/** Valida o token de recuperação e permite cadastrar uma nova senha. */

// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/config/config.php';

// Encaminha quem já está autenticado ao painel, evitando repetir o fluxo de acesso.
bloquearSeLogado();

// Recebe o token da URL na abertura e do campo oculto após o envio do formulário.
$token   = $_SERVER['REQUEST_METHOD'] === 'POST' ? post('token') : get('token');
$usuario = $token !== '' ? Usuario::porTokenRecuperacao($token) : null;
$erros   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $usuario !== null) {
    // Confere o token da sessão antes de aceitar alterações enviadas pelo formulário.
    exigirCsrf();

    $senha       = post('nova_senha');
    $confirmacao = post('confirmar_senha');

    if (!validarSenha($senha)) {
        $erros[] = 'A nova senha deve ter no minimo 6 caracteres.';
    }
    if ($senha !== $confirmacao) {
        $erros[] = 'As senhas nao conferem.';
    }

    if ($erros === []) {
        Usuario::atualizarSenha((int) $usuario['id_usuario'], $senha);
        // Consome o token após a troca de senha para que o link não possa ser usado novamente.
        Usuario::limparTokenRecuperacao((int) $usuario['id_usuario']);

        definirFlash('sucesso', 'Senha redefinida com sucesso. Faca login com a nova senha.');
        redirecionar('login.php');
    }
}

$estabelecimento = Estabelecimento::dados();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Redefinir senha | <?= e($estabelecimento['nome']) ?></title>
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/login.css') ?>">
    <?php require RAIZ . '/includes/tema.php'; ?>
</head>
<body class="pagina-autenticacao">

<div class="autenticacao-area">
    <div class="autenticacao-caixa caixa-simples">
        <div class="autenticacao-formulario">
            <div class="marca"><?= Tema::marca($estabelecimento) ?> <?= e($estabelecimento['nome']) ?></div>
            <a href="<?= url('login.php') ?>" class="voltar-site">&larr; Voltar para o login</a>

            <h1>Redefinir senha</h1>

            <?php if ($usuario === null): ?>
                <p class="subtitulo">O link utilizado e invalido ou expirou.</p>
                <div class="alerta alerta-erro">
                    <span class="alerta-texto">Solicite um novo link de recuperacao para continuar.</span>
                </div>
                <a href="<?= url('recuperar_senha.php') ?>" class="btn btn-bloco">Solicitar novo link</a>
            <?php else: ?>
                <p class="subtitulo">Defina a nova senha da conta <?= e($usuario['email']) ?>.</p>

                <?php if ($erros !== []): ?>
                    <div class="alerta alerta-erro"><span class="alerta-texto"><?= e($erros[0]) ?></span></div>
                <?php endif; ?>

                <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" id="formSenha" novalidate>
                    <?= campoCsrf() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">

                    <div class="campo">
                        <label for="nova_senha">Nova senha</label>
                        <input type="password" id="nova_senha" name="nova_senha" autocomplete="new-password" required>
                        <span class="mensagem-campo"></span>
                    </div>

                    <div class="campo">
                        <label for="confirmar_senha">Confirmar nova senha</label>
                        <input type="password" id="confirmar_senha" name="confirmar_senha" autocomplete="new-password" required>
                        <span class="mensagem-campo"></span>
                    </div>

                    <button type="submit" class="btn btn-bloco btn-grande">Salvar nova senha</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require RAIZ . '/includes/assinatura_sistema.php'; ?>

<div id="notificacoes"></div>
<script src="<?= url('assets/js/main.js') ?>"></script>
<script src="<?= url('assets/js/login.js') ?>"></script>
</body>
</html>
