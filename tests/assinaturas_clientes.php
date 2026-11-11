<?php

/**
 * Teste de integração em banco temporário das assinaturas que o
 * estabelecimento vende aos seus clientes (AssinaturaCliente).
 * Execute com "php tests/assinaturas_clientes.php" (AGENDEI_DB_DRIVER=pgsql para o PostgreSQL).
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
        if (proc_close($processo) !== 0) throw new RuntimeException('Falha no teste das assinaturas de clientes.');
    } finally {
        $db->exec($ehPostgres ? "DROP DATABASE \"$nomeTeste\"" : "DROP DATABASE `$nomeTeste`");
    }
    exit;
}
if (!preg_match('/^agendei_test_[a-f0-9]{12}$/D', getenv('AGENDEI_DB_NAME') ?: '')) throw new RuntimeException('O teste exige banco isolado.');
define('TAREFA_AGENDADA', true);

$esquema = file_get_contents($ehPostgres ? 'banco_postgres.sql' : 'banco.sql');
bd()->exec(preg_replace('/^(CREATE DATABASE|USE)\b[^;]*;\r?\n/mi', '', $esquema));

$checagens = 0;
function verificar(bool $condicao, string $mensagem): void
{
    global $checagens;
    if (!$condicao) throw new RuntimeException($mensagem);
    $checagens++;
}

// 1. Estrutura: banco antigo, sem nada do módulo, ganha tudo e a migração é idempotente.
bd()->exec('ALTER TABLE agendamentos DROP COLUMN id_cliente_assinatura');
foreach (['cobrancas_assinatura_cliente', 'cliente_assinaturas', 'plano_cliente_servicos', 'planos_clientes'] as $tabela) {
    bd()->exec("DROP TABLE $tabela");
}
require_once 'models/Sql.php';
require_once 'models/AssinaturaCliente.php';
AssinaturaCliente::garantirEstrutura();
AssinaturaCliente::garantirEstrutura();
foreach (['planos_clientes', 'plano_cliente_servicos', 'cliente_assinaturas', 'cobrancas_assinatura_cliente'] as $tabela) {
    bd()->query("SELECT * FROM $tabela WHERE 1=0");
    verificar(true, "tabela $tabela");
}
bd()->query('SELECT id_cliente_assinatura FROM agendamentos WHERE 1=0');
verificar(true, 'coluna agendamentos.id_cliente_assinatura');

ob_start();
require 'config/config.php';
restore_exception_handler();
ob_end_clean();

/** Espera que $acao lance $classe (com $mensagem exata, se dada). */
function lanca(string $classe, callable $acao, string $descricao, ?string $mensagem = null): void
{
    try {
        $acao();
    } catch (Throwable $erro) {
        verificar($erro instanceof $classe, "$descricao: lançou " . get_class($erro) . ' (' . $erro->getMessage() . ')');
        if ($mensagem !== null) verificar($erro->getMessage() === $mensagem, "$descricao: mensagem '" . $erro->getMessage() . "'");
        return;
    }
    verificar(false, "$descricao: não lançou $classe");
}
function linha(string $sql, array $p = []): array
{
    $q = bd()->prepare($sql);
    $q->execute($p);
    return $q->fetch() ?: [];
}

// Duas empresas; o teste roda como a tarefa agendada e troca de empresa por assumirTarefa.
$idEmpresa = Estabelecimento::contratar(['estabelecimento' => 'Barbearia A', 'slug' => 'barbearia-a', 'nome' => 'Dono A', 'email' => 'dono-a@teste.local', 'senha' => 'Teste12345!', 'iniciar_demo' => true]);
$idOutra = Estabelecimento::contratar(['estabelecimento' => 'Barbearia B', 'slug' => 'barbearia-b', 'nome' => 'Dono B', 'email' => 'dono-b@teste.local', 'senha' => 'Teste12345!', 'iniciar_demo' => true]);
Contexto::assumirTarefa($idEmpresa);
Configuracao::definir('pix_chave', 'chave@empresa.local', '');
$corte = Servico::criar(['nome' => 'Corte', 'descricao' => '', 'preco' => 45, 'duracao_minutos' => 30]);
$barba = Servico::criar(['nome' => 'Barba', 'descricao' => '', 'preco' => 35, 'duracao_minutos' => 30]);
$escova = Servico::criar(['nome' => 'Escova', 'descricao' => '', 'preco' => 50, 'duracao_minutos' => 30]);
$cliente = Cliente::criar(['nome' => 'Maria', 'email' => 'maria@teste.local', 'senha' => 'Teste12345!', 'telefone' => '11988887777']);
$outroCliente = Cliente::criar(['nome' => 'João', 'email' => 'joao@teste.local', 'senha' => 'Teste12345!', 'telefone' => '11977776666']);

// 2. Fim do período: o dia que não existe no mês seguinte não pula para o outro mês.
foreach (['2026-01-15' => '2026-02-14', '2026-01-31' => '2026-02-28', '2028-01-31' => '2028-02-29', '2026-02-28' => '2026-03-27', '2026-12-31' => '2027-01-30'] as $inicio => $fim) {
    verificar(AssinaturaCliente::periodoFim($inicio) === $fim, "periodoFim($inicio) deu " . AssinaturaCliente::periodoFim($inicio));
}

// 3. Validação do plano
lanca(InvalidArgumentException::class, fn() => AssinaturaCliente::criarPlano(['nome' => 'X', 'descricao' => '', 'preco_mensal' => 10, 'desconto_percentual' => 101, 'servicos' => [$corte => 1]]), 'desconto acima de 100');
lanca(InvalidArgumentException::class, fn() => AssinaturaCliente::criarPlano(['nome' => 'X', 'descricao' => '', 'preco_mensal' => 10, 'desconto_percentual' => 0, 'servicos' => []]), 'plano sem serviços');
lanca(InvalidArgumentException::class, fn() => AssinaturaCliente::criarPlano(['nome' => '', 'descricao' => '', 'preco_mensal' => 10, 'desconto_percentual' => 0, 'servicos' => [$corte => 1]]), 'plano sem nome');
lanca(InvalidArgumentException::class, fn() => AssinaturaCliente::criarPlano(['nome' => 'X', 'descricao' => '', 'preco_mensal' => 0, 'desconto_percentual' => 0, 'servicos' => [$corte => 1]]), 'plano de graça');

$clube = AssinaturaCliente::criarPlano(['nome' => 'Clube', 'descricao' => 'Barba e cabelo', 'preco_mensal' => 99, 'desconto_percentual' => 15, 'servicos' => [$corte => 4, $barba => null]]);
$plano = AssinaturaCliente::planoPorId($clube);
verificar($plano !== null && count($plano['servicos']) === 2, 'plano com dois serviços');
$porServico = array_column($plano['servicos'], 'quantidade_mes', 'id_servico');
verificar((int) $porServico[$corte] === 4 && $porServico[$barba] === null, 'quantidades do plano');

// Serviço que está num plano não some do catálogo (a exclusão quebraria a FK do plano).
$hidratacao = Servico::criar(['nome' => 'Hidratação', 'descricao' => '', 'preco' => 60, 'duracao_minutos' => 30]);
verificar(Servico::podeExcluir($hidratacao), 'serviço novo pode ser excluído');
AssinaturaCliente::criarPlano(['nome' => 'Só hidratação', 'descricao' => '', 'preco_mensal' => 50, 'desconto_percentual' => 0, 'servicos' => [$hidratacao => 1]]);
verificar(!Servico::podeExcluir($hidratacao), 'serviço em plano não pode ser excluído');
AssinaturaCliente::alterarStatusPlano((int) linha("SELECT id_plano_cliente FROM planos_clientes WHERE nome = 'Só hidratação'")['id_plano_cliente'], 'inativo');

// 4. Assinar: pendente com a primeira cobrança; segunda assinatura recusada.
$idAss = AssinaturaCliente::assinar($cliente, $clube, '2026-01-09');
$ass = AssinaturaCliente::daCliente($cliente, '2026-01-09');
verificar($ass['situacao'] === 'pendente', 'nova assinatura pendente');
verificar($ass['cobranca_pendente'] !== null && (float) $ass['cobranca_pendente']['valor'] === 99.0, 'primeira cobrança de 99');
lanca(DomainException::class, fn() => AssinaturaCliente::assinar($cliente, $clube), 'segunda assinatura');

// 5. Confirmar pagamento: ativa por um mês; duplo clique não avança o período.
$cob = (int) $ass['cobranca_pendente']['id_cobranca'];
verificar(AssinaturaCliente::confirmarPagamento($cob, '2026-01-10') === true, 'primeira confirmação');
verificar(AssinaturaCliente::confirmarPagamento($cob, '2026-01-10') === false, 'confirmação repetida recusada');
$ass = AssinaturaCliente::daCliente($cliente, '2026-01-10');
verificar($ass['periodo_inicio'] === '2026-01-10' && $ass['periodo_fim'] === '2026-02-09', 'período ' . $ass['periodo_inicio'] . '..' . $ass['periodo_fim']);
verificar($ass['situacao'] === 'ativa' && $ass['cobranca_pendente'] === null, 'ativa sem cobrança pendente');
verificar(AssinaturaCliente::situacao($ass, '2026-02-10') === 'suspensa', 'suspensa depois do fim');
verificar(AssinaturaCliente::vigenteEm($ass, '2026-02-09') && !AssinaturaCliente::vigenteEm($ass, '2026-02-10'), 'vigência até o último dia');
verificar(!AssinaturaCliente::vigenteEm($ass, '2026-01-09'), 'sem vigência antes do início');

// 6. Renovação em dia: janela de 3 dias, idempotente, período emendado.
verificar(AssinaturaCliente::gerarRenovacoes('2026-02-05') === 0, 'fora da janela de renovação');
verificar(AssinaturaCliente::gerarRenovacoes('2026-02-06') === 1, 'renovação gerada na janela');
verificar(AssinaturaCliente::gerarRenovacoes('2026-02-07') === 0, 'renovação não duplica');
$ass = AssinaturaCliente::daCliente($cliente, '2026-02-08');
verificar(AssinaturaCliente::confirmarPagamento((int) $ass['cobranca_pendente']['id_cobranca'], '2026-02-08'), 'renovação paga');
$ass = AssinaturaCliente::daCliente($cliente, '2026-02-08');
// Pagar adiantado só estende o fim: o resto do mês já pago continua valendo.
verificar($ass['periodo_inicio'] === '2026-01-10' && $ass['periodo_fim'] === '2026-03-09', 'renovação emendada ' . $ass['periodo_inicio'] . '..' . $ass['periodo_fim']);
verificar(AssinaturaCliente::vigenteEm($ass, '2026-02-09'), 'pago adiantado: último dia do mês anterior continua coberto');
verificar(AssinaturaCliente::janela($ass, '2026-02-09') === ['2026-01-10', '2026-02-09'], 'janela do 1º mês');
verificar(AssinaturaCliente::janela($ass, '2026-02-10') === ['2026-02-10', '2026-03-09'], 'janela do 2º mês');
verificar(AssinaturaCliente::janela($ass, '2026-03-10') === null && AssinaturaCliente::janela($ass, '2026-01-09') === null, 'fora do período não tem janela');

// 7. Renovação depois de suspensa: começa no dia do pagamento.
verificar(AssinaturaCliente::gerarRenovacoes('2026-03-20') === 1, 'renovação de suspensa');
$ass = AssinaturaCliente::daCliente($cliente, '2026-03-20');
verificar($ass['situacao'] === 'suspensa', 'suspensa com cobrança pendente');
AssinaturaCliente::confirmarPagamento((int) $ass['cobranca_pendente']['id_cobranca'], '2026-03-20');
$ass = AssinaturaCliente::daCliente($cliente, '2026-03-20');
verificar($ass['periodo_inicio'] === '2026-03-20' && $ass['periodo_fim'] === '2026-04-19', 'reativada a partir do pagamento');

// 8. Cancelamento: só o dono; vale até o fim do período; depois encerra e libera nova assinatura.
AssinaturaCliente::gerarRenovacoes('2026-04-17');
verificar(AssinaturaCliente::cancelar($idAss, $outroCliente) === false, 'outro cliente não cancela');
verificar(AssinaturaCliente::daCliente($cliente, '2026-04-17')['situacao'] === 'ativa', 'assinatura intacta');
verificar(AssinaturaCliente::cancelar($idAss, $cliente) === true, 'dono cancela');
$ass = AssinaturaCliente::daCliente($cliente, '2026-04-18');
verificar($ass['situacao'] === 'cancelada' && AssinaturaCliente::vigenteEm($ass, '2026-04-19'), 'cancelada vale até o fim');
verificar($ass['cobranca_pendente'] === null, 'cobrança pendente cancelada junto');
verificar(AssinaturaCliente::situacao($ass, '2026-04-20') === 'encerrada', 'encerrada depois do fim');
verificar(AssinaturaCliente::gerarRenovacoes('2026-04-18') === 0, 'cancelada não renova');
lanca(DomainException::class, fn() => AssinaturaCliente::assinar($cliente, $clube, '2026-04-18'), 'nova assinatura antes de encerrar');
$idAss2 = AssinaturaCliente::assinar($cliente, $clube, '2026-04-20');
verificar($idAss2 !== $idAss && AssinaturaCliente::daCliente($cliente, '2026-04-20')['situacao'] === 'pendente', 'nova assinatura depois de encerrada');
verificar(AssinaturaCliente::cancelar($idAss2) === true && AssinaturaCliente::daCliente($cliente, '2026-04-20')['situacao'] === 'encerrada', 'pendente cancelada encerra');

// 9. Sem chave Pix a empresa não vende; plano inativo some da vitrine e não aceita assinatura.
Configuracao::definir('pix_chave', '', '');
lanca(DomainException::class, fn() => AssinaturaCliente::assinar($outroCliente, $clube), 'sem chave Pix', 'A empresa ainda não configurou a chave Pix para receber assinaturas.');
Configuracao::definir('pix_chave', 'chave@empresa.local', '');
AssinaturaCliente::alterarStatusPlano($clube, 'inativo');
verificar(AssinaturaCliente::planos(true) === [] && count(AssinaturaCliente::planos()) === 2, 'inativo fora da vitrine');
lanca(DomainException::class, fn() => AssinaturaCliente::assinar($outroCliente, $clube), 'plano inativo');
AssinaturaCliente::alterarStatusPlano($clube, 'ativo');
verificar(count(AssinaturaCliente::assinantes()) === 2, 'lista de assinantes da empresa');

// 10. Isolamento entre empresas
Contexto::assumirTarefa($idOutra);
verificar(AssinaturaCliente::planos() === [] && AssinaturaCliente::planoPorId($clube) === null, 'plano invisível para outra empresa');
verificar(AssinaturaCliente::assinantes() === [], 'assinantes invisíveis para outra empresa');
lanca(InvalidArgumentException::class, fn() => AssinaturaCliente::criarPlano(['nome' => 'Intruso', 'descricao' => '', 'preco_mensal' => 10, 'desconto_percentual' => 0, 'servicos' => [$corte => 1]]), 'serviço de outra empresa');
verificar(AssinaturaCliente::confirmarPagamento($cob) === false, 'cobrança de outra empresa');
Contexto::assumirTarefa($idEmpresa);

// 11. Benefício no agendamento: cota, ilimitado, desconto, cancelamento, sinal e pacote.
$profissional = Profissional::criar(['nome' => 'Carlos', 'email' => 'carlos@teste.local', 'senha' => 'Teste12345!', 'especialidade' => 'Barbeiro', 'servicos' => [$corte, $barba, $escova]]);
for ($dia = 0; $dia <= 6; $dia++) {
    Horario::criar(['id_profissional' => $profissional, 'dia_semana' => $dia, 'hora_inicio' => '08:00:00', 'hora_fim' => '18:00:00']);
}
$ana = Cliente::criar(['nome' => 'Ana', 'email' => 'ana@teste.local', 'senha' => 'Teste12345!', 'telefone' => '11966665555']);
$idAna = AssinaturaCliente::assinar($ana, $clube);
AssinaturaCliente::confirmarPagamento((int) AssinaturaCliente::daCliente($ana)['cobranca_pendente']['id_cobranca']);
$assAna = AssinaturaCliente::daCliente($ana);

$horarios = [];
foreach ([1, 2] as $dias) {
    for ($minuto = 8 * 60; $minuto < 18 * 60; $minuto += 30) {
        $horarios[] = [date('Y-m-d', strtotime("+$dias days")), sprintf('%02d:%02d', intdiv($minuto, 60), $minuto % 60)];
    }
}
/** Agenda o próximo horário livre e devolve a linha gravada. */
function agendar(int $idCliente, int $idServico): array
{
    global $horarios, $profissional;
    [$data, $hora] = array_shift($horarios);
    $r = Agendamento::criar(['id_cliente' => $idCliente, 'id_profissional' => $profissional, 'id_servico' => $idServico, 'data' => $data, 'hora_inicio' => $hora, 'observacao' => '']);
    verificar($r['sucesso'], 'agendamento recusado: ' . implode(' ', $r['erros']));
    return linha('SELECT * FROM agendamentos WHERE id_agendamento = ?', [$r['id_agendamento']]);
}
$cortes = [];
for ($i = 0; $i < 4; $i++) {
    $cortes[] = $a = agendar($ana, $corte);
    verificar((float) $a['valor'] === 0.0 && (int) $a['id_cliente_assinatura'] === $idAna, 'corte ' . ($i + 1) . ' coberto');
}
$a = agendar($ana, $corte);
verificar((float) $a['valor'] === 38.25 && $a['id_cliente_assinatura'] === null, '5º corte com desconto: ' . $a['valor']);
for ($i = 0; $i < 6; $i++) {
    verificar((float) agendar($ana, $barba)['valor'] === 0.0, 'barba ilimitada ' . ($i + 1));
}
$a = agendar($ana, $escova);
verificar((float) $a['valor'] === 42.5 && $a['id_cliente_assinatura'] === null, 'escova fora do plano com desconto: ' . $a['valor']);

Agendamento::cancelar((int) $cortes[0]['id_agendamento'], null, 'teste');
$a = agendar($ana, $corte);
verificar((float) $a['valor'] === 0.0 && (int) $a['id_cliente_assinatura'] === $idAna, 'cota devolvida pelo cancelamento');

// Renovação paga adiantada: a cota do mês corrente não zera e o mês seguinte tem cota própria.
$cobAna = AssinaturaCliente::gerarRenovacoes(date('Y-m-d', strtotime($assAna['periodo_fim'] . ' -1 day')));
AssinaturaCliente::confirmarPagamento((int) AssinaturaCliente::daCliente($ana)['cobranca_pendente']['id_cobranca']);
$assAna = AssinaturaCliente::daCliente($ana);
verificar($assAna['uso'][$corte] === 4, 'uso do mês corrente mantido depois de pagar adiantado: ' . json_encode($assAna['uso']));
$a = agendar($ana, $corte);
verificar((float) $a['valor'] === 38.25, 'cota do mês corrente continua esgotada depois de pagar adiantado');
$mesSeguinte = (new DateTimeImmutable(AssinaturaCliente::janela($assAna, date('Y-m-d'))[1]))->modify('+1 day')->format('Y-m-d');
bd()->beginTransaction();
$proximo = AssinaturaCliente::beneficio($ana, $corte, $mesSeguinte, 45.0);
bd()->rollBack();
verificar($proximo['valor'] === 0.0, 'mês seguinte já pago tem cota nova');
$depois = (new DateTimeImmutable($assAna['periodo_fim']))->modify('+1 day')->format('Y-m-d');
bd()->beginTransaction();
$fora = AssinaturaCliente::beneficio($ana, $corte, $depois, 45.0);
bd()->rollBack();
verificar($fora === ['id_cliente_assinatura' => null, 'valor' => 45.0], 'depois do período paga o preço cheio');

// Sinal e pacote não se somam ao coberto; o sinal sai sobre o valor com desconto.
Configuracao::definir('sinal_percentual', '50');
$a = agendar($ana, $barba);
verificar(linha('SELECT COUNT(*) n FROM pagamentos WHERE id_agendamento = ?', [$a['id_agendamento']])['n'] == 0, 'coberto não gera sinal');
$a = agendar($ana, $escova);
$sinal = linha('SELECT valor FROM pagamentos WHERE id_agendamento = ?', [$a['id_agendamento']]);
verificar(($sinal['valor'] ?? null) !== null && (float) $sinal['valor'] === 21.25, 'sinal sobre o valor com desconto: ' . ($sinal['valor'] ?? 'nenhum'));
Configuracao::definir('sinal_percentual', '0');

Diferencial::criarPacote(['id_servico' => $barba, 'nome' => '10 barbas', 'quantidade' => 10, 'validade_dias' => 90, 'preco' => 200]);
Diferencial::adquirirPacote($ana, (int) Diferencial::pacotes()[0]['id_pacote']);
$compra = Diferencial::pacotesDoCliente($ana)[0];
Diferencial::atualizarCompraPacote((int) $compra['id_cliente_pacote'], 'pago');
agendar($ana, $barba);
verificar((int) Diferencial::pacotesDoCliente($ana)[0]['creditos_restantes'] === 10, 'coberto não gasta crédito de pacote');

// Admin reduz a cota abaixo do que já foi usado: o próximo paga com desconto, os feitos ficam como estão.
AssinaturaCliente::atualizarPlano($clube, ['nome' => 'Clube', 'descricao' => '', 'preco_mensal' => 99, 'desconto_percentual' => 15, 'servicos' => [$corte => 1, $barba => null]]);
$a = agendar($ana, $corte);
verificar((float) $a['valor'] === 38.25, 'cota reduzida: próximo corte com desconto');
verificar((float) linha('SELECT valor FROM agendamentos WHERE id_agendamento = ?', [$cortes[1]['id_agendamento']])['valor'] === 0.0, 'corte já coberto continua coberto');

// Corrida pela última vaga da cota: a transação que já tirou a foto do banco
// precisa ver o agendamento que outra acabou de gravar (REPEATABLE READ no MySQL).
$umCorte = AssinaturaCliente::criarPlano(['nome' => 'Um corte', 'descricao' => '', 'preco_mensal' => 40, 'desconto_percentual' => 0, 'servicos' => [$corte => 1]]);
$lia = Cliente::criar(['nome' => 'Lia', 'email' => 'lia@teste.local', 'senha' => 'Teste12345!', 'telefone' => '11944443333']);
AssinaturaCliente::assinar($lia, $umCorte);
AssinaturaCliente::confirmarPagamento((int) AssinaturaCliente::daCliente($lia)['cobranca_pendente']['id_cobranca']);
[$dataCorrida, $horaCorrida] = array_shift($horarios);
bd()->beginTransaction();
bd()->query('SELECT COUNT(*) FROM servicos')->fetchColumn(); // abre a foto da transação
$filho = sprintf(
    'define("TAREFA_AGENDADA", true); chdir(%s); require "config/database.php"; ob_start(); require "config/config.php"; ob_end_clean();'
    . ' Contexto::assumirTarefa(%d); $r = Agendamento::criar(["id_cliente" => %d, "id_profissional" => %d, "id_servico" => %d, "data" => %s, "hora_inicio" => %s, "observacao" => ""]);'
    . ' exit($r["sucesso"] ? 0 : 1);',
    var_export(getcwd(), true), $idEmpresa, $lia, $profissional, $corte, var_export($dataCorrida, true), var_export($horaCorrida, true)
);
verificar(proc_close(proc_open([PHP_BINARY, '-r', $filho], [], $pipes)) === 0, 'agendamento concorrente gravou');
$corrida = AssinaturaCliente::beneficio($lia, $corte, $dataCorrida, 45.0);
bd()->rollBack();
verificar($corrida === ['id_cliente_assinatura' => null, 'valor' => 45.0], 'a última vaga não sai duas vezes: ' . json_encode($corrida));

// Conta encerrada (LGPD): a assinatura para de cobrar.
$liaAss = AssinaturaCliente::daCliente($lia);
AssinaturaCliente::gerarRenovacoes(date('Y-m-d', strtotime($liaAss['periodo_fim'] . ' -1 day')));
verificar(AssinaturaCliente::daCliente($lia)['cobranca_pendente'] !== null, 'Lia com mensalidade pendente');
Diferencial::anonimizarCliente($lia, (int) Cliente::porId($lia)['id_usuario']);
$liaAss = linha('SELECT * FROM cliente_assinaturas WHERE id_cliente = ?', [$lia]);
verificar($liaAss['status'] === 'cancelada', 'conta encerrada cancela a assinatura');
verificar((int) linha("SELECT COUNT(*) n FROM cobrancas_assinatura_cliente WHERE id_cliente_assinatura = ? AND status = 'pendente'", [$liaAss['id_cliente_assinatura']])['n'] === 0, 'conta encerrada cancela a mensalidade pendente');

// Duplo clique em "Assinar": o segundo pedido espera o primeiro e é recusado.
$rui = Cliente::criar(['nome' => 'Rui', 'email' => 'rui@teste.local', 'senha' => 'Teste12345!', 'telefone' => '11933332222']);
bd()->beginTransaction();
bd()->prepare('SELECT id_cliente FROM clientes WHERE id_estabelecimento = ? AND id_cliente = ? FOR UPDATE')->execute([$idEmpresa, $rui]);
bd()->prepare('INSERT INTO cliente_assinaturas (id_estabelecimento, id_cliente, id_plano_cliente) VALUES (?, ?, ?)')->execute([$idEmpresa, $rui, $umCorte]);
$filho = sprintf(
    'define("TAREFA_AGENDADA", true); chdir(%s); require "config/database.php"; ob_start(); require "config/config.php"; ob_end_clean();'
    . ' Contexto::assumirTarefa(%d); try { AssinaturaCliente::assinar(%d, %d); exit(0); } catch (DomainException $e) { exit(3); }',
    var_export(getcwd(), true), $idEmpresa, $rui, $umCorte
);
$segundo = proc_open([PHP_BINARY, '-r', $filho], [], $pipes);
usleep(1500000); // o segundo pedido chega enquanto o primeiro ainda não confirmou
bd()->commit();
verificar(proc_close($segundo) === 3, 'segundo pedido de assinatura recusado');
verificar((int) linha('SELECT COUNT(*) n FROM cliente_assinaturas WHERE id_cliente = ?', [$rui])['n'] === 1, 'uma assinatura só');

// Sem assinatura, nada muda.
verificar((float) agendar($outroCliente, $corte)['valor'] === 45.0, 'cliente sem assinatura paga o preço cheio');

// 12. Tarefa diária: renova em todas as empresas.
$fimProximo = date('Y-m-d', strtotime('+2 days'));
bd()->prepare('UPDATE cliente_assinaturas SET periodo_fim = ? WHERE id_cliente_assinatura = ?')->execute([$fimProximo, $idAna]);
Contexto::assumirTarefa($idOutra);
Configuracao::definir('pix_chave', 'chave@b.local', '');
$servicoB = Servico::criar(['nome' => 'Corte B', 'descricao' => '', 'preco' => 30, 'duracao_minutos' => 30]);
$clienteB = Cliente::criar(['nome' => 'Bia', 'email' => 'bia@teste.local', 'senha' => 'Teste12345!', 'telefone' => '11955554444']);
$planoB = AssinaturaCliente::criarPlano(['nome' => 'Clube B', 'descricao' => '', 'preco_mensal' => 50, 'desconto_percentual' => 0, 'servicos' => [$servicoB => 2]]);
$idBia = AssinaturaCliente::assinar($clienteB, $planoB);
AssinaturaCliente::confirmarPagamento((int) AssinaturaCliente::daCliente($clienteB)['cobranca_pendente']['id_cobranca']);
bd()->prepare('UPDATE cliente_assinaturas SET periodo_fim = ? WHERE id_cliente_assinatura = ?')->execute([$fimProximo, $idBia]);
$resumo = AssinaturaCliente::renovarTodas();
verificar(($resumo[$idEmpresa] ?? null) === 1 && ($resumo[$idOutra] ?? null) === 1, 'renovação nas duas empresas: ' . json_encode($resumo));
verificar(AssinaturaCliente::renovarTodas()[$idEmpresa] === 0, 'tarefa repetida não duplica');
$semTarefa = proc_open([PHP_BINARY, '-r', 'chdir(' . var_export(getcwd(), true) . '); require "config/database.php"; require "models/AssinaturaCliente.php"; try { AssinaturaCliente::renovarTodas(); exit(1); } catch (LogicException $e) { exit(0); }'], [], $pipes);
verificar(proc_close($semTarefa) === 0, 'renovarTodas exige a tarefa agendada');
Contexto::assumirTarefa($idEmpresa);

echo "Assinaturas de clientes: $checagens checagens OK.\n";
