<?php
/**
 * Usar uma conta que ja existe neste estabelecimento (adesao).
 *
 * A pessoa ja tem conta no Agendei (cliente ou profissional de outra empresa,
 * ou administradora) e quer ser cliente aqui. O cadastro comum recusaria o
 * e-mail; esta tela cria so o vinculo: confirma que a pessoa e dona do e-mail
 * (pela senha, ou pelo Google, que ja a confirmou em google_login.php) e pede
 * o que o perfil de cliente exige neste estabelecimento, o login de 6 letras
 * e o CPF. Os dados pessoais continuam os mesmos e a senha nao muda.
 *
 * Nao abre sessao: como o cadastro, termina na tela de login.
 */
define('ENTRADA_LOCAL', true);
require_once __DIR__ . '/config/config.php';

bloquearSeLogado();

/** Tempo entre confirmar a conta e completar o vinculo. */
const VINCULO_PENDENTE_SEGUNDOS = 900;

/** Pessoa confirmada, aguardando completar o vinculo neste estabelecimento; null se nao ha ou venceu. */
function adesaoPendente(): ?array
{
    $pendente = $_SESSION['vinculo_pendente'] ?? null;
    if (!is_array($pendente)
        || (int) ($pendente['expira'] ?? 0) < time()
        || (int) ($pendente['estabelecimento'] ?? 0) !== Contexto::id()) {
        unset($_SESSION['vinculo_pendente']);
        return null;
    }
    return $pendente;
}

/** Guarda a pessoa confirmada e segue para o segundo passo. */
function confirmarPessoa(array $pessoa): never
{
    $_SESSION['vinculo_pendente'] = [
        'id_usuario'      => (int) $pessoa['id_usuario'],
        'email'           => (string) $pessoa['email'],
        'nome'            => (string) $pessoa['nome'],
        'estabelecimento' => Contexto::id(),
        'expira'          => time() + VINCULO_PENDENTE_SEGUNDOS,
    ];
    redirecionar('vincular.php');
}

$erros = [];
$email = mb_strtolower(trim(get('email')));
$dados = ['login' => '', 'cpf' => ''];

if (get('acao') === 'cancelar') {
    unset($_SESSION['vinculo_pendente']);
    Google::limparVinculo();
    redirecionar('cadastro.php');
}

// O Google ja confirmou o e-mail (google_login.php, origem cadastro): nao ha senha a pedir.
$peloGoogle = Google::vinculoPendente(Contexto::slug());
if ($peloGoogle !== null) {
    Google::limparVinculo();
    $pessoa = Usuario::pessoaPorEmail((string) $peloGoogle['email']);
    if ($pessoa !== null && $pessoa['status'] === 'ativo') {
        confirmarPessoa($pessoa);
    }
    $erros[] = 'Nao encontramos uma conta ativa com o e-mail confirmado pelo Google.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $acao = post('acao');

    if ($acao === 'confirmar') {
        // Primeiro passo: e-mail e senha da conta, com o mesmo freio de forca bruta do login.
        $email = mb_strtolower(trim(post('email')));
        $senha = post('senha');

        $bloqueio = conferirBloqueio(['login_ip' => ipCliente(), 'login_conta' => $email]);
        if ($bloqueio !== '') {
            $erros[] = $bloqueio;
        } elseif (!validarEmail($email)) {
            $erros[] = 'Informe o e-mail da sua conta.';
        } elseif ($senha === '') {
            $erros[] = 'Informe sua senha.';
        } else {
            $pessoa = Usuario::pessoaPorEmail($email);
            if ($pessoa === null || !password_verify($senha, $pessoa['senha_hash']) || $pessoa['status'] !== 'ativo') {
                anotarFalha('login_conta', $email);
                anotarFalha('login_ip', ipCliente());
                atrasarResposta();
                $erros[] = 'E-mail ou senha incorretos.';
            } else {
                limparFalhas('login_conta', $email);
                confirmarPessoa($pessoa);
            }
        }
    } elseif ($acao === 'vincular') {
        // Segundo passo: o que o perfil de cliente exige aqui.
        $pendente = adesaoPendente();
        $dados['login'] = mb_strtolower(trim(post('login')));
        $dados['cpf']   = apenasNumeros(post('cpf'));

        if ($pendente === null) {
            $erros[] = 'A confirmacao expirou. Informe o e-mail e a senha novamente.';
        } else {
            if (!validarLogin($dados['login'])) {
                $erros[] = 'O login deve ter exatamente 6 caracteres alfabeticos.';
            }
            if (!validarCpf($dados['cpf'])) {
                $erros[] = 'Informe um CPF valido.';
            }
            if ($erros === [] && Usuario::loginEmUso($dados['login'])) {
                $erros[] = 'Este login ja esta em uso. Escolha outro.';
            }
            if ($erros === [] && Cliente::cpfEmUso($dados['cpf'])) {
                $erros[] = 'Ja existe uma conta cadastrada com este CPF.';
            }
            if ($erros === []) {
                $pessoa = Usuario::pessoaPorId((int) $pendente['id_usuario']);
                try {
                    Cliente::criar([
                        'id_usuario'      => (int) $pendente['id_usuario'],
                        'login'           => $dados['login'],
                        'cpf'             => $dados['cpf'],
                        'data_nascimento' => $pessoa['data_nascimento'] ?? null,
                    ]);
                    unset($_SESSION['vinculo_pendente']);
                    definirFlash('sucesso', 'Pronto: sua conta agora tambem vale aqui. Entre com o login ou o e-mail e a senha de sempre.');
                    redirecionar('login.php');
                } catch (DomainException $erro) {
                    $erros[] = $erro->getMessage();
                } catch (Throwable $erro) {
                    error_log('Falha na adesao de cliente: ' . $erro->getMessage());
                    $erros[] = 'Nao foi possivel concluir. Tente novamente.';
                }
            }
        }
    }
}

$pendente = adesaoPendente();

// Quem ja e cliente aqui nao tem o que vincular: e so entrar.
if ($pendente !== null && Vinculo::porPessoaEmpresaTipo(Contexto::id(), (int) $pendente['id_usuario'], 'cliente') !== null) {
    unset($_SESSION['vinculo_pendente']);
    definirFlash('info', 'Voce ja tem conta neste estabelecimento. E so entrar.');
    redirecionar('login.php');
}

$estabelecimento = Estabelecimento::dados();
$tituloPagina = 'Usar minha conta | ' . $estabelecimento['nome'];
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
            <h2>Uma conta, varios lugares</h2>
            <p>Quem ja tem conta no Agendei nao precisa se cadastrar de novo: a mesma conta passa a valer aqui.</p>

            <ul class="lista-beneficios">
                <li>Mesmo e-mail e mesma senha</li>
                <li>Seus dados pessoais continuam os mesmos</li>
                <li>Historico e agenda separados por estabelecimento</li>
            </ul>
        </div>

        <div class="autenticacao-formulario">
            <a href="<?= url('cadastro.php') ?>" class="voltar-site">&larr; Voltar ao cadastro</a>

            <?php exibirFlash(); ?>

            <?php if ($erros !== []): ?>
                <div class="alerta alerta-erro">
                    <div>
                        <ul>
                            <?php foreach ($erros as $erro): ?>
                                <li><?= e($erro) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($pendente !== null): ?>
                <h1>Quase la, <?= e(explode(' ', (string) $pendente['nome'])[0]) ?></h1>
                <p class="subtitulo">Conta <strong><?= e((string) $pendente['email']) ?></strong> confirmada. Falta so o que <?= e($estabelecimento['nome']) ?> precisa.</p>

                <form method="post" id="formVincular" novalidate>
                    <?= campoCsrf() ?>
                    <input type="hidden" name="acao" value="vincular">

                    <div class="campo">
                        <label for="login">Login neste estabelecimento <span class="obrigatorio">*</span></label>
                        <input type="text" id="login" name="login" value="<?= e($dados['login']) ?>"
                               autocomplete="username" minlength="6" maxlength="6" required autofocus>
                        <span class="mensagem-campo"></span>
                        <span class="ajuda-campo">Exatamente 6 letras. Pode ser o mesmo que voce usa em outro lugar, se estiver livre aqui.</span>
                    </div>

                    <div class="campo">
                        <label for="cpf">CPF <span class="obrigatorio">*</span></label>
                        <input type="text" id="cpf" name="cpf" value="<?= e($dados['cpf']) ?>"
                               data-mascara="cpf" inputmode="numeric" placeholder="000.000.000-00" required>
                        <span class="mensagem-campo"></span>
                    </div>

                    <div class="acoes-formulario">
                        <button type="submit" class="btn btn-bloco btn-grande">Usar minha conta aqui</button>
                    </div>
                </form>

                <p class="autenticacao-rodape">
                    Nao e voce? <a href="<?= url('vincular.php?acao=cancelar') ?>">Cancelar</a>
                </p>
            <?php else: ?>
                <h1>Usar minha conta</h1>
                <p class="subtitulo">Confirme que a conta e sua para usa-la em <?= e($estabelecimento['nome']) ?>.</p>

                <?php if (Google::configurado()): ?>
                    <?php /* Formulario proprio, fora do de senha: o Enter num campo confirma pela senha, nao pelo Google. */ ?>
                    <form method="post" action="<?= url('google_login.php') ?>" id="formVincularGoogle">
                        <?= campoCsrf() ?>
                        <input type="hidden" name="estabelecimento" value="<?= e(Contexto::slug()) ?>">
                        <input type="hidden" name="origem" value="cadastro">
                        <button type="submit" class="btn btn-contorno btn-bloco btn-grande btn-google" name="acao" value="google">
                            <?= iconeGoogle() ?>
                            Confirmar com o Google
                        </button>
                    </form>
                    <div class="separador-ou"><span>ou com a senha</span></div>
                <?php endif; ?>

                <form method="post" id="formConfirmar" novalidate>
                    <?= campoCsrf() ?>
                    <input type="hidden" name="acao" value="confirmar">

                    <div class="campo">
                        <label for="email">E-mail da sua conta</label>
                        <input type="email" id="email" name="email" value="<?= e($email) ?>"
                               autocomplete="username" maxlength="150" required autofocus>
                        <span class="mensagem-campo"></span>
                    </div>

                    <div class="campo">
                        <label for="senha">Senha</label>
                        <input type="password" id="senha" name="senha" autocomplete="current-password" required>
                        <span class="mensagem-campo"></span>
                        <span class="ajuda-campo">A mesma senha de sempre. Ela nao muda.</span>
                    </div>

                    <div class="acoes-formulario">
                        <button type="submit" class="btn btn-bloco btn-grande">Confirmar</button>
                    </div>
                </form>

                <p class="autenticacao-rodape">
                    Ainda nao tem conta? <a href="<?= url('cadastro.php') ?>">Criar conta</a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require RAIZ . '/includes/assinatura_sistema.php'; ?>

<div id="notificacoes"></div>
<script src="<?= url('assets/js/main.js') ?>"></script>
</body>
</html>
