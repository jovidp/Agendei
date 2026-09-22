<?php
/** Autentica a conta e encaminha o usuário para a área correspondente ao seu perfil. */

// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
// Pagina de entrada local: nunca roda sob a identidade master (ver config.php).
define('ENTRADA_LOCAL', true);
require_once __DIR__ . '/config/config.php';

// Encaminha quem já está autenticado ao painel, evitando repetir o fluxo de acesso.
bloquearSeLogado();

$erros = [];
$identificador = '';

// Um desafio de 2FA em andamento nao pode ficar preso a uma tentativa de login anterior.
cancelarSegundoFator();

// Processa o formulário enviado antes de montar o HTML da página.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Confere o token da sessão antes de aceitar alterações enviadas pelo formulário.
    exigirCsrf();

    $identificador = mb_strtolower(post('identificador'));
    $senha = post('senha');

    // A senha do usuario comum tem oito letras por exigencia da especificacao,
    // o que da um espaco de busca pequeno. O freio por conta e por origem e o
    // que impede transformar esse formulario num teste de dicionario.
    $bloqueio = conferirBloqueio([
        'login_ip'    => ipCliente(),
        'login_conta' => $identificador,
    ]);

    if ($bloqueio !== '') {
        $erros[] = $bloqueio;
    }

    if ($erros === [] && $identificador === '') {
        $erros[] = 'Informe seu login.';
    }
    if ($erros === [] && $senha === '') {
        $erros[] = 'Informe sua senha.';
    }

    if ($erros === []) {
        // Delega a conferência do login, da senha e do status da conta à autenticação compartilhada.
        $usuario = autenticar($identificador, $senha);

        if ($usuario === null) {
            // Cada falha conta nos dois baldes: o da conta alvo e o da origem.
            anotarFalha('login_conta', $identificador);
            anotarFalha('login_ip', ipCliente());
            atrasarResposta();

            $erros[] = 'Login ou senha incorretos.';
        } elseif (exigeSegundoFator($usuario)) {
            // Senha correta ja limpa o balde da conta: o segundo fator tem freio proprio.
            limparFalhas('login_conta', $identificador);
            // A sessao so e aberta depois do segundo fator: aqui fica apenas o desafio pendente.
            iniciarSegundoFator($usuario, $identificador);
            redirecionar('dois_fatores.php');
        } else {
            // Entrada concluida: a conta deixa de arrastar o historico de falhas.
            limparFalhas('login_conta', $identificador);

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
                    <label for="identificador">Login</label>
                    <input type="text" id="identificador" name="identificador" value="<?= e($identificador) ?>"
                           autocomplete="username" maxlength="150" required>
                    <span class="mensagem-campo"></span>
                    <span class="ajuda-campo">Use o login de 6 letras ou o e-mail cadastrado.</span>
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

                <div class="acoes-formulario">
                    <button type="submit" class="btn btn-bloco btn-grande">Entrar</button>
                    <button type="reset" class="btn btn-contorno btn-bloco btn-grande">Limpar</button>
                </div>
            </form>

            <p class="autenticacao-rodape">
                Ainda nao tem conta? <a href="<?= url('cadastro.php') ?>">Criar conta</a>
            </p>
            <p class="autenticacao-rodape">
                Sua conta e de outro estabelecimento? <a href="<?= BASE_URL ?>/entrar.php">Entrar pela pagina geral</a>
            </p>
        </div>
    </div>
</div>

<?php require RAIZ . '/includes/assinatura_sistema.php'; ?>

<div id="notificacoes"></div>
<script src="<?= url('assets/js/main.js') ?>"></script>
<script src="<?= url('assets/js/login.js') ?>"></script>
</body>
</html>
