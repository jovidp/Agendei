<?php
define('AREA_MASTER', true);
require_once __DIR__ . '/../config/config.php';
encerrarSessao();
header('Location: ' . BASE_URL . '/master/login.php');
exit;
