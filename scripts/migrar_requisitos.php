<?php

/**
 * Atualizacao aditiva para os requisitos do projeto academico: cadastro
 * completo, login proprio, dados do segundo fator e log de autenticacao.
 *
 * Pode ser executada novamente sem efeito colateral. E chamada por
 * scripts/migrar.php e tambem funciona sozinha: "php scripts/migrar_requisitos.php".
 */

/** Aplica as alteracoes de esquema exigidas pela especificacao. */
function migrarRequisitos(PDO $db): void
{
    $colunaExiste = static function (string $tabela, string $coluna) use ($db): bool {
        $ddl = $db->query("SHOW CREATE TABLE `$tabela`")->fetch(PDO::FETCH_NUM)[1];
        return (bool) preg_match('/^\s*`' . preg_quote($coluna, '/') . '`\s/m', $ddl);
    };

    $indiceExiste = static function (string $tabela, string $indice) use ($db): bool {
        $ddl = $db->query("SHOW CREATE TABLE `$tabela`")->fetch(PDO::FETCH_NUM)[1];
        return str_contains($ddl, 'KEY `' . $indice . '`');
    };

    // -----------------------------------------------------------------
    // Dados pessoais do cadastro ficam em usuarios: assim tanto o usuario
    // comum quanto o master respondem as perguntas do segundo fator.
    // -----------------------------------------------------------------
    $campos = [
        'login'           => 'VARCHAR(6) NULL',
        'sexo'            => "ENUM('F','M','O') NULL",
        'nome_materno'    => 'VARCHAR(120) NULL',
        'data_nascimento' => 'DATE NULL',
        'telefone_fixo'   => 'VARCHAR(20) NULL',
        'cep'             => 'CHAR(8) NULL',
        'logradouro'      => 'VARCHAR(150) NULL',
        'numero'          => 'VARCHAR(20) NULL',
        'complemento'     => 'VARCHAR(60) NULL',
        'bairro'          => 'VARCHAR(100) NULL',
        'cidade'          => 'VARCHAR(100) NULL',
        'uf'              => 'CHAR(2) NULL',
    ];

    foreach ($campos as $campo => $definicao) {
        if (!$colunaExiste('usuarios', $campo)) {
            $db->exec("ALTER TABLE usuarios ADD COLUMN `$campo` $definicao");
        }
    }

    // O login e opcional no banco: as contas antigas continuam entrando pelo e-mail.
    // O indice aceita varios NULL, entao so impede logins repetidos na mesma empresa.
    if (!$indiceExiste('usuarios', 'uk_estabelecimento_login')) {
        $db->exec('ALTER TABLE usuarios ADD UNIQUE KEY uk_estabelecimento_login (id_estabelecimento, login)');
    }

    // Traz a data de nascimento que ja estava no perfil do cliente.
    $db->exec('UPDATE usuarios u
               INNER JOIN clientes c ON c.id_usuario = u.id_usuario
               SET u.data_nascimento = c.data_nascimento
               WHERE u.data_nascimento IS NULL AND c.data_nascimento IS NOT NULL');

    // -----------------------------------------------------------------
    // Log de autenticacao
    // Nome e CPF ficam desnormalizados de proposito: o log precisa sobreviver
    // a exclusao do usuario feita pelo master.
    // -----------------------------------------------------------------
    $db->exec("CREATE TABLE IF NOT EXISTS logs_autenticacao (
        id_log INT UNSIGNED NOT NULL AUTO_INCREMENT,
        id_estabelecimento INT UNSIGNED NOT NULL,
        id_usuario INT UNSIGNED NULL,
        login_informado VARCHAR(150) NOT NULL,
        nome VARCHAR(120) NOT NULL DEFAULT '',
        cpf CHAR(11) NULL,
        perfil VARCHAR(20) NULL,
        evento ENUM('login_sucesso','login_falha','2fa_sucesso','2fa_falha','2fa_bloqueio','logout') NOT NULL,
        fator_2fa ENUM('nome_materno','data_nascimento','cep') NULL,
        ip VARCHAR(45) NULL,
        data_hora DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_log),
        KEY idx_logs_estabelecimento (id_estabelecimento, data_hora),
        KEY idx_logs_nome (nome),
        KEY idx_logs_cpf (cpf),
        CONSTRAINT fk_logs_estabelecimento FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento (id_estabelecimento)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Execucao direta pela linha de comando; incluido por migrar.php, apenas define a funcao.
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    require_once __DIR__ . '/../config/database.php';

// As migracoes aditivas existem para atualizar bancos MySQL nascidos em versoes
// anteriores. No PostgreSQL esse caso nao existe: a instalacao parte de
// banco_postgres.sql, que ja vem com o esquema completo.
if (Database::ehPostgres()) {
    fwrite(STDERR, "Este script atualiza apenas bancos MySQL/MariaDB.
"
        . "No PostgreSQL, importe banco_postgres.sql: ele ja traz o esquema completo.
");
    exit(1);
}
    migrarRequisitos(bd());
    echo "Requisitos aplicados: campos de cadastro, login proprio e log de autenticacao.\n";
}
