<?php
/**
 * Autenticacao, controle de sessao, controle de acesso por perfil e CSRF.
 */

function iniciarSessao(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // O modo estrito faz o PHP recusar um identificador de sessao que ele nunca
    // emitiu. Sem isso, basta um link com o ID escolhido pelo atacante para
    // fixar a sessao da vitima antes mesmo dela digitar a senha.
    ini_set('session.use_strict_mode', '1');
    // O identificador viaja so no cookie: nada de sessao presa na URL, que
    // vazaria pelo Referer, pelo historico e pelo log do servidor.
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    // Entropia cheia no identificador gerado pelo PHP.
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');

    // Restringe o cookie à aplicação, impede leitura por JavaScript e habilita secure sob HTTPS.
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_URL === '' ? '/' : BASE_URL,
        'httponly' => true,
        // Strict quebraria a volta de um link externo para o painel; Lax ja impede
        // que um POST vindo de outro site carregue o cookie junto.
        'samesite' => 'Lax',
        'secure'   => requisicaoSegura(),
    ]);

    // O prefixo __Host- so e aceito pelo navegador com Secure, path=/ e sem Domain.
    // Quando as tres condicoes valem, ele impede que um subdominio vizinho
    // sobrescreva o cookie de sessao do sistema.
    session_name(requisicaoSegura() && BASE_URL === '' ? '__Host-AGENDEI_SESSAO' : 'AGENDEI_SESSAO');
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
        'admin'        => 'Administrador do estabelecimento',
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

/**
 * Autenticacao pela entrada geral, sem estabelecimento na URL.
 *
 * O e-mail e unico apenas dentro de cada empresa, entao o mesmo endereco pode
 * ter varias contas. A senha e conferida em todas, e so as que batem (e estao
 * ativas, em empresa ativa) voltam: a pessoa escolhe entre elas sem que a tela
 * revele em quais empresas um e-mail existe. O login de 6 letras nao serve
 * aqui porque tambem e unico so por empresa.
 *
 * Nao grava log nem abre sessao: isso exige a empresa definida, e fica a cargo
 * de quem chama, depois de Contexto::assumir(). O hash nunca sai daqui.
 */
function autenticarGlobal(string $email, string $senha): array
{
    $email = mb_strtolower(trim($email));
    if ($senha === '' || !validarEmail($email)) {
        return [];
    }

    $contas = [];
    foreach (Usuario::porEmailGlobal($email) as $conta) {
        if (!password_verify($senha, $conta['senha_hash'])) {
            continue;
        }
        if ($conta['status'] !== 'ativo' || $conta['estabelecimento_status'] !== 'ativo') {
            continue;
        }
        unset($conta['senha_hash'], $conta['totp_segredo'], $conta['token_recuperacao']);
        $contas[] = $conta;
    }

    return $contas;
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
    // O aplicativo autenticador vale para qualquer perfil: quem cadastrou um
    // codigo passa por ele, inclusive o profissional, que nao entra na regra
    // de perfis da especificacao.
    if (Usuario::totpAtivo($usuario)) {
        return true;
    }

    return in_array($usuario['tipo'] ?? '', perfisComSegundoFator(), true)
        && fatoresDisponiveis($usuario) !== [];
}

/**
 * Escolhe o desafio e guarda o pendente, sem autenticar a conta.
 *
 * Quem tem aplicativo autenticador cadastrado sempre cai no codigo — ele e o
 * fator forte, e deixar a pergunta cadastral disponivel em paralelo rebaixaria
 * a seguranca da conta ao elo mais fraco. A pergunta continua existindo apenas
 * para quem ainda nao cadastrou o aplicativo.
 */
function iniciarSegundoFator(array $usuario, string $identificador): void
{
    $usaCodigo = Usuario::totpAtivo($usuario);

    $_SESSION['segundo_fator'] = [
        'usuario_id'    => (int) $usuario['id_usuario'],
        'identificador' => $identificador,
        'fator'         => $usaCodigo ? 'totp' : array_rand(fatoresDisponiveis($usuario)),
        'tentativas'    => 0,
    ];
}

/** Indica se o desafio em andamento e o codigo do aplicativo autenticador. */
function segundoFatorEhCodigo(?string $fator): bool
{
    return $fator === 'totp';
}

// ---------------------------------------------------------------------
// Segundo fator da conta master
//
// Fica em funcoes proprias porque a conta master nao esta em usuarios: o
// desafio pendente e outro, e a sessao aberta no fim tambem.
// ---------------------------------------------------------------------

/** Guarda o desafio pendente da conta master, sem abrir a sessao. */
function iniciarSegundoFatorMaster(array $master): void
{
    $_SESSION['segundo_fator_master'] = [
        'master_id'  => (int) $master['id_master'],
        'tentativas' => 0,
    ];
}

/** Desafio master pendente, ou null quando nao ha nenhum em andamento. */
function segundoFatorMasterPendente(): ?array
{
    $pendente = $_SESSION['segundo_fator_master'] ?? null;

    return is_array($pendente) && !empty($pendente['master_id']) ? $pendente : null;
}

/** Descarta o desafio master pendente. */
function cancelarSegundoFatorMaster(): void
{
    unset($_SESSION['segundo_fator_master']);
}

/** Tentativas que ainda restam no desafio master, das tres previstas. */
function tentativasRestantesSegundoFatorMaster(): int
{
    $pendente = segundoFatorMasterPendente();

    return $pendente === null ? 0 : max(0, 3 - (int) $pendente['tentativas']);
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
 * Confere a resposta do desafio pendente, seja qual for o tipo.
 *
 * Ponto unico de entrada das telas: o codigo do aplicativo precisa de uma
 * checagem extra que a pergunta cadastral nao tem — a janela usada nao pode
 * ser a mesma de um acesso anterior —, e essa diferenca fica escondida aqui.
 */
function conferirSegundoFator(string $fator, string $resposta, array $usuario): bool
{
    if (!segundoFatorEhCodigo($fator)) {
        return respostaSegundoFatorConfere($fator, $resposta, $usuario);
    }

    $contadorUsado = null;

    if (!Totp::confere(Usuario::totpSegredo($usuario), $resposta, $contadorUsado)) {
        return false;
    }

    // Codigo certo, porem de uma janela ja aproveitada: recusado como se
    // estivesse errado. E o que impede reapresentar um codigo observado.
    if (!Usuario::totpContadorUsado((int) $usuario['id_usuario'], $usuario, (int) $contadorUsado)) {
        registrarEventoSeguranca('totp_reapresentado', ['usuario' => (int) $usuario['id_usuario']]);
        return false;
    }

    return true;
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

    // Um login local nunca herda a identidade global ou uma simulacao anterior.
    unset($_SESSION['master_id'], $_SESSION['simulacao'], $_SESSION['segundo_fator_master'], $_SESSION['segundo_fator']);

    $_SESSION['estabelecimento_id'] = (int) $usuario['id_estabelecimento'];
    $_SESSION['usuario_id']    = (int) $usuario['id_usuario'];
    $_SESSION['usuario_nome']  = $usuario['nome'];
    $_SESSION['usuario_email'] = $usuario['email'];
    $_SESSION['usuario_login'] = ($usuario['login'] ?? '') ?: $usuario['email'];
    $_SESSION['usuario_tipo']  = $usuario['tipo'];
    // A empresa vem da propria conta, e nao do Contexto: a sessao lembrada
    // (restaurarSessaoLembrada) abre antes de o Contexto resolver a empresa.
    $_SESSION['perfil_id']     = Usuario::idDoPerfilEm((int) $usuario['id_estabelecimento'], (int) $usuario['id_usuario'], $usuario['tipo']);

    // Marca os instantes que controlam inatividade, duracao maxima e dispositivo.
    marcarInicioSessao();

    Usuario::registrarAcessoEm((int) $usuario['id_estabelecimento'], (int) $usuario['id_usuario']);
}

/**
 * Registra uma sessão global sem associá-la a um estabelecimento.
 * $auditar sai como falso na volta de uma simulacao: ali nao houve login novo,
 * e a auditoria ja recebe o evento proprio de fim de simulacao.
 */
function registrarSessaoMaster(array $master, bool $auditar = true): void
{
    session_regenerate_id(true);
    // usuario_login precisa sair junto: na volta de uma simulacao ele ainda
    // guarda o login do administrador, e o topo do painel passaria a anunciar
    // a conta errada para quem ja voltou a ser master.
    unset($_SESSION['usuario_id'], $_SESSION['estabelecimento_id'], $_SESSION['perfil_id'], $_SESSION['usuario_login'], $_SESSION['simulacao'], $_SESSION['segundo_fator'], $_SESSION['segundo_fator_master']);
    $_SESSION['master_id'] = (int) $master['id_master'];
    $_SESSION['usuario_nome'] = $master['nome'];
    $_SESSION['usuario_email'] = $master['email'];
    $_SESSION['usuario_tipo'] = 'master';

    marcarInicioSessao();
    registrarEventoSeguranca('master_login', ['id' => (int) $master['id_master']]);

    // A auditoria comeca na entrada: sem ela, as acoes registradas depois nao
    // teriam como ser amarradas a uma sessao e a um horario de acesso.
    if ($auditar) {
        LogMaster::registrar('login');
    }
}

// ---------------------------------------------------------------------
// Simulacao: o master vendo o painel de um estabelecimento
//
// Existe para o suporte. Sem ela, a unica forma de o master enxergar o que o
// cliente esta vendo era redefinir a senha do administrador local — ou seja,
// tirar o acesso de quem pediu ajuda para poder ajudar.
//
// A sessao vira a do administrador, mas guarda a marca de quem a abriu: o
// aviso no topo da tela nao sai enquanto durar, e a volta so restaura a conta
// master registrada aqui dentro, nunca uma vinda do formulario.
// ---------------------------------------------------------------------

/** Indica se a sessao atual e um master vendo o painel de outra pessoa. */
function ehSimulacao(): bool
{
    return !empty($_SESSION['simulacao']['master_id']);
}

/** Dados da simulacao em andamento, ou null quando nao ha uma. */
function simulacaoAtual(): ?array
{
    return ehSimulacao() ? $_SESSION['simulacao'] : null;
}

/**
 * Troca a sessao master pela do administrador local indicado.
 * $perfilId vem do chamador porque Usuario::idDoPerfil() se apoia no
 * Contexto, que na area master nao aponta para empresa nenhuma.
 */
function iniciarSimulacao(array $usuario, array $master, ?int $perfilId): void
{
    session_regenerate_id(true);

    unset($_SESSION['master_id']);
    $_SESSION['estabelecimento_id'] = (int) $usuario['id_estabelecimento'];
    $_SESSION['usuario_id']    = (int) $usuario['id_usuario'];
    $_SESSION['usuario_nome']  = $usuario['nome'];
    $_SESSION['usuario_email'] = $usuario['email'];
    $_SESSION['usuario_login'] = ($usuario['login'] ?? '') ?: $usuario['email'];
    $_SESSION['usuario_tipo']  = $usuario['tipo'];
    $_SESSION['perfil_id']     = $perfilId;

    $_SESSION['simulacao'] = [
        'master_id'   => (int) $master['id_master'],
        'master_nome' => (string) $master['nome'],
        'usuario'     => (string) $usuario['nome'],
        'inicio'      => time(),
    ];

    marcarInicioSessao();

    // Usuario::registrarAcesso() fica de fora de proposito: "ultimo acesso" diz
    // quando o responsavel entrou, e o master no lugar dele nao pode
    // reescrever esse dado — ele e usado para saber se a conta esta em uso.
    registrarEventoSeguranca('simulacao_iniciada', [
        'master'  => (int) $master['id_master'],
        'usuario' => (int) $usuario['id_usuario'],
    ]);
}

/**
 * Desfaz a simulacao e devolve a sessao a conta master que a abriu.
 * Retorna o registro do master restaurado, ou null se ele nao puder mais entrar.
 */
function encerrarSimulacao(): ?array
{
    $simulacao = simulacaoAtual();
    unset($_SESSION['simulacao']);

    if ($simulacao === null) {
        return null;
    }

    // A conta pode ter sido desativada enquanto a simulacao corria.
    $master = Master::porId((int) $simulacao['master_id']);
    if (!$master || $master['status'] !== 'ativo') {
        return null;
    }

    registrarSessaoMaster($master, false);
    registrarEventoSeguranca('simulacao_encerrada', ['master' => (int) $master['id_master']]);

    return $master;
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
        // Conta autenticada tentando area de outro perfil e sinal de sondagem:
        // fica no log para que a tentativa seja visivel mesmo sendo barrada.
        registrarEventoSeguranca('acesso_negado', [
            'perfil'    => (string) perfil(),
            'exigido'   => implode('|', $tipos),
            'usuario'   => (int) (usuarioId() ?? 0),
        ]);
        if (ehRequisicaoAjax()) {
            jsonResposta(['sucesso' => false, 'mensagem' => 'Voce nao tem permissao para esta acao.'], 403);
        }
        definirFlash('erro', 'Voce nao tem permissao para acessar esta area.');
        redirecionar('erro.php?codigo=permissao');
    }
}

/**
 * Para onde enviar o usuario logo apos o login.
 * Aceita apenas paginas da area do perfil e do estabelecimento autenticados.
 */
function destinoAposLogin(): string
{
    $destino = $_SESSION['redirecionar_apos_login'] ?? null;
    unset($_SESSION['redirecionar_apos_login']);

    if (is_string($destino)) {
        $partes = parse_url($destino);
        $padrao = '#^' . preg_quote(BASE_URL, '#') . '/(admin|cliente|profissional|master)/[a-z_]+\\.php$#D';
        if (is_array($partes)
            && !isset($partes['host']) && !isset($partes['scheme'])
            && preg_match($padrao, $partes['path'] ?? '', $area)
            && $area[1] === perfil()) {
            parse_str($partes['query'] ?? '', $parametros);
            if (!isset($parametros['estabelecimento']) || $parametros['estabelecimento'] === Contexto::slug()) {
                return $destino;
            }
        }
    }

    if (perfil() === 'cliente' && ($_GET['destino'] ?? '') === 'agendar') {
        return url('cliente/agendar.php');
    }

    return url(painelDe(perfil()));
}

/**
 * Descarta a identidade master da sessao, preservando o restante (token CSRF).
 *
 * Chamado pelo bootstrap nas paginas de entrada local (ENTRADA_LOCAL): quem abre
 * o login de um estabelecimento quer uma sessao local, e as duas identidades sao
 * excludentes. E o que permite ao master entrar no estabelecimento que acabou de
 * criar sem que a conta global assuma um estabelecimento pela URL nas demais
 * paginas. Sem sessao master, nao faz nada.
 */
function descartarIdentidadeMaster(): void
{
    if (empty($_SESSION['master_id']) && ($_SESSION['usuario_tipo'] ?? null) !== 'master') {
        return;
    }

    unset(
        $_SESSION['master_id'],
        $_SESSION['simulacao'],
        $_SESSION['segundo_fator_master'],
        $_SESSION['usuario_tipo'],
        $_SESSION['usuario_nome'],
        $_SESSION['usuario_email']
    );
}

/**
 * Ha uma sessao aberta na area indicada ('local' = estabelecimento, 'master')?
 *
 * As duas identidades sao excludentes: registrarSessao descarta a master e
 * registrarSessaoMaster descarta a local. Por isso uma sessao master, sozinha,
 * nao conta como "logado" para as telas do estabelecimento, e vice-versa.
 */
function sessaoAbertaEm(string $area): bool
{
    return $area === 'master'
        ? !empty($_SESSION['master_id'])
        : !empty($_SESSION['usuario_id']);
}

/**
 * Impede que um usuario ja logado veja login/cadastro.
 *
 * So bloqueia quando ja existe sessao do MESMO tipo da tela. Sem isso, o master
 * que acabava de criar um estabelecimento nao conseguia entrar nele no mesmo
 * navegador: login.php o mandava de volta ao painel master.
 */
function bloquearSeLogado(string $area = 'local'): void
{
    if (sessaoAbertaEm($area)) {
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
        // Senha nova derruba os dispositivos lembrados: e o que se espera de quem
        // troca a senha porque desconfia de um aparelho.
        SessaoLembrada::apagarDoUsuario($idUsuario);
    }

    return $erros;
}

// ---------------------------------------------------------------------
// Manter conectado (dispositivo lembrado)
//
// O cookie de sessao morre com o navegador e a sessao cai por inatividade.
// Quem marca "manter conectado" recebe um segundo cookie, de 30 dias, que
// reabre a sessao sozinho (restaurarSessaoLembrada, chamada pelo bootstrap).
// O segredo vive so no cookie; o banco guarda o hash (models/SessaoLembrada.php).
// ---------------------------------------------------------------------

const LEMBRAR_COOKIE = 'AGENDEI_LEMBRAR';

/** Mesmas regras do cookie de sessao (caminho, HttpOnly, SameSite, Secure), com validade propria. */
function parametrosCookieLembrar(int $expira): array
{
    return [
        'expires'  => $expira,
        'path'     => BASE_URL === '' ? '/' : BASE_URL,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => requisicaoSegura(),
    ];
}

/** Guarda o pedido do formulario; e atendido quando a sessao abre, depois do 2FA se houver. */
function pedirLembrarDispositivo(bool $pedido): void
{
    if ($pedido) {
        $_SESSION['lembrar_dispositivo'] = true;
    } else {
        unset($_SESSION['lembrar_dispositivo']);
    }
}

/** Cria o registro e o cookie quando o login pediu "manter conectado". Chamar logo apos registrarSessao(). */
function lembrarSeSolicitado(array $usuario): void
{
    if (empty($_SESSION['lembrar_dispositivo'])) {
        return;
    }
    unset($_SESSION['lembrar_dispositivo']);

    try {
        gravarCookieLembrar(SessaoLembrada::criar((int) $usuario['id_estabelecimento'], (int) $usuario['id_usuario']));
    } catch (Throwable $erro) {
        // Sem a tabela (banco anterior a migracao) o login segue normal, so nao lembra.
        error_log('Nao foi possivel lembrar o dispositivo: ' . $erro->getMessage());
    }
}

function gravarCookieLembrar(string $valor): void
{
    $_COOKIE[LEMBRAR_COOKIE] = $valor;
    if (!headers_sent()) {
        setcookie(LEMBRAR_COOKIE, $valor, parametrosCookieLembrar(time() + SessaoLembrada::DIAS * 86400));
    }
}

function apagarCookieLembrar(): void
{
    unset($_COOKIE[LEMBRAR_COOKIE]);
    if (!headers_sent()) {
        setcookie(LEMBRAR_COOKIE, '', parametrosCookieLembrar(time() - 86400));
    }
}

/** Sair da conta: apaga o registro deste cookie e o proprio cookie. */
function esquecerDispositivo(): void
{
    $valor = (string) ($_COOKIE[LEMBRAR_COOKIE] ?? '');
    if ($valor !== '') {
        try {
            $registro = SessaoLembrada::porCookie($valor);
            if ($registro !== null) {
                SessaoLembrada::apagar((int) $registro['id_sessao']);
            }
        } catch (Throwable $erro) {
            error_log('Nao foi possivel esquecer o dispositivo: ' . $erro->getMessage());
        }
    }
    apagarCookieLembrar();
}

/**
 * Reabre a sessao a partir do cookie de "manter conectado".
 *
 * Roda no bootstrap, antes de o Contexto resolver a empresa: e a sessao
 * restaurada que diz qual empresa e. So age sem sessao aberta e fora da area
 * master e das tarefas. Num link de outra empresa nao faz nada, para que o
 * cookie nao "sequestre" a pagina; conta ou empresa inativa apaga tudo. A cada
 * uso o segredo do cookie e trocado, e a entrada vai para o log de autenticacao.
 */
function restaurarSessaoLembrada(): void
{
    if (estaLogado() || defined('AREA_MASTER') || defined('TAREFA_AGENDADA')) {
        return;
    }
    $valor = (string) ($_COOKIE[LEMBRAR_COOKIE] ?? '');
    if ($valor === '') {
        return;
    }

    try {
        $registro = SessaoLembrada::porCookie($valor);
        if ($registro === null) {
            apagarCookieLembrar();
            return;
        }

        $q = bd()->prepare(
            'SELECT u.*, e.slug AS estabelecimento_slug, e.status AS estabelecimento_status
             FROM usuarios u
             JOIN estabelecimento e ON e.id_estabelecimento = u.id_estabelecimento
             WHERE u.id_usuario = ? AND u.id_estabelecimento = ? LIMIT 1'
        );
        $q->execute([(int) $registro['id_usuario'], (int) $registro['id_estabelecimento']]);
        $usuario = $q->fetch();

        if (!$usuario || $usuario['status'] !== 'ativo' || $usuario['estabelecimento_status'] !== 'ativo') {
            SessaoLembrada::apagar((int) $registro['id_sessao']);
            apagarCookieLembrar();
            return;
        }

        $slugUrl = get('estabelecimento');
        if ($slugUrl !== '' && $slugUrl !== $usuario['estabelecimento_slug']) {
            return;
        }

        gravarCookieLembrar(SessaoLembrada::renovar($registro));
        registrarSessao($usuario);
        registrarEventoSeguranca('sessao_lembrada', ['usuario' => (int) $usuario['id_usuario'], 'estabelecimento' => (int) $usuario['id_estabelecimento']]);
        LogAutenticacao::registrarEm((int) $usuario['id_estabelecimento'], 'login_sucesso', (string) (($usuario['login'] ?? '') ?: $usuario['email']), $usuario);
    } catch (Throwable $erro) {
        // Banco sem a tabela ou fora do ar: a pagina segue sem sessao, como sem o cookie.
        error_log('Nao foi possivel restaurar a sessao lembrada: ' . $erro->getMessage());
    }
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

/**
 * Confere se o POST partiu de uma pagina do proprio sistema.
 *
 * Segunda barreira, independente do token: o navegador preenche Origin em todo
 * POST entre sites, e o valor nao pode ser forjado por JavaScript de outra
 * origem. Se o cabecalho nao vier (cliente antigo, proxy que remove), a
 * verificacao se abstem e o token continua sendo a defesa principal.
 */
function origemConfere(): bool
{
    $origem = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');

    if ($origem === '') {
        $referencia = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($referencia === '') {
            return true;
        }
        $partes = parse_url($referencia);
        $origem = ($partes['scheme'] ?? '') . '://' . ($partes['host'] ?? '')
            . (isset($partes['port']) ? ':' . $partes['port'] : '');
    }

    $esperado = (requisicaoSegura() ? 'https://' : 'http://') . (string) ($_SERVER['HTTP_HOST'] ?? '');

    return hash_equals($esperado, $origem);
}

/** Interrompe o processamento de um POST sem token valido. */
function exigirCsrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }

    if (!csrfValido($_POST['csrf_token'] ?? null) || !origemConfere()) {
        registrarEventoSeguranca('csrf_recusado', [
            'origem' => (string) ($_SERVER['HTTP_ORIGIN'] ?? '-'),
            'perfil' => (string) perfil(),
        ]);
        if (ehRequisicaoAjax()) {
            jsonResposta(['sucesso' => false, 'mensagem' => 'Requisicao invalida. Recarregue a pagina.'], 419);
        }
        definirFlash('erro', 'Sessao expirada ou requisicao invalida. Tente novamente.');
        redirecionar(estaLogado() ? painelDe(perfil()) : 'login.php');
    }
}
