<?php
/**
 * Cadastro de empresa pela pagina inicial.
 *
 * Quem preenche cria a empresa e a conta do responsavel, mas nada entra no ar:
 * a empresa nasce inativa e o master aprova ou recusa em Estabelecimentos
 * (models/Solicitacao.php). Ate la o login da empresa explica que o cadastro
 * aguarda aprovacao, e a entrada geral nao aceita a conta.
 *
 * A pagina e do produto (PAGINA_PRODUTO): veste a marca do Agendei, e nao a
 * de uma empresa. ENTRADA_LOCAL descarta a identidade master, como nas demais
 * paginas de entrada.
 */
define('ENTRADA_LOCAL', true);
define('PAGINA_PRODUTO', true);
require_once __DIR__ . '/config/config.php';

bloquearSeLogado();

$erros = [];
$dados = [
    'estabelecimento' => '',
    'slug'            => '',
    'nome'            => '',
    'email'           => '',
    'telefone'        => '',
    'mensagem'        => '',
];

// A confirmacao e mostrada uma unica vez, logo depois do envio.
$recebido = $_SESSION['cadastro_empresa_recebido'] ?? null;
unset($_SESSION['cadastro_empresa_recebido']);

// Cadastro iniciado pelo Google (google_login.php): nome e e-mail do
// responsavel ja vem confirmados e preenchidos. A confirmacao vale so para
// esta tela e por pouco tempo (Google::cadastroPendente).
if (get('google') === 'cancelar') {
    Google::limparCadastro();
    redirecionar('cadastro_empresa.php');
}
$googleCadastro = Google::cadastroPendente('cadastro_empresa');
if (is_array($googleCadastro) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $dados['nome']  = (string) ($googleCadastro['nome'] ?? '');
    $dados['email'] = (string) ($googleCadastro['email'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();

    // Cada envio cria uma empresa que o master precisa avaliar: o freio por
    // origem evita que um script encha a fila de aprovacao.
    $bloqueio = conferirBloqueio(['cadastro_empresa_ip' => ipCliente()]);
    if ($bloqueio !== '') {
        $erros[] = $bloqueio;
    }
    anotarFalha('cadastro_empresa_ip', ipCliente());

    foreach (array_keys($dados) as $campo) {
        $dados[$campo] = trim(post($campo));
    }
    $dados['slug']  = mb_strtolower($dados['slug']);
    $dados['email'] = mb_strtolower($dados['email']);
    $senha          = post('senha');
    $confirmacao    = post('confirmar_senha');
    $telefone       = apenasNumeros($dados['telefone']);

    // E-mail que ja tem conta: a pessoa existe e sera vinculada como responsavel,
    // com a senha que ja tem. A senha digitada e a prova de que o e-mail e dela;
    // o Google, quando confirmou o e-mail nesta mesma tela, tambem serve.
    $pessoaExistente     = validarEmail($dados['email']) ? Usuario::pessoaPorEmail($dados['email']) : null;
    $confirmadoPeloGoogle = is_array($googleCadastro) && ($googleCadastro['email'] ?? '') === $dados['email'];

    if (mb_strlen($dados['estabelecimento']) < 2 || mb_strlen($dados['estabelecimento']) > 120) {
        $erros[] = 'Informe o nome da empresa.';
    }
    if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{1,78}[a-z0-9])$/D', $dados['slug'])) {
        $erros[] = 'O endereço deve ter de 3 a 80 letras minúsculas, números ou hifens.';
    }
    if (!validarNomeSobrenome($dados['nome'])) {
        $erros[] = 'Informe o nome e o sobrenome do responsável.';
    }
    if (!validarEmail($dados['email'])) {
        $erros[] = 'Informe um e-mail válido.';
    }
    if ($telefone === '' || !(validarTelefoneBr($telefone) || validarTelefoneBr($telefone, true))) {
        $erros[] = 'Informe um telefone ou WhatsApp com DDD.';
    }
    if (mb_strlen($dados['mensagem']) > 255) {
        $erros[] = 'A mensagem deve ter no máximo 255 caracteres.';
    }
    if ($pessoaExistente !== null) {
        if (!$confirmadoPeloGoogle && !password_verify($senha, $pessoaExistente['senha_hash'])) {
            $erros[] = 'Este e-mail já tem conta no Agendei. Informe a senha dela para vincular a empresa nova; a senha continua a mesma.';
        }
    } else {
        if (!validarSenha($senha)) {
            $erros[] = 'A senha deve ter pelo menos 6 caracteres.';
        }
        if ($senha !== $confirmacao) {
            $erros[] = 'As senhas não conferem.';
        }
    }
    if ($erros === [] && Solicitacao::slugEmUso($dados['slug'])) {
        $erros[] = 'Este endereço já está em uso. Escolha outro.';
    }

    if ($erros === []) {
        try {
            $idSolicitacao = Solicitacao::abrir([
                'estabelecimento' => $dados['estabelecimento'],
                'slug'            => $dados['slug'],
                'nome'            => $dados['nome'],
                'email'           => $dados['email'],
                'senha'           => $senha,
                'telefone'        => $telefone,
                'mensagem'        => $dados['mensagem'],
                // Autonomo: a mesma pessoa administra e atende, com agenda propria.
                'autonomo'        => post('autonomo') === '1',
            ]);
            // Sem master na sessao: a auditoria guarda o pedido com o master em branco.
            LogMaster::registrar('cadastro_solicitado', [
                'estabelecimento_nome' => $dados['estabelecimento'],
                'alvo'                 => $dados['email'],
                'detalhe'              => 'endereco ' . $dados['slug'] . ' (solicitacao ' . $idSolicitacao . ')',
            ]);
            limparFalhas('cadastro_empresa_ip', ipCliente());
            Google::limparCadastro();

            // Avisos por e-mail: o do responsavel confirma o recebimento; o dos
            // masters chama para decidir. Sem e-mail configurado, nada quebra:
            // a tela de confirmacao ja diz tudo e a fila fica no painel master.
            $solicitacao = Solicitacao::porId($idSolicitacao) ?? [];
            $emailEnviado = false;
            if ($solicitacao !== [] && Email::configurado()) {
                [$assunto, $texto, $html] = emailCadastroRecebido($solicitacao);
                $emailEnviado = Email::enviar($solicitacao['email'], $assunto, $texto, $html, $solicitacao['responsavel']);
                [$assunto, $texto, $html] = emailCadastroParaMaster($solicitacao);
                foreach (Master::listar() as $master) {
                    if (($master['status'] ?? 'ativo') === 'ativo' && validarEmail((string) ($master['email'] ?? ''))) {
                        Email::enviar($master['email'], $assunto, $texto, $html, (string) $master['nome']);
                    }
                }
            }

            $_SESSION['cadastro_empresa_recebido'] = [
                'estabelecimento' => $dados['estabelecimento'],
                'slug'            => $dados['slug'],
                'email'           => $dados['email'],
                'email_enviado'   => $emailEnviado,
            ];
            redirecionar('cadastro_empresa.php');
        } catch (PDOException $erro) {
            error_log('Falha no cadastro de empresa: ' . $erro->getMessage());
            $erros[] = $erro->getCode() === '23000'
                ? 'Este endereço já está em uso. Escolha outro.'
                : 'Não foi possível enviar o cadastro. Tente novamente.';
        } catch (Throwable $erro) {
            error_log('Falha no cadastro de empresa: ' . $erro->getMessage());
            $erros[] = 'Não foi possível enviar o cadastro. Tente novamente.';
        }
    }
}

$tituloPagina = 'Cadastrar empresa | ' . NOME_SISTEMA;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($tituloPagina) ?></title>
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/login.css') ?>">
    <?php require RAIZ . '/includes/tema.php'; ?>
</head>
<body class="pagina-autenticacao">

<div class="autenticacao-area">
    <div class="autenticacao-caixa caixa-larga">
        <?php /* Painel do produto, o mesmo da entrada geral. */ ?>
        <div class="autenticacao-apresentacao apresentacao-marca">
            <?= marcaSistema(36, true) ?>
            <h2 class="marca-slogan"><?= e(MARCA_SLOGAN) ?></h2>
        </div>

        <div class="autenticacao-formulario">
            <?php if ($recebido !== null): ?>
                <a href="<?= url('index.php') ?>" class="voltar-site">&larr; Voltar ao início</a>

                <h1>Cadastro recebido</h1>
                <p class="subtitulo">Obrigado, <?= e($recebido['estabelecimento']) ?>. Agora é com a gente.</p>

                <div class="cadastro-recebido">
                    <?php if (!empty($recebido['email_enviado'])): ?>
                        <p>Enviamos uma confirmação para <strong><?= e($recebido['email']) ?></strong>. Vamos analisar o cadastro e avisar pelo mesmo e-mail assim que o acesso for liberado. Costuma ser rápido.</p>
                    <?php else: ?>
                        <p>Vamos analisar o cadastro e avisar pelo e-mail <strong><?= e($recebido['email']) ?></strong> ou pelo telefone informado assim que o acesso for liberado. Costuma ser rápido.</p>
                    <?php endif; ?>
                    <p>Depois da aprovação, o endereço da sua empresa será:</p>
                    <p class="cadastro-link"><?= e(BASE_URL . '/login.php?estabelecimento=' . rawurlencode($recebido['slug'])) ?></p>
                    <p>A senha é a que você acabou de escolher. Guarde as duas informações.</p>
                </div>

                <a href="<?= url('index.php') ?>" class="btn btn-bloco btn-grande">Voltar ao início</a>
            <?php else: ?>
                <a href="<?= url('index.php') ?>" class="voltar-site">&larr; Voltar ao início</a>

                <h1>Cadastrar minha empresa</h1>
                <p class="subtitulo">Preencha os dados abaixo. A gente confere e libera o acesso.</p>

                <?php exibirFlash(); ?>

                <?php if ($erros !== []): ?>
                    <div class="alerta alerta-erro" role="alert"><ul><?php foreach ($erros as $mensagem): ?><li><?= e($mensagem) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>

                <?php if (is_array($googleCadastro)): ?>
                    <div class="alerta alerta-info">
                        <span class="alerta-texto">E-mail <strong><?= e((string) $googleCadastro['email']) ?></strong> confirmado pelo Google. Complete os dados da empresa.
                            <a href="<?= url('cadastro_empresa.php?google=cancelar') ?>">Cadastrar sem o Google</a></span>
                    </div>
                <?php elseif (Google::configurado()): ?>
                    <?php /* Formulario proprio, fora do cadastro. Dentro dele o botao do Google seria o primeiro
                             botao de envio, e o Enter em qualquer campo levaria ao Google em vez de enviar o
                             cadastro. O Google confirma o e-mail e devolve nome e e-mail preenchidos. */ ?>
                    <form method="post" action="<?= url('google_login.php') ?>" id="formCadastroEmpresaGoogle">
                        <?= campoCsrf() ?>
                        <input type="hidden" name="origem" value="cadastro_empresa">
                        <button type="submit" class="btn btn-contorno btn-bloco btn-grande btn-google" name="acao" value="google">
                            <?= iconeGoogle() ?>
                            Cadastrar com o Google
                        </button>
                        <span class="ajuda-campo">Confirma seu e-mail e já preenche seu nome e e-mail. O restante você completa abaixo.</span>
                    </form>
                    <div class="separador-ou"><span>ou preencha tudo</span></div>
                <?php endif; ?>

                <form method="post" id="formCadastroEmpresa" novalidate>
                    <?= campoCsrf() ?>

                    <div class="linha-campos">
                        <div class="campo">
                            <label for="estabelecimento">Nome da empresa</label>
                            <input type="text" id="estabelecimento" name="estabelecimento" value="<?= e($dados['estabelecimento']) ?>"
                                   maxlength="120" required autofocus>
                            <span class="mensagem-campo"></span>
                        </div>
                        <div class="campo">
                            <label for="slug">Endereço da empresa</label>
                            <input type="text" id="slug" name="slug" value="<?= e($dados['slug']) ?>"
                                   maxlength="80" pattern="[a-z0-9][a-z0-9-]{1,78}[a-z0-9]" autocomplete="off" required>
                            <span class="mensagem-campo"></span>
                            <span class="ajuda-campo">Vira o link do seu login. Letras minúsculas, números e hifens.</span>
                        </div>
                    </div>

                    <div class="linha-campos">
                        <div class="campo">
                            <label for="nome">Nome e sobrenome</label>
                            <input type="text" id="nome" name="nome" value="<?= e($dados['nome']) ?>" maxlength="120" autocomplete="name" required>
                            <span class="mensagem-campo"></span>
                            <span class="ajuda-campo">De quem responde pela empresa. Ex.: Ana Souza.</span>
                        </div>
                        <div class="campo">
                            <label for="telefone">Telefone ou WhatsApp</label>
                            <input type="tel" id="telefone" name="telefone" value="<?= e($dados['telefone']) ?>" maxlength="20" autocomplete="tel" required>
                            <span class="mensagem-campo"></span>
                        </div>
                    </div>

                    <div class="campo">
                        <label for="email">E-mail</label>
                        <input type="email" id="email" name="email" value="<?= e($dados['email']) ?>" maxlength="150" autocomplete="email" required>
                        <span class="mensagem-campo"></span>
                        <span class="ajuda-campo">Será o seu login e o canal do aviso de aprovação. Se já tiver conta no Agendei, use a senha dela: a empresa nova entra na mesma conta.</span>
                    </div>

                    <div class="linha-campos">
                        <div class="campo">
                            <label for="senha">Senha</label>
                            <input type="password" id="senha" name="senha" minlength="6" autocomplete="new-password" required>
                            <span class="mensagem-campo"></span>
                            <span class="ajuda-campo">Nova, ou a da sua conta se o e-mail já tiver uma.</span>
                        </div>
                        <div class="campo">
                            <label for="confirmar_senha">Confirmar senha</label>
                            <input type="password" id="confirmar_senha" name="confirmar_senha" minlength="6" autocomplete="new-password" required>
                            <span class="mensagem-campo"></span>
                        </div>
                    </div>

                    <div class="campo">
                        <div class="campo-checkbox">
                            <input type="checkbox" id="autonomo" name="autonomo" value="1" <?= post('autonomo') === '1' ? 'checked' : '' ?>>
                            <label for="autonomo">Sou autônomo(a): eu mesmo(a) atendo os clientes</label>
                        </div>
                        <span class="ajuda-campo">Você entra como administrador(a) e também como profissional, com agenda própria, no mesmo estabelecimento. No nome da empresa pode usar o seu nome ou o nome fantasia.</span>
                    </div>

                    <div class="campo">
                        <label for="mensagem">Conte um pouco sobre a empresa <span class="opcional">(opcional)</span></label>
                        <textarea id="mensagem" name="mensagem" rows="3" maxlength="255"><?= e($dados['mensagem']) ?></textarea>
                        <span class="mensagem-campo"></span>
                    </div>

                    <div class="acoes-formulario">
                        <button type="submit" class="btn btn-bloco btn-grande">Enviar cadastro</button>
                    </div>
                </form>

                <p class="autenticacao-rodape">
                    Já tem conta? <a href="<?= url('entrar.php') ?>">Entrar</a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require RAIZ . '/includes/assinatura_sistema.php'; ?>

<div id="notificacoes"></div>
<script src="<?= url('assets/js/main.js') ?>"></script>
<script src="<?= url('assets/js/cadastro_empresa.js') ?>"></script>
</body>
</html>
