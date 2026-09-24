<?php
/**
 * Entrar (ou comecar o cadastro) com o Google.
 *
 * Recebe o pedido dos formularios (POST, com CSRF) e leva a pessoa ao Google;
 * depois recebe a volta (GET com code e state), confirma o e-mail com o Google
 * (models/Google.php) e decide pelo campo "origem" do pedido:
 *   - login (padrao): abre a conta unica daquele e-mail. Vindo do login de
 *     uma empresa, a conta tambem precisa pertencer a ela; se for de outra,
 *     a pessoa e orientada a usar a entrada geral. Sem conta, a se cadastrar.
 *   - cadastro: o cadastro do cliente. Com conta no estabelecimento, entra
 *     direto; sem conta em lugar nenhum, volta ao formulario com nome e e-mail
 *     preenchidos e o e-mail confirmado; com conta em outra empresa, avisa,
 *     porque o e-mail e unico na plataforma. O Google nao cria a conta sozinho
 *     porque o cadastro exige dados que ele nao fornece (CPF, login, nome
 *     materno...).
 *   - cadastro_empresa: o cadastro de empresa da pagina inicial, mesma ideia.
 * O segundo fator, quando a conta exige, continua valendo.
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

/** De volta a tela de onde a pessoa veio. */
function voltar(string $slug, string $origem = 'login'): never
{
    if ($origem === 'cadastro_empresa') {
        redirecionar('cadastro_empresa.php');
    }
    $pagina = $origem === 'cadastro' ? 'cadastro.php' : 'login.php';
    if ($slug !== '') {
        redirecionar($pagina . '?estabelecimento=' . rawurlencode($slug));
    }
    redirecionar($origem === 'cadastro' ? $pagina : 'entrar.php');
}

/**
 * Guarda nome e e-mail confirmados para a tela de cadastro de origem preencher.
 * A confirmacao vale so para essa tela e por pouco tempo (models/Google.php).
 */
function prepararCadastro(array $dados, string $origem): void
{
    pedirLembrarDispositivo(false);
    Google::guardarCadastro($dados, $origem);
}

/**
 * Abre a conta confirmada pelo Google: assume a empresa, registra o log nela e
 * segue para o segundo fator ou para o painel. Nunca retorna.
 */
function concluirEntradaGoogle(array $conta, string $email, string $origem): never
{
    Contexto::assumir((int) $conta['id_estabelecimento']);
    $usuario = Usuario::porId((int) $conta['id_usuario']);
    if ($usuario === null || $usuario['status'] !== 'ativo') {
        definirFlash('erro', 'Esta conta não está mais disponível.');
        voltar((string) $conta['estabelecimento_slug'], $origem);
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
    if ($origem === 'cadastro') {
        definirFlash('info', 'Este e-mail já tinha conta aqui: entramos com ela.');
    }
    definirFlash('sucesso', 'Bem-vindo(a), ' . explode(' ', $usuario['nome'])[0] . '.');
    header('Location: ' . destinoAposLogin());
    exit;
}

// -------------------------------------------------------------------------
// Passo 1: um formulario pediu para seguir com o Google
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();

    // De onde veio: login (padrao), cadastro do cliente ou cadastro de empresa.
    $origem = in_array(post('origem'), ['cadastro', 'cadastro_empresa'], true) ? post('origem') : 'login';

    // O login e o cadastro da empresa mandam o proprio link; a entrada geral e
    // o cadastro de empresa (cujo campo "estabelecimento" e o nome dela) nao.
    $slug = $origem === 'cadastro_empresa' ? '' : post('estabelecimento');
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,78}[a-z0-9]$/D', $slug)) {
        $slug = '';
    }
    pedirLembrarDispositivo(post('lembrar') === '1');

    // O state volta com o Google e prova que a resposta e deste pedido.
    $estado = bin2hex(random_bytes(16));
    $_SESSION['google_login'] = ['estado' => $estado, 'slug' => $slug, 'origem' => $origem, 'expira' => time() + GOOGLE_ESTADO_SEGUNDOS];

    header('Location: ' . Google::urlAutorizacao($estado, Google::urlRedirecionamento()));
    exit;
}

// -------------------------------------------------------------------------
// Passo 2: o Google devolveu a pessoa
// -------------------------------------------------------------------------
$pendente = $_SESSION['google_login'] ?? null;
unset($_SESSION['google_login']);
$slug   = is_array($pendente) ? (string) ($pendente['slug'] ?? '') : '';
$origem = is_array($pendente) ? (string) ($pendente['origem'] ?? 'login') : 'login';

if (!is_array($pendente) || (int) ($pendente['expira'] ?? 0) < time()) {
    pedirLembrarDispositivo(false);
    definirFlash('erro', 'A entrada com o Google expirou. Tente novamente.');
    voltar($slug, $origem);
}

if (get('error') !== '') {
    // A pessoa desistiu na tela do Google (ou negou o acesso).
    pedirLembrarDispositivo(false);
    definirFlash('aviso', 'Entrada com o Google cancelada.');
    voltar($slug, $origem);
}

if (get('code') === '' || !hash_equals((string) ($pendente['estado'] ?? ''), get('state'))) {
    pedirLembrarDispositivo(false);
    registrarEventoSeguranca('google_state_invalido', ['slug' => $slug]);
    definirFlash('erro', 'Não foi possível confirmar a entrada com o Google. Tente novamente.');
    voltar($slug, $origem);
}

try {
    $dados = Google::trocarCodigo(get('code'), Google::urlRedirecionamento());
} catch (RuntimeException $erro) {
    pedirLembrarDispositivo(false);
    definirFlash('erro', $erro->getMessage());
    voltar($slug, $origem);
}

$email = (string) $dados['email'];

// Cadastro de empresa: nao ha conta a abrir, so o formulario a preencher.
if ($origem === 'cadastro_empresa') {
    prepararCadastro($dados, $origem);
    definirFlash('info', 'E-mail confirmado pelo Google. Nome e e-mail já vieram preenchidos; complete os dados da empresa.');
    voltar('', $origem);
}

$contas = Google::contas($email, $slug);

if ($contas === []) {
    // O e-mail e unico na plataforma: com conta em outra empresa (ou uma conta
    // desligada), o cadastro seria recusado e o link desta empresa nao a abre.
    // O Google confirmou que o e-mail e da pessoa, entao ela pode saber disso.
    $temContaEmOutroLugar = Usuario::vinculosPorEmail($email) !== [];

    if ($origem === 'cadastro' && !$temContaEmOutroLugar) {
        prepararCadastro($dados, $origem);
        definirFlash('info', 'E-mail confirmado pelo Google. Nome e e-mail já vieram preenchidos; complete o restante para criar sua conta.');
        voltar($slug, $origem);
    }
    pedirLembrarDispositivo(false);
    registrarEventoSeguranca('google_sem_conta', ['slug' => $slug]);
    if ($temContaEmOutroLugar) {
        definirFlash('erro', $slug !== ''
            ? 'O e-mail ' . $email . ' já tem conta em outro estabelecimento, ou ela está desativada. Tente pela entrada geral ou fale com quem te atende.'
            : 'A conta do e-mail ' . $email . ' está desativada. Fale com o estabelecimento.');
    } else {
        definirFlash('erro', 'Não encontramos uma conta com o e-mail ' . $email . '. Crie sua conta primeiro ou entre com login e senha.');
    }
    voltar($slug, $origem);
}

if (count($contas) === 1) {
    concluirEntradaGoogle($contas[0], $email, $origem);
}

// Compatibilidade com bases antigas que ainda tenham e-mail repetido: a
// entrada geral sabe concluir sem pedir a senha de novo.
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
