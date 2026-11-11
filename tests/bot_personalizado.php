<?php

/**
 * Teste de integração em banco temporário do bot personalizável: textos,
 * opções embutidas (ligar, renomear, ordem) e opções próprias (BotMenu + Bot).
 * Execute com "php tests/bot_personalizado.php" (AGENDEI_DB_DRIVER=pgsql para o PostgreSQL).
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
        if (proc_close($processo) !== 0) throw new RuntimeException('Falha no teste do bot personalizável.');
    } finally {
        $db->exec($ehPostgres ? "DROP DATABASE \"$nomeTeste\"" : "DROP DATABASE `$nomeTeste`");
    }
    exit;
}
if (!preg_match('/^agendei_test_[a-f0-9]{12}$/D', getenv('AGENDEI_DB_NAME') ?: '')) throw new RuntimeException('O teste exige banco isolado.');
define('TAREFA_AGENDADA', true);

$esquema = file_get_contents($ehPostgres ? 'banco_postgres.sql' : 'banco.sql');
bd()->exec(preg_replace('/^(CREATE DATABASE|USE)\b[^;]*;\r?\n/mi', '', $esquema));
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
function lanca(string $classe, callable $acao, string $descricao): void
{
    try {
        $acao();
    } catch (Throwable $erro) {
        verificar($erro instanceof $classe, "$descricao: lançou " . get_class($erro) . ' (' . $erro->getMessage() . ')');
        return;
    }
    verificar(false, "$descricao: não lançou $classe");
}
function igual(string $obtido, string $esperado, string $descricao): void
{
    verificar($obtido === $esperado, "$descricao\n--- esperado:\n$esperado\n--- obtido:\n$obtido");
}

// Banco antigo, sem a tabela nova: a estrutura do bot a cria.
bd()->exec('DROP TABLE bot_opcoes');
bd()->exec($ehPostgres ? 'ALTER TABLE configuracoes ALTER COLUMN valor TYPE VARCHAR(255)' : 'ALTER TABLE configuracoes MODIFY valor VARCHAR(255) NOT NULL');
foreach (Bot::ddl() as $comando) bd()->exec($comando);
foreach (Bot::ddl() as $comando) bd()->exec($comando);
if ($ehPostgres) {
    verificar((bool) bd()->query("SELECT rowsecurity FROM pg_tables WHERE tablename = 'bot_opcoes'")->fetchColumn(), 'bot_opcoes com RLS ligado');
}

Plano::garantirEstrutura();
$premium = Plano::porNome('Premium');
$idEmpresa = Estabelecimento::contratar(['estabelecimento' => 'Barbearia Bot', 'slug' => 'barbearia-bot', 'nome' => 'Dono', 'email' => 'dono@bot.local', 'senha' => 'Teste12345!']);
$idOutra = Estabelecimento::contratar(['estabelecimento' => 'Outra', 'slug' => 'outra', 'nome' => 'Dono B', 'email' => 'dono-b@bot.local', 'senha' => 'Teste12345!']);
Contexto::assumirTarefa($idEmpresa);
Plano::atribuir($idEmpresa, (int) $premium['id_plano']);
$servico = Servico::criar(['nome' => 'Corte', 'descricao' => '', 'preco' => 50, 'duracao_minutos' => 30]);
$profissional = Profissional::criar(['nome' => 'Marcos', 'email' => 'marcos@bot.local', 'senha' => 'Teste12345!', 'especialidade' => 'X', 'servicos' => [$servico]]);
$cliente = Cliente::criar(['nome' => 'Ana Souza', 'email' => 'ana@bot.local', 'senha' => 'Teste12345!', 'telefone' => '11987654321']);
$numero = '5511987654321';
$estranho = '5521911112222';
$link = urlAbsoluta('cadastro.php');

// ---------------------------------------------------------------------
// 1. Padrão: idêntico ao bot de antes da personalização.
// ---------------------------------------------------------------------
$menuCliente = "Olá, Ana! Sou o assistente virtual de *Barbearia Bot*. Responda com o número:\n\n"
    . "1. Marcar horário\n2. Meus agendamentos\n3. Cancelar um agendamento\n4. Serviços e preços\n5. Falar com a equipe\n\n"
    . 'A qualquer momento, mande *menu* para voltar aqui.';
$menuEstranho = "Olá! Sou o assistente virtual de *Barbearia Bot*.\n\n"
    . "Não encontrei um cadastro com este número. Para marcar horário por aqui, crie sua conta usando este mesmo celular:\n"
    . $link . "\n\nEnquanto isso, responda com o número:\n4. Serviços e preços\n5. Falar com a equipe";
$vars = ['nome' => 'Ana', 'empresa' => 'Barbearia Bot', 'link_cadastro' => $link];
igual(BotMenu::menu(true, $vars), $menuCliente, 'menu padrão do cliente');
igual(BotMenu::menu(false, $vars), $menuEstranho, 'menu padrão sem cadastro');
igual((string) Bot::atender($numero, 'oi'), $menuCliente, 'bot: menu padrão');
igual((string) Bot::atender($estranho, 'oi'), $menuEstranho, 'bot: menu sem cadastro');
verificar(count(BotMenu::opcoes()) === 5, 'padrão criado uma vez só');
BotMenu::garantirPadrao();
verificar(count(BotMenu::opcoes()) === 5, 'padrão não duplica');

$porTipo = fn(string $tipo) => (int) array_values(array_filter(BotMenu::opcoes(), fn($o) => $o['tipo'] === $tipo))[0]['id_bot_opcao'];

// ---------------------------------------------------------------------
// 2. Textos personalizados com variáveis; vazio volta ao padrão.
// ---------------------------------------------------------------------
BotMenu::definirTexto('boas_vindas', "Oi, {nome}! Bem-vindo à *{empresa}* 💈\nEscolha {desconhecida}:");
verificar(str_starts_with((string) Bot::atender($numero, 'menu'), "Oi, Ana! Bem-vindo à *Barbearia Bot* 💈\nEscolha {desconhecida}:\n\n1. Marcar horário"), 'boas-vindas personalizada');
BotMenu::definirTexto('boas_vindas', '');
igual((string) Bot::atender($numero, 'menu'), $menuCliente, 'texto vazio volta ao padrão');
BotMenu::definirTexto('sem_cadastro', 'Crie sua conta: {link_cadastro}');
igual(BotMenu::menu(false, $vars), "Crie sua conta: $link\n4. Serviços e preços\n5. Falar com a equipe", 'sem cadastro personalizado');
BotMenu::definirTexto('sem_cadastro', '');
BotMenu::definirTexto('equipe', 'Já chamei alguém da equipe, {nome}.');
Bot::atender($numero, 'menu');
igual((string) Bot::atender($numero, '5'), 'Já chamei alguém da equipe, Ana.', 'texto da equipe');
Bot::atender($numero, 'menu');
BotMenu::definirTexto('equipe', '');
BotMenu::definirTexto('nao_entendi', 'Opa, não entendi.');
verificar(str_starts_with((string) Bot::atender($numero, '9'), "Opa, não entendi.\n\nOlá, Ana!"), 'texto de não entendi');
BotMenu::definirTexto('nao_entendi', '');
// Texto grande (acima dos 255 de configuracoes.valor antigo) grava inteiro.
$longo = BotMenu::TEXTOS['sem_cadastro'] . "\n\n" . str_repeat('Atendemos de segunda a sábado. ', 25);
BotMenu::definirTexto('sem_cadastro', $longo);
verificar(BotMenu::texto('sem_cadastro', []) === trim($longo) && mb_strlen($longo) > 900, 'texto longo gravado inteiro: ' . mb_strlen(BotMenu::texto('sem_cadastro', [])));
BotMenu::definirTexto('sem_cadastro', '');
lanca(InvalidArgumentException::class, fn() => BotMenu::definirTexto('inexistente', 'x'), 'chave de texto desconhecida');
lanca(InvalidArgumentException::class, fn() => BotMenu::definirTexto('equipe', str_repeat('a', 1001)), 'texto longo demais');

// ---------------------------------------------------------------------
// 3. Ligar/desligar, renomear e ordem renumeram o menu e a escolha.
// ---------------------------------------------------------------------
BotMenu::alternar($porTipo('cancelar'));
$menu = (string) Bot::atender($numero, 'menu');
verificar(str_contains($menu, "2. Meus agendamentos\n3. Serviços e preços\n4. Falar com a equipe") && !str_contains($menu, 'Cancelar'), 'cancelar desligado renumera: ' . $menu);
verificar(str_starts_with((string) Bot::atender($numero, '3'), 'Nossos serviços:'), 'escolha 3 vira serviços');
BotMenu::alternar($porTipo('cancelar'));

BotMenu::atualizar($porTipo('servicos'), 'Tabela de preços', null);
BotMenu::mover($porTipo('servicos'), -1);
BotMenu::mover($porTipo('servicos'), -1);
BotMenu::mover($porTipo('servicos'), -1);
BotMenu::mover($porTipo('servicos'), -1);
BotMenu::mover($porTipo('servicos'), -1); // já é a primeira: não muda
$menu = (string) Bot::atender($numero, 'menu');
verificar(str_contains($menu, "1. Tabela de preços\n2. Marcar horário"), 'opção subiu para o topo: ' . $menu);
verificar(str_starts_with((string) Bot::atender($numero, '1'), 'Nossos serviços:'), 'escolha 1 vira serviços');
Bot::atender($numero, 'menu');
igual((string) Bot::atender($numero, '2'), "Qual serviço?\n\n1. Corte — R$ 50,00 (30 min)\n\n0. Voltar ao menu", 'escolha 2 vira marcar');
Bot::atender($numero, '0');
verificar(str_contains(BotMenu::menu(false, $vars), "\n1. Tabela de preços\n5. Falar com a equipe"), 'sem cadastro usa os mesmos números');
for ($i = 0; $i < 3; $i++) BotMenu::mover($porTipo('servicos'), 1);
BotMenu::atualizar($porTipo('servicos'), 'Serviços e preços', null);
igual((string) Bot::atender($numero, 'menu'), $menuCliente, 'ordem e nome restaurados');
lanca(InvalidArgumentException::class, fn() => BotMenu::atualizar($porTipo('marcar'), '', null), 'rótulo vazio');
lanca(InvalidArgumentException::class, fn() => BotMenu::atualizar($porTipo('marcar'), str_repeat('a', 61), null), 'rótulo longo');

// ---------------------------------------------------------------------
// 4. Opções próprias com resposta pronta.
// ---------------------------------------------------------------------
$endereco = BotMenu::criar('Endereço e horário', "Rua das Flores, 10\n*Seg a sáb*, 9h às 19h");
$menu = (string) Bot::atender($numero, 'menu');
verificar(str_contains($menu, "5. Falar com a equipe\n6. Endereço e horário"), 'opção própria no fim do menu');
igual((string) Bot::atender($numero, '6'), "Rua das Flores, 10\n*Seg a sáb*, 9h às 19h\n\nMande *menu* para ver as opções.", 'resposta própria');
igual((string) Bot::atender($numero, '1'), "Qual serviço?\n\n1. Corte — R$ 50,00 (30 min)\n\n0. Voltar ao menu", 'continua no menu depois da resposta');
Bot::atender($numero, '0');
verificar(str_contains(BotMenu::menu(false, $vars), "5. Falar com a equipe\n6. Endereço e horário"), 'sem cadastro vê a opção própria');
igual((string) Bot::atender($estranho, '6'), "Rua das Flores, 10\n*Seg a sáb*, 9h às 19h\n\nMande *menu* para ver as opções.", 'sem cadastro escolhe a opção própria');
Bot::atender($estranho, 'menu');
igual((string) Bot::atender($estranho, '1'), $menuEstranho . "\n6. Endereço e horário", 'sem cadastro não escolhe marcar');
BotMenu::atualizar($endereco, 'Onde fica', 'Rua Nova, 20');
Bot::atender($numero, 'menu');
igual((string) Bot::atender($numero, '6'), "Rua Nova, 20\n\nMande *menu* para ver as opções.", 'resposta editada');
lanca(InvalidArgumentException::class, fn() => BotMenu::criar('Vazia', ''), 'opção própria sem resposta');
lanca(InvalidArgumentException::class, fn() => BotMenu::criar('Longa', str_repeat('a', 1001)), 'resposta longa');
lanca(DomainException::class, fn() => BotMenu::apagar($porTipo('marcar')), 'embutida não se apaga');
for ($i = 7; $i <= 12; $i++) BotMenu::criar("Opção $i", 'Texto');
lanca(InvalidArgumentException::class, fn() => BotMenu::criar('Décima terceira', 'Texto'), 'limite de 12 opções');
foreach (BotMenu::opcoes() as $o) if ($o['tipo'] === 'resposta' && $o['id_bot_opcao'] != $endereco) BotMenu::apagar((int) $o['id_bot_opcao']);
BotMenu::apagar($endereco);
igual((string) Bot::atender($numero, 'menu'), $menuCliente, 'opções próprias apagadas');

// ---------------------------------------------------------------------
// 5. "Mande *N*" usa o número real; tudo desligado não quebra.
// ---------------------------------------------------------------------
Servico::alterarStatus($servico, 'inativo');
BotMenu::mover($porTipo('equipe'), -1);
Bot::atender($numero, 'menu');
igual((string) Bot::atender($numero, '1'), 'No momento não há serviços disponíveis para agendamento. Mande *4* para falar com a equipe.', 'número real da equipe');
BotMenu::alternar($porTipo('equipe'));
Bot::atender($numero, 'menu');
igual((string) Bot::atender($numero, '1'), 'No momento não há serviços disponíveis para agendamento.', 'sem equipe a frase some');
BotMenu::alternar($porTipo('equipe'));
BotMenu::mover($porTipo('equipe'), 1);
Servico::alterarStatus($servico, 'ativo');
Bot::atender($numero, 'menu');
BotMenu::mover($porTipo('marcar'), 1);
igual((string) Bot::atender($numero, '1'), 'Você não tem horários marcados. Responda *2* para marcar um.', 'número real de marcar');
BotMenu::mover($porTipo('marcar'), -1);

foreach (BotMenu::opcoes() as $o) BotMenu::alternar((int) $o['id_bot_opcao']);
igual((string) Bot::atender($numero, 'menu'), "Olá, Ana! Sou o assistente virtual de *Barbearia Bot*. Responda com o número:\n\nMande *menu* a qualquer momento.", 'tudo desligado');
verificar(str_starts_with((string) Bot::atender($numero, '1'), 'Não entendi.'), 'escolha com tudo desligado');
foreach (BotMenu::opcoes() as $o) BotMenu::alternar((int) $o['id_bot_opcao']);

// ---------------------------------------------------------------------
// 6. Isolamento entre empresas
// ---------------------------------------------------------------------
BotMenu::criar('Só da A', 'Texto da A');
BotMenu::definirTexto('equipe', 'Equipe da A');
Contexto::assumirTarefa($idOutra);
verificar(count(BotMenu::opcoes()) === 5 && !in_array('Só da A', array_column(BotMenu::opcoes(), 'rotulo'), true), 'opções da outra empresa');
verificar(BotMenu::texto('equipe', []) === BotMenu::TEXTOS['equipe'], 'textos da outra empresa');
lanca(InvalidArgumentException::class, fn() => BotMenu::apagar($porTipo('marcar') + 1000), 'opção inexistente');
Contexto::assumirTarefa($idEmpresa);
$daA = array_values(array_filter(BotMenu::opcoes(), fn($o) => $o['rotulo'] === 'Só da A'))[0]['id_bot_opcao'];
Contexto::assumirTarefa($idOutra);
lanca(InvalidArgumentException::class, fn() => BotMenu::apagar((int) $daA), 'apagar opção de outra empresa');
Contexto::assumirTarefa($idEmpresa);
verificar(in_array('Só da A', array_column(BotMenu::opcoes(), 'rotulo'), true), 'opção da A intacta');

echo "OK: $checagens verificações do bot personalizável.\n";
