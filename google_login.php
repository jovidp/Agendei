<?php
/**
 * Entrar com o Google.
 *
 * Recebe o pedido dos formularios de login (POST, com CSRF) e leva a pessoa ao
 * Google; depois recebe a volta (GET com code e state), confirma o e-mail com
 * o Google (models/Google.php) e abre a conta daquele e-mail:
 *   - vindo do login de uma empresa, so a conta naquela empresa serve;
 *   - vindo da entrada geral, todas as contas do e-mail; com mais de uma, a
 *     escolha e a mesma tela da entrada geral (entrar.php).
 * Sem conta, a pessoa e orientada a se cadastrar: o Google prova o e-mail,
 * nao cria conta. O segundo fator, quando a conta exige, continua valendo.
 *
 * ENTRADA_LOCAL descarta a identidade master, como no login comum.
 * ENTRADA_GLOBAL abre o Contexto sem empresa e libera Contexto::assumir().
 */
define('ENTRADA_LOCAL', true);
define('ENTRADA_GLOBAL', true);
require_once __DIR__ . '/config/config.php';

bloquearSeLogado();

if (!Google::configurado()) {
    http_response_code(404);
    exit('A entrada com o Google nao esta configurada.');
}

/** Tempo entre sair para o Google e voltar; depois disso o pedido e refeito. */
const GOOGLE_ESTADO_SEGUNDOS = 600;

/** De volta a tela de onde a pessoa veio: o login da empresa ou a entrada geral. */
function voltarParaLogin(string $slug): never
{
    redirecionar($slug !== '' ? 'login.php?estabelecimento=' . rawurlencode($slug) : 'entrar.php');
}

/**
 * Abre a conta confirmada pelo Google: assume a empresa, registra o log nela e
 * segue para o segundo fator ou para o painel. Nunca retorna.
 */
function concluirEntradaGoogle(array $conta, string $email): never
{
    Contexto::assumir((int) $conta['id_estabelecimento']);
    $usuario = Usuario::porId((int) $conta['id_usuario']);
    if ($usuario === null || $usuario['status'] !== 'ativo') {
        definirFlash('erro', 'Esta conta não está mais disponível.');
        voltarParaLogin((string) $conta['estabelecimento_slug']);
    }

    limparFalhas('login_conta', $email);
    LogAutenticacao::registrar('login_sucesso', $email, $usuario, null, cpfDoUsuario($usuario));

    if (exigeSegundoFator($usuario)) {
        // A sessao so abre depois do segundo fator; o pedido de "manter
        // conectado" espera na sessao ate la (lembrarSeSolicitado).
        iniciarSegundoFator($usuario, $email);
        redirecionar('dois_fatores.php');
    }

    registrarSessao($usuario);
    lembrarSeSolicitado($usuario);
    definirFlash('sucesso', 'Bem-vindo(a), ' . explode(' ', $usuario['nome'])[0] . '.');
    header('Location: ' . destinoAposLogin());
    exit;
}

// -------------------------------------------------------------------------
// Passo 1: o formulario de login pediu para entrar com o Google
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();

    // O login da empresa manda o proprio link; a entrada geral nao manda nada.
    $slug = post('estabelecimento');
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,78}[a-z0-9]$/D', $slug)) {
        $slug = '';
    }
    pedirLembrarDispositivo(post('lembrar') === '1');

    // O state volta com o Google e prova que a resposta e deste pedido.
    $estado = bin2hex(random_bytes(16));
    $_SESSION['google_login'] = ['estado' => $estado, 'slug' => $slug, 'expira' => time() + GOOGLE_ESTADO_SEGUNDOS];

    header('Location: ' . Google::urlAutorizacao($estado, Google::urlRedirecionamento()));
    exit;
}

// -------------------------------------------------------------------------
// Passo 2: o Google devolveu a pessoa
// -------------------------------------------------------------------------
$pendente = $_SESSION['google_login'] ?? null;
unset($_SESSION['google_login']);
$slug = is_array($pendente) ? (string) ($pendente['slug'] ?? '') : '';

if (!is_array($pendente) || (int) ($pendente['expira'] ?? 0) < time()) {
    pedirLembrarDispositivo(false);
    definirFlash('erro', 'A entrada com o Google expirou. Tente novamente.');
    voltarParaLogin($slug);
}

if (get('error') !== '') {
    // A pessoa desistiu na tela do Google (ou negou o acesso).
    pedirLembrarDispositivo(false);
    definirFlash('aviso', 'Entrada com o Google cancelada.');
    voltarParaLogin($slug);
}

if (get('code') === '' || !hash_equals((string) ($pendente['estado'] ?? ''), get('state'))) {
    pedirLembrarDispositivo(false);
    registrarEventoSeguranca('google_state_invalido', ['slug' => $slug]);
    definirFlash('erro', 'Não foi possível confirmar a entrada com o Google. Tente novamente.');
    voltarParaLogin($slug);
}

try {
    $dados = Google::trocarCodigo(get('code'), Google::urlRedirecionamento());
} catch (RuntimeException $erro) {
    pedirLembrarDispositivo(false);
    definirFlash('erro', $erro->getMessage());
    voltarParaLogin($slug);
}

$email  = (string) $dados['email'];
$contas = Google::contas($email, $slug);

if ($contas === []) {
    pedirLembrarDispositivo(false);
    registrarEventoSeguranca('google_sem_conta', ['slug' => $slug]);
    definirFlash('erro', 'Não encontramos uma conta com o e-mail ' . $email . '. Crie sua conta primeiro ou entre com login e senha.');
    voltarParaLogin($slug);
}

if (count($contas) === 1) {
    concluirEntradaGoogle($contas[0], $email);
}

// Mais de uma empresa com este e-mail: a escolha e a mesma da entrada geral,
// que ja sabe concluir a entrada sem pedir a senha de novo.
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
$_SESSION['entrada_escolha'] = ['email' => $email, 'contas' => $lista, 'expira' => time() + 300];
redirecionar('entrar.php');
