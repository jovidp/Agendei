<?php

/**
 * Libera o segundo fator por codigo de uma conta que perdeu o celular.
 *
 * E a unica saida para a conta master, que nao tem pergunta cadastral de
 * reserva: sem o aplicativo, sem este script, o acesso fica trancado.
 *
 * Roda somente na linha de comando, de proposito. Quem executa precisa ter
 * acesso ao servidor — o mesmo nivel de acesso de quem poderia alterar o banco
 * direto. Nao existe versao pela web, para nao criar um caminho de desligar a
 * protecao pela internet.
 *
 *   php scripts/liberar_2fa.php                       lista quem usa codigo
 *   php scripts/liberar_2fa.php master <e-mail>       libera uma conta master
 *   php scripts/liberar_2fa.php usuario <e-mail|login> libera uma conta comum
 *
 * Depois de liberar, a conta volta a entrar sem codigo (o usuario comum volta
 * a pergunta cadastral) e pode cadastrar o aplicativo novamente na tela
 * "Verificacao em 2 etapas".
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(dirname(__DIR__));
require_once 'config/database.php';

$db = bd();
$acao = $argv[1] ?? '';
$alvo = $argv[2] ?? '';

// -------------------------------------------------------------------------
// Sem argumentos: mostra o que existe, para nao liberar a conta errada
// -------------------------------------------------------------------------
if ($acao === '') {
    echo "Contas com segundo fator por codigo ativo\n";
    echo str_repeat('-', 62) . "\n";

    $masters = $db->query(
        'SELECT email, nome, totp_ativado_em FROM administradores_master
         WHERE totp_ativado_em IS NOT NULL ORDER BY email'
    )->fetchAll(PDO::FETCH_ASSOC);

    echo "\nmaster:\n";
    if ($masters === []) {
        echo "  (nenhuma)\n";
    }
    foreach ($masters as $linha) {
        echo '  ' . str_pad((string) $linha['email'], 38) . ' desde ' . $linha['totp_ativado_em'] . "\n";
    }

    $usuarios = $db->query(
        'SELECT email, login, tipo, totp_ativado_em FROM usuarios
         WHERE totp_ativado_em IS NOT NULL ORDER BY email'
    )->fetchAll(PDO::FETCH_ASSOC);

    echo "\nusuario:\n";
    if ($usuarios === []) {
        echo "  (nenhuma)\n";
    }
    foreach ($usuarios as $linha) {
        echo '  ' . str_pad((string) $linha['email'], 38)
            . str_pad('(' . $linha['tipo'] . ')', 16)
            . ' desde ' . $linha['totp_ativado_em'] . "\n";
    }

    echo "\nPara liberar:  php scripts/liberar_2fa.php master <e-mail>\n";
    echo "               php scripts/liberar_2fa.php usuario <e-mail ou login>\n";
    exit;
}

if (!in_array($acao, ['master', 'usuario'], true) || $alvo === '') {
    fwrite(STDERR, "Uso: php scripts/liberar_2fa.php [master|usuario] <e-mail ou login>\n");
    exit(1);
}

$limpar = 'totp_segredo = NULL, totp_ativado_em = NULL, totp_ultimo_contador = NULL';

// -------------------------------------------------------------------------
// Conta master
// -------------------------------------------------------------------------
if ($acao === 'master') {
    $consulta = $db->prepare('UPDATE administradores_master SET ' . $limpar . ' WHERE email = ?');
    $consulta->execute([mb_strtolower(trim($alvo))]);

    if ($consulta->rowCount() === 0) {
        fwrite(STDERR, "Nenhuma conta master com este e-mail.\n");
        exit(1);
    }

    echo "Segundo fator liberado para a conta master {$alvo}.\n";
    echo "O proximo login pede somente a senha. Cadastre o aplicativo de novo\n";
    echo "em Administracao master > Verificacao em 2 etapas.\n";
    exit;
}

// -------------------------------------------------------------------------
// Conta comum (cliente, profissional ou administrador do estabelecimento)
//
// A busca aceita e-mail ou login e nao filtra por estabelecimento: quem roda
// isto esta no servidor e precisa alcancar a conta sem saber a qual empresa
// ela pertence.
// -------------------------------------------------------------------------
$consulta = $db->prepare('UPDATE usuarios SET ' . $limpar . ' WHERE email = :alvo OR login = :alvo');
$consulta->execute([':alvo' => mb_strtolower(trim($alvo))]);

if ($consulta->rowCount() === 0) {
    fwrite(STDERR, "Nenhuma conta com este e-mail ou login.\n");
    exit(1);
}

echo "Segundo fator liberado para {$alvo} ({$consulta->rowCount()} conta(s)).\n";
echo "O login volta a pedir a pergunta cadastral. Cadastre o aplicativo de novo\n";
echo "na tela Verificacao em 2 etapas do painel.\n";
