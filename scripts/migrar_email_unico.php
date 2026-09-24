<?php
/**
 * Torna usuarios.email unico em toda a plataforma.
 *
 * A migracao nunca escolhe nem apaga contas duplicadas. Quando encontra
 * repeticoes, lista os registros envolvidos e encerra sem alterar o indice;
 * o responsavel precisa decidir qual e-mail cada conta deve conservar.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

function migrarEmailUnico(PDO $db, bool $exibir = true): bool
{
    $duplicados = $db->query(
        'SELECT LOWER(TRIM(email)) AS email_normalizado, COUNT(*) AS total
         FROM usuarios
         GROUP BY LOWER(TRIM(email))
         HAVING COUNT(*) > 1
         ORDER BY total DESC, email_normalizado'
    )->fetchAll();

    if ($duplicados !== []) {
        if ($exibir) {
            fwrite(STDERR, "Nao foi possivel criar a unicidade global: existem e-mails repetidos.\n");
            $detalhes = $db->prepare(
                'SELECT u.id_usuario, u.nome, u.email, e.nome AS estabelecimento
                 FROM usuarios u
                 JOIN estabelecimento e ON e.id_estabelecimento = u.id_estabelecimento
                 WHERE LOWER(TRIM(u.email)) = :email
                 ORDER BY e.nome, u.id_usuario'
            );
            foreach ($duplicados as $grupo) {
                fwrite(STDERR, "\n" . $grupo['email_normalizado'] . ' (' . $grupo['total'] . " contas):\n");
                $detalhes->execute([':email' => $grupo['email_normalizado']]);
                foreach ($detalhes->fetchAll() as $conta) {
                    fwrite(STDERR, '  ID ' . $conta['id_usuario'] . ' | ' . $conta['nome'] . ' | ' . $conta['estabelecimento'] . "\n");
                }
            }
            fwrite(STDERR, "\nAltere os e-mails duplicados e execute este script novamente. Nenhuma conta foi apagada.\n");
        }
        return false;
    }

    // Todas as novas gravacoes ja sao normalizadas; isto corrige cadastros
    // antigos antes de o indice definitivo ser criado.
    $db->exec('UPDATE usuarios SET email = LOWER(TRIM(email))');

    if (Database::ehPostgres()) {
        $db->exec('ALTER TABLE usuarios DROP CONSTRAINT IF EXISTS uk_estabelecimento_email');
        $existe = $db->prepare(
            "SELECT 1 FROM pg_constraint
             WHERE conrelid = 'usuarios'::regclass AND conname = 'uk_usuarios_email'"
        );
        $existe->execute();
        if (!$existe->fetchColumn()) {
            $db->exec('ALTER TABLE usuarios ADD CONSTRAINT uk_usuarios_email UNIQUE (email)');
        }
    } else {
        $indices = $db->query('SHOW INDEX FROM usuarios')->fetchAll();
        $nomes = array_values(array_unique(array_column($indices, 'Key_name')));

        if (in_array('uk_estabelecimento_email', $nomes, true)) {
            $db->exec('ALTER TABLE usuarios DROP INDEX uk_estabelecimento_email');
        }
        if (!in_array('uk_usuarios_email', $nomes, true)) {
            $db->exec('ALTER TABLE usuarios ADD UNIQUE KEY uk_usuarios_email (email)');
        }
    }

    if ($exibir) {
        echo "E-mail unico em toda a plataforma: migracao concluida.\n";
    }
    return true;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(migrarEmailUnico(bd()) ? 0 : 1);
}

