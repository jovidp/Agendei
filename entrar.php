<?php
/**
 * Entrada geral da plataforma: login sem saber o link do estabelecimento.
 *
 * A tela login.php de cada empresa continua valendo — ela e o link direto,
 * com a marca da empresa. Esta pagina existe para quem nao sabe esse link:
 * pede e-mail e senha, confere a conta daquele e-mail (autenticarGlobal) e:
 *   - com uma conta valida, entra direto no painel do tipo dela;
 *   - com varias em uma base antiga, permite escolher ate a migracao;
 *   - sem nenhuma, responde "Login ou senha incorretos" como o login comum.
 *
 * A lista de compatibilidade so aparece depois da senha conferida. O login de
 * 6 letras nao e aceito aqui porque e unico apenas dentro de cada empresa.
 *
 * ENTRADA_LOCAL descarta a identidade master, como no login comum.
 * ENTRADA_GLOBAL abre o Contexto sem empresa e libera Contexto::assumir().
 */
define('ENTRADA_LOCAL', true);
define('ENTRADA_GLOBAL', true);
require_once __DIR__ . '/config/config.php';

// Quem ja esta autenticado vai para o seu painel: a sessao ja tem empresa.
bloquearSeLogado();

// Um desafio de 2FA em andamento nao pode ficar preso a uma tentativa anterior.
cancelarSegundoFator();

// Escolha pendente entre empresas: guardada por poucos minutos, sem a senha.
const ENTRADA_ESCOLHA_SEGUNDOS = 300;

/** Contas aguardando a escolha da empresa, ou null quando nao ha (ou venceu). */
function escolhaPendente(): ?array
{
    $pendente = $_SESSION['entrada_escolha'] ?? null;
    if (!is_array($pendente) || empty($pendente['contas']) || ($pendente['expira'] ?? 0) < time()) {
        unset($_SESSION['entrada_escolha']);
        return null;
    }
    return $pendente;
}

/**
 * Conclui a entrada em uma conta ja autenticada: assume a empresa, registra o
 * log naquela empresa e segue para o 2FA ou para o painel. Nunca retorna.
 */
function concluirEntrada(array $conta, string $email): never
{
    unset($_SESSION['entrada_escolha']);

    Contexto::assumir((int) $conta['id_estabelecimento']);
    // Recarrega o registro completo ja dentro da empresa: o 2FA e a sessao leem daqui.
    $usuario = Usuario::porId((int) $conta['id_usuario']);
    if ($usuario === null || $usuario['status'] !== 'ativo') {
        definirFlash('erro', 'Esta conta nao esta mais disponivel.');
        redirecionar('entrar.php');
    }

    limparFalhas('login_conta', $email);
    LogAutenticacao::registrar('login_sucesso', $email, $usuario, null, cpfDoUsuario($usuario));

    if (exigeSegundoFator($usuario)) {
        // A sessao so abre depois do segundo fator; o Contexto ja tem o slug,
        // entao a URL do desafio sai com a empresa certa.
        iniciarSegundoFator($usuario, $email);
        redirecionar('dois_fatores.php');
    }

    registrarSessao($usuario);
    lembrarSeSolicitado($usuario);
    definirFlash('sucesso', 'Bem-vindo(a), ' . explode(' ', $usuario['nome'])[0] . '.');
    header('Location: ' . destinoAposLogin());
    exit;
}

$erros = [];
$email = '';

if (get('acao') === 'cancelar') {
    unset($_SESSION['entrada_escolha']);
    redirecionar('entrar.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();

    if (post('acao') === 'escolher') {
        // Segundo passo: a pessoa escolheu a empresa entre as contas ja conferidas.
        $pendente = escolhaPendente();
        $idUsuario = (int) post('id_usuario');
        $conta = $pendente['contas'][$idUsuario] ?? null;

        if ($pendente === null || $conta === null) {
            $erros[] = 'A escolha expirou. Informe o e-mail e a senha novamente.';
            unset($_SESSION['entrada_escolha']);
        } else {
            concluirEntrada($conta, (string) $pendente['email']);
        }
    } else {
        // Primeiro passo: e-mail e senha, com o mesmo freio de forca bruta do login comum.
        $email = mb_strtolower(post('email'));
        $senha = post('senha');
        // "Manter conectado" so vira cookie quando a sessao abrir, depois do 2FA se houver.
        pedirLembrarDispositivo(post('lembrar') === '1');

        $bloqueio = conferirBloqueio([
            'login_ip'    => ipCliente(),
            'login_conta' => $email,
        ]);
        if ($bloqueio !== '') {
            $erros[] = $bloqueio;
        }
        if ($erros === [] && !validarEmail($email)) {
            $erros[] = 'Informe o e-mail cadastrado.';
        }
        if ($erros === [] && $senha === '') {
            $erros[] = 'Informe sua senha.';
        }

        if ($erros === []) {
            $contas = autenticarGlobal($email, $senha);

            if ($contas === []) {
                // A senha foi testada em cada conta do e-mail: cada uma recebe o log
                // de falha na sua empresa, como aconteceria no login dela. (Sem o
                // CPF: ele mora no perfil de cliente, que so e lido dentro da empresa.)
                foreach (Usuario::vinculosPorEmail($email) as $vinculo) {
                    LogAutenticacao::registrarEm((int) $vinculo['id_estabelecimento'], 'login_falha', $email, $vinculo);
                }
                anotarFalha('login_conta', $email);
                anotarFalha('login_ip', ipCliente());
                atrasarResposta();
                $erros[] = 'Login ou senha incorretos.';
            } elseif (count($contas) === 1) {
                concluirEntrada($contas[0], $email);
            } else {
                $lista = [];
                foreach ($contas as $conta) {
                    $lista[(int) $conta['id_usuario']] = [
                        'id_usuario'           => (int) $conta['id_usuario'],
                        'id_estabelecimento'   => (int) $conta['id_estabelecimento'],
                        'nome'                 => $conta['nome'],
                        'tipo'                 => $conta['tipo'],
                        'estabelecimento_nome' => $conta['estabelecimento_nome'],
                        'estabelecimento_slug' => $conta['estabelecimento_slug'],
                    ];
                }
                $_SESSION['entrada_escolha'] = ['email' => $email, 'contas' => $lista, 'expira' => time() + ENTRADA_ESCOLHA_SEGUNDOS];
            }
        }
    }
}

$pendente = escolhaPendente();
$plataforma = Estabelecimento::dados();
$tituloPagina = 'Entrar | ' . $plataforma['nome'];
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
    <style>
        .lista-escolha { list-style: none; padding: 0; margin: 0 0 20px; display: grid; gap: 10px; }
        .lista-escolha button { width: 100%; text-align: left; display: flex; flex-direction: column; gap: 2px; padding: 12px 14px; }
        .lista-escolha small { color: var(--texto-secundario); font-size: 12.5px; }
    </style>
</head>
<body class="pagina-autenticacao">

<div class="autenticacao-area">
    <div class="autenticacao-caixa">
        <?php /* Painel do produto: marca e slogan, nas cores fixas da marca (includes/marca.php). */ ?>
        <div class="autenticacao-apresentacao apresentacao-marca">
            <?= marcaSistema(36, true) ?>
            <h2 class="marca-slogan"><?= e(MARCA_SLOGAN) ?></h2>
        </div>

        <div class="autenticacao-formulario">
            <?php if ($pendente !== null): ?>
                <a href="<?= url('entrar.php?acao=cancelar') ?>" class="voltar-site">&larr; Usar outro e-mail</a>

                <h1>Escolha o estabelecimento</h1>
                <p class="subtitulo">O e-mail <strong><?= e($pendente['email']) ?></strong> tem conta em mais de um lugar.</p>

                <?php if ($erros !== []): ?>
                    <div class="alerta alerta-erro"><span class="alerta-texto"><?= e($erros[0]) ?></span></div>
                <?php endif; ?>

                <form method="post">
                    <?= campoCsrf() ?>
                    <input type="hidden" name="acao" value="escolher">
                    <ul class="lista-escolha">
                        <?php foreach ($pendente['contas'] as $conta): ?>
                            <li>
                                <button type="submit" class="btn btn-contorno" name="id_usuario" value="<?= (int) $conta['id_usuario'] ?>">
                                    <strong><?= e($conta['estabelecimento_nome']) ?></strong>
                                    <small><?= e(Usuario::TIPOS[$conta['tipo']] ?? $conta['tipo']) ?> &middot; <?= e($conta['nome']) ?></small>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </form>
            <?php else: ?>
                <a href="<?= url('index.php') ?>" class="voltar-site">&larr; Voltar ao site</a>

                <h1>Entrar</h1>
                <p class="subtitulo">Informe o e-mail e a senha da sua conta.</p>

                <?php exibirFlash(); ?>

                <?php if ($erros !== []): ?>
                    <div class="alerta alerta-erro"><span class="alerta-texto"><?= e($erros[0]) ?></span></div>
                <?php endif; ?>

                <form method="post" novalidate>
                    <?= campoCsrf() ?>

                    <div class="campo">
                        <label for="email">E-mail</label>
                        <input type="email" id="email" name="email" value="<?= e($email) ?>"
                               autocomplete="username" maxlength="150" required autofocus>
                        <span class="mensagem-campo"></span>
                    </div>

                    <div class="campo">
                        <label for="senha">Senha</label>
                        <input type="password" id="senha" name="senha" autocomplete="current-password" required>
                        <span class="mensagem-campo"></span>
                    </div>

                    <div class="linha-opcoes">
                        <div class="campo-checkbox">
                            <input type="checkbox" id="lembrar" name="lembrar" value="1">
                            <label for="lembrar">Manter conectado</label>
                        </div>
                        <span></span>
                    </div>

                    <div class="acoes-formulario">
                        <button type="submit" class="btn btn-bloco btn-grande">Entrar</button>
                        <button type="reset" class="btn btn-contorno btn-bloco btn-grande">Limpar</button>
                    </div>

                    <?php if (Google::configurado()): ?>
                        <div class="separador-ou"><span>ou</span></div>
                        <button type="submit" class="btn btn-contorno btn-bloco btn-grande btn-google"
                                formaction="<?= url('google_login.php') ?>" formnovalidate name="acao" value="google">
                            <?= iconeGoogle() ?>
                            Entrar com o Google
                        </button>
                    <?php endif; ?>
                </form>

            <?php endif; ?>
        </div>
    </div>
</div>

<?php require RAIZ . '/includes/assinatura_sistema.php'; ?>

<div id="notificacoes"></div>
<script src="<?= url('assets/js/main.js') ?>"></script>
</body>
</html>
