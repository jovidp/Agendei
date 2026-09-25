<?php

/**
 * Migracao: pessoa e vinculo separados.
 *
 * Ate aqui cada linha de usuarios era uma conta em UMA empresa (com
 * id_estabelecimento, tipo, status, login e ultimo_acesso). Agora usuarios e a
 * pessoa, unica em toda a plataforma, e a tabela vinculos guarda o que ela e
 * em cada estabelecimento: cliente, profissional ou administradora. Os
 * perfis (clientes, profissionais, administradores) passam a apontar para o
 * vinculo pela chave composta (id_estabelecimento, id_vinculo).
 *
 * A conversao dos dados e um para um: cada linha antiga de usuarios vira a
 * pessoa mais um vinculo, sem perda. Pode rodar de novo sem efeito
 * colateral. Atende MySQL/MariaDB e PostgreSQL (Supabase), localizando as
 * restricoes pelo catalogo, e nao pelo nome, porque os dois esquemas as
 * nomeiam de forma diferente. Execute: php scripts/migrar_vinculos.php
 *
 * Pre-requisito: o e-mail ja unico (scripts/migrar_email_unico.php). Sem
 * isso a pessoa nao tem identidade unica e a migracao para.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

/** Aplica a migracao. Devolve as mensagens do que foi feito. */
function migrarVinculos(PDO $db): array
{
    $ehPostgres = Database::ehPostgres();
    $feito = [];

    // O information_schema existe nos dois dialetos; muda so o nome do esquema atual.
    $esquemaAtual = $ehPostgres ? 'current_schema()' : 'DATABASE()';

    $tabelaExiste = static function (string $tabela) use ($db, $esquemaAtual): bool {
        $q = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = $esquemaAtual AND table_name = ?");
        $q->execute([$tabela]);
        return (bool) $q->fetchColumn();
    };
    $colunaExiste = static function (string $tabela, string $coluna) use ($db, $esquemaAtual): bool {
        $q = $db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = $esquemaAtual AND table_name = ? AND column_name = ?");
        $q->execute([$tabela, $coluna]);
        return (bool) $q->fetchColumn();
    };
    $constraintExiste = static function (string $tabela, string $nome) use ($db, $esquemaAtual): bool {
        $q = $db->prepare("SELECT 1 FROM information_schema.table_constraints WHERE table_schema = $esquemaAtual AND table_name = ? AND constraint_name = ?");
        $q->execute([$tabela, $nome]);
        return (bool) $q->fetchColumn();
    };
    $indiceExiste = static function (string $tabela, string $nome) use ($db, $ehPostgres): bool {
        $q = $ehPostgres
            ? $db->prepare('SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?')
            : $db->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1');
        $q->execute([$tabela, $nome]);
        return (bool) $q->fetchColumn();
    };
    // Chaves estrangeiras de $tabela que apontam para $referenciada com $colunas colunas.
    $chavesPara = static function (string $tabela, string $referenciada, int $colunas) use ($db, $ehPostgres): array {
        if ($ehPostgres) {
            $q = $db->prepare(
                'SELECT c.conname FROM pg_constraint c
                 JOIN pg_class t ON t.oid = c.conrelid
                 JOIN pg_class r ON r.oid = c.confrelid
                 JOIN pg_namespace n ON n.oid = t.relnamespace
                 WHERE c.contype = \'f\' AND n.nspname = current_schema() AND t.relname = ? AND r.relname = ?
                   AND array_length(c.conkey, 1) = ?'
            );
        } else {
            $q = $db->prepare(
                'SELECT k.CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE k
                 WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME = ?
                 GROUP BY k.CONSTRAINT_NAME HAVING COUNT(*) = ?'
            );
        }
        $q->execute([$tabela, $referenciada, $colunas]);
        return $q->fetchAll(PDO::FETCH_COLUMN);
    };
    // Restricoes UNIQUE de $tabela exatamente sobre as colunas dadas, nesta ordem.
    $unicasSobre = static function (string $tabela, array $colunas) use ($db, $ehPostgres): array {
        $lista = implode(',', $colunas);
        if ($ehPostgres) {
            $q = $db->prepare(
                'SELECT c.conname FROM pg_constraint c
                 JOIN pg_class t ON t.oid = c.conrelid
                 JOIN pg_namespace n ON n.oid = t.relnamespace
                 WHERE c.contype = \'u\' AND n.nspname = current_schema() AND t.relname = ?
                   AND (SELECT string_agg(a.attname, \',\' ORDER BY x.ord)
                          FROM unnest(c.conkey) WITH ORDINALITY AS x(attnum, ord)
                          JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = x.attnum) = ?'
            );
        } else {
            $q = $db->prepare(
                'SELECT tc.CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS tc
                 JOIN information_schema.KEY_COLUMN_USAGE k
                   ON k.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND k.CONSTRAINT_NAME = tc.CONSTRAINT_NAME AND k.TABLE_NAME = tc.TABLE_NAME
                 WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = \'UNIQUE\'
                 GROUP BY tc.CONSTRAINT_NAME
                 HAVING GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION) = ?'
            );
        }
        $q->execute([$tabela, $lista]);
        return $q->fetchAll(PDO::FETCH_COLUMN);
    };
    $derrubarChave = static function (string $tabela, string $nome) use ($db, $ehPostgres, $indiceExiste): void {
        $db->exec($ehPostgres ? "ALTER TABLE $tabela DROP CONSTRAINT $nome" : "ALTER TABLE $tabela DROP FOREIGN KEY $nome");
        // No MySQL a chave deixa um indice de mesmo nome para tras.
        if (!$ehPostgres && $indiceExiste($tabela, $nome)) {
            $db->exec("ALTER TABLE $tabela DROP INDEX $nome");
        }
    };
    $derrubarUnica = static function (string $tabela, string $nome) use ($db, $ehPostgres): void {
        $db->exec($ehPostgres ? "ALTER TABLE $tabela DROP CONSTRAINT $nome" : "ALTER TABLE $tabela DROP INDEX $nome");
    };
    $inteiro = $ehPostgres ? 'INTEGER' : 'int(10) unsigned';

    $baseAntiga = $colunaExiste('usuarios', 'id_estabelecimento');

    if ($baseAntiga && !$unicasSobre('usuarios', ['email'])) {
        throw new RuntimeException('Rode antes php scripts/migrar_email_unico.php: a pessoa precisa de e-mail unico.');
    }

    // ---------------------------------------------------------------------
    // 1. Tabela vinculos
    // ---------------------------------------------------------------------
    if (!$tabelaExiste('vinculos')) {
        if ($ehPostgres) {
            $db->exec("CREATE TABLE vinculos (
                id_vinculo         INTEGER GENERATED BY DEFAULT AS IDENTITY,
                id_estabelecimento INTEGER NOT NULL,
                id_usuario         INTEGER NOT NULL,
                tipo               VARCHAR(12) NOT NULL DEFAULT 'cliente',
                status             VARCHAR(7) NOT NULL DEFAULT 'ativo',
                login              VARCHAR(6) DEFAULT NULL,
                ultimo_acesso      TIMESTAMP DEFAULT NULL,
                data_criacao       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_vinculos PRIMARY KEY (id_vinculo),
                CONSTRAINT uk_vinculos_registro UNIQUE (id_estabelecimento, id_vinculo),
                CONSTRAINT uk_vinculos_pessoa_tipo UNIQUE (id_estabelecimento, id_usuario, tipo),
                CONSTRAINT uk_vinculos_login UNIQUE (id_estabelecimento, login),
                CONSTRAINT ck_vinculos_tipo CHECK (tipo IN ('cliente','profissional','admin')),
                CONSTRAINT ck_vinculos_status CHECK (status IN ('ativo','inativo')),
                CONSTRAINT fk_vinculos_estabelecimento FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento (id_estabelecimento),
                CONSTRAINT fk_vinculos_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario) ON DELETE CASCADE ON UPDATE CASCADE
            )");
            $db->exec('CREATE INDEX idx_vinculos_usuario ON vinculos (id_usuario)');
            $db->exec('CREATE INDEX idx_vinculos_tipo_status ON vinculos (id_estabelecimento, tipo, status)');
        } else {
            $db->exec("CREATE TABLE vinculos (
                id_vinculo         int(10) unsigned NOT NULL AUTO_INCREMENT,
                id_estabelecimento int(10) unsigned NOT NULL,
                id_usuario         int(10) unsigned NOT NULL,
                tipo               enum('cliente','profissional','admin') NOT NULL DEFAULT 'cliente',
                status             enum('ativo','inativo') NOT NULL DEFAULT 'ativo',
                login              varchar(6) DEFAULT NULL,
                ultimo_acesso      datetime DEFAULT NULL,
                data_criacao       datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (id_vinculo),
                UNIQUE KEY uk_vinculos_registro (id_estabelecimento, id_vinculo),
                UNIQUE KEY uk_vinculos_pessoa_tipo (id_estabelecimento, id_usuario, tipo),
                UNIQUE KEY uk_vinculos_login (id_estabelecimento, login),
                KEY idx_vinculos_usuario (id_usuario),
                KEY idx_vinculos_tipo_status (id_estabelecimento, tipo, status),
                CONSTRAINT fk_vinculos_estabelecimento FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento (id_estabelecimento),
                CONSTRAINT fk_vinculos_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        $feito[] = 'tabela vinculos criada';
    }

    // ---------------------------------------------------------------------
    // 2. Um vinculo por linha antiga de usuarios, e um por perfil que a linha
    //    antiga nao cobria (tipo diferente do perfil, o que nao deveria existir).
    // ---------------------------------------------------------------------
    $perfis = [
        'clientes'        => 'cliente',
        'profissionais'   => 'profissional',
        'administradores' => 'admin',
    ];
    if ($baseAntiga) {
        $n = $db->exec(
            'INSERT INTO vinculos (id_estabelecimento, id_usuario, tipo, status, login, ultimo_acesso)
             SELECT u.id_estabelecimento, u.id_usuario, u.tipo, u.status, u.login, u.ultimo_acesso
             FROM usuarios u
             WHERE NOT EXISTS (SELECT 1 FROM vinculos v
                               WHERE v.id_usuario = u.id_usuario AND v.id_estabelecimento = u.id_estabelecimento AND v.tipo = u.tipo)'
        );
        if ($n > 0) {
            $feito[] = "$n vinculo(s) gerado(s) a partir das contas antigas";
        }
        foreach ($perfis as $tabela => $tipo) {
            $n = $db->exec(
                "INSERT INTO vinculos (id_estabelecimento, id_usuario, tipo, status)
                 SELECT p.id_estabelecimento, p.id_usuario, '$tipo', u.status
                 FROM $tabela p JOIN usuarios u ON u.id_usuario = p.id_usuario
                 WHERE NOT EXISTS (SELECT 1 FROM vinculos v
                                   WHERE v.id_usuario = p.id_usuario AND v.id_estabelecimento = p.id_estabelecimento AND v.tipo = '$tipo')"
            );
            if ($n > 0) {
                $feito[] = "$n vinculo(s) de $tipo gerado(s) a partir de $tabela";
            }
        }
    }

    // ---------------------------------------------------------------------
    // 3. id_vinculo nos perfis, no lugar da chave composta com usuarios
    // ---------------------------------------------------------------------
    foreach ($perfis as $tabela => $tipo) {
        if (!$colunaExiste($tabela, 'id_vinculo')) {
            $db->exec("ALTER TABLE $tabela ADD COLUMN id_vinculo $inteiro NULL");
            $feito[] = "coluna id_vinculo criada em $tabela";
        }
        $db->exec(
            "UPDATE $tabela SET id_vinculo = (SELECT v.id_vinculo FROM vinculos v
                 WHERE v.id_estabelecimento = $tabela.id_estabelecimento AND v.id_usuario = $tabela.id_usuario AND v.tipo = '$tipo')
             WHERE id_vinculo IS NULL"
        );
        $orfaos = (int) $db->query("SELECT COUNT(*) FROM $tabela WHERE id_vinculo IS NULL")->fetchColumn();
        if ($orfaos > 0) {
            throw new RuntimeException("$orfaos registro(s) de $tabela sem vinculo correspondente; corrija antes de continuar.");
        }
        $db->exec($ehPostgres
            ? "ALTER TABLE $tabela ALTER COLUMN id_vinculo SET NOT NULL"
            : "ALTER TABLE $tabela MODIFY id_vinculo int(10) unsigned NOT NULL");

        foreach ($chavesPara($tabela, 'usuarios', 2) as $nome) {
            $derrubarChave($tabela, $nome);
            $feito[] = "chave composta $nome removida de $tabela";
        }
        // No MySQL a chave simples para usuarios precisa de um indice em
        // id_usuario, e ate aqui quem servia era o UNIQUE que vai cair: um
        // indice comum entra antes, senao o banco recusa a remocao.
        if (!$indiceExiste($tabela, "idx_{$tabela}_usuario")) {
            $db->exec("CREATE INDEX idx_{$tabela}_usuario ON $tabela (id_usuario)");
        }
        foreach ($unicasSobre($tabela, ['id_usuario']) as $nome) {
            $derrubarUnica($tabela, $nome);
            $feito[] = "unicidade $nome removida de $tabela (a pessoa pode ter o perfil em varias empresas)";
        }
        if (!$unicasSobre($tabela, ['id_vinculo'])) {
            $db->exec("ALTER TABLE $tabela ADD CONSTRAINT uk_{$tabela}_vinculo UNIQUE (id_vinculo)");
        }
        if (!$indiceExiste($tabela, "idx_{$tabela}_pessoa")) {
            $db->exec("CREATE INDEX idx_{$tabela}_pessoa ON $tabela (id_estabelecimento, id_usuario)");
        }
        if (!$indiceExiste($tabela, "idx_{$tabela}_tenant_vinculo")) {
            $db->exec("CREATE INDEX idx_{$tabela}_tenant_vinculo ON $tabela (id_estabelecimento, id_vinculo)");
        }
        if (!$chavesPara($tabela, 'vinculos', 2)) {
            $db->exec("ALTER TABLE $tabela ADD CONSTRAINT fk_tenant_{$tabela}_id_vinculo
                       FOREIGN KEY (id_estabelecimento, id_vinculo) REFERENCES vinculos (id_estabelecimento, id_vinculo)
                       ON DELETE CASCADE ON UPDATE CASCADE");
            $feito[] = "$tabela passa a apontar para vinculos";
        }
    }

    // ---------------------------------------------------------------------
    // 4. notificacoes: a chave composta com usuarios vira chave simples
    // ---------------------------------------------------------------------
    if ($tabelaExiste('notificacoes')) {
        foreach ($chavesPara('notificacoes', 'usuarios', 2) as $nome) {
            $derrubarChave('notificacoes', $nome);
            $feito[] = "chave composta $nome removida de notificacoes";
        }
        if (!$chavesPara('notificacoes', 'usuarios', 1)) {
            $db->exec('ALTER TABLE notificacoes ADD CONSTRAINT fk_notificacao_usuario
                       FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario) ON DELETE CASCADE');
        }
    }

    // ---------------------------------------------------------------------
    // 5. sessoes_lembradas: o dispositivo lembra um vinculo
    // ---------------------------------------------------------------------
    if ($tabelaExiste('sessoes_lembradas')) {
        if (!$colunaExiste('sessoes_lembradas', 'id_vinculo')) {
            $db->exec("ALTER TABLE sessoes_lembradas ADD COLUMN id_vinculo $inteiro NULL");
            $feito[] = 'coluna id_vinculo criada em sessoes_lembradas';
        }
        $db->exec(
            'UPDATE sessoes_lembradas SET id_vinculo = (SELECT MIN(v.id_vinculo) FROM vinculos v
                 WHERE v.id_estabelecimento = sessoes_lembradas.id_estabelecimento AND v.id_usuario = sessoes_lembradas.id_usuario)
             WHERE id_vinculo IS NULL'
        );
        $db->exec('DELETE FROM sessoes_lembradas WHERE id_vinculo IS NULL');
        $db->exec($ehPostgres
            ? 'ALTER TABLE sessoes_lembradas ALTER COLUMN id_vinculo SET NOT NULL'
            : 'ALTER TABLE sessoes_lembradas MODIFY id_vinculo int(10) unsigned NOT NULL');
        if (!$indiceExiste('sessoes_lembradas', 'idx_sessoes_lembradas_vinculo')) {
            $db->exec('CREATE INDEX idx_sessoes_lembradas_vinculo ON sessoes_lembradas (id_vinculo)');
        }
        if (!$chavesPara('sessoes_lembradas', 'vinculos', 1)) {
            $db->exec('ALTER TABLE sessoes_lembradas ADD CONSTRAINT fk_sessoes_lembradas_vinculo
                       FOREIGN KEY (id_vinculo) REFERENCES vinculos (id_vinculo) ON DELETE CASCADE');
        }
    }

    // ---------------------------------------------------------------------
    // 6. usuarios perde o que agora e do vinculo
    // ---------------------------------------------------------------------
    if ($baseAntiga) {
        foreach ($chavesPara('usuarios', 'estabelecimento', 1) as $nome) {
            $derrubarChave('usuarios', $nome);
        }
        foreach (array_merge($unicasSobre('usuarios', ['id_estabelecimento', 'id_usuario']), $unicasSobre('usuarios', ['id_estabelecimento', 'login'])) as $nome) {
            $derrubarUnica('usuarios', $nome);
        }
        if (!$ehPostgres) {
            foreach (['idx_usuarios_tipo_status', 'idx_estabelecimento', 'idx_usuarios_estabelecimento'] as $nome) {
                if ($indiceExiste('usuarios', $nome)) {
                    $db->exec("ALTER TABLE usuarios DROP INDEX $nome");
                }
            }
        }
        $db->exec('ALTER TABLE usuarios DROP COLUMN id_estabelecimento, DROP COLUMN tipo, DROP COLUMN login, DROP COLUMN ultimo_acesso');
        $feito[] = 'usuarios agora e a pessoa: id_estabelecimento, tipo, login e ultimo_acesso foram para vinculos';
    }

    return $feito;
}

// Execucao direta pela linha de comando; incluido por migrar.php, apenas define a funcao.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $feito = migrarVinculos(bd());
    $dialeto = Database::ehPostgres() ? 'PostgreSQL' : 'MySQL/MariaDB';
    echo "Pessoa e vinculo separados em $dialeto.\n";
    echo $feito === [] ? "Nada a alterar: o esquema ja estava pronto.\n" : '  - ' . implode("\n  - ", $feito) . "\n";
}
