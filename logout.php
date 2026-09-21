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

// Registra a saida no log de autenticacao antes de perder os dados da sessao.
if (estaLogado() && usuarioId() !== null) {
    $usuarioSaindo = Usuario::porId((int) usuarioId());
    if ($usuarioSaindo !== null) {
        LogAutenticacao::registrar('logout', (string) ($usuarioSaindo['login'] ?? $usuarioSaindo['email']), $usuarioSaindo, null, cpfDoUsuario($usuarioSaindo));
    }
}

// Sair pelo menu durante uma simulacao encerra tudo de uma vez, e a auditoria
// precisa receber o fim aqui tambem: senao o master entra no painel de um
// cliente, sai por este caminho e a trilha fica aberta.
//
// A simulacao e desfeita antes de registrar, para que o evento saia no nome da
// conta master que a abriu, e nao no do administrador que estava na tela.
if (ehSimulacao()) {
    $empresaSimulada = Contexto::dados()['nome'];
    $contaSimulada = (string) ($_SESSION['usuario_email'] ?? '');
    $idEmpresaSimulada = (int) ($_SESSION['estabelecimento_id'] ?? 0);

    if (encerrarSimulacao() !== null) {
        LogMaster::registrar('simulacao_fim', [
            'estabelecimento'      => $idEmpresaSimulada,
            'estabelecimento_nome' => (string) $empresaSimulada,
            'alvo'                 => $contaSimulada,
            'detalhe'              => 'encerrada pelo logout do painel',
        ]);
    }
}

// Descarta tambem um desafio de 2FA que tenha ficado pendente.
cancelarSegundoFator();
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
    <?= faviconSistema() ?>
</head>
<body class="pagina-autenticacao">
<div class="autenticacao-area">
    <div class="autenticacao-caixa caixa-simples">
        <div class="autenticacao-formulario">
            <?= marcaSistema(34) ?>
            <h1 class="titulo-apos-marca">Sessão encerrada</h1>
            <p class="subtitulo">Você saiu da sua conta com segurança.</p>
            <a class="btn btn-bloco btn-grande" href="<?= e($urlEntrar) ?>">Entrar novamente</a>
        </div>
    </div>
</div>
</body>
</html>
