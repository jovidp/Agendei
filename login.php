<?php
/** Autentica a conta e encaminha o usuário para a área correspondente ao seu perfil. */

// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/config/config.php';

// Encaminha quem já está autenticado ao painel, evitando repetir o fluxo de acesso.
bloquearSeLogado();

$erros = [];
$email = '';

// Processa o formulário enviado antes de montar o HTML da página.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Confere o token da sessão antes de aceitar alterações enviadas pelo formulário.
    exigirCsrf();

    $email = mb_strtolower(post('email'));
    $senha = post('senha');

    if ($email === '' || !validarEmail($email)) {
        $erros[] = 'Informe um e-mail valido.';
    }
    if ($senha === '') {
        $erros[] = 'Informe sua senha.';
    }

    if ($erros === []) {
        // Delega a conferência do e-mail, da senha e do status da conta à autenticação compartilhada.
        $usuario = autenticar($email, $senha);

        if ($usuario === null) {
            $erros[] = 'E-mail ou senha incorretos.';
        } else {
            // Guarda a identidade autenticada antes de encaminhar ao destino permitido.
            registrarSessao($usuario);
            definirFlash('sucesso', 'Bem-vindo(a), ' . explode(' ', $usuario['nome'])[0] . '.');
            header('Location: ' . destinoAposLogin());
            exit;
        }
    }
}

$estabelecimento = Estabelecimento::dados();
// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina = 'Entrar | ' . $estabelecimento['nome'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($tituloPagina) ?></title>
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/login.css') ?>">
    <?php require RAIZ . '/includes/tema.php'; ?>
</head>
<body class="pagina-autenticacao">

<div class="autenticacao-area">
    <div class="autenticacao-caixa">
        <div class="autenticacao-apresentacao">
            <span class="marca">
                <?= Tema::marca($estabelecimento) ?>
                <?= e($estabelecimento['nome']) ?>
            </span>
            <h2>Agendamento sem complicacao</h2>
            <p>Acesse sua conta para agendar, acompanhar e gerenciar seus atendimentos.</p>

            <ul class="lista-beneficios">
                <li>Horarios atualizados em tempo real</li>
                <li>Historico completo dos atendimentos</li>
                <li>Cancelamento pelo proprio painel</li>
            </ul>
        </div>

        <div class="autenticacao-formulario">
            <a href="<?= url('index.php') ?>" class="voltar-site">&larr; Voltar ao site</a>

            <h1>Entrar</h1>
            <p class="subtitulo">Informe seus dados de acesso.</p>

            <?php exibirFlash(); ?>

            <?php if ($erros !== []): ?>
                <div class="alerta alerta-erro">
                    <span class="alerta-texto"><?= e($erros[0]) ?></span>
                </div>
            <?php endif; ?>

            <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" id="formLogin" novalidate>
                <?= campoCsrf() ?>

                <div class="campo">
                    <label for="email">E-mail</label>
                    <input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="email" required>
                    <span class="mensagem-campo"></span>
                </div>

                <div class="campo">
                    <label for="senha">Senha</label>
                    <input type="password" id="senha" name="senha" autocomplete="current-password" required>
                    <span class="mensagem-campo"></span>
                </div>

                <div class="linha-opcoes">
                    <span></span>
                    <a href="<?= url('recuperar_senha.php') ?>">Esqueci minha senha</a>
                </div>

                <button type="submit" class="btn btn-bloco btn-grande">Entrar</button>
            </form>

            <p class="autenticacao-rodape">
                Ainda nao tem conta? <a href="<?= url('cadastro.php') ?>">Criar conta</a>
            </p>
        </div>
    </div>
</div>

<div id="notificacoes"></div>
<script src="<?= url('assets/js/main.js') ?>"></script>
<script src="<?= url('assets/js/login.js') ?>"></script>
</body>
</html>
