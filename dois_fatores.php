<?php
/**
 * Segundo fator de autenticacao.
 * A sessao do usuario so e aberta aqui, depois que a pergunta sorteada e respondida.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
// Pagina de entrada local: nunca roda sob a identidade master (ver config.php).
define('ENTRADA_LOCAL', true);
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
$ehCodigo = segundoFatorEhCodigo($fator);

// Enunciados: o codigo do aplicativo e o caminho padrao; as perguntas
// cadastrais, previstas na especificacao, atendem quem ainda nao cadastrou o
// aplicativo autenticador.
$pergunta = match ($fator) {
    'totp'            => 'Digite o codigo do seu aplicativo autenticador',
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

    // O desafio ja se encerra em tres erros, mas nada impedia recomecar o login
    // e sortear outra pergunta sem limite. O freio por origem fecha esse laco.
    $bloqueio = conferirBloqueio(['fator_ip' => ipCliente()]);

    if ($bloqueio !== '') {
        $erros[] = $bloqueio;
    } elseif ($resposta === '') {
        $erros[] = $ehCodigo ? 'Informe o codigo do aplicativo.' : 'Informe a resposta.';
    } elseif (conferirSegundoFator($fator, $resposta, $usuario)) {
        LogAutenticacao::registrar('2fa_sucesso', (string) $pendente['identificador'], $usuario, $fator, cpfDoUsuario($usuario));
        cancelarSegundoFator();

        // Identidade confirmada nos dois fatores: agora a sessao pode ser aberta.
        registrarSessao($usuario);
        lembrarSeSolicitado($usuario);
        definirFlash('sucesso', 'Bem-vindo(a), ' . explode(' ', $usuario['nome'])[0] . '.');
        header('Location: ' . destinoAposLogin());
        exit;
    } else {
        $_SESSION['segundo_fator']['tentativas'] = (int) $pendente['tentativas'] + 1;
        anotarFalha('fator_ip', ipCliente());
        atrasarResposta();
        LogAutenticacao::registrar('2fa_falha', (string) $pendente['identificador'], $usuario, $fator, cpfDoUsuario($usuario));

        // A especificacao encerra o fluxo na terceira tentativa sem exito.
        if (tentativasRestantesSegundoFator() === 0) {
            LogAutenticacao::registrar('2fa_bloqueio', (string) $pendente['identificador'], $usuario, $fator, cpfDoUsuario($usuario));
            cancelarSegundoFator();
            definirFlash('erro', '3 tentativas sem sucesso! Favor realizar Login novamente.');
            redirecionar('login.php');
        }

        $restantes = tentativasRestantesSegundoFator();
        $erros[] = ($ehCodigo ? 'Codigo incorreto ou ja utilizado.' : 'Resposta incorreta.')
            . ' Voce ainda tem ' . $restantes . ($restantes === 1 ? ' tentativa.' : ' tentativas.');
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
            <?php if ($ehCodigo): ?>
                <h2>Confirme com seu aplicativo</h2>
                <p>Abra o aplicativo autenticador no seu celular e digite o codigo que aparece para o Agendei.</p>

                <ul class="lista-beneficios">
                    <li>O codigo muda a cada 30 segundos</li>
                    <li>Funciona sem internet no celular</li>
                    <li>Cada codigo vale uma unica vez</li>
                </ul>
            <?php else: ?>
                <h2>Mais uma confirmacao</h2>
                <p>Para proteger sua conta, confirmamos um dado que so voce informou no cadastro.</p>

                <ul class="lista-beneficios">
                    <li>A pergunta muda a cada acesso</li>
                    <li>Nenhum codigo por e-mail ou SMS</li>
                    <li>Sua sessao so abre apos a confirmacao</li>
                </ul>
            <?php endif; ?>
        </div>

        <div class="autenticacao-formulario">
            <a href="<?= url('login.php') ?>" class="voltar-site">&larr; Voltar ao login</a>

            <h1>Verificacao em duas etapas</h1>
            <p class="subtitulo">
                Ola, <?= e(explode(' ', $usuario['nome'])[0]) ?>.
                <?= $ehCodigo ? 'Informe o codigo para concluir a entrada.' : 'Responda para concluir a entrada.' ?>
            </p>

            <?php if ($erros !== []): ?>
                <div class="alerta alerta-erro">
                    <span class="alerta-texto"><?= e($erros[0]) ?></span>
                </div>
            <?php endif; ?>

            <?php /* A resposta e conferida no servidor contra o dado gravado no cadastro. */ ?><form method="post" id="formDoisFatores" novalidate>
                <?= campoCsrf() ?>

                <div class="campo">
                    <label for="resposta"><?= e($pergunta) ?></label>
                    <?php if ($ehCodigo): ?>
                        <?php /* autocomplete one-time-code deixa o celular oferecer o codigo direto do teclado. */ ?>
                        <input type="text" id="resposta" name="resposta" class="campo-codigo"
                               inputmode="numeric" pattern="[0-9 ]*" maxlength="7" placeholder="000000"
                               autocomplete="one-time-code" required autofocus>
                    <?php elseif ($fator === 'cep'): ?>
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
                        <?php if ($ehCodigo): ?>
                            Seis digitos, sem espacos. Tentativas restantes:
                            <?= (int) tentativasRestantesSegundoFator() ?> de 3.
                        <?php else: ?>
                            Tentativas restantes: <?= (int) tentativasRestantesSegundoFator() ?> de 3.
                        <?php endif; ?>
                    </span>
                </div>

                <button type="submit" class="btn btn-bloco btn-grande">Confirmar</button>
            </form>

            <p class="autenticacao-rodape">
                <?php if ($ehCodigo): ?>
                    Perdeu o acesso ao aplicativo? Procure o administrador do estabelecimento.
                    <a href="<?= url('logout.php') ?>">Cancelar e sair</a>
                <?php else: ?>
                    Nao reconhece esta pergunta? <a href="<?= url('logout.php') ?>">Cancelar e sair</a>
                <?php endif; ?>
            </p>
        </div>
    </div>
</div>

<?php require RAIZ . '/includes/assinatura_sistema.php'; ?>

<div id="notificacoes"></div>
<script src="<?= url('assets/js/main.js') ?>"></script>
</body>
</html>
