<?php

/**
 * Prepara o banco para o segundo fator por codigo (TOTP).
 *
 * Acrescenta a usuarios as tres colunas do aplicativo autenticador e abre o
 * campo fator_2fa do log para o novo valor 'totp'.
 *
 * Vale nos dois dialetos e pode ser executado de novo sem efeito.
 *
 *   php scripts/migrar_totp.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$db = bd();
$ehPostgres = Database::ehPostgres();
$feito = [];

/** Executa e engole o erro de "ja existe", que e o caso normal ao repetir. */
$tentar = static function (string $comando, string $rotulo) use ($db, &$feito): void {
    try {
        $db->exec($comando);
        $feito[] = $rotulo;
    } catch (Throwable $erro) {
        $mensagem = strtolower($erro->getMessage());
        $jaExiste = str_contains($mensagem, 'duplicate')
            || str_contains($mensagem, 'already exists')
            || str_contains($mensagem, 'ja existe');

        if (!$jaExiste) {
            throw $erro;
        }
    }
};

// -------------------------------------------------------------------------
// Colunas do segundo fator por codigo
// -------------------------------------------------------------------------
$colunas = $ehPostgres
    ? [
        'totp_segredo'         => 'VARCHAR(255) DEFAULT NULL',
        'totp_ativado_em'      => 'TIMESTAMP DEFAULT NULL',
        'totp_ultimo_contador' => 'BIGINT DEFAULT NULL',
    ]
    : [
        'totp_segredo'         => 'VARCHAR(255) DEFAULT NULL',
        'totp_ativado_em'      => 'DATETIME DEFAULT NULL',
        'totp_ultimo_contador' => 'BIGINT DEFAULT NULL',
    ];

/**
 * Confere se a coluna ja existe, para que o relatorio do script diga o que
 * realmente aconteceu. O ADD COLUMN IF NOT EXISTS do Postgres passa calado
 * quando a coluna ja esta la, e sem esta consulta o script anunciaria uma
 * criacao que nao houve.
 */
$colunaExiste = static function (string $tabela, string $coluna) use ($db): bool {
    try {
        $db->query('SELECT ' . $coluna . ' FROM ' . $tabela . ' LIMIT 1')->fetch();
        return true;
    } catch (Throwable) {
        return false;
    }
};

// A conta master fica em outra tabela e precisa das mesmas colunas: e a conta
// de maior poder do sistema, e seria estranho deixar justamente ela sem codigo.
foreach (['usuarios', 'administradores_master'] as $tabela) {
    foreach ($colunas as $coluna => $definicao) {
        if ($colunaExiste($tabela, $coluna)) {
            continue;
        }

        // O IF NOT EXISTS existe no Postgres e no MariaDB, mas nao no MySQL 8:
        // por isso o erro de coluna repetida tambem e tolerado acima.
        $tentar(
            'ALTER TABLE ' . $tabela . ' ADD COLUMN ' . ($ehPostgres ? 'IF NOT EXISTS ' : '') . $coluna . ' ' . $definicao,
            $tabela . '.' . $coluna . ' criada'
        );
    }
}

// -------------------------------------------------------------------------
// O log precisa aceitar 'totp' como fator registrado
// -------------------------------------------------------------------------
if ($ehPostgres) {
    // No Postgres a restricao e um CHECK nomeado: troca-se a regra inteira.
    $db->exec('ALTER TABLE logs_autenticacao DROP CONSTRAINT IF EXISTS ck_logs_fator');
    $db->exec(
        "ALTER TABLE logs_autenticacao ADD CONSTRAINT ck_logs_fator
         CHECK (fator_2fa IN ('nome_materno','data_nascimento','cep','totp'))"
    );
} else {
    $db->exec(
        "ALTER TABLE logs_autenticacao
         MODIFY fator_2fa ENUM('nome_materno','data_nascimento','cep','totp') DEFAULT NULL"
    );
}
$feito[] = 'fator_2fa do log aceita o valor totp';

// -------------------------------------------------------------------------
// Confere que o esquema responde de verdade, e nao apenas que o ALTER passou
// -------------------------------------------------------------------------
foreach (['usuarios', 'administradores_master'] as $tabela) {
    $db->query('SELECT totp_segredo, totp_ativado_em, totp_ultimo_contador FROM ' . $tabela . ' LIMIT 1')->fetch();
}

$dialeto = $ehPostgres ? 'PostgreSQL' : 'MySQL/MariaDB';
echo "Banco preparado para o segundo fator por codigo em {$dialeto}.\n";
echo $feito === [] ? "Nada a alterar: o esquema ja estava pronto.\n" : '  - ' . implode("\n  - ", $feito) . "\n";
echo "\nDefina AGENDEI_CHAVE_TOTP no ambiente antes de cadastrar o primeiro aplicativo:\n";
echo "e a chave que protege os segredos gravados. Trocar essa chave depois\n";
echo "invalida os aplicativos ja cadastrados, que precisarao ser cadastrados de novo.\n";
