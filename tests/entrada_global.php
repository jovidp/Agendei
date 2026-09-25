<?php
/**
 * Regressao da entrada geral (entrar.php): login sem o link do estabelecimento.
 * Usa apenas SQLite em memoria, como tests/acesso.php.
 *
 * Cobre: contexto neutro sob ENTRADA_GLOBAL, pessoa unica com varios
 * vinculos, exclusao de vinculo e empresa inativos, escolha da empresa por
 * Contexto::assumir() e a sessao que nasce dela.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('RAIZ', dirname(__DIR__));
define('BASE_URL', $argv[1] ?? '');
define('AMBIENTE', 'desenvolvimento');
define('ENTRADA_LOCAL', true);
define('ENTRADA_GLOBAL', true);
// A entrada geral veste a marca do produto: o nome e o slogan vem de config.php e includes/marca.php.
define('NOME_SISTEMA', 'Agendei');
set_error_handler(static function (int $nivel, string $mensagem, string $arquivo, int $linha): never {
    throw new ErrorException($mensagem, 0, $nivel, $arquivo, $linha);
});
require RAIZ . '/includes/funcoes.php';
require RAIZ . '/includes/auth.php';
require RAIZ . '/includes/seguranca.php';
require RAIZ . '/includes/marca.php';
spl_autoload_register(static function (string $classe): void {
    require RAIZ . '/models/' . $classe . '.php';
});

function bd(): PDO
{
    static $db;
    if (!$db) {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));
    }
    return $db;
}

// Somente as tabelas/colunas usadas neste fluxo; nenhum banco instalado e acessado.
bd()->exec("CREATE TABLE estabelecimento (id_estabelecimento INTEGER PRIMARY KEY, nome TEXT, slug TEXT UNIQUE, status TEXT DEFAULT 'ativo');
    CREATE TABLE usuarios (id_usuario INTEGER PRIMARY KEY, nome TEXT, email TEXT UNIQUE, senha_hash TEXT, telefone TEXT, telefone_fixo TEXT, sexo TEXT, nome_materno TEXT, data_nascimento TEXT, cep TEXT, logradouro TEXT, numero TEXT, complemento TEXT, bairro TEXT, cidade TEXT, uf TEXT, status TEXT DEFAULT 'ativo');
    CREATE TABLE vinculos (id_vinculo INTEGER PRIMARY KEY, id_estabelecimento INTEGER, id_usuario INTEGER, tipo TEXT, status TEXT DEFAULT 'ativo', login TEXT, ultimo_acesso TEXT, UNIQUE (id_estabelecimento, id_usuario, tipo));
    CREATE TABLE administradores (id_administrador INTEGER PRIMARY KEY, id_estabelecimento INTEGER, id_vinculo INTEGER, id_usuario INTEGER, nivel TEXT);
    CREATE TABLE administradores_master (id_master INTEGER PRIMARY KEY, nome TEXT, status TEXT DEFAULT 'ativo');
    CREATE TABLE logs_autenticacao (id_estabelecimento INTEGER, id_usuario INTEGER, login_informado TEXT, nome TEXT, cpf TEXT, perfil TEXT, evento TEXT, fator_2fa TEXT, ip TEXT);");

$checagens = 0;
function verificar(bool $condicao, string $mensagem): void
{
    global $checagens;
    if (!$condicao) {
        throw new RuntimeException($mensagem);
    }
    $checagens++;
}

session_save_path(sys_get_temp_dir());
iniciarSessao();
verificar(session_status() === PHP_SESSION_ACTIVE, 'Sessao de teste nao iniciou.');

// Quatro empresas com responsaveis diferentes. O e-mail e uma identidade
// global e nao pode mais aparecer em duas contas.
$ids = [];
foreach (['empresa-a' => 'Senha-AB-1', 'empresa-b' => 'Senha-AB-1', 'empresa-c' => 'Senha-C-99', 'empresa-d' => 'Senha-AB-1'] as $slug => $senha) {
    $email = 'pessoa-' . substr($slug, -1) . '@teste.local';
    $ids[$slug] = Estabelecimento::contratar([
        'estabelecimento' => $slug, 'slug' => $slug, 'nome' => 'Responsavel ' . $slug,
        'email' => $email, 'senha' => $senha,
    ]);
}
bd()->exec("UPDATE estabelecimento SET status = 'inativo' WHERE slug = 'empresa-d'");

// O mesmo e-mail em outra empresa nao cria outra pessoa: a que existe ganha um
// segundo vinculo (administradora tambem da empresa nova) e a senha dela fica.
$ids['empresa-e'] = Estabelecimento::contratar([
    'estabelecimento' => 'empresa-e', 'slug' => 'empresa-e',
    'nome' => 'Responsavel duplicado', 'email' => 'PESSOA-A@teste.local ', 'senha' => 'outra-senha-ignorada',
]);
$idPessoaA = (int) bd()->query("SELECT id_usuario FROM usuarios WHERE email = 'pessoa-a@teste.local'")->fetchColumn();
verificar((int) bd()->query("SELECT COUNT(*) FROM usuarios WHERE email = 'pessoa-a@teste.local'")->fetchColumn() === 1, 'O segundo vinculo criou outra pessoa.');
verificar(count(Vinculo::daPessoa($idPessoaA)) === 2, 'A pessoa nao ficou com dois vinculos.');
$vinculoRepetido = false;
try {
    Estabelecimento::vincularAdministrador($ids['empresa-e'], $idPessoaA);
} catch (DomainException) {
    $vinculoRepetido = true;
}
verificar($vinculoRepetido, 'A mesma pessoa virou administradora duas vezes da mesma empresa.');

// -------------------------------------------------------------------------
// Contexto neutro: a entrada geral ignora o slug da URL e nao carrega empresa.
// -------------------------------------------------------------------------
$_SESSION = [];
$_GET = ['estabelecimento' => 'empresa-c'];
Contexto::iniciar();
verificar(Contexto::id() === 0 && Contexto::slug() === '', 'A entrada geral assumiu uma empresa pela URL.');
verificar(Contexto::dados()['nome'] === 'Agendei', 'A entrada geral nao usou a aparencia da plataforma.');
verificar(!str_contains(url('entrar.php'), 'estabelecimento='), 'A URL da entrada geral carregou slug de empresa.');

// -------------------------------------------------------------------------
// Senha conferida em todas as contas do e-mail
// -------------------------------------------------------------------------
$contas = autenticarGlobal('pessoa-a@teste.local', 'Senha-AB-1');
$empresas = array_map('intval', array_column($contas, 'id_estabelecimento'));
sort($empresas);
verificar($empresas === [$ids['empresa-a'], $ids['empresa-e']], 'A entrada geral nao listou os dois vinculos da pessoa.');
verificar(!isset($contas[0]['senha_hash']), 'autenticarGlobal expos o hash de senha.');
verificar($contas[0]['estabelecimento_slug'] === 'empresa-a' && (int) $contas[0]['id_vinculo'] > 0, 'A conta veio sem o slug da empresa ou sem o vinculo.');
verificar($contas[0]['tipo'] === 'admin', 'A conta veio sem o tipo.');
verificar(autenticarGlobal('pessoa-a@teste.local', 'outra-senha-ignorada') === [], 'O segundo vinculo trocou a senha da pessoa.');

verificar(autenticarGlobal('pessoa-c@teste.local', 'Senha-C-99') !== [] && count(autenticarGlobal('pessoa-c@teste.local', 'Senha-C-99')) === 1, 'A senha exclusiva da empresa c nao achou a conta.');
verificar(autenticarGlobal('pessoa-a@teste.local', 'senha-errada') === [], 'Senha errada devolveu contas.');
verificar(autenticarGlobal('PESSOA-A@teste.local ', 'Senha-AB-1') !== [], 'O e-mail nao foi normalizado.');
verificar(autenticarGlobal('ninguem@teste.local', 'Senha-AB-1') === [], 'E-mail desconhecido devolveu contas.');
verificar(autenticarGlobal('nao-e-email', 'Senha-AB-1') === [], 'Identificador que nao e e-mail foi aceito.');
verificar(autenticarGlobal('pessoa-a@teste.local', '') === [], 'Senha vazia foi aceita.');

// Vinculo desligado some da escolha, mesmo com a senha certa.
bd()->exec("UPDATE vinculos SET status = 'inativo' WHERE id_estabelecimento = {$ids['empresa-b']}");
$contas = autenticarGlobal('pessoa-b@teste.local', 'Senha-AB-1');
verificar($contas === [], 'Vinculo inativo apareceu na entrada geral.');
bd()->exec("UPDATE vinculos SET status = 'ativo' WHERE id_estabelecimento = {$ids['empresa-b']}");

// Pessoa bloqueada pelo master nao entra em lugar nenhum, mesmo com vinculos ativos.
Usuario::alterarStatusPessoa($idPessoaA, 'inativo');
verificar(autenticarGlobal('pessoa-a@teste.local', 'Senha-AB-1') === [], 'Pessoa bloqueada entrou pela entrada geral.');
Usuario::alterarStatusPessoa($idPessoaA, 'ativo');

// A consulta sem segredos, usada pelas telas, ve todos os vinculos (inclusive os inativos).
$vinculos = Usuario::vinculosPorEmail('pessoa-a@teste.local');
verificar(count($vinculos) === 2, 'vinculosPorEmail nao listou os dois vinculos da pessoa.');
verificar(!isset($vinculos[0]['senha_hash']), 'vinculosPorEmail expos o hash de senha.');

// -------------------------------------------------------------------------
// Escolha da empresa: assumir() define o Contexto e a sessao nasce dela
// -------------------------------------------------------------------------
try {
    Contexto::assumir($ids['empresa-d']);
    verificar(false, 'Empresa inativa foi assumida.');
} catch (InvalidArgumentException) {
    verificar(Contexto::id() === 0, 'A recusa da empresa inativa alterou o contexto.');
}

Contexto::assumir($ids['empresa-b']);
verificar(Contexto::id() === $ids['empresa-b'] && Contexto::slug() === 'empresa-b', 'assumir nao trocou para a empresa escolhida.');
verificar(str_contains(url('dois_fatores.php'), 'estabelecimento=empresa-b'), 'Depois de assumir, a URL nao carrega o slug da empresa.');

$escolhida = autenticarGlobal('pessoa-b@teste.local', 'Senha-AB-1')[0];
$usuario = Usuario::porId((int) $escolhida['id_usuario']);
verificar($usuario !== null && (int) $usuario['id_estabelecimento'] === $ids['empresa-b'], 'A conta escolhida nao foi lida dentro da empresa.');
Contexto::assumir($ids['empresa-a']);
$idUsuarioA = (int) autenticarGlobal('pessoa-a@teste.local', 'Senha-AB-1')[0]['id_usuario'];
Contexto::assumir($ids['empresa-b']);
verificar(Usuario::porId($idUsuarioA) === null, 'A empresa assumida enxergou conta de outra empresa.');

LogAutenticacao::registrar('login_sucesso', 'pessoa-b@teste.local', $usuario);
LogAutenticacao::registrarEm($ids['empresa-a'], 'login_falha', 'pessoa-a@teste.local', $vinculos[0]);
LogAutenticacao::registrarEm(0, 'login_falha', 'pessoa-a@teste.local', null);
$logs = bd()->query('SELECT id_estabelecimento, evento FROM logs_autenticacao ORDER BY rowid')->fetchAll();
verificar(count($logs) === 2, 'Log sem empresa foi gravado, ou o log por empresa nao foi.');
verificar((int) $logs[0]['id_estabelecimento'] === $ids['empresa-b'] && $logs[0]['evento'] === 'login_sucesso', 'O log de sucesso nao ficou na empresa assumida.');
verificar((int) $logs[1]['id_estabelecimento'] === $ids['empresa-a'] && $logs[1]['evento'] === 'login_falha', 'O log de falha nao ficou na empresa indicada.');

registrarSessao($usuario);
verificar(ehAdmin() && (int) $_SESSION['estabelecimento_id'] === $ids['empresa-b'], 'A sessao nao nasceu na empresa escolhida.');
verificar(destinoAposLogin() === url('admin/dashboard.php') && str_contains(destinoAposLogin(), 'estabelecimento=empresa-b'), 'O destino apos a entrada geral perdeu a empresa.');

// Com a sessao aberta, a entrada geral deixa de ser neutra: o Contexto segue a sessao.
$_GET = [];
Contexto::iniciar();
verificar(Contexto::id() === $ids['empresa-b'], 'Com sessao aberta, a entrada geral voltou ao contexto neutro.');

session_destroy();
echo "OK: {$checagens} verificacoes da entrada geral (base: " . (BASE_URL ?: '/') . ").\n";
