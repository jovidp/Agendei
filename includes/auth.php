<?php
/**
 * Autenticacao, controle de sessao, controle de acesso por perfil e CSRF.
 */

function iniciarSessao(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Restringe o cookie à aplicação, impede leitura por JavaScript e habilita secure sob HTTPS.
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_URL === '' ? '/' : BASE_URL,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
    ]);

    session_name('AGENDEI_SESSAO');
    session_start();
}

// ---------------------------------------------------------------------
// Estado da sessao
// ---------------------------------------------------------------------

/** Indica se a sessão atual possui um identificador de usuário autenticado. */
function estaLogado(): bool
{
    return !empty($_SESSION['usuario_id']) || !empty($_SESSION['master_id']);
}

/** Retorna o ID da conta autenticada ou null quando não há login. */
function usuarioId(): ?int
{
    return isset($_SESSION['usuario_id']) ? (int) $_SESSION['usuario_id'] : null;
}

/** Recupera o nome salvo na sessão para exibição no painel. */
function usuarioNome(): string
{
    return $_SESSION['usuario_nome'] ?? '';
}


/** Perfil do usuario logado: cliente, profissional ou admin. */
function perfil(): ?string
{
    return $_SESSION['usuario_tipo'] ?? null;
}

/** ID na tabela do perfil (id_cliente, id_profissional ou id_administrador). */
function perfilId(): ?int
{
    return isset($_SESSION['perfil_id']) ? (int) $_SESSION['perfil_id'] : null;
}

/** Verifica se o perfil da sessão é administrador. */
function ehAdmin(): bool
{
    return perfil() === 'admin';
}

/** Verifica se o perfil da sessão é profissional. */
function ehProfissional(): bool
{
    return perfil() === 'profissional';
}

/** Verifica se o perfil da sessão é cliente. */
function ehCliente(): bool
{
    return perfil() === 'cliente';
}

/** Verifica se a sessão pertence ao administrador global da plataforma. */
function ehMaster(): bool
{
    return perfil() === 'master';
}

// ---------------------------------------------------------------------
// Login / logout
// ---------------------------------------------------------------------

/**
 * Verifica as credenciais. Retorna o usuario ou null.
 */
function autenticar(string $email, string $senha): ?array
{
    $usuario = Usuario::porEmail($email);

    if (!$usuario || !password_verify($senha, $usuario['senha_hash'])) {
        return null;
    }

    if ($usuario['status'] !== 'ativo') {
        return null;
    }

    // Reforca o hash caso o algoritmo padrao do PHP tenha mudado.
    if (password_needs_rehash($usuario['senha_hash'], PASSWORD_DEFAULT)) {
        Usuario::atualizarSenha((int) $usuario['id_usuario'], $senha);
    }

    return $usuario;
}

/** Grava os dados do usuario na sessao. */
function registrarSessao(array $usuario): void
{
    // Renova o identificador no login para não reutilizar a sessão anterior à autenticação.
    session_regenerate_id(true);

    $_SESSION['estabelecimento_id'] = (int) $usuario['id_estabelecimento'];
    $_SESSION['usuario_id']    = (int) $usuario['id_usuario'];
    $_SESSION['usuario_nome']  = $usuario['nome'];
    $_SESSION['usuario_email'] = $usuario['email'];
    $_SESSION['usuario_tipo']  = $usuario['tipo'];
    $_SESSION['perfil_id']     = Usuario::idDoPerfil((int) $usuario['id_usuario'], $usuario['tipo']);

    Usuario::registrarAcesso((int) $usuario['id_usuario']);
}

/** Registra uma sessão global sem associá-la a um estabelecimento. */
function registrarSessaoMaster(array $master): void
{
    session_regenerate_id(true);
    unset($_SESSION['usuario_id'], $_SESSION['estabelecimento_id'], $_SESSION['perfil_id']);
    $_SESSION['master_id'] = (int) $master['id_master'];
    $_SESSION['usuario_nome'] = $master['nome'];
    $_SESSION['usuario_email'] = $master['email'];
    $_SESSION['usuario_tipo'] = 'master';
}

/** Limpa os dados, expira o cookie e encerra a sessão no servidor. */
function encerrarSessao(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $parametros = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $parametros['path'], $parametros['domain'], $parametros['secure'], $parametros['httponly']);
    }

    session_destroy();
}

// ---------------------------------------------------------------------
// Controle de acesso
// ---------------------------------------------------------------------

/** Painel inicial de cada perfil. */
function painelDe(?string $tipo): string
{
    return match ($tipo) {
        'admin'        => 'admin/dashboard.php',
        'profissional' => 'profissional/dashboard.php',
        'cliente'      => 'cliente/dashboard.php',
        'master'       => 'master/dashboard.php',
        default        => 'index.php',
    };
}

/**
 * Bloqueia o acesso a paginas restritas.
 * exigirLogin()            -> qualquer usuario autenticado
 * exigirLogin('admin')     -> somente administradores
 * exigirLogin(['admin','profissional'])
 */
function exigirLogin(array|string $tiposPermitidos = []): void
{
    if (!estaLogado()) {
        if (ehRequisicaoAjax()) {
            jsonResposta(['sucesso' => false, 'mensagem' => 'Sessao expirada. Faca login novamente.'], 401);
        }
        $_SESSION['redirecionar_apos_login'] = $_SERVER['REQUEST_URI'] ?? null;
        definirFlash('aviso', 'Faca login para continuar.');
        $permitidos = is_string($tiposPermitidos) ? [$tiposPermitidos] : $tiposPermitidos;
        redirecionar(in_array('master', $permitidos, true) ? 'master/login.php' : 'login.php');
    }

    $tipos = is_string($tiposPermitidos) ? [$tiposPermitidos] : $tiposPermitidos;

    if ($tipos !== [] && !in_array(perfil(), $tipos, true)) {
        if (ehRequisicaoAjax()) {
            jsonResposta(['sucesso' => false, 'mensagem' => 'Voce nao tem permissao para esta acao.'], 403);
        }
        definirFlash('erro', 'Voce nao tem permissao para acessar esta area.');
        redirecionar(painelDe(perfil()));
    }
}

/**
 * Para onde enviar o usuario logo apos o login.
 * Aceita apenas caminhos internos, evitando redirecionamento para sites externos.
 */
function destinoAposLogin(): string
{
    $destino = $_SESSION['redirecionar_apos_login'] ?? null;
    unset($_SESSION['redirecionar_apos_login']);

    if (is_string($destino) && preg_match('#^/[^/\\\\]#', $destino)) {
        return $destino;
    }

    if (perfil() === 'cliente' && ($_GET['destino'] ?? '') === 'agendar') {
        return url('cliente/agendar.php');
    }

    return url(painelDe(perfil()));
}

/** Impede que um usuario ja logado veja login/cadastro. */
function bloquearSeLogado(): void
{
    if (estaLogado()) {
        redirecionar(painelDe(perfil()));
    }
}

/**
 * Troca de senha a partir do painel. Retorna a lista de erros (vazia = alterada).
 */
function alterarSenhaUsuario(int $idUsuario, string $senhaAtual, string $novaSenha, string $confirmacao): array
{
    $erros = [];

    if (!Usuario::senhaConfere($idUsuario, $senhaAtual)) {
        $erros[] = 'A senha atual esta incorreta.';
    }
    if (!validarSenha($novaSenha)) {
        $erros[] = 'A nova senha deve ter no minimo 6 caracteres.';
    }
    if ($novaSenha !== $confirmacao) {
        $erros[] = 'As senhas nao conferem.';
    }

    if ($erros === []) {
        Usuario::atualizarSenha($idUsuario, $novaSenha);
    }

    return $erros;
}

// ---------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------

/** Gera e mantém um token aleatório na sessão para validar os formulários. */
function tokenCsrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Campo oculto para os formularios. */
function campoCsrf(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(tokenCsrf()) . '">';
}

/** Compara o token recebido com o da sessão usando hash_equals. */
function csrfValido(?string $token): bool
{
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

/** Interrompe o processamento de um POST sem token valido. */
function exigirCsrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }

    if (!csrfValido($_POST['csrf_token'] ?? null)) {
        if (ehRequisicaoAjax()) {
            jsonResposta(['sucesso' => false, 'mensagem' => 'Requisicao invalida. Recarregue a pagina.'], 419);
        }
        definirFlash('erro', 'Sessao expirada ou requisicao invalida. Tente novamente.');
        redirecionar(estaLogado() ? painelDe(perfil()) : 'login.php');
    }
}
