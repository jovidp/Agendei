<?php
/**
 * Escolha do cadastro: cliente ou estabelecimento.
 *
 * O Agendei tem dois cadastros com objetivos diferentes, e a pagina inicial
 * so oferecia o da empresa. Esta tela mostra os dois lado a lado, com as
 * mesmas perguntas respondidas para cada um, e leva a pessoa ao certo:
 *
 *   - cliente: a conta e criada dentro de uma empresa (cadastro.php precisa
 *     do link dela). Sem o link, cadastro.php cairia na primeira empresa
 *     ativa, por isso a pessoa informa o link ou o endereco da empresa aqui;
 *   - estabelecimento: cadastro_empresa.php, que cria a empresa e a conta do
 *     responsavel e aguarda a aprovacao do master.
 *
 * Pagina do produto (PAGINA_PRODUTO), como o cadastro de empresa.
 */
define('ENTRADA_LOCAL', true);
define('PAGINA_PRODUTO', true);
require_once __DIR__ . '/config/config.php';

bloquearSeLogado();

/**
 * Extrai o endereco da empresa do que a pessoa colou: o link inteiro
 * (".../login.php?estabelecimento=studio") ou so o endereco ("studio").
 */
function enderecoDaEmpresa(string $informado): string
{
    $informado = trim($informado);
    if (preg_match('/[?&]estabelecimento=([^&#\s]+)/i', $informado, $achado)) {
        $informado = rawurldecode($achado[1]);
    }
    return mb_strtolower(trim($informado, " \t/"));
}

$empresaInformada = get('empresa');
$erroEmpresa = '';

if (isset($_GET['empresa']) && $empresaInformada === '') {
    $erroEmpresa = 'Informe o link ou o endereço da empresa onde você quer marcar.';
} elseif ($empresaInformada !== '') {
    $slug = enderecoDaEmpresa($empresaInformada);

    if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{1,78}[a-z0-9])$/D', $slug)) {
        $erroEmpresa = 'Informe o link que a empresa divulga ou só o endereço dela, como studio-vila-nova.';
    } else {
        $q = bd()->prepare('SELECT status FROM estabelecimento WHERE slug = ? LIMIT 1');
        $q->execute([$slug]);
        $status = $q->fetchColumn();

        if ($status === 'ativo') {
            redirecionar('cadastro.php?estabelecimento=' . rawurlencode($slug));
        }
        $erroEmpresa = $status === false
            ? 'Não encontramos uma empresa com esse endereço. Confira com ela o link de agendamento.'
            : 'Essa empresa ainda não está recebendo cadastros. Fale com ela para saber quando abre.';
    }
}

$tituloPagina = 'Criar conta | ' . NOME_SISTEMA;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Cadastro de cliente ou de estabelecimento no Agendei: veja a diferença e escolha o seu.">
    <title><?= e($tituloPagina) ?></title>
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/login.css') ?>">
    <?php require RAIZ . '/includes/tema.php'; ?>
</head>
<body class="pagina-autenticacao">

<div class="autenticacao-area">
    <div class="escolha-cadastro">
        <a href="<?= e(urlInicio()) ?>" class="voltar-site">&larr; Voltar ao início</a>

        <div class="escolha-cabecalho">
            <?= marcaSistema(32) ?>
            <h1>Qual cadastro é o seu?</h1>
            <p class="subtitulo">O Agendei tem dois cadastros. Um é de quem marca horário, o outro é de quem atende.</p>
        </div>

        <div class="escolha-grade">
            <section class="cartao-cadastro" id="cliente" aria-labelledby="titulo-cliente">
                <span class="cartao-cadastro-etiqueta">Cliente</span>
                <h2 id="titulo-cliente">Quero marcar horários</h2>

                <dl class="cartao-cadastro-dados">
                    <div><dt>Para quem</dt><dd>Quem vai ser atendido: corte, consulta, aula, sessão.</dd></div>
                    <div><dt>O que cria</dt><dd>A sua conta pessoal, ligada à empresa onde você vai marcar.</dd></div>
                    <div><dt>O que você informa</dt><dd>Nome, CPF, nascimento, e-mail, telefone, endereço, login e senha.</dd></div>
                    <div><dt>Quando libera</dt><dd>Na hora. Terminou o cadastro, é só entrar e agendar.</dd></div>
                    <div><dt>Depois</dt><dd>A mesma conta serve em qualquer empresa que use o Agendei.</dd></div>
                </dl>

                <form method="get" action="<?= url('criar_conta.php') ?>#cliente" class="cartao-cadastro-acao" novalidate data-sem-bloqueio>
                    <div class="campo">
                        <label for="empresa">Onde você quer marcar?</label>
                        <input type="text" id="empresa" name="empresa" value="<?= e($empresaInformada) ?>"
                               placeholder="Link ou endereço da empresa" maxlength="300" autocomplete="off"
                               <?= $erroEmpresa !== '' ? 'aria-invalid="true" aria-describedby="erro-empresa"' : '' ?> required>
                        <?php if ($erroEmpresa !== ''): ?>
                            <span class="mensagem-campo visivel" id="erro-empresa" role="alert"><?= e($erroEmpresa) ?></span>
                        <?php endif; ?>
                        <span class="ajuda-campo">A empresa divulga esse link. Se você abriu o link dela, use "Criar conta" na tela de entrada.</span>
                    </div>
                    <button type="submit" class="btn btn-bloco btn-grande">Criar conta de cliente</button>
                </form>
            </section>

            <section class="cartao-cadastro" id="estabelecimento" aria-labelledby="titulo-estabelecimento">
                <span class="cartao-cadastro-etiqueta">Estabelecimento</span>
                <h2 id="titulo-estabelecimento">Quero receber agendamentos</h2>

                <dl class="cartao-cadastro-dados">
                    <div><dt>Para quem</dt><dd>Dono, gerente ou profissional autônomo que atende clientes.</dd></div>
                    <div><dt>O que cria</dt><dd>A empresa no Agendei e a sua conta de administrador dela.</dd></div>
                    <div><dt>O que você informa</dt><dd>Nome da empresa, endereço do link, seu nome, e-mail, telefone e senha.</dd></div>
                    <div><dt>Quando libera</dt><dd>Depois que a equipe do Agendei confere o cadastro. O aviso chega por e-mail.</dd></div>
                    <div><dt>Depois</dt><dd>Você monta serviços, equipe e horários e divulga o link para os clientes.</dd></div>
                </dl>

                <div class="cartao-cadastro-acao">
                    <a href="<?= url('cadastro_empresa.php') ?>" class="btn btn-bloco btn-grande">Cadastrar minha empresa</a>
                </div>
            </section>
        </div>

        <p class="autenticacao-rodape">
            Já tem conta? <a href="<?= url('entrar.php') ?>">Entrar</a>
        </p>
    </div>
</div>

<?php require RAIZ . '/includes/assinatura_sistema.php'; ?>

<div id="notificacoes"></div>
<script src="<?= url('assets/js/main.js') ?>"></script>
</body>
</html>
