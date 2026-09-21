<?php
/**
 * Segundo fator de autenticacao.
 * A sessao do usuario so e aberta aqui, depois que a pergunta sorteada e respondida.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/config/config.php';

// Encaminha quem já está autenticado ao painel, evitando repetir o fluxo de acesso.
bloquearSeLogado();

// Sem desafio pendente nao ha o que responder: o fluxo recomeca pelo login.
$pendente = segundoFatorPendente();
if ($pendente === null) {
    definirFlash('aviso', 'Faca login para continuar.');
    redirecionar('login.php');
}

$usuario = Usuario::porId((int) $pendente['usuario_id']);
if ($usuario === null || $usuario['status'] !== 'ativo') {
    cancelarSegundoFator();
    definirFlash('erro', 'Nao foi possivel continuar a autenticacao. Faca login novamente.');
    redirecionar('login.php');
}

$fator = (string) $pendente['fator'];

// Enunciados definidos na especificacao do projeto.
$pergunta = match ($fator) {
    'nome_materno'    => 'Qual o nome da sua mae?',
    'data_nascimento' => 'Qual a data do seu nascimento?',
    'cep'             => 'Qual o CEP do seu endereco?',
    default           => 'Confirme seus dados cadastrais.',
};

$erros = [];

// Processa o formulário enviado antes de montar o HTML da página.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Confere o token da sessão antes de aceitar alterações enviadas pelo formulário.
    exigirCsrf();

    $resposta = post('resposta');

    if ($resposta === '') {
        $erros[] = 'Informe a resposta.';
    } elseif (respostaSegundoFatorConfere($fator, $resposta, $usuario)) {
        LogAutenticacao::registrar('2fa_sucesso', (string) $pendente['identificador'], $usuario, $fator, cpfDoUsuario($usuario));
        cancelarSegundoFator();

        // Identidade confirmada nos dois fatores: agora a sessao pode ser aberta.
        registrarSessao($usuario);
        definirFlash('sucesso', 'Bem-vindo(a), ' . explode(' ', $usuario['nome'])[0] . '.');
        header('Location: ' . destinoAposLogin());
        exit;
    } else {
        $_SESSION['segundo_fator']['tentativas'] = (int) $pendente['tentativas'] + 1;
        LogAutenticacao::registrar('2fa_falha', (string) $pendente['identificador'], $usuario, $fator, cpfDoUsuario($usuario));

        // A especificacao encerra o fluxo na terceira tentativa sem exito.
        if (tentativasRestantesSegundoFator() === 0) {
            LogAutenticacao::registrar('2fa_bloqueio', (string) $pendente['identificador'], $usuario, $fator, cpfDoUsuario($usuario));
            cancelarSegundoFator();
            definirFlash('erro', '3 tentativas sem sucesso! Favor realizar Login novamente.');
            redirecionar('login.php');
        }

        $restantes = tentativasRestantesSegundoFator();
        $erros[] = 'Resposta incorreta. Voce ainda tem ' . $restantes . ($restantes === 1 ? ' tentativa.' : ' tentativas.');
    }
}

$estabelecimento = Estabelecimento::dados();
// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina = 'Verificacao em duas etapas | ' . $estabelecimento['nome'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
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
            <h2>Mais uma confirmacao</h2>
            <p>Para proteger sua conta, confirmamos um dado que so voce informou no cadastro.</p>

            <ul class="lista-beneficios">
                <li>A pergunta muda a cada acesso</li>
                <li>Nenhum codigo por e-mail ou SMS</li>
                <li>Sua sessao so abre apos a confirmacao</li>
            </ul>
        </div>

        <div class="autenticacao-formulario">
            <a href="<?= url('login.php') ?>" class="voltar-site">&larr; Voltar ao login</a>

            <h1>Verificacao em duas etapas</h1>
            <p class="subtitulo">Ola, <?= e(explode(' ', $usuario['nome'])[0]) ?>. Responda para concluir a entrada.</p>

            <?php if ($erros !== []): ?>
                <div class="alerta alerta-erro">
                    <span class="alerta-texto"><?= e($erros[0]) ?></span>
                </div>
            <?php endif; ?>

            <?php /* A resposta e conferida no servidor contra o dado gravado no cadastro. */ ?><form method="post" id="formDoisFatores" novalidate>
                <?= campoCsrf() ?>

                <div class="campo">
                    <label for="resposta"><?= e($pergunta) ?></label>
                    <?php if ($fator === 'cep'): ?>
                        <input type="text" id="resposta" name="resposta" data-mascara="cep"
                               inputmode="numeric" placeholder="00000-000" autocomplete="off" required autofocus>
                    <?php elseif ($fator === 'data_nascimento'): ?>
                        <input type="text" id="resposta" name="resposta"
                               inputmode="numeric" placeholder="dd/mm/aaaa" autocomplete="off" required autofocus>
                    <?php else: ?>
                        <input type="text" id="resposta" name="resposta" maxlength="120"
                               autocomplete="off" required autofocus>
                    <?php endif; ?>
                    <span class="mensagem-campo"></span>
                    <span class="ajuda-campo">
                        Tentativas restantes: <?= (int) tentativasRestantesSegundoFator() ?> de 3.
                    </span>
                </div>

                <button type="submit" class="btn btn-bloco btn-grande">Confirmar</button>
            </form>

            <p class="autenticacao-rodape">
                Nao reconhece esta pergunta? <a href="<?= url('logout.php') ?>">Cancelar e sair</a>
            </p>
        </div>
    </div>
</div>

<div id="notificacoes"></div>
<script src="<?= url('assets/js/main.js') ?>"></script>
</body>
</html>
