<?php
/**
 * Encerra a sessao do usuario.
 */
require_once __DIR__ . '/config/config.php';

encerrarSessao();
iniciarSessao();

definirFlash('info', 'Sessao encerrada.');
redirecionar('login.php');
