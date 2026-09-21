<?php
/**
 * Ponto de entrada do sistema.
 *
 * Nao ha pagina de apresentacao: quem chega vai direto ao sistema.
 * Usuarios autenticados seguem para o seu painel; os demais para o login.
 */
require_once __DIR__ . '/config/config.php';

if (estaLogado()) {
    redirecionar(painelDe(perfil()));
}

redirecionar('login.php');
