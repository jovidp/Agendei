<?php

/**
 * Teste de integracao do cadastro de empresa pela pagina inicial, em banco
 * temporario. Cobre a abertura da solicitacao (empresa inativa, conta do
 * responsavel, auditoria sem master), a recusa do login enquanto pendente e
 * as duas decisoes do master: aprovar e recusar.
 *
 * Execute com "php tests/cadastro_empresa.php". Nunca toca o banco de uso normal.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(dirname(__DIR__));
require_once 'config/database.php';

// O mesmo teste roda nos dois dialetos. Escolha com AGENDEI_DB_DRIVER=pgsql.
$ehPostgres = Database::ehPostgres();

// -------------------------------------------------------------------------
// Processo principal: monta o banco isolado e chama o worker
// -------------------------------------------------------------------------
if (($argv[1] ?? '') !== '--worker') {
    $nomeTeste = 'agendei_test_' . bin2hex(random_bytes(6));
    if (!preg_match('/^agendei_test_[a-f0-9]{12}$/D', $nomeTeste)) {
        throw new RuntimeException('Nome de banco de teste invalido.');
    }

    $db = bd();
    $db->exec($ehPostgres
        ? "CREATE DATABASE \"$nomeTeste\" ENCODING 'UTF8'"
        : "CREATE DATABASE `$nomeTeste` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    try {
        putenv('AGENDEI_DB_NAME=' . $nomeTeste);
        $processo = proc_open([PHP_BINARY, __FILE__, '--worker'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
        $codigo = proc_close($processo);

        if ($codigo !== 0) {
            throw new RuntimeException('Falha no cadastro de empresa.');
        }
    } finally {
        $db->exec($ehPostgres ? "DROP DATABASE \"$nomeTeste\"" : "DROP DATABASE `$nomeTeste`");
    }

    exit;
}

// -------------------------------------------------------------------------
// Worker: roda dentro do banco isolado
// -------------------------------------------------------------------------
if (!preg_match('/^agendei_test_[a-f0-9]{12}$/D', getenv('AGENDEI_DB_NAME') ?: '')) {
    throw new RuntimeException('O teste exige banco isolado.');
}

$arquivoEsquema = $ehPostgres ? 'banco_postgres.sql' : 'banco.sql';
if (!is_file($arquivoEsquema)) {
    throw new RuntimeException("Esquema {$arquivoEsquema} nao encontrado.");
}
$esquema = preg_replace('/^(CREATE DATABASE|USE)\b[^;]*;\r?\n/mi', '', file_get_contents($arquivoEsquema));
bd()->exec($esquema);

ob_start();
if (!$ehPostgres) {
    require 'scripts/migrar.php';
}
// A pagina de cadastro e a entrada geral abrem sob a marca do produto.
define('ENTRADA_LOCAL', true);
define('PAGINA_PRODUTO', true);
require 'config/config.php';
restore_exception_handler();
ob_end_clean();

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$total = 0;
$falhas = 0;

/** Compara o resultado com o esperado e acumula o placar do teste. */
function verificar(string $descricao, $obtido, $esperado): void
{
    global $total, $falhas;
    $total++;

    if ($obtido === $esperado) {
        return;
    }

    $falhas++;
    echo "  FALHA  {$descricao}\n";
    echo '         esperado: ' . var_export($esperado, true) . "\n";
    echo '         obtido:   ' . var_export($obtido, true) . "\n";
}

$pedido = [
    'estabelecimento' => 'Barbearia do Zé',
    'slug'            => 'barbearia-do-ze',
    'nome'            => 'José Responsável',
    'email'           => 'ze@barbearia.test',
    'senha'           => 'senha-forte-1',
    'telefone'        => '11999998888',
    'mensagem'        => 'Duas cadeiras, atendemos das 9h as 19h.',
];

// -------------------------------------------------------------------------
// Pagina inicial: contexto do produto e endereco livre
// -------------------------------------------------------------------------
verificar('a pagina do produto nao assume empresa', Contexto::id(), 0);
verificar('a pagina do produto usa o nome do sistema', Contexto::dados()['nome'], NOME_SISTEMA);
verificar('a pagina do produto usa o slogan da marca', Contexto::dados()['slogan'], MARCA_SLOGAN);
verificar('a tabela de solicitacoes e criada sob demanda', Solicitacao::disponivel(), true);
verificar('o endereco comeca livre', Solicitacao::slugEmUso($pedido['slug']), false);
verificar('nenhuma solicitacao pendente no inicio', Solicitacao::contarPendentes(), 0);

// -------------------------------------------------------------------------
// Abertura da solicitacao, sem master na sessao
// -------------------------------------------------------------------------
$idSolicitacao = Solicitacao::abrir($pedido);
verificar('a solicitacao recebe um id', $idSolicitacao > 0, true);

$solicitacao = Solicitacao::porId($idSolicitacao);
verificar('a solicitacao nasce pendente', $solicitacao['status'] ?? null, 'pendente');
verificar('a solicitacao guarda a empresa', $solicitacao['estabelecimento_nome'] ?? null, $pedido['estabelecimento']);
verificar('a solicitacao guarda o contato', $solicitacao['telefone'] ?? null, $pedido['telefone']);
verificar('a solicitacao guarda a mensagem', $solicitacao['mensagem'] ?? null, $pedido['mensagem']);

$idEmpresa = (int) $solicitacao['id_estabelecimento'];
$empresa = Estabelecimento::porIdGlobal($idEmpresa);
verificar('a empresa e criada', $empresa !== null, true);
verificar('a empresa nasce inativa', $empresa['status'] ?? null, 'inativo');
verificar('o endereco passa a estar em uso', Solicitacao::slugEmUso($pedido['slug']), true);
verificar('a empresa aparece entre as pendentes', in_array($idEmpresa, Solicitacao::empresasPendentes(), true), true);
verificar('o pedido e encontrado pelo endereco', (int) (Solicitacao::pendentePorSlug($pedido['slug'])['id_solicitacao'] ?? 0), $idSolicitacao);
verificar('a fila do master tem um pedido', Solicitacao::contarPendentes(), 1);

$admins = Estabelecimento::administradores($idEmpresa);
verificar('o responsavel vira administrador da empresa', count($admins), 1);
verificar('o administrador e criado ativo', $admins[0]['status'] ?? null, 'ativo');

// A empresa inativa nao entra pela entrada geral, mesmo com a senha certa.
verificar('a entrada geral recusa a conta enquanto pendente', autenticarGlobal($pedido['email'], $pedido['senha']), []);

LogMaster::registrar('cadastro_solicitado', ['estabelecimento_nome' => $pedido['estabelecimento'], 'alvo' => $pedido['email']]);
$evento = LogMaster::listar(['limite' => 1])[0] ?? [];
verificar('o pedido entra na auditoria', $evento['acao'] ?? null, 'cadastro_solicitado');
verificar('o pedido nao tem master associado', (int) ($evento['id_master'] ?? 0), 0);

// -------------------------------------------------------------------------
// Aprovacao pelo master
// -------------------------------------------------------------------------
$idMaster = (int) bd()->query('SELECT id_master FROM administradores_master ORDER BY id_master LIMIT 1')->fetchColumn();
if ($idMaster === 0) {
    $consulta = bd()->prepare('INSERT INTO administradores_master (nome, email, senha_hash) VALUES (?, ?, ?)');
    $consulta->execute(['Administrador Master', 'master@agendei.com.br', password_hash('agendei-master-2026', PASSWORD_DEFAULT)]);
    $idMaster = (int) bd()->lastInsertId();
}
$_SESSION['master_id']     = $idMaster;
$_SESSION['usuario_tipo']  = 'master';
$_SESSION['usuario_nome']  = 'Administrador Master';
$_SESSION['usuario_email'] = 'master@agendei.com.br';

verificar('aprovar devolve verdadeiro', Solicitacao::aprovar($idSolicitacao), true);
verificar('a empresa passa a ativa', Estabelecimento::porIdGlobal($idEmpresa)['status'] ?? null, 'ativo');
verificar('a solicitacao fica aprovada', Solicitacao::porId($idSolicitacao)['status'] ?? null, 'aprovada');
verificar('a data da decisao e gravada', Solicitacao::porId($idSolicitacao)['data_decisao'] !== null, true);
verificar('a fila do master esvazia', Solicitacao::contarPendentes(), 0);
verificar('o pedido some da busca por endereco', Solicitacao::pendentePorSlug($pedido['slug']), null);
verificar('aprovar de novo e recusado', Solicitacao::aprovar($idSolicitacao), false);
verificar('recusar depois de aprovar e recusado', Solicitacao::recusar($idSolicitacao), false);

$contas = autenticarGlobal($pedido['email'], $pedido['senha']);
verificar('a entrada geral aceita a conta depois da aprovacao', count($contas), 1);
verificar('a conta aceita e a da empresa aprovada', (int) ($contas[0]['id_estabelecimento'] ?? 0), $idEmpresa);

// -------------------------------------------------------------------------
// Recusa pelo master: empresa e conta somem, o pedido fica como historico
// -------------------------------------------------------------------------
$segundo = ['estabelecimento' => 'Salão Teste', 'slug' => 'salao-teste', 'nome' => 'Maria Teste', 'email' => 'maria@salao.test', 'senha' => 'outra-senha-2', 'telefone' => '', 'mensagem' => ''];
$idSegunda = Solicitacao::abrir($segundo);
$idEmpresaB = (int) Solicitacao::porId($idSegunda)['id_estabelecimento'];
verificar('telefone vazio vira nulo', Solicitacao::porId($idSegunda)['telefone'], null);
verificar('a segunda empresa existe antes da recusa', Estabelecimento::porIdGlobal($idEmpresaB) !== null, true);

verificar('recusar devolve verdadeiro', Solicitacao::recusar($idSegunda), true);
verificar('a empresa recusada e apagada', Estabelecimento::porIdGlobal($idEmpresaB), null);
verificar('o endereco recusado fica livre', Solicitacao::slugEmUso($segundo['slug']), false);
verificar('a solicitacao fica como recusada', Solicitacao::porId($idSegunda)['status'] ?? null, 'recusada');
verificar('a solicitacao recusada continua legivel', Solicitacao::porId($idSegunda)['estabelecimento_nome'] ?? null, $segundo['estabelecimento']);
verificar('a empresa aprovada nao e afetada', Estabelecimento::porIdGlobal($idEmpresa)['status'] ?? null, 'ativo');

// -------------------------------------------------------------------------
// Autonomo: administra e atende no proprio negocio, com a Matriz e agenda propria
// -------------------------------------------------------------------------
$autonomo = ['estabelecimento' => 'Joana Manicure', 'slug' => 'joana-manicure', 'nome' => 'Joana Autonoma', 'email' => 'joana@autonoma.test', 'senha' => 'senha-forte-3', 'telefone' => '11977776666', 'mensagem' => '', 'autonomo' => true];
$idTerceira = Solicitacao::abrir($autonomo);
$idEmpresaC = (int) Solicitacao::porId($idTerceira)['id_estabelecimento'];
$pessoaJoana = Usuario::pessoaPorEmail($autonomo['email']);
verificar('a autonoma e uma pessoa so', $pessoaJoana !== null, true);
$tiposJoana = array_column(Vinculo::daPessoa((int) $pessoaJoana['id_usuario']), 'tipo');
sort($tiposJoana);
verificar('a autonoma e administradora e profissional da propria empresa', $tiposJoana, ['admin', 'profissional']);
verificar('a autonoma tem a unidade Matriz', (int) bd()->query("SELECT COUNT(*) FROM filiais WHERE id_estabelecimento = $idEmpresaC")->fetchColumn(), 1);
verificar('a autonoma tem perfil de profissional', (int) bd()->query("SELECT COUNT(*) FROM profissionais WHERE id_estabelecimento = $idEmpresaC")->fetchColumn(), 1);

// E-mail que ja tem conta: a nova empresa reaproveita a pessoa como responsavel.
$reaproveitado = ['estabelecimento' => 'Segundo Negocio da Joana', 'slug' => 'joana-dois', 'nome' => 'Outro Nome', 'email' => 'joana@autonoma.test', 'senha' => 'qualquer', 'telefone' => '', 'mensagem' => ''];
Solicitacao::abrir($reaproveitado);
verificar('a segunda empresa reaproveita a pessoa', (int) bd()->query("SELECT COUNT(*) FROM usuarios WHERE email = 'joana@autonoma.test'")->fetchColumn(), 1);
verificar('a pessoa passa a ter tres vinculos', count(Vinculo::daPessoa((int) $pessoaJoana['id_usuario'])), 3);
verificar('a pessoa reaproveitada mantem o nome', Usuario::pessoaPorEmail('joana@autonoma.test')['nome'], 'Joana Autonoma');

// -------------------------------------------------------------------------
// Freio por origem do cadastro publico
// -------------------------------------------------------------------------
$limites = politicaLimites();
verificar('o cadastro de empresa tem limite proprio', isset($limites['cadastro_empresa_ip']), true);
for ($i = 0; $i < ($limites['cadastro_empresa_ip']['limite'] ?? 5); $i++) {
    anotarFalha('cadastro_empresa_ip', '203.0.113.7');
}
verificar('estourar o limite bloqueia a origem', conferirBloqueio(['cadastro_empresa_ip' => '203.0.113.7']) !== '', true);
verificar('outra origem segue livre', conferirBloqueio(['cadastro_empresa_ip' => '203.0.113.8']), '');

echo "\n";
if ($falhas > 0) {
    echo "FALHOU: {$falhas} de {$total} verificacoes do cadastro de empresa.\n";
    exit(1);
}
echo "OK: {$total} verificacoes do cadastro de empresa.\n";
