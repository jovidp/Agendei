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

date_default_timezone_set('America/Sao_Paulo');
setlocale(LC_ALL, 'pt_BR.UTF-8', 'pt_BR', 'Portuguese_Brazil');

/** Caminho absoluto da raiz do projeto no disco. */
define('RAIZ', dirname(__DIR__));

/** Caminho base da aplicacao na URL (ex.: /agendei). Vazio se estiver na raiz do dominio. */
if (!defined('BASE_URL')) {
    $raizProjeto    = str_replace('\\', '/', RAIZ);
    $raizDocumento  = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'));
    $caminhoBase    = '';

    if ($raizDocumento !== '' && strpos($raizProjeto, $raizDocumento) === 0) {
        $caminhoBase = substr($raizProjeto, strlen($raizDocumento));
    }

    define('BASE_URL', rtrim($caminhoBase, '/'));
}

define('NOME_SISTEMA', 'Agendei');

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

iniciarSessao();
