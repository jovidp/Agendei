<?php
/**
 * Tela de erro do sistema.
 * Recebe o codigo por ?codigo= e e tambem o destino das excecoes nao tratadas.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/config/config.php';

// Cada codigo tem titulo, explicacao e status HTTP proprios.
$catalogo = [
    'autenticacao' => [
        'status'    => 401,
        'titulo'    => 'Falha na autenticacao',
        'descricao' => 'Nao foi possivel confirmar sua identidade. Verifique o login e a senha e tente novamente.',
    ],
    'sessao' => [
        'status'    => 440,
        'titulo'    => 'Sessao expirada',
        'descricao' => 'Sua sessao ficou muito tempo aberta e foi encerrada por seguranca. Faca login novamente.',
    ],
    'permissao' => [
        'status'    => 403,
        'titulo'    => 'Acesso nao permitido',
        'descricao' => 'Seu perfil nao tem permissao para abrir esta tela.',
    ],
    'nao_encontrado' => [
        'status'    => 404,
        'titulo'    => 'Pagina nao encontrada',
        'descricao' => 'O endereco acessado nao existe ou foi movido.',
    ],
    'inesperado' => [
        'status'    => 500,
        'titulo'    => 'Algo inesperado aconteceu',
        'descricao' => 'O sistema encontrou um erro ao processar sua solicitacao. A equipe tecnica foi avisada pelo log.',
    ],
];

$codigo = get('codigo', 'inesperado');
$erro   = $catalogo[$codigo] ?? $catalogo['inesperado'];

// Detalhe tecnico gravado pelo tratador de excecoes; so aparece em desenvolvimento.
$detalhe = $_SESSION['erro_detalhe'] ?? '';
unset($_SESSION['erro_detalhe']);

http_response_code($erro['status']);

$estabelecimento = Estabelecimento::dados();
// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina = $erro['titulo'] . ' | ' . $estabelecimento['nome'];
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
    <div class="autenticacao-caixa caixa-simples">
        <div class="autenticacao-formulario">
            <span class="marca">
                <?= Tema::marca($estabelecimento) ?>
                <?= e($estabelecimento['nome']) ?>
            </span>

            <p class="erro-codigo"><?= (int) $erro['status'] ?></p>
            <h1><?= e($erro['titulo']) ?></h1>
            <p class="subtitulo"><?= e($erro['descricao']) ?></p>

            <?php exibirFlash(); ?>

            <?php if ($detalhe !== '' && AMBIENTE === 'desenvolvimento'): ?>
                <?php /* Visivel apenas em desenvolvimento para apoiar a correcao. */ ?>
                <pre class="erro-detalhe"><?= e($detalhe) ?></pre>
            <?php endif; ?>

            <div class="acoes-formulario">
                <?php if (estaLogado()): ?>
                    <a class="btn btn-bloco btn-grande" href="<?= url(painelDe(perfil())) ?>">Voltar ao painel</a>
                <?php else: ?>
                    <a class="btn btn-bloco btn-grande" href="<?= url('login.php') ?>">Ir para o login</a>
                <?php endif; ?>
                <a class="btn btn-contorno btn-bloco btn-grande" href="<?= url('index.php') ?>">Pagina inicial</a>
            </div>
        </div>
    </div>
</div>

<script src="<?= url('assets/js/main.js') ?>"></script>
</body>
</html>
