<?php
/**
 * Bootstrap do sistema.
 * Todo arquivo publico do projeto deve incluir apenas este arquivo.
 */

// 'desenvolvimento' exibe erros na tela. Use 'producao' no servidor final.
define('AMBIENTE', 'desenvolvimento');

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

/** Carregamento automatico dos models. */
spl_autoload_register(function (string $classe): void {
    $arquivo = RAIZ . '/models/' . $classe . '.php';
    if (is_file($arquivo)) {
        require_once $arquivo;
    }
});

// Abre a sessÃ£o antes de qualquer saÃ­da HTML para permitir o envio dos cookies.
iniciarSessao();

// Resolve a empresa antes de consultar cadastros ou renderizar a identidade visual.
Contexto::iniciar();
