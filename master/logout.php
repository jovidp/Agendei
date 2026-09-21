<?php
define('AREA_MASTER', true);
require_once __DIR__ . '/../config/config.php';

// A saida e anotada antes de limpar a sessao: depois de encerrarSessao() nao
// ha mais como saber qual conta master estava em uso.
if (ehMaster()) {
    LogMaster::registrar('logout');
}

encerrarSessao();
header('Location: ' . BASE_URL . '/master/login.php');
exit;
