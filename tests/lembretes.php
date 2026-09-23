<?php

/**
 * Teste de integracao dos lembretes automaticos por WhatsApp, em banco temporario.
 * Cobre a geracao da fila, o envio pelo provedor (simulado, sem rede), a
 * contagem de tentativas, o cancelamento junto com o agendamento, a troca de
 * empresa pela tarefa agendada, o modelo da Meta e a repeticao da migracao.
 *
 * Execute com "php tests/lembretes.php". Nunca toca o banco de uso normal.
 * Roda nos dois dialetos: escolha com AGENDEI_DB_DRIVER=pgsql.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(dirname(__DIR__));
require_once 'config/database.php';

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
            throw new RuntimeException('Falha no teste dos lembretes.');
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
$esquema = preg_replace('/^(CREATE DATABASE|USE)\b[^;]*;\r?\n/mi', '', file_get_contents($arquivoEsquema));
bd()->exec($esquema);

// A tarefa agendada e o unico caminho que percorre todas as empresas. A
// constante precisa existir antes do bootstrap, como nos gatilhos reais.
define('TAREFA_AGENDADA', true);

ob_start();
if (!$ehPostgres) {
    require 'scripts/migrar.php';
}
require 'config/config.php';
ob_end_clean();

restore_exception_handler();
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$total = 0;
$falhas = 0;

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

/** Mensagem da excecao lancada pela chamada, ou null se nada foi lancado. */
function mensagemErro(callable $chamada): ?string
{
    try {
        $chamada();
    } catch (Throwable $erro) {
        return $erro->getMessage();
    }
    return null;
}

/** Notificacao de um agendamento, pelo tipo de lembrete. */
function notificacaoDe(int $idAgendamento): ?array
{
    $q = bd()->prepare('SELECT * FROM notificacoes WHERE id_estabelecimento = ? AND id_agendamento = ? ORDER BY id_notificacao');
    $q->execute([Contexto::id(), $idAgendamento]);
    return $q->fetch() ?: null;
}

// -------------------------------------------------------------------------
// 1. Empresa de teste e agendamentos de amanha
// -------------------------------------------------------------------------
verificar('sem empresa assumida o contexto e o do produto', Contexto::id(), 0);
verificar('assumir empresa inexistente falha', mensagemErro(fn() => Contexto::assumirTarefa(999999)), 'Estabelecimento inativo ou inexistente.');

$idEmpresa = Estabelecimento::contratar([
    'estabelecimento' => 'Studio Lembrete', 'slug' => 'studio-lembrete',
    'nome' => 'Dona do Studio', 'email' => 'dona@teste.local', 'senha' => 'Teste12345!',
]);
Contexto::assumirTarefa($idEmpresa);
verificar('tarefa assume a empresa criada', Contexto::id(), $idEmpresa);

$idServico = Servico::criar(['nome' => 'Corte', 'descricao' => 'Teste', 'preco' => 50, 'duracao_minutos' => 30, 'status' => 'ativo']);
$idClienteA = Cliente::criar(['nome' => 'Ana Beatriz Lima', 'email' => 'ana@teste.local', 'senha' => 'Teste12345!', 'telefone' => '(11) 99111-1111', 'cpf' => '52998224725']);
$idClienteB = Cliente::criar(['nome' => 'Bruno Cardoso', 'email' => 'bruno@teste.local', 'senha' => 'Teste12345!', 'telefone' => '11992222222', 'cpf' => '11144477735']);
$idClienteC = Cliente::criar(['nome' => 'Carla Sem Telefone', 'email' => 'carla@teste.local', 'senha' => 'Teste12345!', 'telefone' => '123', 'cpf' => '12345678909']);
$idProfissional = Profissional::criar(['nome' => 'Marcos Silva', 'email' => 'marcos@teste.local', 'senha' => 'Teste12345!', 'telefone' => '11993333333', 'especialidade' => 'Barbeiro', 'servicos' => [$idServico]]);

// Amanha, em horario comercial. A janela do lembrete e de 48 h para o teste
// nao depender da hora em que roda.
$data = date('Y-m-d', strtotime('+1 day'));
Horario::criar(['id_profissional' => $idProfissional, 'dia_semana' => (int) date('w', strtotime($data)), 'hora_inicio' => '08:00:00', 'hora_fim' => '18:00:00']);
Configuracao::definir('lembrete_horas', '48');

function agendar(int $cliente, int $profissional, int $servico, string $data, string $hora): int
{
    $r = Agendamento::criar(
        ['id_cliente' => $cliente, 'id_profissional' => $profissional, 'id_servico' => $servico, 'data' => $data, 'hora_inicio' => $hora, 'observacao' => '', 'origem' => 'admin'],
        ['ignorar_antecedencia' => true]
    );
    if (empty($r['sucesso'])) {
        throw new RuntimeException('Nao agendou: ' . implode(' ', $r['erros']));
    }
    return (int) $r['id_agendamento'];
}

$agA = agendar($idClienteA, $idProfissional, $idServico, $data, '10:00');
$agCancelado = agendar($idClienteA, $idProfissional, $idServico, $data, '11:00');
$agB = agendar($idClienteB, $idProfissional, $idServico, $data, '12:00');
$agC = agendar($idClienteC, $idProfissional, $idServico, $data, '13:00');

// -------------------------------------------------------------------------
// 2. Sem provedor: a fila e gerada, nada e enviado
// -------------------------------------------------------------------------
verificar('sem configuracao o provedor e manual', WhatsApp::provedor(), 'manual');
verificar('sem provedor o envio automatico esta desligado', WhatsApp::ativo(), false);

$r = Lembrete::processar();
verificar('processar sem provedor gera a fila', $r['geradas'], 4);
verificar('processar sem provedor nao envia', $r['enviadas'], 0);
verificar('lembrete gerado fica pendente', notificacaoDe($agA)['status'], 'pendente');
verificar('lembrete nasce sem tentativas', (int) notificacaoDe($agA)['tentativas'], 0);
verificar('mensagem cita o servico', str_contains(notificacaoDe($agA)['mensagem'], 'Corte'), true);
verificar('mensagem cita a empresa', str_contains(notificacaoDe($agA)['mensagem'], 'Studio Lembrete'), true);
verificar('mensagem cita o profissional', str_contains(notificacaoDe($agA)['mensagem'], 'Marcos'), true);
verificar('processar de novo nao duplica a fila', Lembrete::processar()['geradas'], 0);

// -------------------------------------------------------------------------
// 3. Numeros
// -------------------------------------------------------------------------
verificar('celular com mascara ganha o DDI', WhatsApp::normalizarNumero('(11) 99111-1111'), '5511991111111');
verificar('fixo com DDD ganha o DDI', WhatsApp::normalizarNumero('1133334444'), '551133334444');
verificar('numero ja com DDI fica como esta', WhatsApp::normalizarNumero('5511991111111'), '5511991111111');
verificar('numero curto e rejeitado', WhatsApp::normalizarNumero('123'), null);
verificar('numero vazio e rejeitado', WhatsApp::normalizarNumero(null), null);

// -------------------------------------------------------------------------
// 4. Evolution API: envio, falha e desistencia
// -------------------------------------------------------------------------
Configuracao::definir('whatsapp_provedor', 'evolution');
verificar('evolution sem credenciais continua manual', WhatsApp::provedor(), 'manual');
verificar('pendencia explica o que falta', WhatsApp::pendencia(), 'Informe a URL, a instancia e a chave (apikey) da Evolution API.');

Configuracao::definir('whatsapp_url', 'https://evo.teste/');
Configuracao::definir('whatsapp_instancia', 'agendei');
Configuracao::definir('whatsapp_token', 'chave-secreta');
verificar('evolution com credenciais fica ativo', WhatsApp::provedor(), 'evolution');
verificar('barra final da URL e removida', WhatsApp::configuracao()['url'], 'https://evo.teste');
verificar('sem pendencia quando ativo', WhatsApp::pendencia(), '');

// Cancelar a reserva antes do envio tira o lembrete da fila.
Agendamento::cancelar($agCancelado, null, 'Cliente desistiu');
verificar('cancelar o agendamento cancela o lembrete', notificacaoDe($agCancelado)['status'], 'cancelada');
verificar('cancelamento guarda a razao', notificacaoDe($agCancelado)['erro'], 'Agendamento cancelado antes do envio.');

$envios = [];
WhatsApp::simular(static function (array $chamada) use (&$envios): array {
    $envios[] = $chamada;
    if (($chamada['corpo']['number'] ?? '') === '5511992222222') {
        throw new RuntimeException('Provedor: numero nao esta no WhatsApp');
    }
    return ['key' => ['id' => 'ABC']];
});

$r = Lembrete::processar();
verificar('processar informa o provedor', $r['provedor'], 'evolution');
verificar('um lembrete enviado', $r['enviadas'], 1);
verificar('um lembrete falhou', $r['falhas'], 1);
verificar('lembrete sem telefone valido e cancelado', $r['canceladas'], 1);
verificar('o cancelado nao foi tentado', count($envios), 2);

$nA = notificacaoDe($agA);
verificar('lembrete enviado muda o status', $nA['status'], 'enviada');
verificar('lembrete enviado registra a data', $nA['data_envio'] !== null, true);
verificar('chamada foi para a rota da Evolution', $envios[0]['url'], 'https://evo.teste/message/sendText/agendei');
verificar('chamada levou a apikey', in_array('apikey: chave-secreta', $envios[0]['cabecalhos'], true), true);
verificar('chamada levou o numero normalizado', $envios[0]['corpo']['number'], '5511991111111');
verificar('chamada levou o texto do lembrete', $envios[0]['corpo']['text'], $nA['mensagem']);

$nB = notificacaoDe($agB);
verificar('falha mantem o lembrete pendente', $nB['status'], 'pendente');
verificar('falha conta uma tentativa', (int) $nB['tentativas'], 1);
verificar('falha guarda a razao', $nB['erro'], 'Provedor: numero nao esta no WhatsApp');

$nC = notificacaoDe($agC);
verificar('telefone invalido cancela a notificacao', $nC['status'], 'cancelada');
verificar('telefone invalido explica a razao', $nC['erro'], 'Telefone do cliente incompleto ou invalido.');

verificar('ultima execucao registrada', Configuracao::obter('whatsapp_ultima_execucao') !== '', true);

// Depois de MAX_TENTATIVAS a tarefa desiste, e a mensagem segue na fila manual.
Lembrete::processar();
Lembrete::processar();
verificar('tentativas chegam ao maximo', (int) notificacaoDe($agB)['tentativas'], Lembrete::MAX_TENTATIVAS);
$antes = count($envios);
$r = Lembrete::processar();
verificar('depois do maximo nao ha nova tentativa', count($envios), $antes);
verificar('depois do maximo nao conta falha', $r['falhas'], 0);
verificar('mensagem esgotada continua na fila manual', notificacaoDe($agB)['status'], 'pendente');
verificar('fila manual do painel ainda a lista', count(Diferencial::notificacoesPendentes()), 1);
verificar('historico do painel lista enviadas e canceladas', count(Diferencial::notificacoesRecentes()), 3);

// Concluir o atendimento tambem tira o lembrete da fila.
Agendamento::alterarStatus($agB, 'concluido');
verificar('concluir o agendamento cancela o lembrete', notificacaoDe($agB)['status'], 'cancelada');

// Mensagem de teste.
$antes = count($envios);
verificar('teste com numero invalido e recusado', mensagemErro(fn() => Lembrete::enviarTeste('12')), 'Informe um numero com DDD (10 ou 11 digitos).');
verificar('teste com numero valido envia', mensagemErro(fn() => Lembrete::enviarTeste('(11) 99111-1111')), null);
verificar('teste passou pelo provedor', count($envios), $antes + 1);
verificar('teste cita a empresa', str_contains($envios[$antes]['corpo']['text'], 'Studio Lembrete'), true);

// -------------------------------------------------------------------------
// 5. Meta Cloud API: modelo com quatro variaveis; outros tipos ficam manuais
// -------------------------------------------------------------------------
$agD = agendar($idClienteA, $idProfissional, $idServico, $data, '14:00');
Configuracao::definir('whatsapp_provedor', 'meta');
verificar('meta sem credenciais continua manual', WhatsApp::provedor(), 'manual');
Configuracao::definir('whatsapp_telefone_id', '123456789');
Configuracao::definir('whatsapp_modelo', 'lembrete_agendamento');
verificar('meta com credenciais fica ativo', WhatsApp::provedor(), 'meta');

$inserir = bd()->prepare(
    Sql::inserirIgnorando() . ' notificacoes (id_estabelecimento,id_usuario,id_agendamento,canal,tipo,destinatario,mensagem,data_programada)
     VALUES (?,?,?,?,?,?,?,NOW())' . Sql::ignorarConflito()
);
$inserir->execute([Contexto::id(), (int) Cliente::porId($idClienteA)['id_usuario'], $agA, 'whatsapp', 'vaga_lista_1', '11991111111', 'Surgiu uma vaga.']);

$envios = [];
$r = Lembrete::processar();
verificar('meta envia o lembrete novo', $r['enviadas'], 1);
verificar('meta deixa o aviso de vaga para a fila manual', count($envios), 1);
verificar('chamada foi para o Graph API', str_starts_with($envios[0]['url'], 'https://graph.facebook.com/'), true);
verificar('chamada usa o numero configurado', str_contains($envios[0]['url'], '/123456789/messages'), true);
verificar('chamada leva o token Bearer', in_array('Authorization: Bearer chave-secreta', $envios[0]['cabecalhos'], true), true);
verificar('chamada e do tipo modelo', $envios[0]['corpo']['type'], 'template');
verificar('modelo e o configurado', $envios[0]['corpo']['template']['name'], 'lembrete_agendamento');
$parametros = array_column($envios[0]['corpo']['template']['components'][0]['parameters'], 'text');
verificar('modelo recebe nome, servico, data e hora', $parametros, ['Ana', 'Corte', formatarData($data), '14:00']);
verificar('texto livre e recusado na meta', mensagemErro(fn() => WhatsApp::enviarTexto('5511991111111', 'oi')) !== null, true);

// -------------------------------------------------------------------------
// 6. Tarefa percorre todas as empresas ativas
// -------------------------------------------------------------------------
$idInativa = Estabelecimento::contratar([
    'estabelecimento' => 'Fechada', 'slug' => 'fechada', 'status' => 'inativo',
    'nome' => 'Ninguem', 'email' => 'ninguem@teste.local', 'senha' => 'Teste12345!',
]);
$resumo = Lembrete::processarTodas();
verificar('resumo cobre a empresa ativa', isset($resumo[$idEmpresa]), true);
verificar('resumo ignora a empresa inativa', isset($resumo[$idInativa]), false);
verificar('resumo traz o nome da empresa', $resumo[$idEmpresa]['nome'], 'Studio Lembrete');
verificar('resumo traz o provedor', $resumo[$idEmpresa]['provedor'], 'meta');
verificar('resumo em texto e legivel', str_starts_with(Lembrete::resumoTexto($idEmpresa, $resumo[$idEmpresa]), "[{$idEmpresa}] Studio Lembrete (meta)"), true);

// -------------------------------------------------------------------------
// 7. Migracao repetida nao falha nem duplica
// -------------------------------------------------------------------------
ob_start();
require 'scripts/migrar_lembretes.php';
ob_end_clean();
verificar('migracao repetida preserva a fila', notificacaoDe($agA)['status'], 'enviada');

WhatsApp::simular(null);

// -------------------------------------------------------------------------
echo "OK: {$total} verificacoes dos lembretes";
echo $falhas === 0 ? ".\n" : ", {$falhas} FALHA(S).\n";
exit($falhas === 0 ? 0 : 1);
