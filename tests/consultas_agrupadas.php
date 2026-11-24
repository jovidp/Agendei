<?php
/**
 * As consultas agrupadas (dashboard, listas de servicos e profissionais, tela
 * de planos do master) devolvem o mesmo que as consultas uma-a-uma que elas
 * substituiram. Banco isolado, dados aleatorios espalhados por tres meses.
 *
 *   php tests/consultas_agrupadas.php
 *   AGENDEI_DB_DRIVER=pgsql php tests/consultas_agrupadas.php
 */
chdir(dirname(__DIR__));
require_once 'config/database.php';

$ehPostgres = Database::ehPostgres();

if (($argv[1] ?? '') !== '--worker') {
    $nomeTeste = 'agendei_test_' . bin2hex(random_bytes(6));
    $db = bd();
    $db->exec($ehPostgres
        ? "CREATE DATABASE \"$nomeTeste\" ENCODING 'UTF8'"
        : "CREATE DATABASE `$nomeTeste` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        putenv('AGENDEI_DB_NAME=' . $nomeTeste);
        $codigo = proc_close(proc_open([PHP_BINARY, __FILE__, '--worker'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes));
        if ($codigo !== 0) {
            throw new RuntimeException('Falha nas consultas agrupadas.');
        }
    } finally {
        $db->exec($ehPostgres ? "DROP DATABASE \"$nomeTeste\"" : "DROP DATABASE `$nomeTeste`");
    }
    exit;
}

if (!preg_match('/^agendei_test_[a-f0-9]{12}$/D', getenv('AGENDEI_DB_NAME') ?: '')) {
    throw new RuntimeException('O teste exige banco isolado.');
}

$esquema = preg_replace('/^(CREATE DATABASE|USE)\b[^;]*;\r?\n/mi', '', file_get_contents($ehPostgres ? 'banco_postgres.sql' : 'banco.sql'));
bd()->exec($esquema);
ob_start();
if (!$ehPostgres) {
    require 'scripts/migrar.php';
}
require 'config/config.php';
restore_exception_handler();
ob_end_clean();

$falhas = 0;
$total = 0;
function verificar(string $descricao, $obtido, $esperado): void
{
    global $falhas, $total;
    $total++;
    if ($obtido != $esperado) {
        $falhas++;
        echo "FALHA: $descricao\n  esperado: " . var_export($esperado, true) . "\n  obtido:   " . var_export($obtido, true) . "\n";
    }
}

// ---------------------------------------------------------------------
// Dados: servicos, profissionais (um inativo), clientes e agendamentos
// ---------------------------------------------------------------------
$idsServicos = [];
foreach (['Corte', 'Barba', 'Escova', 'Sem uso'] as $i => $nome) {
    $idsServicos[] = Servico::criar(['nome' => $nome, 'descricao' => '', 'preco' => 30 + $i * 10, 'duracao_minutos' => 30, 'status' => 'ativo']);
}
$idsProfissionais = [];
foreach (['Paulo', 'Rita', 'Caio'] as $i => $nome) {
    $idsProfissionais[] = Profissional::criar([
        'nome' => $nome . ' Teste', 'email' => strtolower($nome) . '@teste.local', 'senha' => 'abcdefgh',
        'telefone' => '1199000000' . $i, 'especialidade' => 'x',
        'servicos' => array_slice($idsServicos, 0, 3 - $i),
    ]);
}
// Caio sem agendamento e inativo: nao conta como profissional do servico nem no uso do plano.
Vinculo::alterarStatus((int) Profissional::porId($idsProfissionais[2])['id_vinculo'], 'inativo');

$idsClientes = [];
foreach (['Ana', 'Bia'] as $i => $nome) {
    $idsClientes[] = Cliente::criar([
        'nome' => $nome . ' Teste', 'email' => strtolower($nome) . '@teste.local', 'senha' => 'abcdefgh',
        'telefone' => '1198000000' . $i, 'cpf' => ['52998224725', '11144477735'][$i],
    ]);
}

mt_srand(42);
$status = Agendamento::STATUS;
$insere = bd()->prepare('INSERT INTO agendamentos (id_estabelecimento, id_cliente, id_profissional, id_servico,
    data_agendamento, hora_inicio, hora_fim, valor, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
for ($n = 0; $n < 300; $n++) {
    $data = date('Y-m-d', strtotime((mt_rand(-75, 20)) . ' days'));
    $hora = sprintf('%02d:%02d:00', mt_rand(8, 17), mt_rand(0, 1) * 30);
    $insere->execute([
        Contexto::id(), $idsClientes[$n % 2], $idsProfissionais[$n % 2], $idsServicos[$n % 3],
        $data, $hora, date('H:i:s', strtotime($hora) + 1800), mt_rand(1000, 9000) / 100, $status[mt_rand(0, 3)],
    ]);
}

// ---------------------------------------------------------------------
// Dashboard: mesmas contas de antes, uma consulta por numero
// ---------------------------------------------------------------------
$hoje = date('Y-m-d');
$inicioSemana = date('Y-m-d', strtotime('monday this week'));
$fimSemana = date('Y-m-d', strtotime('sunday this week'));
$inicioMes = date('Y-m-01');
$fimMes = date('Y-m-t');
$ativos = ['agendado', 'confirmado', 'concluido'];

$antigo = [
    'agendamentos_hoje'   => Agendamento::contar(['data' => $hoje, 'status_em' => $ativos]),
    'agendamentos_semana' => Agendamento::contar(['data_inicial' => $inicioSemana, 'data_final' => $fimSemana, 'status_em' => $ativos]),
    'clientes_total'      => Cliente::total(),
    'clientes_ativos'     => Cliente::total('ativo'),
    'servicos_realizados' => Agendamento::contar(['data_inicial' => $inicioMes, 'data_final' => $fimMes, 'status' => 'concluido']),
    'faturamento_mes'     => Relatorio::faturamento($inicioMes, $fimMes),
    'faturamento_realizado_mes' => Relatorio::faturamento($inicioMes, $fimMes, ['concluido']),
    'cancelamentos_mes'   => Agendamento::contar(['data_inicial' => $inicioMes, 'data_final' => $fimMes, 'status' => 'cancelado']),
    'profissionais_ativos' => Profissional::total('ativo'),
    'servicos_ativos'      => Servico::total('ativo'),
];
$novo = Relatorio::indicadores();
foreach ($antigo as $chave => $valor) {
    verificar("indicadores[$chave]", $novo[$chave] ?? null, $valor);
}
verificar('indicadores sem chave a mais', count($novo), count($antigo));
verificar('ha movimento no mes para o teste valer', $antigo['faturamento_mes'] > 0, true);

// Varias datas de referencia, incluindo dia 31 e virada de ano.
foreach ([$hoje, date('Y-m-d', strtotime('-20 days')), date('Y') . '-03-31', date('Y') . '-01-02'] as $ref) {
    $dia = new DateTimeImmutable($ref);
    $iSemana = $dia->modify('monday this week');
    $iMes = $dia->modify('first day of this month');
    $iMesAnt = $iMes->modify('-1 month');
    $fMesAnt = $iMesAnt->modify('+' . (min((int) $dia->format('j'), (int) $iMesAnt->format('t')) - 1) . ' days');
    $f = fn (DateTimeImmutable $d) => $d->format('Y-m-d');
    $p = fn (string $de, string $ate, array $filtro) => Agendamento::contar(['data_inicial' => $de, 'data_final' => $ate] + $filtro);
    $esperado = [
        'semana_atual'        => $p($f($iSemana), $ref, ['status_em' => $ativos]),
        'semana_anterior'     => $p($f($iSemana->modify('-7 days')), $f($dia->modify('-7 days')), ['status_em' => $ativos]),
        'concluidos_atual'    => $p($f($iMes), $ref, ['status' => 'concluido']),
        'concluidos_anterior' => $p($f($iMesAnt), $f($fMesAnt), ['status' => 'concluido']),
        'realizado_atual'     => Relatorio::faturamento($f($iMes), $ref, ['concluido']),
        'realizado_anterior'  => Relatorio::faturamento($f($iMesAnt), $f($fMesAnt), ['concluido']),
        'cancelados_atual'    => $p($f($iMes), $ref, ['status' => 'cancelado']),
        'cancelados_anterior' => $p($f($iMesAnt), $f($fMesAnt), ['status' => 'cancelado']),
    ];
    verificar("comparativos($ref)", Relatorio::comparativos($ref), $esperado);
}

// ---------------------------------------------------------------------
// Listas do admin
// ---------------------------------------------------------------------
$resumo = Servico::resumoUso();
foreach ($idsServicos as $id) {
    verificar("servico $id: profissionais", $resumo[$id]['profissionais'], count(Profissional::porServico($id)));
    verificar("servico $id: excluivel", $resumo[$id]['excluivel'], Servico::podeExcluir($id));
}
verificar('servico sem uso pode ser excluido', $resumo[$idsServicos[3]]['excluivel'], true);

$porProfissional = Agendamento::totaisPorProfissional();
foreach ($idsProfissionais as $id) {
    verificar("profissional $id: agendamentos", $porProfissional[$id] ?? 0, Agendamento::contar(['id_profissional' => $id]));
}

// ---------------------------------------------------------------------
// Tela de planos do master
// ---------------------------------------------------------------------
$idEmpresa = Contexto::id();
$esperadoUso = Plano::uso($idEmpresa);
$esperadoSituacao = Plano::situacao($idEmpresa);
$usoTodas = Plano::usoDeTodas();
verificar('uso de todas = uso da empresa', ($usoTodas[$idEmpresa] ?? []) + ['profissionais' => 0, 'servicos' => 0, 'agendamentos_mes' => 0], $esperadoUso);
verificar('situacao com uso pronto', Plano::situacao($idEmpresa, $usoTodas[$idEmpresa] ?? []), $esperadoSituacao);
verificar('situacao de empresa sem nada', Plano::situacao(999999, []), Plano::situacao(999999));

echo $falhas === 0 ? "OK: $total verificações das consultas agrupadas.\n" : "$falhas de $total verificações falharam.\n";
exit($falhas === 0 ? 0 : 1);
