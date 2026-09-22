<?php
/** Regressao do cadastro/login local e dos destinos. Usa apenas SQLite em memoria. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('RAIZ', dirname(__DIR__));
define('BASE_URL', $argv[1] ?? '');
define('AMBIENTE', 'desenvolvimento');
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
foreach (['empresa-a', 'empresa-b'] as $slug) {
    $id = Estabelecimento::contratar([
        'estabelecimento' => $slug, 'slug' => $slug, 'nome' => 'Responsavel Teste',
        'email' => 'admin@teste.local', 'senha' => 'Teste12345!',
    ]);
    $_SESSION = [];
    $_GET = ['estabelecimento' => $slug];
    Contexto::iniciar();
    $usuario = autenticar('admin@teste.local', 'Teste12345!');
    verificar($usuario !== null && (int) $usuario['id_estabelecimento'] === $id, 'Login selecionou outra empresa.');
    verificar($usuario['tipo'] === 'admin', 'Cadastro nao criou administrador local.');
    verificar(autenticar('admin@teste.local', 'senha-errada') === null, 'Senha invalida aceita.');

    // Fora da entrada geral (tests/entrada_global.php), a requisicao nunca troca de empresa.
    try {
        Contexto::assumir($id);
        verificar(false, 'Contexto::assumir aceito fora da entrada geral.');
    } catch (LogicException) {
        verificar(Contexto::id() === $id, 'Contexto mudou apos a recusa do assumir.');
    }

    // A conta master e global e nunca assume um estabelecimento pela URL (o
    // multitenancy cobre isso com o esquema completo). As paginas de entrada
    // local descartam a identidade master primeiro (ENTRADA_LOCAL) - e ai o slug
    // resolve e o login local funciona: e assim que o master entra no que
    // acabou de criar.
    $_SESSION = ['master_id' => 99, 'usuario_tipo' => 'master'];
    descartarIdentidadeMaster();
    verificar(!sessaoAbertaEm('master') && perfil() === null, 'Identidade master nao foi descartada.');
    Contexto::iniciar();
    verificar(Contexto::id() === $id, 'Sem a identidade master, o slug nao resolveu o estabelecimento.');
    verificar(autenticar('admin@teste.local', 'Teste12345!') !== null, 'Login local falhou apos descartar a identidade master.');
    $_SESSION = [];
    Contexto::iniciar();

    // Residuos de outra identidade nao podem sobreviver ao login local.
    $_SESSION['master_id'] = 99;
    $_SESSION['simulacao'] = ['master_id' => 99];
    $_SESSION['segundo_fator_master'] = ['master_id' => 99];
    $_SESSION['redirecionar_apos_login'] = BASE_URL . '/master/dashboard.php';
    // A tela de login local precisa continuar acessivel com a sessao master aberta.
    verificar(!sessaoAbertaEm('local') && sessaoAbertaEm('master'), 'Sessao master bloqueou a tela de login local.');
    registrarSessao($usuario);
    verificar(ehAdmin() && !ehMaster() && !ehSimulacao() && !isset($_SESSION['master_id']), 'Login manteve identidade master.');
    verificar(sessaoAbertaEm('local') && !sessaoAbertaEm('master'), 'Login local nao encerrou a sessao master.');
    verificar(segundoFatorMasterPendente() === null, 'Login manteve desafio master.');
    verificar(perfilId() !== null, 'Login perdeu o perfil administrativo.');
    verificar(perfilRotulo() === 'Administrador do estabelecimento', 'Rotulo confunde admin com master.');
    verificar(destinoAposLogin() === url('admin/dashboard.php'), 'Login local encaminhou ao master.');
    verificar(!isset($_SESSION['redirecionar_apos_login']), 'Destino antigo continuou na sessao.');
    Contexto::iniciar();
    verificar(Contexto::id() === $id, 'Sessao perdeu o estabelecimento depois do login.');
}

foreach (['admin', 'cliente', 'profissional', 'master'] as $perfil) {
    $_SESSION['usuario_tipo'] = $perfil;
    $destinoCorreto = url(painelDe($perfil));
    foreach (['admin', 'cliente', 'profissional', 'master'] as $outraArea) {
        $destino = BASE_URL . '/' . $outraArea . '/dashboard.php';
        $_SESSION['redirecionar_apos_login'] = $destino;
        verificar(destinoAposLogin() === ($perfil === $outraArea ? $destino : $destinoCorreto), 'Destino cruzou perfis.');
    }
    foreach ([
        'https://externo.test' . BASE_URL . '/' . $perfil . '/dashboard.php',
        '//externo.test' . BASE_URL . '/' . $perfil . '/dashboard.php',
        BASE_URL . '/' . $perfil . '/../master/dashboard.php',
        BASE_URL . '/' . $perfil . '/%2e%2e/master/dashboard.php',
        BASE_URL . '/' . $perfil . '/dashboard.php?estabelecimento=empresa-a',
        '/outra-aplicacao/' . $perfil . '/dashboard.php',
    ] as $destino) {
        $_SESSION['redirecionar_apos_login'] = $destino;
        verificar(destinoAposLogin() === $destinoCorreto, 'Destino indevido aceito: ' . $destino);
    }
}

$_SESSION['usuario_tipo'] = 'admin';
$agenda = url('admin/agenda.php?data=2026-09-21');
$_SESSION['redirecionar_apos_login'] = $agenda;
verificar(destinoAposLogin() === $agenda, 'Destino valido perdeu filtros ou estabelecimento.');
$_SESSION['usuario_tipo'] = 'cliente';
$_GET['destino'] = 'agendar';
verificar(destinoAposLogin() === url('cliente/agendar.php'), 'Atalho para agendamento foi perdido.');

registrarSessaoMaster(['id_master' => 99, 'nome' => 'Gestao', 'email' => 'master@teste.local'], false);
verificar(ehMaster() && usuarioId() === null && perfilId() === null, 'Login master manteve identidade local.');
verificar(!isset($_SESSION['estabelecimento_id']), 'Master permaneceu vinculado a empresa.');
verificar(painelDe(perfil()) === 'master/dashboard.php', 'Master perdeu seu painel global.');

session_destroy();
echo "OK: {$checagens} verificacoes de acesso (base: " . (BASE_URL ?: '/') . ").\n";
