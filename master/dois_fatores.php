<?php

/**
 * Segundo fator da conta master.
 * A sessao global so e aberta aqui, depois que o codigo do aplicativo confere.
 */
define('AREA_MASTER', true);
require_once __DIR__ . '/../config/config.php';

bloquearSeLogado('master');

// Sem desafio pendente nao ha o que responder: o fluxo recomeca pelo login.
$pendente = segundoFatorMasterPendente();
if ($pendente === null) {
    definirFlash('aviso', 'Faça login para continuar.');
    redirecionar('master/login.php');
}

$master = Master::porId((int) $pendente['master_id']);
if ($master === null || $master['status'] !== 'ativo' || !Master::totpAtivo($master)) {
    cancelarSegundoFatorMaster();
    definirFlash('erro', 'Não foi possível continuar a autenticação. Faça login novamente.');
    redirecionar('master/login.php');
}

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();

    $codigo = post('codigo');

    // O mesmo freio por origem do login master vale aqui.
    $bloqueio = conferirBloqueio(['master_ip' => ipCliente()]);

    if ($bloqueio !== '') {
        $erros[] = $bloqueio;
    } elseif ($codigo === '') {
        $erros[] = 'Informe o código do aplicativo.';
    } elseif (Master::totpConfere($master, $codigo)) {
        cancelarSegundoFatorMaster();
        limparFalhas('master_ip', ipCliente());

        // Identidade confirmada nos dois fatores: agora a sessao pode abrir.
        registrarSessaoMaster($master);
        definirFlash('sucesso', 'Bem-vindo(a) à administração master.');
        redirecionar('master/dashboard.php');
    } else {
        $_SESSION['segundo_fator_master']['tentativas'] = (int) $pendente['tentativas'] + 1;
        anotarFalha('master_ip', ipCliente());
        atrasarResposta();
        registrarEventoSeguranca('master_2fa_falha', ['master' => (int) $master['id_master']]);

        // Mesma regra dos demais perfis: tres tentativas e volta ao login.
        if (tentativasRestantesSegundoFatorMaster() === 0) {
            cancelarSegundoFatorMaster();
            definirFlash('erro', '3 tentativas sem sucesso! Favor realizar Login novamente.');
            redirecionar('master/login.php');
        }

        $restantes = tentativasRestantesSegundoFatorMaster();
        $erros[] = 'Código incorreto ou já utilizado. Você ainda tem ' . $restantes
            . ($restantes === 1 ? ' tentativa.' : ' tentativas.');
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
    <title>Verificação em duas etapas | <?= e($estabelecimento['nome']) ?></title>
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/login.css') ?>">
    <?php require RAIZ . '/includes/tema.php'; ?>
</head>
<body class="pagina-autenticacao">
<div class="autenticacao-area">
    <div class="autenticacao-caixa caixa-simples">
        <div class="autenticacao-formulario">
            <div class="marca"><?= Tema::marca($estabelecimento) ?> <?= e($estabelecimento['nome']) ?></div>

            <h1>Verificação em duas etapas</h1>
            <p class="subtitulo">
                Olá, <?= e(explode(' ', (string) $master['nome'])[0]) ?>.
                Informe o código do aplicativo autenticador para abrir a administração master.
            </p>

            <?php if ($erros !== []): ?>
                <div class="alerta alerta-erro" role="alert"><span class="alerta-texto"><?= e($erros[0]) ?></span></div>
            <?php endif; ?>

            <?php /* O codigo e conferido no servidor contra o segredo cifrado da conta. */ ?><form method="post" novalidate>
                <?= campoCsrf() ?>

                <div class="campo">
                    <label for="codigo">Código de seis dígitos</label>
                    <input type="text" id="codigo" name="codigo" class="campo-codigo"
                           inputmode="numeric" pattern="[0-9 ]*" maxlength="7" placeholder="000000"
                           autocomplete="one-time-code" required autofocus>
                    <span class="mensagem-campo"></span>
                    <span class="ajuda-campo">
                        Tentativas restantes: <?= (int) tentativasRestantesSegundoFatorMaster() ?> de 3.
                    </span>
                </div>

                <button type="submit" class="btn btn-bloco btn-grande">Confirmar</button>
            </form>

            <p class="autenticacao-rodape">
                <a href="<?= e(BASE_URL . '/master/login.php') ?>">Voltar ao login</a>
            </p>
        </div>
    </div>
</div>
<script src="<?= url('assets/js/main.js') ?>"></script>
</body>
</html>
