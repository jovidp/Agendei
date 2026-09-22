<?php
/**
 * Bootstrap do sistema.
 * Todo arquivo publico do projeto deve incluir apenas este arquivo.
 */

// 'desenvolvimento' exibe erros na tela. Use 'producao' no servidor final.
// A variavel de ambiente tem a ultima palavra; sem ela, a deteccao falha para o
// lado seguro: host que nao seja reconhecidamente local entra como producao,
// para nunca expor rastro de erro em servidor publico.
$ambiente = strtolower((string) (getenv('AGENDEI_AMBIENTE') ?: ''));
if ($ambiente !== 'producao' && $ambiente !== 'desenvolvimento') {
    $hospedeiro = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $ehLocal = PHP_SAPI === 'cli'
        || (bool) preg_match('/^(localhost|127\.0\.0\.1|\[::1\]|[^:]+\.(local|test))(:\d+)?$/', $hospedeiro);
    $ambiente = $ehLocal ? 'desenvolvimento' : 'producao';
}
define('AMBIENTE', $ambiente);

if (AMBIENTE === 'desenvolvimento') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

// Usa o fuso do estabelecimento nos cÃ¡lculos de datas, horÃ¡rios e antecedÃªncia.
date_default_timezone_set('America/Sao_Paulo');
setlocale(LC_ALL, 'pt_BR.UTF-8', 'pt_BR', 'Portuguese_Brazil');

/** Caminho absoluto da raiz do projeto no disco. */
define('RAIZ', dirname(__DIR__));

/** Caminho base da aplicacao na URL (ex.: /agendei). Vazio se estiver na raiz do dominio. */
if (!defined('BASE_URL')) {
    // Descobre o caminho pÃºblico mesmo quando o projeto Ã© exposto por junction ou alias.
    $raizProjeto = str_replace(chr(92), '/', RAIZ);
    $raizDocumento = str_replace(chr(92), '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'));
    $arquivoExecutado = $_SERVER['SCRIPT_FILENAME'] ?? '';
    $arquivoReal = $arquivoExecutado !== '' ? realpath($arquivoExecutado) : false;
    $arquivoReal = str_replace(chr(92), '/', $arquivoReal ?: $arquivoExecutado);
    $scriptUrl = str_replace(chr(92), '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $caminhoBase = '';

    if ($raizDocumento !== '' && strpos($raizProjeto, $raizDocumento) === 0) {
        $caminhoBase = substr($raizProjeto, strlen($raizDocumento));
    } elseif ($arquivoReal !== '' && strpos($arquivoReal, $raizProjeto . '/') === 0) {
        $arquivoRelativo = substr($arquivoReal, strlen($raizProjeto));
        if ($arquivoRelativo !== '' && str_ends_with($scriptUrl, $arquivoRelativo)) {
            $caminhoBase = substr($scriptUrl, 0, -strlen($arquivoRelativo));
        }
    }
    define('BASE_URL', rtrim($caminhoBase, '/'));
}

define('NOME_SISTEMA', 'Agendei');

// Disponibiliza a conexÃ£o, os utilitÃ¡rios e a autenticaÃ§Ã£o antes de carregar os models.
require_once RAIZ . '/config/database.php';
require_once RAIZ . '/includes/funcoes.php';
require_once RAIZ . '/includes/auth.php';
require_once RAIZ . '/includes/seguranca.php';
require_once RAIZ . '/includes/marca.php';
require_once RAIZ . '/includes/emails.php';

/** Carregamento automatico dos models. */
spl_autoload_register(function (string $classe): void {
    $arquivo = RAIZ . '/models/' . $classe . '.php';
    if (is_file($arquivo)) {
        require_once $arquivo;
    }
});

// Cabecalhos de defesa saem antes de qualquer byte de conteudo, para que
// nenhuma resposta do sistema — nem uma tela de erro — fique sem politica.
aplicarCabecalhosSeguranca();

// Abre a sessÃ£o antes de qualquer saÃ­da HTML para permitir o envio dos cookies.
iniciarSessao();

// Resolve a empresa antes de consultar cadastros ou renderizar a identidade visual.
// Paginas de entrada local (login, cadastro, 2FA, senha) nunca rodam sob a
// identidade master: quem chega nelas quer uma sessao local. Descarta-la aqui e
// o que permite ao master entrar no estabelecimento que acabou de criar, sem que
// a conta global assuma um estabelecimento pela URL nas demais paginas.
if (defined('ENTRADA_LOCAL')) {
    descartarIdentidadeMaster();
}

Contexto::iniciar();

// Derruba sessao vencida por inatividade, por tempo total ou usada em outro
// navegador. Roda depois do Contexto para que o redirecionamento preserve a empresa.
validarSessao();

// Pagina de quem esta autenticado nao pode ficar no cache do navegador: sem isto
// o botao voltar depois do logout ainda mostra agenda e dados pessoais.
if (estaLogado()) {
    impedirCacheAutenticado();
}

/**
 * Excecoes nao tratadas terminam na tela de erro do sistema.
 * O rastro tecnico vai para o log e so aparece na tela em desenvolvimento.
 */
set_exception_handler(static function (Throwable $erro): void {
    error_log('Erro nao tratado: ' . $erro->getMessage() . ' em ' . $erro->getFile() . ':' . $erro->getLine());

    // Chamadas de API devem continuar respondendo JSON, e nao HTML de redirecionamento.
    if (ehRequisicaoAjax()) {
        jsonResposta(['sucesso' => false, 'mensagem' => 'Erro inesperado. Tente novamente.'], 500);
    }

    // Se a propria tela de erro falhar, encerra aqui para nao entrar em laco.
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'erro.php') {
        http_response_code(500);
        exit('Erro inesperado.');
    }

    $_SESSION['erro_detalhe'] = $erro->getMessage() . "\n" . $erro->getFile() . ':' . $erro->getLine();

    // Depois que o HTML comecou a ser enviado nao ha mais como redirecionar.
    if (headers_sent()) {
        http_response_code(500);
        echo '<div class="alerta alerta-erro" role="alert">Erro inesperado ao processar a pagina.</div>';
        exit;
    }

    redirecionar('erro.php?codigo=inesperado');
});
