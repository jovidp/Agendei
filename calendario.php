<?php

/**
 * Feed iCalendar privado do profissional. O token aleatório funciona como chave
 * de acesso e permite assinar a agenda no Google Calendar e outros aplicativos.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/models/Sql.php';

$token = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
    http_response_code(404);
    exit('Calendário não encontrado.');
}

$q = bd()->prepare(
    'SELECT p.id_profissional,p.id_estabelecimento,u.nome,e.nome estabelecimento
     FROM profissionais p JOIN usuarios u ON u.id_usuario=p.id_usuario
     JOIN estabelecimento e ON e.id_estabelecimento=p.id_estabelecimento
     WHERE p.token_calendario=? AND u.status=\'ativo\' AND e.status=\'ativo\' LIMIT 1'
);
$q->execute([$token]);
$profissional = $q->fetch(PDO::FETCH_ASSOC);
if (!$profissional) {
    http_response_code(404);
    exit('Calendário não encontrado.');
}

$q = bd()->prepare(
    'SELECT a.*,s.nome servico,uc.nome cliente
     FROM agendamentos a JOIN servicos s ON s.id_servico=a.id_servico
     JOIN clientes c ON c.id_cliente=a.id_cliente JOIN usuarios uc ON uc.id_usuario=c.id_usuario
     WHERE a.id_estabelecimento=? AND a.id_profissional=?
       AND a.status IN (\'agendado\',\'confirmado\',\'concluido\')
       AND a.data_agendamento>=' . Sql::somarDias('CURRENT_DATE', '-30') . '
     ORDER BY a.data_agendamento,a.hora_inicio'
);
$q->execute([$profissional['id_estabelecimento'], $profissional['id_profissional']]);

$escapar = static fn(string $valor): string => str_replace(
    ["\\", ";", ",", "\r\n", "\r", "\n"],
    ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"],
    $valor
);
$linhas = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//Agendei//Agenda Profissional//PT-BR',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'X-WR-CALNAME:' . $escapar('Agendei - ' . $profissional['estabelecimento']),
    'X-WR-TIMEZONE:America/Sao_Paulo',
];
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
    $linhas[] = 'BEGIN:VEVENT';
    $linhas[] = 'UID:agendamento-' . $a['id_estabelecimento'] . '-' . $a['id_agendamento'] . '@agendei';
    $linhas[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z', strtotime($a['data_criacao']));
    $linhas[] = 'DTSTART;TZID=America/Sao_Paulo:' . date('Ymd\THis', strtotime($a['data_agendamento'] . ' ' . $a['hora_inicio']));
    $linhas[] = 'DTEND;TZID=America/Sao_Paulo:' . date('Ymd\THis', strtotime($a['data_agendamento'] . ' ' . $a['hora_fim']));
    $linhas[] = 'SUMMARY:' . $escapar($a['servico'] . ' - ' . $a['cliente']);
    $linhas[] = 'DESCRIPTION:' . $escapar('Status: ' . $a['status'] . ($a['observacao'] ? "\n" . $a['observacao'] : ''));
    $linhas[] = 'END:VEVENT';
}
$linhas[] = 'END:VCALENDAR';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="agenda.ics"');
echo implode("\r\n", $linhas) . "\r\n";
