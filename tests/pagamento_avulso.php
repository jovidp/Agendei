<?php

/**
 * Teste de integração em banco temporário: forma de pagamento da mensalidade
 * (Pix, boleto, cartão) e pagamento avulso online por atendimento.
 * Execute com "php tests/pagamento_avulso.php" (AGENDEI_DB_DRIVER=pgsql para o PostgreSQL).
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
    $db = bd();
    $db->exec($ehPostgres ? "CREATE DATABASE \"$nomeTeste\" ENCODING 'UTF8'" : "CREATE DATABASE `$nomeTeste` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        putenv('AGENDEI_DB_NAME=' . $nomeTeste);
        if (proc_close(proc_open([PHP_BINARY, __FILE__, '--worker'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes)) !== 0) throw new RuntimeException('Falha no teste do pagamento avulso.');
    } finally {
        $db->exec($ehPostgres ? "DROP DATABASE \"$nomeTeste\"" : "DROP DATABASE `$nomeTeste`");
    }
    exit;
}
if (!preg_match('/^agendei_test_[a-f0-9]{12}$/D', getenv('AGENDEI_DB_NAME') ?: '')) throw new RuntimeException('O teste exige banco isolado.');
define('TAREFA_AGENDADA', true);
bd()->exec(preg_replace('/^(CREATE DATABASE|USE)\b[^;]*;\r?\n/mi', '', file_get_contents($ehPostgres ? 'banco_postgres.sql' : 'banco.sql')));

// Banco antigo: sem as colunas novas e sem 'boleto' nos pagamentos.
if ($ehPostgres) {
    bd()->exec('ALTER TABLE pagamentos DROP CONSTRAINT ck_pagamento_metodo');
    bd()->exec("ALTER TABLE pagamentos ADD CONSTRAINT ck_pagamento_metodo CHECK (metodo IN ('pix','dinheiro','cartao','outro'))");
} else {
    bd()->exec("ALTER TABLE pagamentos MODIFY metodo ENUM('pix','dinheiro','cartao','outro') NOT NULL DEFAULT 'pix'");
}
foreach (['pagamentos' => ['cartao_final'], 'cobrancas_assinatura_cliente' => ['metodo', 'cartao_final']] as $tabela => $colunas) {
    foreach ($colunas as $coluna) bd()->exec("ALTER TABLE $tabela DROP COLUMN $coluna");
}
ob_start();
require 'config/config.php';
restore_exception_handler();
ob_end_clean();
Cobranca::garantirEstrutura();
Cobranca::garantirEstrutura();

$checagens = 0;
function verificar(bool $condicao, string $mensagem): void
{
    global $checagens;
    if (!$condicao) throw new RuntimeException($mensagem);
    $checagens++;
}
function lanca(callable $acao, string $descricao): void
{
    try { $acao(); } catch (InvalidArgumentException | DomainException $e) { verificar(true, $descricao); return; }
    verificar(false, "$descricao: não lançou");
}
function linha(string $sql, array $p = []): array
{
    $q = bd()->prepare($sql);
    $q->execute($p);
    return $q->fetch() ?: [];
}

$idEmpresa = Estabelecimento::contratar(['estabelecimento' => 'Barbearia P', 'slug' => 'barbearia-p', 'nome' => 'Dono', 'email' => 'dono@p.local', 'senha' => 'Teste12345!', 'iniciar_demo' => true]);
$idOutra = Estabelecimento::contratar(['estabelecimento' => 'Outra P', 'slug' => 'outra-p', 'nome' => 'Dono B', 'email' => 'dono-b@p.local', 'senha' => 'Teste12345!', 'iniciar_demo' => true]);
Contexto::assumirTarefa($idEmpresa);
Configuracao::definir('pix_chave', 'pix@p.local', '');
$corte = Servico::criar(['nome' => 'Corte', 'descricao' => '', 'preco' => 50, 'duracao_minutos' => 30]);
$barba = Servico::criar(['nome' => 'Barba', 'descricao' => '', 'preco' => 30, 'duracao_minutos' => 30]);
$prof = Profissional::criar(['nome' => 'Carlos', 'email' => 'carlos@p.local', 'senha' => 'Teste12345!', 'especialidade' => 'X', 'servicos' => [$corte, $barba]]);
for ($d = 0; $d <= 6; $d++) Horario::criar(['id_profissional' => $prof, 'dia_semana' => $d, 'hora_inicio' => '08:00:00', 'hora_fim' => '18:00:00']);
$ana = Cliente::criar(['nome' => 'Ana', 'email' => 'ana@p.local', 'senha' => 'Teste12345!', 'telefone' => '11911112222']);
$bia = Cliente::criar(['nome' => 'Bia', 'email' => 'bia@p.local', 'senha' => 'Teste12345!', 'telefone' => '11933334444']);
$hora = 8 * 60;
function agendar(int $cliente, int $servico): array
{
    global $prof, $hora;
    $r = Agendamento::criar(['id_cliente' => $cliente, 'id_profissional' => $prof, 'id_servico' => $servico, 'data' => date('Y-m-d', strtotime('+1 day')), 'hora_inicio' => sprintf('%02d:%02d', intdiv($hora, 60), $hora % 60), 'observacao' => '']);
    $hora += 30;
    verificar($r['sucesso'], 'agendamento: ' . implode(' ', $r['erros']));
    return Agendamento::porId($r['id_agendamento']);
}

// 1. Migração: 'boleto' aceito e colunas novas.
verificar(Cobranca::metodosDisponiveis() === Cobranca::METODOS, 'três formas com chave Pix');
Configuracao::definir('pix_chave', '', '');
verificar(array_keys(Cobranca::metodosDisponiveis()) === ['boleto', 'cartao'], 'sem chave, sem Pix');
Configuracao::definir('pix_chave', 'pix@p.local', '');
verificar(Cobranca::rotuloPagamento('cartao', '4242') === 'Cartão final 4242' && Cobranca::rotuloPagamento('boleto', null) === 'Boleto' && Cobranca::rotuloPagamento(null, null) === '-', 'rótulos');

// 2. Mensalidade: escolher a forma.
$plano = AssinaturaCliente::criarPlano(['nome' => 'Clube', 'descricao' => '', 'preco_mensal' => 90, 'desconto_percentual' => 10, 'servicos' => [$barba => 1]]);
AssinaturaCliente::assinar($ana, $plano);
$cob = AssinaturaCliente::daCliente($ana)['cobranca_pendente'];
verificar($cob['metodo'] === null, 'sem forma escolhida');
AssinaturaCliente::escolherMetodo((int) $cob['id_cobranca'], $ana, 'boleto', null);
verificar(AssinaturaCliente::daCliente($ana)['cobranca_pendente']['metodo'] === 'boleto', 'boleto escolhido');
AssinaturaCliente::escolherMetodo((int) $cob['id_cobranca'], $ana, 'cartao', '4242');
$cob = AssinaturaCliente::daCliente($ana)['cobranca_pendente'];
verificar($cob['metodo'] === 'cartao' && $cob['cartao_final'] === '4242', 'cartão escolhido');
lanca(fn() => AssinaturaCliente::escolherMetodo((int) $cob['id_cobranca'], $bia, 'pix', null), 'outro cliente não escolhe');
lanca(fn() => AssinaturaCliente::escolherMetodo((int) $cob['id_cobranca'], $ana, 'cartao', '42'), 'cartão sem 4 dígitos');
lanca(fn() => AssinaturaCliente::escolherMetodo((int) $cob['id_cobranca'], $ana, 'cheque', null), 'forma desconhecida');
Configuracao::definir('pix_chave', '', '');
lanca(fn() => AssinaturaCliente::escolherMetodo((int) $cob['id_cobranca'], $ana, 'pix', null), 'Pix sem chave');
Configuracao::definir('pix_chave', 'pix@p.local', '');
AssinaturaCliente::escolherMetodo((int) $cob['id_cobranca'], $ana, 'pix', null);
verificar(AssinaturaCliente::daCliente($ana)['cobranca_pendente']['cartao_final'] === null, 'trocar para Pix limpa o cartão');
AssinaturaCliente::confirmarPagamento((int) $cob['id_cobranca']);
lanca(fn() => AssinaturaCliente::escolherMetodo((int) $cob['id_cobranca'], $ana, 'pix', null), 'cobrança paga não muda');

// 3. Avulso: valor cheio, trocar forma, duplo clique.
$a1 = agendar($bia, $corte);
verificar(PagamentoAvulso::valorAPagar($a1) === 50.0 && PagamentoAvulso::pagavel($a1, $bia), 'avulso cheio pagável');
verificar(!PagamentoAvulso::pagavel($a1, $ana), 'outro cliente não paga');
$p = PagamentoAvulso::solicitar((int) $a1['id_agendamento'], $bia, 'boleto', null);
verificar($p['tipo'] === 'integral' && (float) $p['valor'] === 50.0 && $p['metodo'] === 'boleto' && $p['status'] === 'pendente' && $p['referencia'] === 'AV' . $a1['id_agendamento'], 'pagamento avulso criado');
PagamentoAvulso::solicitar((int) $a1['id_agendamento'], $bia, 'cartao', '1111');
PagamentoAvulso::solicitar((int) $a1['id_agendamento'], $bia, 'cartao', '1111');
$n = linha("SELECT COUNT(*) n FROM pagamentos WHERE id_agendamento = ? AND tipo = 'integral'", [$a1['id_agendamento']])['n'];
verificar((int) $n === 1 && PagamentoAvulso::pendente((int) $a1['id_agendamento'])['cartao_final'] === '1111', 'trocar forma não duplica');
lanca(fn() => PagamentoAvulso::solicitar((int) $a1['id_agendamento'], $ana, 'pix', null), 'outro cliente não solicita');
lanca(fn() => PagamentoAvulso::solicitar((int) $a1['id_agendamento'], $bia, 'cartao', 'abcd'), 'cartão inválido no avulso');
Diferencial::atualizarPagamento((int) $p['id_pagamento'], 'pago');
verificar(!PagamentoAvulso::pagavel(Agendamento::porId($a1['id_agendamento']), $bia), 'pago não é pagável');
lanca(fn() => PagamentoAvulso::solicitar((int) $a1['id_agendamento'], $bia, 'pix', null), 'pago não aceita nova escolha');
$lista = array_values(array_filter(Diferencial::pagamentos(), fn($x) => (int) $x['id_agendamento'] === (int) $a1['id_agendamento']));
verificar($lista[0]['tipo'] === 'integral' && $lista[0]['metodo'] === 'cartao' && $lista[0]['status_agendamento'] === 'agendado', 'admin vê tipo, forma e situação');

// 4. Com sinal: pago desconta; pendente é cancelado.
Configuracao::definir('sinal_percentual', '20');
$a2 = agendar($bia, $corte);
$sinal = Diferencial::sinalDoAgendamento((int) $a2['id_agendamento']);
verificar($sinal !== null && (float) $sinal['valor'] === 10.0, 'sinal de 10');
PagamentoAvulso::solicitar((int) $a2['id_agendamento'], $bia, 'pix', null);
verificar(Diferencial::sinalDoAgendamento((int) $a2['id_agendamento'])['status'] === 'cancelado', 'pagar o total cancela o sinal pendente');
verificar((float) PagamentoAvulso::pendente((int) $a2['id_agendamento'])['valor'] === 50.0, 'total sem sinal pago');
$a3 = agendar($bia, $corte);
Diferencial::pagarSinalSimulado((int) $a3['id_agendamento']);
verificar(PagamentoAvulso::valorAPagar(Agendamento::porId($a3['id_agendamento'])) === 40.0, 'sinal pago desconta');
verificar((float) PagamentoAvulso::solicitar((int) $a3['id_agendamento'], $bia, 'pix', null)['valor'] === 40.0, 'cobra só a diferença');
Configuracao::definir('sinal_percentual', '0');

// 5. Coberto pela assinatura e cancelado não são pagáveis; cancelar cancela o pendente.
$a4 = agendar($ana, $barba);
verificar((float) $a4['valor'] === 0.0 && !PagamentoAvulso::pagavel($a4, $ana), 'coberto pela assinatura não é pagável');
$a5 = agendar($ana, $corte);
verificar(PagamentoAvulso::valorAPagar($a5) === 45.0, 'assinante paga avulso com desconto');
PagamentoAvulso::solicitar((int) $a5['id_agendamento'], $ana, 'boleto', null);
Agendamento::cancelar((int) $a5['id_agendamento'], null, 'teste', true);
verificar(PagamentoAvulso::pendente((int) $a5['id_agendamento'])['status'] === 'cancelado', 'cancelar cancela o avulso pendente');
verificar(!PagamentoAvulso::pagavel(Agendamento::porId($a5['id_agendamento']), $ana), 'cancelado não é pagável');
Agendamento::cancelar((int) $a1['id_agendamento'], null, 'teste', true);
verificar(PagamentoAvulso::pendente((int) $a1['id_agendamento'])['status'] === 'pago', 'avulso pago continua pago no cancelamento');

// 6. Isolamento
Contexto::assumirTarefa($idOutra);
verificar(PagamentoAvulso::pendente((int) $a3['id_agendamento']) === null, 'pagamento de outra empresa invisível');
lanca(fn() => PagamentoAvulso::solicitar((int) $a3['id_agendamento'], $bia, 'boleto', null), 'agendamento de outra empresa');
Contexto::assumirTarefa($idEmpresa);

echo "OK: $checagens verificações do pagamento avulso.\n";
