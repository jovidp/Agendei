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
        'secure'   => requisicaoSegura(),
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

/**
 * Identificacao exibida no canto superior direito das telas.
 * Usa o login de 6 letras quando a conta tem um; senao, cai no e-mail.
 */
function usuarioLogin(): string
{
    return $_SESSION['usuario_login'] ?? ($_SESSION['usuario_email'] ?? '');
}

/** Rotulo do perfil autenticado, usado no cabecalho e no menu lateral. */
function perfilRotulo(?string $tipo = null): string
{
    return match ($tipo ?? perfil()) {
        'master'       => 'Administrador master',
        'admin'        => 'Usuario master',
        'profissional' => 'Profissional',
        'cliente'      => 'Usuario comum',
        default        => '',
    };
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
 * O identificador aceita o login de 6 letras ou o e-mail da conta.
 */
function autenticar(string $identificador, string $senha): ?array
{
    $usuario = Usuario::porLoginOuEmail($identificador);

    if (!$usuario || !password_verify($senha, $usuario['senha_hash'])) {
        LogAutenticacao::registrar('login_falha', $identificador, $usuario, null, cpfDoUsuario($usuario));
        return null;
    }

    if ($usuario['status'] !== 'ativo') {
        LogAutenticacao::registrar('login_falha', $identificador, $usuario, null, cpfDoUsuario($usuario));
        return null;
    }

    // Reforca o hash caso o algoritmo padrao do PHP tenha mudado.
    if (password_needs_rehash($usuario['senha_hash'], PASSWORD_DEFAULT)) {
        Usuario::atualizarSenha((int) $usuario['id_usuario'], $senha);
    }

    LogAutenticacao::registrar('login_sucesso', $identificador, $usuario, null, cpfDoUsuario($usuario));

    return $usuario;
}

/** CPF do perfil de cliente, usado apenas para alimentar o filtro da tela de log. */
function cpfDoUsuario(?array $usuario): ?string
{
    if ($usuario === null || ($usuario['tipo'] ?? '') !== 'cliente') {
        return null;
    }

    $cliente = Cliente::porUsuario((int) $usuario['id_usuario']);
    return $cliente['cpf'] ?? null;
}

// ---------------------------------------------------------------------
// Segundo fator de autenticacao (2FA)
// A conta so entra na sessao depois que a pergunta sorteada e respondida.
// ---------------------------------------------------------------------

/** Perfis avaliados pela especificacao: master (admin) e comum (cliente). */
function perfisComSegundoFator(): array
{
    return ['admin', 'cliente'];
}

/**
 * Remove acentos e caixa para comparar a resposta digitada com a cadastrada.
 *
 * O mapa e explicito de proposito: iconv com //TRANSLIT depende da biblioteca
 * do sistema e no Windows devolve "M'arcia" no lugar de "Marcia".
 */
function normalizarResposta(string $texto): string
{
    $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? '');
    $texto = mb_strtolower($texto, 'UTF-8');

    $acentos = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ];

    return strtr($texto, $acentos);
}

/**
 * Perguntas que a conta consegue responder, com o valor esperado.
 * Uma conta sem nenhum dos tres dados nao entra no fluxo de 2FA.
 */
function fatoresDisponiveis(array $usuario): array
{
    $disponiveis = [];

    if (trim((string) ($usuario['nome_materno'] ?? '')) !== '') {
        $disponiveis['nome_materno'] = (string) $usuario['nome_materno'];
    }
    if (!empty($usuario['data_nascimento'])) {
        $disponiveis['data_nascimento'] = (string) $usuario['data_nascimento'];
    }
    if (apenasNumeros($usuario['cep'] ?? '') !== '') {
        $disponiveis['cep'] = apenasNumeros($usuario['cep']);
    }

    return $disponiveis;
}

/** Indica se a conta deve passar pelo segundo fator antes de abrir a sessao. */
function exigeSegundoFator(array $usuario): bool
{
    return in_array($usuario['tipo'] ?? '', perfisComSegundoFator(), true)
        && fatoresDisponiveis($usuario) !== [];
}

/** Sorteia a pergunta e guarda o desafio pendente, sem autenticar a conta. */
function iniciarSegundoFator(array $usuario, string $identificador): void
{
    $fatores = fatoresDisponiveis($usuario);

    $_SESSION['segundo_fator'] = [
        'usuario_id'    => (int) $usuario['id_usuario'],
        'identificador' => $identificador,
        'fator'         => array_rand($fatores),
        'tentativas'    => 0,
    ];
}

/** Desafio pendente da sessao, ou null quando nao ha 2FA em andamento. */
function segundoFatorPendente(): ?array
{
    $pendente = $_SESSION['segundo_fator'] ?? null;

    return is_array($pendente) && !empty($pendente['usuario_id']) ? $pendente : null;
}

/** Descarta o desafio pendente (acerto, bloqueio ou desistencia). */
function cancelarSegundoFator(): void
{
    unset($_SESSION['segundo_fator']);
}

/** Quantas tentativas ainda restam antes do bloqueio. */
function tentativasRestantesSegundoFator(): int
{
    $pendente = segundoFatorPendente();

    return $pendente === null ? 0 : max(0, 3 - (int) $pendente['tentativas']);
}

/**
 * Compara a resposta digitada com o dado cadastrado.
 * A data aceita 10/03/1990 e 1990-03-10; o CEP ignora a mascara.
 */
function respostaSegundoFatorConfere(string $fator, string $resposta, array $usuario): bool
{
    $esperado = fatoresDisponiveis($usuario)[$fator] ?? null;

    if ($esperado === null) {
        return false;
    }

    if ($fator === 'cep') {
        $digitado = apenasNumeros($resposta);
        return $digitado !== '' && $digitado === $esperado;
    }

    if ($fator === 'data_nascimento') {
        $digitada = trim($resposta);
        // Aceita o formato brasileiro convertendo para o padrao do banco antes de comparar.
        if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $digitada, $partes)) {
            $digitada = $partes[3] . '-' . $partes[2] . '-' . $partes[1];
        }
        return $digitada !== '' && $digitada === $esperado;
    }

    $digitado = normalizarResposta($resposta);
    return $digitado !== '' && $digitado === normalizarResposta($esperado);
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
    $_SESSION['usuario_login'] = ($usuario['login'] ?? '') ?: $usuario['email'];
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
        redirecionar('erro.php?codigo=permissao');
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

    // O usuario comum segue a regra da especificacao; os demais perfis mantem o minimo antigo.
    $usuario = Usuario::porId($idUsuario);
    if (($usuario['tipo'] ?? '') === 'cliente') {
        if (!validarSenhaProjeto($novaSenha)) {
            $erros[] = 'A nova senha deve ter exatamente 8 caracteres alfabeticos.';
        }
    } elseif (!validarSenha($novaSenha)) {
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
