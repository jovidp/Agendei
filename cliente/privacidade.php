<?php

/**
 * Direitos do titular: cópia dos dados e encerramento com anonimização.
 */
require_once __DIR__ . '/../config/config.php';
exigirLogin('cliente');

$idCliente = (int) perfilId();
$idUsuario = (int) usuarioId();
$cliente = Cliente::porId($idCliente);
$erros = [];

if (get('exportar') === '1') {
    $dados = Diferencial::exportarCliente($idCliente);
    unset($dados['cliente']['senha_hash'], $dados['cliente']['token_recuperacao'], $dados['cliente']['token_expiracao']);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="meus-dados-agendei-' . date('Y-m-d') . '.json"');
    echo json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    if (!Usuario::senhaConfere($idUsuario, post('senha'))) {
        $erros[] = 'A senha informada está incorreta.';
    } elseif (post('confirmacao') !== 'ENCERRAR') {
        $erros[] = 'Digite ENCERRAR para confirmar.';
    } else {
        Diferencial::anonimizarCliente($idCliente, $idUsuario);
        encerrarSessao();
        header('Location: ' . BASE_URL . '/logout.php?conta=encerrada');
        exit;
    }
}

$tituloPagina = 'Privacidade';
$subtituloTopo = 'Controle dos seus dados pessoais';
require RAIZ . '/includes/painel_header.php';
?>
<?php if ($erros): ?><div class="alerta alerta-erro">
        <ul><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul>
    </div><?php endif; ?>
<div class="grade-painel-igual">
    <div class="cartao">
        <div class="cartao-cabecalho">
            <h3>Baixar meus dados</h3>
        </div>
        <div class="cartao-corpo">
            <p>Receba uma cópia em JSON dos seus dados cadastrais, agendamentos, pontos, pacotes, avaliações e lista de espera.</p><a class="btn" href="<?= url('cliente/privacidade.php?exportar=1') ?>">Exportar meus dados</a>
        </div>
    </div>
    <div class="cartao">
        <div class="cartao-cabecalho">
            <h3>Encerrar minha conta</h3>
        </div>
        <div class="cartao-corpo">
            <p>Seus próximos agendamentos serão cancelados e os dados de identificação serão anonimizados. O histórico financeiro do estabelecimento será preservado sem identificar você.</p>
            <form method="post"><?= campoCsrf() ?><div class="campo"><label for="confirmacao">Digite ENCERRAR</label><input id="confirmacao" name="confirmacao" required autocomplete="off"></div>
                <div class="campo"><label for="senha">Senha atual</label><input type="password" id="senha" name="senha" required autocomplete="current-password"></div><button class="btn btn-perigo" type="submit" data-confirmar="Esta ação encerra permanentemente sua conta. Continuar?">Encerrar conta</button>
            </form>
        </div>
    </div>
</div>
<?php require RAIZ . '/includes/painel_footer.php'; ?>