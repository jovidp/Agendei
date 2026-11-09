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

// Codigo que chegou junto com o link: certo, leva direto para a troca de senha.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('acao'), ['codigo', 'outro'], true)) {
    exigirCsrf();
    if (post('acao') === 'outro') {
        VerificacaoEmail::cancelarRecuperacao();
        redirecionar('recuperar_senha.php');
    }
    $resultado = VerificacaoEmail::conferirRecuperacao((string) post('codigo'));
    if (isset($resultado['token']) && $resultado['token'] !== '') {
        redirecionar('redefinir_senha.php?token=' . rawurlencode($resultado['token']));
    }
    definirFlash('erro', $resultado['erro'] ?? 'Código incorreto.');
    redirecionar('recuperar_senha.php');
}

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
        $erro = 'Informe um e-mail válido.';
    } else {
        // Toda solicitacao conta, tenha o e-mail cadastro ou nao: contar so os
        // acertos devolveria justamente a informacao que queremos esconder.
        anotarFalha('recuperar_ip', ipCliente());

        $usuario = Usuario::porEmail($email);
        $ativo = $usuario && $usuario['status'] === 'ativo';
        // Com ou sem conta a tela pede o codigo; sem conta ele nunca confere.
        $token = $ativo ? Usuario::gerarTokenRecuperacao((int) $usuario['id_usuario']) : null;
        $codigo = VerificacaoEmail::codigoRecuperacao($ativo ? (int) $usuario['id_usuario'] : null, $email, $token);

        if ($ativo) {
            $link  = urlAbsoluta('redefinir_senha.php?token=' . $token);

            $entregue = false;
            if ($emailDisponivel) {
                [$assunto, $texto, $html] = emailRecuperacaoSenha((string) $usuario['nome'], $link, (string) Estabelecimento::dados()['nome'], $codigo);
                $entregue = Email::enviar($email, $assunto, $texto, $html, (string) $usuario['nome']);
            }

            // Em desenvolvimento o link aparece na tela quando não foi por e-mail.
            if (!$entregue && AMBIENTE === 'desenvolvimento') {
                $linkGerado = $link;
            }
        }


        // Mantém a mesma confirmação para e-mails existentes ou ausentes, evitando revelar cadastros.
        $enviado = true;
        if ($emailDisponivel) {
            redirecionar('recuperar_senha.php');
        }
    }
}

$estabelecimento = Estabelecimento::dados();
$emailPendente = $emailDisponivel ? VerificacaoEmail::recuperacaoPendente() : null;
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

            <?php if ($emailPendente !== null && !$enviado): ?>
                <div class="codigo-email">
                    <div class="codigo-email-icone" aria-hidden="true">&#128273;</div>
                    <h1>Digite o código</h1>
                    <p class="subtitulo">Se <strong><?= e(VerificacaoEmail::mascarar($emailPendente)) ?></strong> tiver conta aqui, enviamos um código de 6 dígitos e um link. Use o que for mais fácil.</p>
                    <?php exibirFlash(); ?>
                    <form method="post">
                        <?= campoCsrf() ?>
                        <div class="campo">
                            <label for="codigo">Código recebido por e-mail</label>
                            <input type="text" id="codigo" name="codigo" class="codigo-email-campo" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7"
                                   autocomplete="one-time-code" placeholder="000000" required autofocus>
                        </div>
                        <button type="submit" name="acao" value="codigo" class="btn btn-bloco btn-grande">Continuar</button>
                    </form>
                    <p class="ajuda-campo codigo-email-dica">Não chegou? Olhe a caixa de spam. O código vale por <?= VerificacaoEmail::VALIDADE_MINUTOS ?> minutos.</p>
                    <form method="post" class="codigo-email-acoes">
                        <?= campoCsrf() ?>
                        <button type="submit" name="acao" value="outro" class="btn btn-contorno" formnovalidate>Pedir de novo ou usar outro e-mail</button>
                    </form>
                </div>
            <?php else: ?>
            <h1>Recuperar senha</h1>
            <p class="subtitulo">Informe o e-mail cadastrado para receber o código e o link de redefinição.</p>

            <?php if ($erro !== ''): ?>
                <div class="alerta alerta-erro"><span class="alerta-texto"><?= e($erro) ?></span></div>
            <?php endif; ?>

            <?php if ($enviado): ?>
                <?php if ($emailDisponivel || $linkGerado !== null): ?>
                    <div class="alerta alerta-info">
                        <span class="alerta-texto">
                            Se este e-mail estiver cadastrado, o link de redefinição será enviado em instantes. Confira também a caixa de spam.
                        </span>
                    </div>
                <?php else: ?>
                    <div class="alerta alerta-aviso">
                        <span class="alerta-texto">
                            O envio de e-mail não está disponível neste momento. Fale com o estabelecimento para redefinir a sua senha.
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ($linkGerado !== null): ?>
                    <div class="credenciais-demo">
                        <strong>Ambiente de desenvolvimento</strong><br>
                        O link não foi por e-mail. Use-o aqui:<br>
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

                    <button type="submit" class="btn btn-bloco btn-grande">Enviar código de recuperação</button>
                </form>
            <?php endif; ?>

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
