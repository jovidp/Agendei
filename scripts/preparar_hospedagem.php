<?php

/**
 * Gera uma copia do banco.sql pronta para hospedagem compartilhada.
 *
 * Em host compartilhado o nome do banco e imposto pelo painel (algo como
 * "if0_12345678_agendei") e o usuario nao tem permissao para criar bancos.
 * Este script remove o CREATE DATABASE e o USE, deixando so as tabelas:
 * o phpMyAdmin do host ja importa dentro do banco selecionado.
 *
 * Execute com "php scripts/preparar_hospedagem.php".
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(dirname(__DIR__));

$origem = 'banco.sql';
$destino = 'banco_hospedagem.sql';

if (!is_file($origem)) {
    fwrite(STDERR, "Arquivo {$origem} nao encontrado.\n");
    exit(1);
}

$sql = file_get_contents($origem);
$removidas = 0;

// Tira apenas os comandos que exigem privilegio de criacao de banco.
$sql = preg_replace(
    '/^(CREATE DATABASE|USE)\b[^;]*;\r?\n/mi',
    '',
    $sql,
    -1,
    $removidas
);

$aviso = "-- Gerado por scripts/preparar_hospedagem.php\r\n"
       . "-- Importe este arquivo DENTRO do banco ja criado no painel da hospedagem.\r\n"
       . "-- Nao edite aqui: altere banco.sql e rode o script de novo.\r\n\r\n";

file_put_contents($destino, $aviso . $sql);

echo "Gerado {$destino} ({$removidas} comando(s) de banco removido(s)).\n";
echo "Importe pelo phpMyAdmin da hospedagem, com o banco do painel selecionado.\n";
