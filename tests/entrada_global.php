<?php
/**
 * Regressao da entrada geral (entrar.php): login sem o link do estabelecimento.
 * Usa apenas SQLite em memoria, como tests/acesso.php.
 *
 * Cobre: contexto neutro sob ENTRADA_GLOBAL, senha conferida em todas as
 * contas do e-mail, exclusao de conta e empresa inativas, escolha da empresa
 * por Contexto::assumir() e a sessao que nasce dela.
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
set_error_handler(static function (int $nivel, string $mensagem, string $arquivo, int $linha): never {
    throw new ErrorException($mensagem, 0, $nivel, $arquivo, $linha);
});
require RAIZ . '/includes/funcoes.php';
require RAIZ . '/includes/auth.php';
require RAIZ . '/includes/seguranca.php';
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
    CREATE TABLE usuarios (id_usuario INTEGER PRIMARY KEY, id_estabelecimento INTEGER, nome TEXT, email TEXT, senha_hash TEXT, tipo TEXT, status TEXT DEFAULT 'ativo', ultimo_acesso TEXT);
    CREATE TABLE administradores (id_administrador INTEGER PRIMARY KEY, id_estabelecimento INTEGER, id_usuario INTEGER, nivel TEXT);
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

// Quatro empresas com o mesmo e-mail responsavel:
//   a e b: mesma senha (a pessoa tem duas contas validas);
//   c: outra senha (nao pode aparecer na escolha);
//   d: mesma senha, mas empresa inativa.
$ids = [];
foreach (['empresa-a' => 'Senha-AB-1', 'empresa-b' => 'Senha-AB-1', 'empresa-c' => 'Senha-C-99', 'empresa-d' => 'Senha-AB-1'] as $slug => $senha) {
    $ids[$slug] = Estabelecimento::contratar([
        'estabelecimento' => $slug, 'slug' => $slug, 'nome' => 'Responsavel ' . $slug,
        'email' => 'pessoa@teste.local', 'senha' => $senha,
    ]);
}
bd()->exec("UPDATE estabelecimento SET status = 'inativo' WHERE slug = 'empresa-d'");

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
$contas = autenticarGlobal('pessoa@teste.local', 'Senha-AB-1');
$empresas = array_map('intval', array_column($contas, 'id_estabelecimento'));
sort($empresas);
verificar($empresas === [$ids['empresa-a'], $ids['empresa-b']], 'A senha nao selecionou exatamente as contas em que ela vale.');
verificar(!isset($contas[0]['senha_hash']), 'autenticarGlobal expos o hash de senha.');
verificar($contas[0]['estabelecimento_slug'] === 'empresa-a', 'A conta veio sem o slug da empresa.');
verificar($contas[0]['tipo'] === 'admin', 'A conta veio sem o tipo.');

verificar(autenticarGlobal('pessoa@teste.local', 'Senha-C-99') !== [] && count(autenticarGlobal('pessoa@teste.local', 'Senha-C-99')) === 1, 'A senha exclusiva da empresa c nao achou so a conta dela.');
verificar(autenticarGlobal('pessoa@teste.local', 'senha-errada') === [], 'Senha errada devolveu contas.');
verificar(autenticarGlobal('PESSOA@teste.local ', 'Senha-AB-1') !== [], 'O e-mail nao foi normalizado.');
verificar(autenticarGlobal('ninguem@teste.local', 'Senha-AB-1') === [], 'E-mail desconhecido devolveu contas.');
verificar(autenticarGlobal('nao-e-email', 'Senha-AB-1') === [], 'Identificador que nao e e-mail foi aceito.');
verificar(autenticarGlobal('pessoa@teste.local', '') === [], 'Senha vazia foi aceita.');

// Conta desligada some da escolha, mesmo com a senha certa.
bd()->exec("UPDATE usuarios SET status = 'inativo' WHERE id_estabelecimento = {$ids['empresa-b']}");
$contas = autenticarGlobal('pessoa@teste.local', 'Senha-AB-1');
verificar(count($contas) === 1 && (int) $contas[0]['id_estabelecimento'] === $ids['empresa-a'], 'Conta inativa apareceu na entrada geral.');
bd()->exec("UPDATE usuarios SET status = 'ativo' WHERE id_estabelecimento = {$ids['empresa-b']}");

// A consulta sem segredos, usada pelas telas, ve todos os vinculos (inclusive os inativos).
$vinculos = Usuario::vinculosPorEmail('pessoa@teste.local');
verificar(count($vinculos) === 4, 'vinculosPorEmail nao listou todas as empresas do e-mail.');
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

$escolhida = autenticarGlobal('pessoa@teste.local', 'Senha-AB-1')[1];
$usuario = Usuario::porId((int) $escolhida['id_usuario']);
verificar($usuario !== null && (int) $usuario['id_estabelecimento'] === $ids['empresa-b'], 'A conta escolhida nao foi lida dentro da empresa.');
verificar(Usuario::porId((int) autenticarGlobal('pessoa@teste.local', 'Senha-AB-1')[0]['id_usuario']) === null, 'A empresa assumida enxergou conta de outra empresa.');

LogAutenticacao::registrar('login_sucesso', 'pessoa@teste.local', $usuario);
LogAutenticacao::registrarEm($ids['empresa-a'], 'login_falha', 'pessoa@teste.local', $vinculos[0]);
LogAutenticacao::registrarEm(0, 'login_falha', 'pessoa@teste.local', null);
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
