<?php

/**
 * Teste de integração em banco temporário: colunas do aceite e do
 * cancelamento, registro do aceite, cancelamento da assinatura e retenção.
 * Execute com "php tests/legal.php" (AGENDEI_DB_DRIVER=pgsql para o PostgreSQL).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(dirname(__DIR__));
require_once 'config/database.php';

$ehPostgres = Database::ehPostgres();
if (($argv[1] ?? '') !== '--worker') {
    $nomeTeste = 'agendei_test_' . bin2hex(random_bytes(6));
    if (!preg_match('/^agendei_test_[a-f0-9]{12}$/D', $nomeTeste)) throw new RuntimeException('Nome de banco de teste inválido.');
    $db = bd();
    $db->exec($ehPostgres
        ? "CREATE DATABASE \"$nomeTeste\" ENCODING 'UTF8'"
        : "CREATE DATABASE `$nomeTeste` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        putenv('AGENDEI_DB_NAME=' . $nomeTeste);
        $processo = proc_open([PHP_BINARY, __FILE__, '--worker'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
        if (proc_close($processo) !== 0) throw new RuntimeException('Falha no teste dos requisitos legais.');
    } finally {
        $db->exec($ehPostgres ? "DROP DATABASE \"$nomeTeste\"" : "DROP DATABASE `$nomeTeste`");
    }
    exit;
}
if (!preg_match('/^agendei_test_[a-f0-9]{12}$/D', getenv('AGENDEI_DB_NAME') ?: '')) throw new RuntimeException('O teste exige banco isolado.');

$esquema = file_get_contents($ehPostgres ? 'banco_postgres.sql' : 'banco.sql');
bd()->exec(preg_replace('/^(CREATE DATABASE|USE)\b[^;]*;\r?\n/mi', '', $esquema));

// Banco como o de produção antes desta versão: sem as colunas novas.
bd()->exec('ALTER TABLE usuarios DROP COLUMN termos_versao');
bd()->exec('ALTER TABLE usuarios DROP COLUMN termos_aceitos_em');
bd()->exec('ALTER TABLE assinaturas DROP COLUMN data_cancelamento');
require_once 'models/Assinatura.php';
require_once 'models/Sql.php';
require_once 'models/Legal.php';
Legal::garantirEstrutura();
Legal::garantirEstrutura(); // de novo: não pode falhar nem duplicar

ob_start();
require 'config/config.php';
restore_exception_handler();
ob_end_clean();

$checagens = 0;
function verificar(bool $condicao, string $mensagem): void
{
    global $checagens;
    if (!$condicao) throw new RuntimeException($mensagem);
    $checagens++;
}

// 1. Aceite dos termos
Estabelecimento::contratar(['estabelecimento' => 'LEGAL', 'slug' => 'legal', 'nome' => 'Responsável Teste', 'email' => 'admin@legal.local', 'senha' => 'Teste12345!', 'iniciar_demo' => true]);
$_SESSION = [];
$_GET = ['estabelecimento' => 'legal'];
Contexto::iniciar();
$idEmpresa = Contexto::id();

Legal::registrarAceite('ADMIN@legal.local ');
$pessoa = Usuario::pessoaPorEmail('admin@legal.local');
verificar(($pessoa['termos_versao'] ?? null) === Legal::VERSAO_TERMOS, 'A versão aceita não foi gravada.');
verificar(!empty($pessoa['termos_aceitos_em']), 'A data do aceite não foi gravada.');

// 2. Dados do fornecedor
putenv('AGENDEI_FORNECEDOR_EMAIL=contato@legal.local');
verificar(Legal::campo('nome') === 'a informar', 'Campo vazio deveria aparecer como "a informar".');
verificar(Legal::campo('dpo_email') === 'contato@legal.local', 'Sem DPO, o contato de privacidade deveria ser o do fornecedor.');

// 3. Cancelamento da assinatura
$assinatura = fn() => Assinatura::situacao($idEmpresa);
verificar($assinatura()['status'] === 'demo', 'A empresa deveria nascer em demonstração.');
verificar(Assinatura::cancelar($idEmpresa) === 'imediato', 'Cancelar a demonstração deveria encerrar na hora.');
verificar($assinatura()['status'] === 'bloqueada', 'Demonstração cancelada deveria bloquear o acesso.');
verificar(Assinatura::cancelar($idEmpresa) === '', 'Cancelar de novo não deveria fazer nada.');

Assinatura::solicitarPagamento($idEmpresa, 'pix');
verificar(empty($assinatura()['data_cancelamento']), 'Voltar a pagar deveria desfazer o cancelamento.');
Assinatura::confirmarPagamento($idEmpresa);
$quando = fn(int $dias) => date('Y-m-d H:i:s', strtotime("-$dias days"));
bd()->prepare('UPDATE assinaturas SET data_pagamento = ? WHERE id_estabelecimento = ?')->execute([$quando(2), $idEmpresa]);
verificar(Assinatura::cancelar($idEmpresa) === 'reembolso', 'Cancelar 2 dias depois de pagar deveria dar reembolso.');
verificar($assinatura()['status'] === 'bloqueada', 'Com reembolso o acesso deveria encerrar na hora.');

Assinatura::confirmarPagamento($idEmpresa);
bd()->prepare('UPDATE assinaturas SET data_pagamento = ? WHERE id_estabelecimento = ?')->execute([$quando(20), $idEmpresa]);
verificar(Assinatura::cancelar($idEmpresa) === 'fim_periodo', 'Cancelar 20 dias depois de pagar deveria valer até o fim do período.');
verificar($assinatura()['status'] === 'ativa', 'O acesso deveria continuar até o fim do período pago.');
verificar(substr((string) Assinatura::acessoAte($assinatura()), 0, 10) === date('Y-m-d', strtotime('+10 days')), 'O fim do período deveria ser 30 dias depois do pagamento.');
bd()->prepare('UPDATE assinaturas SET data_pagamento = ? WHERE id_estabelecimento = ?')->execute([$quando(31), $idEmpresa]);
verificar($assinatura()['status'] === 'bloqueada', 'Passado o período pago, a assinatura cancelada deveria bloquear.');

Assinatura::confirmarPagamento($idEmpresa);
bd()->prepare('UPDATE assinaturas SET data_pagamento = ? WHERE id_estabelecimento = ?')->execute([$quando(29), $idEmpresa]);
verificar($assinatura()['status'] === 'ativa', 'Dentro do período pago a assinatura deveria seguir ativa.');
bd()->prepare('UPDATE assinaturas SET data_pagamento = ? WHERE id_estabelecimento = ?')->execute([$quando(31), $idEmpresa]);
verificar($assinatura()['status'] === 'bloqueada', 'Sem novo pagamento, a assinatura não cancelada também deveria vencer.');

// 4. Retenção
$inserirLog = bd()->prepare("INSERT INTO logs_autenticacao (id_estabelecimento, login_informado, evento, data_hora) VALUES (?, 'x', 'login_falha', ?)");
$inserirLog->execute([$idEmpresa, $quando(400)]);
$inserirLog->execute([$idEmpresa, $quando(10)]);
$inserirMaster = bd()->prepare("INSERT INTO logs_master (acao, data_hora) VALUES ('login', ?)");
$inserirMaster->execute([$quando(400)]);
$inserirMaster->execute([$quando(6 * 365)]);
$apagadas = Legal::limparDadosAntigos();
verificar($apagadas['logs_autenticacao'] === 1, 'Só o registro de acesso com mais de 12 meses deveria sair: ' . json_encode($apagadas));
verificar($apagadas['logs_master'] === 1, 'Só a auditoria com mais de 5 anos deveria sair: ' . json_encode($apagadas));
verificar((int) bd()->query("SELECT COUNT(*) FROM logs_autenticacao WHERE login_informado = 'x'")->fetchColumn() === 1, 'O registro recente de acesso sumiu.');

// 5. Código de confirmação que não saiu não fica valendo (a tela não diz "enviamos")
foreach (['AGENDEI_EMAIL_HOST', 'AGENDEI_EMAIL_API_CHAVE'] as $variavel) putenv($variavel);
$_SESSION['codigos_email'] = [];
verificar(VerificacaoEmail::reenviar(1, 'admin@legal.local', 'Responsável') !== '', 'Envio sem provedor deveria falhar.');
verificar(!VerificacaoEmail::codigoAtivo(1), 'Código que não foi enviado não deveria ficar ativo.');
verificar(str_starts_with(VerificacaoEmail::reenviar(1, 'admin@legal.local', 'Responsável'), 'Aguarde'), 'Falha também respeita o intervalo de reenvio.');
verificar(VerificacaoEmail::conferir(1, 'admin@legal.local', '123456') !== '', 'Código não enviado não pode ser confirmado.');

echo "OK: {$checagens} verificações dos requisitos legais.\n";
