<?php
/**
 * Regressao de "manter conectado" (SessaoLembrada) e da entrada com o Google
 * (Google). Usa apenas SQLite em memoria, como tests/entrada_global.php, e um
 * transporte falso no lugar do Google: nenhum banco instalado e acessado e
 * nenhuma chamada sai da maquina.
 *
 * Execute com "php tests/lembrar_google.php".
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('RAIZ', dirname(__DIR__));
define('BASE_URL', '');
define('AMBIENTE', 'desenvolvimento');
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

// Somente as tabelas/colunas usadas neste fluxo.
bd()->exec("CREATE TABLE estabelecimento (id_estabelecimento INTEGER PRIMARY KEY, nome TEXT, slug TEXT UNIQUE, status TEXT DEFAULT 'ativo');
    CREATE TABLE usuarios (id_usuario INTEGER PRIMARY KEY, nome TEXT, email TEXT UNIQUE, senha_hash TEXT, status TEXT DEFAULT 'ativo');
    CREATE TABLE vinculos (id_vinculo INTEGER PRIMARY KEY, id_estabelecimento INTEGER, id_usuario INTEGER, tipo TEXT, status TEXT DEFAULT 'ativo', login TEXT, ultimo_acesso TEXT, UNIQUE (id_estabelecimento, id_usuario, tipo));
    CREATE TABLE clientes (id_cliente INTEGER PRIMARY KEY, id_estabelecimento INTEGER, id_vinculo INTEGER, id_usuario INTEGER);
    CREATE TABLE logs_autenticacao (id_estabelecimento INTEGER, id_usuario INTEGER, login_informado TEXT, nome TEXT, cpf TEXT, perfil TEXT, evento TEXT, fator_2fa TEXT, ip TEXT);
    CREATE TABLE sessoes_lembradas (id_sessao INTEGER PRIMARY KEY, id_estabelecimento INTEGER, id_usuario INTEGER, id_vinculo INTEGER, seletor TEXT UNIQUE, validador_hash TEXT, expira_em TEXT, criado_em TEXT, ultimo_uso TEXT);");

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
session_start();

// -------------------------------------------------------------------------
// Massa: contas com e-mails unicos e uma empresa inativa
// -------------------------------------------------------------------------
bd()->exec("INSERT INTO estabelecimento (id_estabelecimento, nome, slug, status) VALUES (1, 'Studio Um', 'studio-um', 'ativo'), (2, 'Salao Dois', 'salao-dois', 'ativo'), (3, 'Fechado', 'fechado', 'inativo')");
bd()->exec("INSERT INTO usuarios (id_usuario, nome, email, senha_hash, status) VALUES
    (10, 'Ana Souza', 'ana@teste.local', 'x', 'ativo'),
    (11, 'Ana Souza', 'ana-dois@teste.local', 'x', 'ativo'),
    (12, 'Ana Souza', 'ana-fechado@teste.local', 'x', 'ativo'),
    (13, 'Bruno Lima', 'bruno@teste.local', 'x', 'ativo')");
// Um vinculo por pessoa; o de Bruno esta desligado na empresa.
bd()->exec("INSERT INTO vinculos (id_vinculo, id_estabelecimento, id_usuario, tipo, status, login) VALUES
    (110, 1, 10, 'cliente', 'ativo', 'anasou'),
    (111, 2, 11, 'admin', 'ativo', NULL),
    (112, 3, 12, 'cliente', 'ativo', NULL),
    (113, 1, 13, 'cliente', 'inativo', NULL)");
bd()->exec('INSERT INTO clientes (id_cliente, id_estabelecimento, id_vinculo, id_usuario) VALUES (100, 1, 110, 10)');

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'agendei.test';

/** Pessoa juntada ao vinculo, como as telas a recebem. */
function conta(int $id): array
{
    $q = bd()->prepare("SELECT u.*, v.id_vinculo, v.id_estabelecimento, v.tipo, v.login, v.ultimo_acesso,
                               v.status AS status_vinculo, u.status AS status_pessoa,
                               CASE WHEN u.status = 'ativo' AND v.status = 'ativo' THEN 'ativo' ELSE 'inativo' END AS status
                        FROM vinculos v JOIN usuarios u ON u.id_usuario = v.id_usuario
                        WHERE v.id_usuario = ? ORDER BY v.id_vinculo LIMIT 1");
    $q->execute([$id]);
    return $q->fetch();
}

// -------------------------------------------------------------------------
// SessaoLembrada: criar, reconhecer, renovar, apagar
// -------------------------------------------------------------------------
$cookie = SessaoLembrada::criar(1, 10, 110);
verificar((bool) preg_match('/^[a-f0-9]{24}:[a-f0-9]{64}$/D', $cookie), 'Cookie fora do formato seletor:validador.');

$registro = SessaoLembrada::porCookie($cookie);
verificar($registro !== null && (int) $registro['id_usuario'] === 10 && (int) $registro['id_estabelecimento'] === 1, 'Cookie recem-criado nao foi reconhecido.');
verificar(!str_contains((string) $registro['validador_hash'], explode(':', $cookie)[1]), 'O segredo do cookie foi gravado em claro.');

verificar(SessaoLembrada::porCookie('lixo') === null, 'Cookie malformado foi aceito.');
verificar(SessaoLembrada::porCookie(str_repeat('a', 24) . ':' . str_repeat('b', 64)) === null, 'Seletor desconhecido foi aceito.');

[$seletor] = explode(':', $cookie);
verificar(SessaoLembrada::porCookie($seletor . ':' . str_repeat('c', 64)) === null, 'Seletor certo com segredo errado foi aceito.');
verificar(SessaoLembrada::porCookie($cookie) === null, 'Copia com segredo errado nao invalidou o registro legitimo.');

$cookie = SessaoLembrada::criar(1, 10, 110);
$registro = SessaoLembrada::porCookie($cookie);
$novo = SessaoLembrada::renovar($registro);
verificar($novo !== $cookie && explode(':', $novo)[0] === $seletorNovo = explode(':', $cookie)[0], 'Renovar deveria trocar so o segredo.');
verificar(SessaoLembrada::porCookie($novo) !== null, 'Segredo novo nao foi reconhecido.');
verificar(SessaoLembrada::porCookie($cookie) === null, 'Segredo antigo continuou valendo depois da renovacao.');
// Usar o segredo antigo e o que uma copia roubada faria: o dispositivo inteiro cai.
verificar(SessaoLembrada::porCookie($novo) === null, 'Copia antiga usada nao derrubou o dispositivo.');

$vencido = SessaoLembrada::criar(1, 10, 110);
bd()->exec("UPDATE sessoes_lembradas SET expira_em = '2000-01-01 00:00:00'");
verificar(SessaoLembrada::porCookie($vencido) === null, 'Cookie vencido foi aceito.');
verificar((int) bd()->query('SELECT COUNT(*) FROM sessoes_lembradas')->fetchColumn() === 0, 'Registro vencido nao foi apagado ao ser usado.');

SessaoLembrada::criar(1, 10, 110);
SessaoLembrada::criar(2, 11, 111);
SessaoLembrada::apagarDoUsuario(10);
verificar((int) bd()->query('SELECT COUNT(*) FROM sessoes_lembradas')->fetchColumn() === 1, 'apagarDoUsuario apagou de mais ou de menos.');
bd()->exec("UPDATE sessoes_lembradas SET expira_em = '2000-01-01 00:00:00'");
verificar(SessaoLembrada::apagarVencidas() === 1, 'apagarVencidas nao limpou o registro vencido.');

// -------------------------------------------------------------------------
// Pedido no login: so vira cookie quando a sessao abre
// -------------------------------------------------------------------------
$_SESSION = [];
$_COOKIE = [];
pedirLembrarDispositivo(false);
registrarSessao(conta(10));
lembrarSeSolicitado(conta(10));
verificar(!isset($_COOKIE[LEMBRAR_COOKIE]), 'Sem pedido, o dispositivo foi lembrado.');
verificar((int) bd()->query('SELECT COUNT(*) FROM sessoes_lembradas')->fetchColumn() === 0, 'Sem pedido, houve registro.');

$_SESSION = [];
pedirLembrarDispositivo(true);
verificar(!empty($_SESSION['lembrar_dispositivo']), 'Pedido nao ficou na sessao.');
registrarSessao(conta(10));
verificar(!empty($_SESSION['lembrar_dispositivo']), 'registrarSessao apagou o pedido antes de ele ser atendido.');
lembrarSeSolicitado(conta(10));
verificar(isset($_COOKIE[LEMBRAR_COOKIE]) && SessaoLembrada::porCookie($_COOKIE[LEMBRAR_COOKIE]) !== null, 'Pedido nao virou cookie reconhecido.');
verificar(!isset($_SESSION['lembrar_dispositivo']), 'Pedido continuou na sessao depois de atendido.');
verificar((int) $_SESSION['usuario_id'] === 10 && (int) $_SESSION['estabelecimento_id'] === 1 && (int) $_SESSION['perfil_id'] === 100, 'registrarSessao sem Contexto perdeu dados da sessao.');

// -------------------------------------------------------------------------
// Sair esquece o dispositivo
// -------------------------------------------------------------------------
esquecerDispositivo();
verificar(!isset($_COOKIE[LEMBRAR_COOKIE]), 'Logout manteve o cookie.');
verificar((int) bd()->query('SELECT COUNT(*) FROM sessoes_lembradas')->fetchColumn() === 0, 'Logout manteve o registro.');

// -------------------------------------------------------------------------
// Restaurar a sessao a partir do cookie
// -------------------------------------------------------------------------
$_SESSION = [];
$_GET = [];
$_COOKIE = [LEMBRAR_COOKIE => SessaoLembrada::criar(1, 10, 110)];
$antes = $_COOKIE[LEMBRAR_COOKIE];
restaurarSessaoLembrada();
verificar(estaLogado() && (int) usuarioId() === 10 && (int) $_SESSION['estabelecimento_id'] === 1 && perfil() === 'cliente', 'Cookie valido nao reabriu a sessao.');
verificar($_COOKIE[LEMBRAR_COOKIE] !== $antes, 'O segredo do cookie nao foi trocado no uso.');
verificar(SessaoLembrada::porCookie($_COOKIE[LEMBRAR_COOKIE]) !== null, 'Cookie renovado nao e reconhecido.');
verificar((int) bd()->query("SELECT COUNT(*) FROM logs_autenticacao WHERE evento = 'login_sucesso' AND id_usuario = 10")->fetchColumn() >= 1, 'Sessao restaurada nao entrou no log de autenticacao.');

// Ja logado: o cookie nao mexe na sessao existente.
$_SESSION['usuario_nome'] = 'marcador';
restaurarSessaoLembrada();
verificar($_SESSION['usuario_nome'] === 'marcador', 'Restaurar sobrescreveu uma sessao aberta.');

// Link de outra empresa: a sessao nao renasce ali, mas o cookie fica.
$_SESSION = [];
$_GET = ['estabelecimento' => 'salao-dois'];
$guardado = $_COOKIE[LEMBRAR_COOKIE];
restaurarSessaoLembrada();
verificar(!estaLogado(), 'Sessao lembrada da empresa 1 renasceu no link da empresa 2.');
verificar($_COOKIE[LEMBRAR_COOKIE] === $guardado, 'Cookie foi descartado so por abrir outro link.');

// Link da propria empresa: renasce.
$_GET = ['estabelecimento' => 'studio-um'];
restaurarSessaoLembrada();
verificar(estaLogado() && (int) usuarioId() === 10, 'Sessao nao renasceu no link da propria empresa.');
$_GET = [];

// Conta inativa: registro e cookie somem.
$_SESSION = [];
$_COOKIE = [LEMBRAR_COOKIE => SessaoLembrada::criar(1, 13, 113)];
restaurarSessaoLembrada();
verificar(!estaLogado() && !isset($_COOKIE[LEMBRAR_COOKIE]), 'Conta inativa reabriu a sessao ou manteve o cookie.');
verificar((int) bd()->query('SELECT COUNT(*) FROM sessoes_lembradas WHERE id_usuario = 13')->fetchColumn() === 0, 'Registro da conta inativa ficou no banco.');

// Empresa inativa: idem.
$_SESSION = [];
$_COOKIE = [LEMBRAR_COOKIE => SessaoLembrada::criar(3, 12, 112)];
restaurarSessaoLembrada();
verificar(!estaLogado() && !isset($_COOKIE[LEMBRAR_COOKIE]), 'Empresa inativa reabriu a sessao.');

// Cookie invalido: apenas descartado.
$_SESSION = [];
$_COOKIE = [LEMBRAR_COOKIE => 'qualquer-coisa'];
restaurarSessaoLembrada();
verificar(!estaLogado() && !isset($_COOKIE[LEMBRAR_COOKIE]), 'Cookie invalido nao foi descartado.');

// Sessao master aberta: nada muda.
$_SESSION = ['master_id' => 5, 'usuario_tipo' => 'master'];
$_COOKIE = [LEMBRAR_COOKIE => SessaoLembrada::criar(1, 10, 110)];
restaurarSessaoLembrada();
verificar(ehMaster() && !isset($_SESSION['usuario_id']), 'Cookie lembrado derrubou a sessao master.');
$_SESSION = [];
$_COOKIE = [];

// Troca de senha esquece todos os dispositivos da conta.
SessaoLembrada::criar(1, 10, 110);
SessaoLembrada::criar(1, 10, 110);
verificar((int) bd()->query('SELECT COUNT(*) FROM sessoes_lembradas WHERE id_usuario = 10')->fetchColumn() >= 3, 'Massa dos dispositivos da conta 10.');
SessaoLembrada::apagarDoUsuario(10);
verificar((int) bd()->query('SELECT COUNT(*) FROM sessoes_lembradas WHERE id_usuario = 10')->fetchColumn() === 0, 'Dispositivos da conta nao foram apagados.');

// -------------------------------------------------------------------------
// Google: configuracao e URL de autorizacao
// -------------------------------------------------------------------------
putenv('AGENDEI_GOOGLE_CLIENT_ID');
putenv('AGENDEI_GOOGLE_CLIENT_SECRET');
verificar(!Google::configurado() || is_file(RAIZ . '/config/google.local.php'), 'Sem credenciais, o Google apareceu como configurado.');

putenv('AGENDEI_GOOGLE_CLIENT_ID=cliente-teste.apps.googleusercontent.com');
putenv('AGENDEI_GOOGLE_CLIENT_SECRET=segredo-teste');
verificar(Google::configurado(), 'Com as variaveis, o Google nao apareceu como configurado.');

$redirecionamento = Google::urlRedirecionamento();
verificar($redirecionamento === 'http://agendei.test/google_login.php', 'URL de redirecionamento inesperada: ' . $redirecionamento);

$urlAutorizacao = Google::urlAutorizacao('estado-xyz', $redirecionamento);
verificar(str_starts_with($urlAutorizacao, Google::URL_AUTORIZACAO . '?'), 'URL de autorizacao nao aponta para o Google.');
parse_str((string) parse_url($urlAutorizacao, PHP_URL_QUERY), $parametros);
verificar($parametros['client_id'] === 'cliente-teste.apps.googleusercontent.com' && $parametros['state'] === 'estado-xyz'
    && $parametros['redirect_uri'] === $redirecionamento && $parametros['response_type'] === 'code'
    && str_contains($parametros['scope'], 'email') && str_contains($parametros['scope'], 'openid'), 'Parametros da autorizacao incompletos.');
verificar(!str_contains($urlAutorizacao, 'segredo-teste'), 'O segredo do cliente vazou na URL de autorizacao.');

// -------------------------------------------------------------------------
// Google: leitura do id_token
// -------------------------------------------------------------------------
function jwt(array $carga): string
{
    $codificar = static fn (array $dados): string => rtrim(strtr(base64_encode(json_encode($dados)), '+/', '-_'), '=');
    return $codificar(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $codificar($carga) . '.assinatura';
}

$base = ['iss' => 'https://accounts.google.com', 'aud' => 'cliente-teste.apps.googleusercontent.com', 'exp' => time() + 3600, 'email' => 'Ana@Teste.local', 'email_verified' => true, 'name' => 'Ana Souza', 'sub' => '123'];
$carga = Google::lerIdToken(jwt($base), 'cliente-teste.apps.googleusercontent.com');
verificar($carga['email'] === 'ana@teste.local' && $carga['sub'] === '123', 'id_token valido nao foi lido (e-mail deveria sair em minusculas).');
verificar(Google::lerIdToken(jwt(['iss' => 'accounts.google.com', 'email_verified' => 'true'] + $base), 'cliente-teste.apps.googleusercontent.com')['email'] === 'ana@teste.local', 'Emissor sem https e email_verified em texto deveriam ser aceitos.');

function recusa(callable $acao): string
{
    try {
        $acao();
        return '';
    } catch (RuntimeException $erro) {
        return $erro->getMessage();
    }
}

verificar(recusa(fn () => Google::lerIdToken('nao-e-jwt', 'x')) !== '', 'Token malformado foi aceito.');
verificar(recusa(fn () => Google::lerIdToken(jwt(['aud' => 'outro-cliente'] + $base), 'cliente-teste.apps.googleusercontent.com')) !== '', 'Token de outro cliente foi aceito.');
verificar(recusa(fn () => Google::lerIdToken(jwt(['iss' => 'https://evil.example'] + $base), 'cliente-teste.apps.googleusercontent.com')) !== '', 'Token de outro emissor foi aceito.');
verificar(recusa(fn () => Google::lerIdToken(jwt(['exp' => time() - 10] + $base), 'cliente-teste.apps.googleusercontent.com')) !== '', 'Token vencido foi aceito.');
verificar(recusa(fn () => Google::lerIdToken(jwt(['email_verified' => false] + $base), 'cliente-teste.apps.googleusercontent.com')) !== '', 'E-mail nao verificado foi aceito.');
verificar(recusa(fn () => Google::lerIdToken(jwt(['email' => ''] + $base), 'cliente-teste.apps.googleusercontent.com')) !== '', 'Token sem e-mail foi aceito.');

// -------------------------------------------------------------------------
// Google: troca do codigo com o transporte falso
// -------------------------------------------------------------------------
$chamadas = [];
Google::$postar = static function (string $url, array $campos) use (&$chamadas, $base): array {
    $chamadas[] = [$url, $campos];
    if ($campos['code'] === 'codigo-bom') {
        return [200, json_encode(['id_token' => jwt($base), 'access_token' => 'irrelevante'])];
    }
    return [400, json_encode(['error' => 'invalid_grant', 'error_description' => 'Bad Request'])];
};

$dados = Google::trocarCodigo('codigo-bom', $redirecionamento);
verificar($dados['email'] === 'ana@teste.local', 'Troca do codigo nao devolveu o e-mail.');
verificar($chamadas[0][0] === Google::URL_TOKEN && $chamadas[0][1]['client_secret'] === 'segredo-teste'
    && $chamadas[0][1]['grant_type'] === 'authorization_code' && $chamadas[0][1]['redirect_uri'] === $redirecionamento, 'Troca do codigo enviou campos errados ao Google.');
verificar(recusa(fn () => Google::trocarCodigo('codigo-ruim', $redirecionamento)) === 'O Google nao confirmou a entrada. Tente novamente.', 'Codigo recusado nao gerou a mensagem esperada.');
Google::$postar = null;

// -------------------------------------------------------------------------
// Google: quais contas o e-mail confirmado abre
// -------------------------------------------------------------------------
$todas = Google::contas('ana@teste.local');
verificar(count($todas) === 1 && !isset($todas[0]['senha_hash']), 'Conta do e-mail: esperada uma unica empresa ativa, sem hash.');
verificar(!in_array(3, array_map(static fn ($c) => (int) $c['id_estabelecimento'], $todas), true), 'Empresa inativa entrou na lista.');

$daEmpresa = Google::contas('ANA-DOIS@teste.local', 'salao-dois');
verificar(count($daEmpresa) === 1 && (int) $daEmpresa[0]['id_usuario'] === 11, 'Com o link da empresa, so a conta dela deveria entrar.');
verificar(Google::contas('ana-fechado@teste.local', 'fechado') === [], 'Empresa inativa abriu conta pelo link.');
verificar(Google::contas('bruno@teste.local') === [], 'Conta inativa abriu pelo Google.');
verificar(Google::contas('ninguem@teste.local') === [], 'E-mail sem conta devolveu algo.');

// -------------------------------------------------------------------------
// Cadastro pelo Google: nome e e-mail esperam so a tela de origem, por pouco tempo
// -------------------------------------------------------------------------
Google::guardarCadastro(['name' => ' Ana Souza ', 'email' => 'ana@teste.local'], 'cadastro');
$pendente = Google::cadastroPendente('cadastro');
verificar($pendente !== null && $pendente['nome'] === 'Ana Souza' && $pendente['email'] === 'ana@teste.local', 'A confirmacao nao voltou para a tela de origem.');
verificar(Google::cadastroPendente('cadastro_empresa') === null, 'A confirmacao do cadastro do cliente vazou para o cadastro de empresa.');
verificar(Google::cadastroPendente('cadastro') !== null, 'Consultar por outra tela apagou a confirmacao.');

$_SESSION['google_cadastro']['expira'] = time() - 1;
verificar(Google::cadastroPendente('cadastro') === null && !isset($_SESSION['google_cadastro']), 'Confirmacao vencida continuou valendo.');

Google::guardarCadastro(['email' => 'ana@teste.local'], 'cadastro_empresa');
verificar((Google::cadastroPendente('cadastro_empresa')['nome'] ?? 'x') === '', 'Sem nome vindo do Google, o nome deveria ficar vazio.');
Google::limparCadastro();
verificar(Google::cadastroPendente('cadastro_empresa') === null, 'limparCadastro nao esqueceu a confirmacao.');

echo "OK: {$checagens} verificacoes de manter conectado e entrada com o Google.\n";
