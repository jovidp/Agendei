<?php
/**
 * Gera o token e o link de recuperação de senha e o envia por e-mail
 * (models/Email.php). Sem e-mail configurado, o link aparece na tela só em
 * desenvolvimento; em produção a tela avisa que o envio não está disponível.
 */

// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
// Pagina de entrada local: nunca roda sob a identidade master (ver config.php).
define('ENTRADA_LOCAL', true);
require_once __DIR__ . '/config/config.php';

// Encaminha quem já está autenticado ao painel, evitando repetir o fluxo de acesso.
bloquearSeLogado();

$enviado = false;
$linkGerado = null;
$erro = '';
$emailDisponivel = Email::configurado();

// Processa o formulário enviado antes de montar o HTML da página.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Confere o token da sessão antes de aceitar alterações enviadas pelo formulário.
    exigirCsrf();

    $email = mb_strtolower(post('email'));

    // Sem freio, este formulario serve para descobrir quais e-mails existem
    // (pelo tempo de resposta) e para inundar a caixa de quem tem conta.
    $bloqueio = conferirBloqueio(['recuperar_ip' => ipCliente()]);

    if ($bloqueio !== '') {
        $erro = $bloqueio;
    } elseif (!validarEmail($email)) {
        $erro = 'Informe um e-mail valido.';
    } else {
        // Toda solicitacao conta, tenha o e-mail cadastro ou nao: contar so os
        // acertos devolveria justamente a informacao que queremos esconder.
        anotarFalha('recuperar_ip', ipCliente());

        $usuario = Usuario::porEmail($email);

        if ($usuario && $usuario['status'] === 'ativo') {
            $token = Usuario::gerarTokenRecuperacao((int) $usuario['id_usuario']);
            $link  = urlAbsoluta('redefinir_senha.php?token=' . $token);

            $entregue = false;
            if ($emailDisponivel) {
                [$assunto, $texto, $html] = emailRecuperacaoSenha((string) $usuario['nome'], $link, (string) Estabelecimento::dados()['nome']);
                $entregue = Email::enviar($email, $assunto, $texto, $html, (string) $usuario['nome']);
            }

            // Em desenvolvimento o link aparece na tela quando não foi por e-mail.
            if (!$entregue && AMBIENTE === 'desenvolvimento') {
                $linkGerado = $link;
            }
        }


        // Mantém a mesma confirmação para e-mails existentes ou ausentes, evitando revelar cadastros.
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
    <?php require RAIZ . '/includes/tema.php'; ?>
</head>
<body class="pagina-autenticacao">

<div class="autenticacao-area">
    <div class="autenticacao-caixa caixa-simples">
        <div class="autenticacao-formulario">
            <div class="marca"><?= Tema::marca($estabelecimento) ?> <?= e($estabelecimento['nome']) ?></div>
            <a href="<?= url('login.php') ?>" class="voltar-site">&larr; Voltar para o login</a>

            <h1>Recuperar senha</h1>
            <p class="subtitulo">Informe o e-mail cadastrado para receber o link de redefinicao.</p>

            <?php if ($erro !== ''): ?>
                <div class="alerta alerta-erro"><span class="alerta-texto"><?= e($erro) ?></span></div>
            <?php endif; ?>

            <?php if ($enviado): ?>
                <?php if ($emailDisponivel || $linkGerado !== null): ?>
                    <div class="alerta alerta-info">
                        <span class="alerta-texto">
                            Se este e-mail estiver cadastrado, o link de redefinicao sera enviado em instantes. Confira tambem a caixa de spam.
                        </span>
                    </div>
                <?php else: ?>
                    <div class="alerta alerta-aviso">
                        <span class="alerta-texto">
                            O envio de e-mail nao esta disponivel neste momento. Fale com o estabelecimento para redefinir a sua senha.
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ($linkGerado !== null): ?>
                    <div class="credenciais-demo">
                        <strong>Ambiente de desenvolvimento</strong><br>
                        O link nao foi por e-mail. Use-o aqui:<br>
                        <a href="<?= e($linkGerado) ?>"><?= e($linkGerado) ?></a>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" novalidate>
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

<?php require RAIZ . '/includes/assinatura_sistema.php'; ?>

<div id="notificacoes"></div>
<script src="<?= url('assets/js/main.js') ?>"></script>
</body>
</html>
