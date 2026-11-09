<?php

/**
 * Teste de integração em banco temporário: planos com preço e benefícios,
 * bloqueio por plano e o bot de WhatsApp (conversa e webhook pela HTTP).
 * Execute com "php tests/planos_bot.php" (AGENDEI_DB_DRIVER=pgsql para o PostgreSQL).
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
        if (proc_close($processo) !== 0) throw new RuntimeException('Falha no teste dos planos e do bot.');
    } finally {
        $db->exec($ehPostgres ? "DROP DATABASE \"$nomeTeste\"" : "DROP DATABASE `$nomeTeste`");
    }
    exit;
}
if (!preg_match('/^agendei_test_[a-f0-9]{12}$/D', getenv('AGENDEI_DB_NAME') ?: '')) throw new RuntimeException('O teste exige banco isolado.');

$esquema = file_get_contents($ehPostgres ? 'banco_postgres.sql' : 'banco.sql');
bd()->exec(preg_replace('/^(CREATE DATABASE|USE)\b[^;]*;\r?\n/mi', '', $esquema));
// Banco como o de produção antes desta versão: planos sem preço nem benefícios.
bd()->exec('ALTER TABLE planos DROP COLUMN preco_mensal');
bd()->exec('ALTER TABLE planos DROP COLUMN recursos');

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

// ---------------------------------------------------------------------
// 1. Estrutura e planos padrão
// ---------------------------------------------------------------------
Plano::garantirEstrutura();
Plano::garantirEstrutura(); // de novo: não duplica coluna nem plano
$planos = Plano::contrataveis();
verificar(array_column($planos, 'nome') === ['Básico', 'Profissional', 'Premium'], 'Planos padrão errados: ' . json_encode(array_column($planos, 'nome')));
verificar((float) $planos[0]['preco_mensal'] === 79.0 && (float) $planos[2]['preco_mensal'] === 249.0, 'Preços padrão errados.');
verificar(Plano::menorPreco() === 79.0, 'O menor preço deveria ser o do Básico.');
verificar(Plano::recursosDe($planos[1]) === ['lembretes_whatsapp', 'sinal_pix', 'diferenciais'], 'Benefícios do Profissional errados.');
verificar(Plano::menorCom('bot_whatsapp')['nome'] === 'Premium', 'O bot deveria começar no Premium.');
[$basico, $profissional, $premium] = $planos;

// Editar preço e benefícios pelo formato do formulário.
Plano::atualizar((int) $basico['id_plano'], ['preco_mensal' => '89,90', 'recursos' => ['diferenciais', 'inexistente']] + $basico);
$editado = Plano::porId((int) $basico['id_plano']);
verificar((float) $editado['preco_mensal'] === 89.9, 'Preço "89,90" não foi entendido.');
verificar(Plano::recursosDe($editado) === ['diferenciais'], 'Benefício inválido não deveria ser gravado.');
Plano::atualizar((int) $basico['id_plano'], ['preco_mensal' => 79, 'recursos' => []] + $basico);

// ---------------------------------------------------------------------
// 2. Benefícios e preço por empresa
// ---------------------------------------------------------------------
Estabelecimento::contratar(['estabelecimento' => 'BOT', 'slug' => 'bot', 'nome' => 'Responsável Teste', 'email' => 'admin@bot.local', 'senha' => 'Teste12345!']);
$_SESSION = [];
$_GET = ['estabelecimento' => 'bot'];
Contexto::iniciar();
$idEmpresa = Contexto::id();

verificar(Plano::temRecurso('diferenciais') && !Plano::temRecurso('bot_whatsapp'), 'Sem plano: recursos antigos sim, bot não.');
verificar(Plano::precoDe($idEmpresa) === Assinatura::VALOR_MENSAL, 'Sem plano o preço deveria ser o padrão.');
Plano::atribuir($idEmpresa, (int) $basico['id_plano']);
verificar(!Plano::temRecurso('sinal_pix') && !Plano::temRecurso('diferenciais'), 'O Básico não deveria ter sinal nem diferenciais.');
verificar(Plano::precoDe($idEmpresa) === 79.0, 'A empresa no Básico deveria pagar 79.');

$servico = Servico::criar(['nome' => 'Corte', 'descricao' => 'Teste', 'preco' => 100, 'duracao_minutos' => 30, 'destaque' => 1]);
$profissional = Profissional::criar(['nome' => 'Marcos Barbeiro', 'email' => 'marcos@bot.local', 'senha' => 'Teste12345!', 'especialidade' => 'X', 'servicos' => [$servico]]);
for ($dia = 0; $dia <= 6; $dia++) {
    Horario::criar(['id_profissional' => $profissional, 'dia_semana' => $dia, 'hora_inicio' => '08:00:00', 'hora_fim' => '20:00:00']);
}
$cliente = Cliente::criar(['nome' => 'Ana Cliente Bot', 'email' => 'ana@bot.local', 'senha' => 'Teste12345!', 'telefone' => '11987654321', 'cpf' => '52998224725']);
Configuracao::definir('pix_chave', 'pix@bot.local');
Configuracao::definir('sinal_percentual', '20');

$amanha = date('Y-m-d', strtotime('+1 day'));
$semSinal = Agendamento::criar(['id_cliente' => $cliente, 'id_profissional' => $profissional, 'id_servico' => $servico, 'data' => $amanha, 'hora_inicio' => '08:00', 'observacao' => '', 'origem' => 'cliente']);
verificar($semSinal['sucesso'] && Diferencial::sinalDoAgendamento((int) $semSinal['id_agendamento']) === null, 'No Básico o agendamento não deveria gerar sinal.');

Plano::atribuir($idEmpresa, (int) $premium['id_plano']);
verificar(Plano::temRecurso('bot_whatsapp') && Plano::temRecurso('sinal_pix'), 'O Premium deveria ter bot e sinal.');

// ---------------------------------------------------------------------
// 3. Conversa com o bot
// ---------------------------------------------------------------------
verificar(!Bot::ligado(), 'O bot não deveria ligar sem Evolution configurada.');
Configuracao::definir('whatsapp_provedor', 'evolution');
Configuracao::definir('whatsapp_url', 'http://127.0.0.1:1');
Configuracao::definir('whatsapp_instancia', 'agendei');
Configuracao::definir('whatsapp_token', 'token-teste');
Configuracao::definir('whatsapp_bot_ativo', '1');
verificar(Bot::ligado(), 'Com plano, chave e Evolution o bot deveria ligar.');

$numero = '5511987654321';
$fala = fn(string $texto, string $id = '') => (string) Bot::atender($numero, $texto, $id);

verificar(str_contains((string) Bot::atender('5521911112222', 'oi'), 'Não encontrei um cadastro'), 'Número desconhecido deveria receber o link de cadastro.');
verificar(Bot::clientePorNumero('551187654321') !== null, 'Número sem o nono dígito deveria achar a cliente.');
verificar(Bot::clientePorNumero('5521987654321') === null, 'Mesmo final com outro DDD não é a mesma pessoa.');

verificar(str_contains($fala('oi'), 'Olá, Ana!'), 'O menu deveria cumprimentar a cliente pelo nome.');
verificar(str_contains($fala('1'), '1. Corte'), 'Marcar deveria listar os serviços.');
verificar(str_contains($fala('1'), 'Qual dia?'), 'Com um só profissional, o bot deveria pular para o dia.');
$horarios = $fala('2');
verificar(str_contains($horarios, 'Horários livres'), 'Escolher o dia deveria listar os horários: ' . $horarios);
$confirma = $fala('1');
verificar(str_contains($confirma, 'Confira') && str_contains($confirma, 'Sinal: R$ 20,00'), 'A confirmação deveria mostrar o resumo e o sinal: ' . $confirma);
$pronto = $fala('1');
verificar(str_contains($pronto, 'Pronto, horário marcado!') && str_contains($pronto, 'pix@bot.local'), 'A confirmação deveria marcar e passar o Pix: ' . $pronto);
$marcados = Agendamento::listar(['id_cliente' => $cliente, 'status_em' => ['agendado'], 'ordem' => 'asc']);
verificar(count($marcados) === 2 && in_array('Marcado pelo WhatsApp', array_column($marcados, 'observacao'), true), 'O agendamento do bot não foi gravado.');

verificar(str_contains($fala('xyz'), 'Não entendi'), 'Resposta fora do menu deveria ser explicada.');
verificar(str_contains($fala('2'), 'Seus próximos horários'), '"Meus agendamentos" deveria listar os horários.');
$fala('menu');
verificar(str_contains($fala('3'), 'Qual horário você quer cancelar?'), 'Cancelar deveria listar os horários.');
verificar(str_contains($fala('1'), 'Cancelar Corte'), 'Escolher deveria pedir confirmação.');
verificar(str_contains($fala('1'), 'Horário cancelado.'), 'Confirmar deveria cancelar.');
verificar(count(Agendamento::listar(['id_cliente' => $cliente, 'status_em' => ['agendado']])) === 1, 'O cancelamento pelo bot não foi gravado.');

$fala('menu');
verificar(str_contains($fala('5'), 'Avisei a equipe'), 'Falar com a equipe deveria passar para o humano.');
verificar(Bot::atender($numero, 'tudo bem?') === null, 'Com atendimento humano o bot deveria ficar calado.');
verificar(str_contains($fala('menu'), 'Olá, Ana!'), '"menu" deveria devolver o bot.');
verificar(Bot::atender($numero, 'oi', 'MSG-1') !== null && Bot::atender($numero, 'oi', 'MSG-1') === null, 'Mensagem repetida (mesmo id) não deveria ter duas respostas.');

// ---------------------------------------------------------------------
// 4. Webhook de verdade: servidor embutido + Evolution falsa
// ---------------------------------------------------------------------
$sobe = function (array $comando) {
    for ($sorteio = 0; $sorteio < 5; $sorteio++) {
        $porta = random_int(20000, 40000);
        $proc = proc_open(array_merge([PHP_BINARY, '-S', '127.0.0.1:' . $porta], $comando), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos, getcwd());
        for ($i = 0; $i < 50; $i++) {
            $sonda = @stream_socket_client('tcp://127.0.0.1:' . $porta, $c, $e, 0.2);
            if ($sonda !== false) { fclose($sonda); return [$proc, $porta]; }
            usleep(100000);
        }
        proc_terminate($proc);
    }
    throw new RuntimeException('Servidor embutido não subiu.');
};
$captura = sys_get_temp_dir() . '/agendei_api_falsa.json';
@unlink($captura);
[$evolution, $portaEvolution] = $sobe([__DIR__ . '/api_falsa.php']);
[$app, $portaApp] = $sobe(['-t', getcwd()]);

try {
    Configuracao::definir('whatsapp_url', 'http://127.0.0.1:' . $portaEvolution);
    $chave = Bot::chave();
    $post = function (string $chaveUsada, array $corpo) use ($portaApp) {
        $resposta = @file_get_contents('http://127.0.0.1:' . $portaApp . '/api/whatsapp.php?estabelecimento=bot&chave=' . $chaveUsada, false, stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode($corpo), 'ignore_errors' => true, 'timeout' => 20,
        ]]));
        return [(int) explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1], json_decode((string) $resposta, true)];
    };
    $evento = fn(string $texto, bool $deMim = false, string $id = 'W1') => [
        'event' => 'messages.upsert', 'instance' => 'agendei',
        'data' => ['key' => ['remoteJid' => $numero . '@s.whatsapp.net', 'fromMe' => $deMim, 'id' => $id], 'pushName' => 'Ana', 'message' => ['conversation' => $texto]],
    ];

    [$codigo] = $post('chave-errada', $evento('menu'));
    verificar($codigo === 404, 'Chave errada deveria dar 404, deu ' . $codigo);

    [$codigo, $corpo] = $post($chave, $evento('menu', false, 'W2'));
    verificar($codigo === 200 && ($corpo['respondidas'] ?? 0) === 1, 'O webhook deveria responder a mensagem: ' . json_encode([$codigo, $corpo]));
    $enviado = json_decode((string) @file_get_contents($captura), true);
    verificar(($enviado['caminho'] ?? '') === '/message/sendText/agendei' && ($enviado['corpo']['number'] ?? '') === $numero, 'A resposta não saiu pela Evolution: ' . json_encode($enviado));
    verificar(str_contains((string) ($enviado['corpo']['text'] ?? ''), 'Olá, Ana!'), 'O texto enviado deveria ser o menu.');

    [, $corpo] = $post($chave, $evento('menu', true, 'W3'));
    verificar(($corpo['respondidas'] ?? -1) === 0, 'Mensagem enviada pela própria empresa não deveria ter resposta.');

    $grupo = $evento('menu', false, 'W4');
    $grupo['data']['key']['remoteJid'] = '120363000000@g.us';
    [, $corpo] = $post($chave, $grupo);
    verificar(($corpo['respondidas'] ?? -1) === 0, 'O bot não deveria responder em grupo.');

    Configuracao::definir('whatsapp_bot_ativo', '0');
    [, $corpo] = $post($chave, $evento('menu', false, 'W5'));
    verificar(isset($corpo['ignorado']), 'Bot desligado deveria ignorar o evento.');
} finally {
    foreach ([$app, $evolution] as $proc) { proc_terminate($proc); proc_close($proc); }
}

echo "OK: {$checagens} verificações dos planos e do bot.\n";
