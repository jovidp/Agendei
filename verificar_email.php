<?php
/**
 * Confirmacao do e-mail da conta logada: digita o codigo que chegou depois
 * do cadastro ou pede outro (models/VerificacaoEmail.php).
 */
require_once __DIR__ . '/config/config.php';

if (!estaLogado() || ehMaster()) redirecionar('entrar.php');

$idUsuario = (int) $_SESSION['usuario_id'];
$email = (string) ($_SESSION['usuario_email'] ?? '');
$nome = (string) ($_SESSION['usuario_nome'] ?? '');

if (VerificacaoEmail::confirmado($idUsuario)) {
    definirFlash('sucesso', 'Seu e-mail já está confirmado.');
    redirecionar(painelDe(perfil()));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    if (post('acao') === 'reenviar') {
        $erro = VerificacaoEmail::reenviar($idUsuario, $email, $nome);
        definirFlash($erro === '' ? 'sucesso' : 'erro', $erro === '' ? 'Enviamos um novo código para o seu e-mail.' : $erro);
        redirecionar('verificar_email.php');
    }
    $erro = VerificacaoEmail::conferir($idUsuario, $email, (string) post('codigo'));
    if ($erro === '') {
        definirFlash('sucesso', 'E-mail confirmado. Obrigado!');
        redirecionar(painelDe(perfil()));
    }
    definirFlash('erro', $erro);
    redirecionar('verificar_email.php');
}

// Entrou em outro aparelho, ou o codigo venceu: ja manda um novo (com o mesmo intervalo do botao).
if (!VerificacaoEmail::codigoAtivo($idUsuario) && Email::configurado()) {
    VerificacaoEmail::reenviar($idUsuario, $email, $nome);
}

$tituloPagina = 'Confirmar e-mail';
$ocultarAvisoEmail = true;
require RAIZ . '/includes/painel_header.php';
?>
<div class="cartao cartao-estreito">
    <div class="cartao-corpo codigo-email">
        <div class="codigo-email-icone" aria-hidden="true">&#9993;</div>
        <h2>Confirme seu e-mail</h2>
        <p>Enviamos um código de 6 dígitos para <strong><?= e(VerificacaoEmail::mascarar($email)) ?></strong>. Ele vale por <?= VerificacaoEmail::VALIDADE_MINUTOS ?> minutos.</p>

        <form method="post">
            <?= campoCsrf() ?>
            <div class="campo">
                <label for="codigo">Código de confirmação</label>
                <input type="text" id="codigo" name="codigo" class="codigo-email-campo" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7"
                       autocomplete="one-time-code" placeholder="000000" required autofocus>
            </div>
            <button type="submit" class="btn btn-bloco btn-grande">Confirmar e-mail</button>
        </form>

        <p class="ajuda-campo codigo-email-dica">Não chegou? Olhe a caixa de spam ou de promoções.</p>
        <form method="post" class="codigo-email-acoes">
            <?= campoCsrf() ?>
            <button type="submit" name="acao" value="reenviar" class="btn btn-contorno" formnovalidate>Reenviar código</button>
        </form>
    </div>
</div>
<?php require RAIZ . '/includes/painel_footer.php'; ?>
