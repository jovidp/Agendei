<?php

/**
 * Teste de integracao em banco temporario: copia de horarios, calendario do
 * mes calculado de uma vez e o destino do sinal quando o cliente cancela.
 * Execute com "php tests/sinal_horarios.php". Nunca toca o banco de uso normal.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(dirname(__DIR__));
require_once 'config/database.php';
// O mesmo teste roda nos dois dialetos. Escolha com AGENDEI_DB_DRIVER=pgsql.
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
        if (proc_close($processo) !== 0) throw new RuntimeException('Falha no teste do sinal e dos horários.');
    } finally {
        $db->exec($ehPostgres ? "DROP DATABASE \"$nomeTeste\"" : "DROP DATABASE `$nomeTeste`");
    }
    exit;
}
if (!preg_match('/^agendei_test_[a-f0-9]{12}$/D', getenv('AGENDEI_DB_NAME') ?: '')) throw new RuntimeException('O teste exige banco isolado.');
$esquema = file_get_contents($ehPostgres ? 'banco_postgres.sql' : 'banco.sql');
bd()->exec(preg_replace('/^(CREATE DATABASE|USE)\b[^;]*;\r?\n/mi', '', $esquema));
ob_start();
if ($ehPostgres) {
    // Banco como o de producao antes desta versao: sem credito nem retido no sinal.
    bd()->exec('ALTER TABLE pagamentos DROP CONSTRAINT ck_pagamento_status');
    bd()->exec("ALTER TABLE pagamentos ADD CONSTRAINT ck_pagamento_status CHECK (status IN ('pendente','pago','cancelado','estornado'))");
    require_once 'models/Diferencial.php';
    Diferencial::garantirStatusSinal();
} else {
    require 'scripts/migrar.php';
}
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

Estabelecimento::contratar(['estabelecimento' => 'SINAL', 'slug' => 'sinal', 'nome' => 'Responsável Teste', 'email' => 'admin@sinal.local', 'senha' => 'Teste12345!']);
$_SESSION = [];
$_GET = ['estabelecimento' => 'sinal'];
Contexto::iniciar();
$admin = Usuario::porEmail('admin@sinal.local');

$servico = Servico::criar(['nome' => 'Corte', 'descricao' => 'Teste', 'preco' => 100, 'duracao_minutos' => 30, 'destaque' => 1]);
$cliente = Cliente::criar(['nome' => 'Cliente Sinal', 'email' => 'cliente@sinal.local', 'senha' => 'Teste12345!', 'telefone' => '11999999999', 'cpf' => '52998224725']);
$profissional = Profissional::criar(['nome' => 'Profissional Sinal', 'email' => 'prof@sinal.local', 'senha' => 'Teste12345!', 'especialidade' => 'X', 'servicos' => [$servico]]);

// ---------------------------------------------------------------------
// 1. Copiar horarios substitui o que o dia de destino tinha
// ---------------------------------------------------------------------
Horario::criar(['id_profissional' => $profissional, 'dia_semana' => 1, 'hora_inicio' => '08:00:00', 'hora_fim' => '12:00:00']);
Horario::criar(['id_profissional' => $profissional, 'dia_semana' => 1, 'hora_inicio' => '13:00:00', 'hora_fim' => '18:00:00']);
Horario::criar(['id_profissional' => $profissional, 'dia_semana' => 2, 'hora_inicio' => '09:00:00', 'hora_fim' => '11:00:00']);
$criadas = Horario::replicarDia($profissional, 1, [0, 1, 2, 3, 4, 5, 6]);
$porDia = Horario::agrupadoPorDia($profissional);
verificar($criadas === 12, 'A cópia deveria criar 2 horários em cada um dos 6 dias: ' . $criadas);
verificar(count($porDia[2]) === 2 && substr($porDia[2][0]['hora_inicio'], 0, 5) === '08:00', 'A terça não ficou igual à segunda.');
verificar(count($porDia[1]) === 2, 'O dia de origem mudou.');

// ---------------------------------------------------------------------
// 2. Calendario do mes: mesmo resultado de slots() dia a dia
// ---------------------------------------------------------------------
$inicio = date('Y-m-d', strtotime('+1 day'));
$fim = date('Y-m-d', strtotime('+20 days'));
Bloqueio::criar(['id_profissional' => $profissional, 'data_bloqueio' => $inicio, 'hora_inicio' => '00:00:00', 'hora_fim' => '23:59:00', 'motivo' => 'Folga', 'id_usuario_criou' => $admin['id_usuario']]);
$esperado = [];
for ($dia = $inicio; $dia <= $fim; $dia = date('Y-m-d', strtotime($dia . ' +1 day'))) {
    if (Disponibilidade::slots($profissional, $servico, $dia) !== []) $esperado[] = $dia;
}
$obtido = Disponibilidade::diasComVagas($profissional, $servico, $inicio, $fim);
verificar($obtido === $esperado, 'diasComVagas diverge de slots(): ' . json_encode([$obtido, $esperado]));
verificar(!in_array($inicio, $obtido, true), 'O dia todo bloqueado apareceu como livre.');

// ---------------------------------------------------------------------
// 3. Sinal: credito, reaproveitamento, retencao
// ---------------------------------------------------------------------
Configuracao::definir('pix_chave', 'teste@pix.local');
Configuracao::definir('sinal_percentual', '20');
Configuracao::definir('cancelamento_limite_minutos', '30');
$diaLivre = $obtido[1];

$reservar = function (string $hora) use ($cliente, $profissional, $servico, $diaLivre): int {
    $r = Agendamento::criar(['id_cliente' => $cliente, 'id_profissional' => $profissional, 'id_servico' => $servico, 'data' => $diaLivre, 'hora_inicio' => $hora, 'observacao' => '', 'origem' => 'cliente'], ['ignorar_antecedencia' => true]);
    verificar($r['sucesso'], 'Reserva não criada: ' . json_encode($r));
    return (int) $r['id_agendamento'];
};
$statusSinal = fn(int $id) => Diferencial::sinalDoAgendamento($id)['status'] ?? null;

$a = $reservar('08:00');
verificar($statusSinal($a) === 'pendente', 'O sinal deveria nascer pendente.');
verificar(Diferencial::pagarSinalSimulado($a), 'A simulação de pagamento falhou.');
verificar($statusSinal($a) === 'pago' && !Diferencial::pagarSinalSimulado($a), 'Simulação não marcou pago ou pagou duas vezes.');

// Cancelamento do cliente dentro do prazo: o sinal vira credito.
Agendamento::cancelar($a, null, 'teste', true);
verificar($statusSinal($a) === 'credito', 'Cancelar no prazo deveria guardar o sinal como crédito.');
verificar(Diferencial::creditoSinal($cliente) === 20.0, 'Crédito do cliente errado.');

// A reserva seguinte ja nasce com o sinal pago pelo credito.
$b = $reservar('09:00');
$sinalB = Diferencial::sinalDoAgendamento($b);
verificar($sinalB['status'] === 'pago' && str_starts_with($sinalB['referencia'], Diferencial::PREFIXO_CREDITO), 'O crédito não pagou o sinal da reserva nova.');
verificar(Diferencial::creditoSinal($cliente) === 0.0, 'O crédito foi usado e continuou disponível.');

// Segundo cancelamento seguido, mesmo no prazo: o sinal fica com a empresa.
Agendamento::cancelar($b, null, 'teste', true);
verificar($statusSinal($b) === 'retido', 'No segundo cancelamento o sinal deveria ficar com a empresa.');

// Fora do prazo: a reserva de agora + 10 min ja passou dos 30 min de antecedencia.
$agora = Agendamento::prazoSemPerda(['data_agendamento' => date('Y-m-d'), 'hora_inicio' => date('H:i:s', time() + 600)]);
verificar($agora < time(), 'O prazo de quem marcou para daqui a 10 min deveria ter passado.');
$c = $reservar('10:00');
Diferencial::pagarSinalSimulado($c);
// hora_fim muda junto: depois das 10h20 o inicio passaria do fim antigo (10:30) e a constraint recusaria.
bd()->exec("UPDATE agendamentos SET data_agendamento = CURRENT_DATE, hora_inicio = '" . date('H:i:s', time() + 600) . "', hora_fim = '" . date('H:i:s', time() + 600 + 1800) . "' WHERE id_agendamento = $c");
verificar(Agendamento::clientePodeCancelar(Agendamento::porId($c)), 'Até o horário começar o cliente ainda cancela.');
Agendamento::cancelar($c, null, 'teste', true);
verificar($statusSinal($c) === 'retido', 'Cancelar a menos de 30 min deveria reter o sinal.');

// Cancelamento pela empresa nunca tira o sinal do cliente.
$d = $reservar('11:00');
Diferencial::pagarSinalSimulado($d);
Agendamento::cancelar($d, (int) $admin['id_usuario'], 'empresa cancelou');
verificar($statusSinal($d) === 'credito', 'Cancelamento da empresa deveria devolver o sinal como crédito.');

// Reserva cancelada nao volta a valer nem vira concluida (a vaga pode ja ser de outro).
verificar(!Agendamento::alterarStatus($d, 'confirmado') && Agendamento::porId($d)['status'] === 'cancelado', 'Reserva cancelada foi reativada.');
verificar(!Agendamento::alterarStatus($d, 'concluido') && Agendamento::porId($d)['status'] === 'cancelado', 'Reserva cancelada foi concluída.');

// Cliente so marca nos inicios da grade (faixa 08:00, passo padrao); a equipe encaixa fora dela.
$foraDaGrade = Disponibilidade::validar(['id_profissional' => $profissional, 'id_servico' => $servico, 'data' => $obtido[2], 'hora_inicio' => '08:07']);
verificar($foraDaGrade !== [], 'O cliente marcou fora da grade de horários.');
verificar(Disponibilidade::validar(['id_profissional' => $profissional, 'id_servico' => $servico, 'data' => $obtido[2], 'hora_inicio' => '08:00']) === [], 'O início da grade deveria valer para o cliente.');
$encaixe = Disponibilidade::validar(['id_profissional' => $profissional, 'id_servico' => $servico, 'data' => $obtido[2], 'hora_inicio' => '08:07'], ['ignorar_antecedencia' => true]);
verificar($encaixe === [], 'A equipe deveria poder encaixar fora da grade: ' . json_encode($encaixe));

// Sinal pendente continua so cancelado.
$e = $reservar('13:00');
verificar($statusSinal($e) === 'pago', 'O crédito devolvido pela empresa deveria pagar a reserva seguinte.');
$f = $reservar('14:00');
Agendamento::cancelar($f, null, 'teste', true);
verificar($statusSinal($f) === 'cancelado', 'Sinal pendente deveria ficar só cancelado.');

// A garantia do esquema roda de novo sem efeito.
Diferencial::garantirStatusSinal();
verificar(true, '');

echo "OK: {$checagens} verificações do sinal e dos horários.\n";
