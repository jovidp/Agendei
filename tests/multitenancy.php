<?php

/** Teste de integração em banco temporário. Nunca importa o SQL no banco de uso normal. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(dirname(__DIR__));
require_once 'config/database.php';
if (($argv[1] ?? '') !== '--worker') {
    $nomeTeste = 'agendei_test_' . bin2hex(random_bytes(6));
    if (!preg_match('/^agendei_test_[a-f0-9]{12}$/D', $nomeTeste)) throw new RuntimeException('Nome de banco de teste inválido.');
    $db = bd();
    $db->exec("CREATE DATABASE `$nomeTeste` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $manter = in_array('--keep', $argv, true);
    try {
        $schema = str_replace('`agendei`', '`' . $nomeTeste . '`', file_get_contents('banco.sql'));
        $db->exec($schema);
        putenv('AGENDEI_DB_NAME=' . $nomeTeste);
        $processo = proc_open([PHP_BINARY, __FILE__, '--worker'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
        $codigo = proc_close($processo);
        if ($codigo !== 0) {
            $manter = false;
            throw new RuntimeException('Falha na integração.');
        }
        if ($manter) echo "TEST_DATABASE=$nomeTeste\n";
    } finally {
        if (!$manter) $db->exec("DROP DATABASE `$nomeTeste`");
    }
    exit;
}
if (!preg_match('/^agendei_test_[a-f0-9]{12}$/D', getenv('AGENDEI_DB_NAME') ?: '')) throw new RuntimeException('O teste exige banco isolado.');
// A atualização deve funcionar tanto na primeira instalação quanto em uma repetição.
ob_start();
require 'scripts/migrar.php';
require 'scripts/migrar.php';
require 'config/config.php';
ob_end_clean();
$checagens = 0;
function verificar(bool $condicao, string $mensagem): void
{
    global $checagens;
    if (!$condicao) throw new RuntimeException($mensagem);
    $checagens++;
}
function empresa(string $slug): void
{
    $_SESSION = [];
    $_GET = ['estabelecimento' => $slug];
    Contexto::iniciar();
}
$fixtures = [];
$data = date('Y-m-d', strtotime('+2 days'));
$semana = (int) date('w', strtotime($data));
foreach (['empresa-a', 'empresa-b'] as $slug) {
    Estabelecimento::contratar(['estabelecimento' => strtoupper($slug), 'slug' => $slug, 'nome' => 'Responsável Teste', 'email' => 'admin@teste.local', 'senha' => 'Teste12345!']);
    empresa($slug);
    $admin = Usuario::porEmail('admin@teste.local');
    $servico = Servico::criar(['nome' => 'Serviço ' . $slug, 'descricao' => 'Teste', 'preco' => 80, 'duracao_minutos' => 30, 'destaque' => 1]);
    $cliente = Cliente::criar(['nome' => 'Cliente ' . $slug, 'email' => 'cliente@teste.local', 'senha' => 'Teste12345!', 'telefone' => '11999999999', 'cpf' => '52998224725']);
    $profissional = Profissional::criar(['nome' => 'Profissional ' . $slug, 'email' => 'profissional@teste.local', 'senha' => 'Teste12345!', 'especialidade' => 'Especialidade', 'pode_bloquear_agenda' => 1, 'servicos' => [$servico]]);
    $horario = Horario::criar(['id_profissional' => $profissional, 'dia_semana' => $semana, 'hora_inicio' => '08:00:00', 'hora_fim' => '18:00:00']);
    $bloqueio = Bloqueio::criar(['id_profissional' => $profissional, 'data_bloqueio' => $data, 'hora_inicio' => '16:00:00', 'hora_fim' => '17:00:00', 'motivo' => 'Teste', 'id_usuario_criou' => $admin['id_usuario']]);
    $reserva = Agendamento::criar(['id_cliente' => $cliente, 'id_profissional' => $profissional, 'id_servico' => $servico, 'data' => $data, 'hora_inicio' => '10:00', 'observacao' => '', 'origem' => 'admin'], ['ignorar_antecedencia' => true]);
    verificar($reserva['sucesso'], 'Não criou a reserva: ' . json_encode($reserva));
    Configuracao::definir('teste_empresa', $slug);
    $cor = $slug === 'empresa-a' ? '#8844AA' : '#227744';
    Estabelecimento::personalizar(strtoupper($slug), Tema::valores(['cor_primaria' => $cor, 'fonte' => 'georgia']), null);
    $token = Usuario::gerarTokenRecuperacao((int) $admin['id_usuario']);
    $fixtures[$slug] = compact('admin', 'servico', 'cliente', 'profissional', 'horario', 'bloqueio', 'reserva', 'token', 'cor');
}
foreach ($fixtures as $slug => $f) {
    empresa($slug);
    $outro = $fixtures[$slug === 'empresa-a' ? 'empresa-b' : 'empresa-a'];
    verificar(Configuracao::obter('teste_empresa') === $slug, 'Cache de configurações cruzou empresas.');
    verificar(Estabelecimento::dados()['cor_primaria'] === $f['cor'], 'Tema de outra empresa.');
    verificar(Usuario::porId((int) $outro['admin']['id_usuario']) === null, 'Conta de outra empresa acessível.');
    verificar(Usuario::porTokenRecuperacao($outro['token']) === null, 'Token de outra empresa acessível.');
    verificar(Usuario::porTokenRecuperacao($f['token']) !== null, 'Token da própria empresa indisponível.');
    verificar(autenticar('admin@teste.local', 'Teste12345!')['id_usuario'] === $f['admin']['id_usuario'], 'Autenticação fora do estabelecimento.');
    verificar(Usuario::emailEmUso('cliente@teste.local'), 'Verificação de e-mail falhou.');
    verificar(Cliente::cpfEmUso('52998224725'), 'Verificação de CPF falhou.');
    foreach (['Servico', 'Cliente', 'Profissional', 'Agendamento'] as $classeBusca) {
        verificar(count($classeBusca::listar(['busca' => $slug])) === 1, 'Filtro de busca falhou: ' . $classeBusca);
        verificar($classeBusca::listar(['busca' => $slug === 'empresa-a' ? 'empresa-b' : 'empresa-a']) === [], 'Busca inclui resultados indevidos: ' . $classeBusca);
    }
    foreach (['Servico' => 'servico', 'Cliente' => 'cliente', 'Profissional' => 'profissional', 'Horario' => 'horario', 'Bloqueio' => 'bloqueio'] as $classe => $campo) {
        verificar($classe::porId($f[$campo]) !== null, "$classe não encontra registro próprio.");
        verificar($classe::porId($outro[$campo]) === null, "$classe expõe registro alheio.");
    }
    verificar(Agendamento::porId($outro['reserva']['id_agendamento']) === null, 'Reserva alheia acessível.');
    verificar(count(Servico::listar()) === 1 && Servico::total('ativo') === 1, 'Lista de serviços sem isolamento.');
    verificar(count(Servico::destaques()) === 1 && count(Servico::ativosComProfissional()) === 1, 'Catálogo sem isolamento.');
    verificar(count(Cliente::listar()) === 1 && Cliente::contar() === 1 && Cliente::total('ativo') === 1, 'Lista de clientes sem isolamento.');
    verificar(Cliente::cadastradosDesde('2000-01-01') === 1, 'Contagem de clientes sem isolamento.');
    verificar(count(Profissional::listar()) === 1 && Profissional::total('ativo') === 1, 'Lista de profissionais sem isolamento.');
    verificar(Profissional::porServico($outro['servico']) === [], 'Serviço alheio expõe profissionais.');
    verificar(Profissional::servicos($outro['profissional']) === [] && Profissional::idsServicos($outro['profissional']) === [], 'Vínculos alheios expostos.');
    verificar(!Profissional::executaServico($outro['profissional'], $outro['servico']), 'Consulta de vínculo fora do estabelecimento.');
    verificar(!Profissional::podeBloquearAgenda($outro['profissional']), 'Permissão de profissional alheio exposta.');
    verificar(Horario::porProfissional($outro['profissional']) === [] && !Horario::possuiExpediente($outro['profissional']), 'Expediente alheio exposto.');
    verificar(count(Bloqueio::listar()) === 1 && Bloqueio::porData($outro['profissional'], $data) === [], 'Bloqueios sem isolamento.');
    verificar(count(Agendamento::listar()) === 1 && Agendamento::contar() === 1, 'Agenda sem isolamento.');
    verificar(Agendamento::ocupacoesDoDia($outro['profissional'], $data) === [], 'Ocupações alheias expostas.');
    verificar(Disponibilidade::slots($outro['profissional'], $outro['servico'], $data) === [], 'Vagas alheias expostas.');
    verificar(count(Disponibilidade::slots($f['profissional'], $f['servico'], $data)) > 0, 'Disponibilidade própria indisponível.');
    verificar(Relatorio::faturamento('2000-01-01', '2100-01-01') === 80.0, 'Faturamento inclui outra empresa.');
    verificar(count(Relatorio::agendamentosRecentes()) === 1, 'Relatório recente inclui outra empresa.');
    verificar(count(Relatorio::servicosMaisAgendados('2000-01-01', '2100-01-01')) === 1, 'Ranking de serviços sem isolamento.');
    verificar(count(Relatorio::profissionaisMaisAtendimentos('2000-01-01', '2100-01-01')) === 1, 'Ranking de profissionais sem isolamento.');
    verificar(count(Relatorio::clientesMaisFrequentes('2000-01-01', '2100-01-01')) === 1, 'Ranking de clientes sem isolamento.');
    verificar(count(Relatorio::movimentoPorDia('2000-01-01', '2100-01-01')) === 1, 'Movimento sem isolamento.');
    verificar(Relatorio::indicadores()['clientes_total'] === 1, 'Dashboard sem isolamento.');
    verificar(str_contains(url('index.php#servicos'), 'estabelecimento=' . $slug . '#servicos'), 'Links não preservam a empresa.');
    verificar(!str_contains(url('assets/css/style.css'), 'estabelecimento='), 'URL estática foi alterada.');
    // Tentativas de alterar e excluir IDs de outra empresa não podem afetar seus registros.
    Servico::alterarStatus($outro['servico'], 'inativo');
    Usuario::atualizarSenha((int) $outro['admin']['id_usuario'], 'SenhaIndevida');
    Usuario::limparTokenRecuperacao((int) $outro['admin']['id_usuario']);
    Horario::excluir($outro['horario']);
    Bloqueio::excluir($outro['bloqueio']);
    Agendamento::cancelar($outro['reserva']['id_agendamento'], (int) $f['admin']['id_usuario'], 'Tentativa cruzada');
    $rejeitou = false;
    try {
        Profissional::definirServicos($f['profissional'], [$outro['servico']]);
    } catch (InvalidArgumentException $e) {
        $rejeitou = true;
    }
    verificar($rejeitou && Profissional::idsServicos($f['profissional']) === [$f['servico']], 'Troca inválida de serviços removeu vínculos válidos.');
    $rejeitou = false;
    try {
        bd()->exec('INSERT INTO profissional_servico (id_estabelecimento,id_profissional,id_servico) VALUES (' . Contexto::id() . ',' . $f['profissional'] . ',' . $outro['servico'] . ')');
    } catch (PDOException $e) {
        $rejeitou = $e->getCode() === '23000';
    }
    verificar($rejeitou, 'Banco aceitou associação entre empresas.');
}
foreach ($fixtures as $slug => $f) {
    empresa($slug);
    verificar(Servico::estaAtivo($f['servico']), 'Mutação cruzada alterou serviço.');
    verificar(Usuario::senhaConfere((int) $f['admin']['id_usuario'], 'Teste12345!'), 'Mutação cruzada alterou senha.');
    verificar(Usuario::porTokenRecuperacao($f['token']) !== null, 'Mutação cruzada limpou token.');
    verificar(Horario::porId($f['horario']) !== null && Bloqueio::porId($f['bloqueio']) !== null, 'Exclusão cruzada removeu registro.');
    verificar(Agendamento::porId($f['reserva']['id_agendamento'])['status'] !== 'cancelado', 'Mutação cruzada cancelou reserva.');
}

// Recursos diferenciais também respeitam a empresa e alteram o fluxo real da agenda.
empresa('empresa-a');
$f = $fixtures['empresa-a'];
Configuracao::definir('pix_chave', 'teste@pix.local');
Configuracao::definir('sinal_percentual', '25');
Configuracao::definir('pontos_por_real', '2');
Configuracao::definir('lembrete_horas', '72');

Diferencial::criarPacote(['id_servico' => $f['servico'], 'nome' => 'Pacote teste', 'quantidade' => 1, 'validade_dias' => 30, 'preco' => 70]);
$pacote = Diferencial::pacotes(true)[0];
Diferencial::adquirirPacote($f['cliente'], (int) $pacote['id_pacote']);
$compra = Diferencial::pacotesDoCliente($f['cliente'])[0];
Diferencial::atualizarCompraPacote((int) $compra['id_cliente_pacote'], 'pago');
$reservaPacote = Agendamento::criar(['id_cliente' => $f['cliente'], 'id_profissional' => $f['profissional'], 'id_servico' => $f['servico'], 'data' => $data, 'hora_inicio' => '11:00', 'observacao' => '', 'origem' => 'cliente'], ['ignorar_antecedencia' => true]);
verificar($reservaPacote['sucesso'], 'Crédito de pacote não criou agendamento.');
verificar((int) Diferencial::pacotesDoCliente($f['cliente'])[0]['creditos_restantes'] === 0, 'Crédito do pacote não foi consumido.');
verificar(Diferencial::pagamentos($f['cliente']) === [], 'Agendamento coberto por pacote gerou sinal.');

$reservaPix = Agendamento::criar(['id_cliente' => $f['cliente'], 'id_profissional' => $f['profissional'], 'id_servico' => $f['servico'], 'data' => $data, 'hora_inicio' => '13:00', 'observacao' => '', 'origem' => 'cliente'], ['ignorar_antecedencia' => true]);
verificar($reservaPix['sucesso'] && count(Diferencial::pagamentos($f['cliente'])) === 1, 'Sinal Pix não foi gerado.');
Diferencial::entrarLista($f['cliente'], $f['servico'], $f['profissional'], $data, 'tarde');
verificar(count(Diferencial::listaDoCliente($f['cliente'])) === 1, 'Lista de espera não registrou preferência.');
Agendamento::cancelar((int) $reservaPix['id_agendamento'], (int) $f['admin']['id_usuario'], 'Teste de encaixe');
verificar(Diferencial::pagamentos($f['cliente'])[0]['status'] === 'cancelado', 'Sinal pendente não foi cancelado com a reserva.');
verificar(count(Diferencial::notificacoesPendentes()) === 1, 'Cancelamento não gerou aviso de encaixe.');
verificar(Diferencial::gerarLembretes() >= 1, 'Fila de lembretes não foi gerada.');

Agendamento::alterarStatus((int) $f['reserva']['id_agendamento'], 'concluido');
verificar((int) Cliente::porId($f['cliente'])['pontos_fidelidade'] === 160, 'Pontos de fidelidade não foram creditados.');
Diferencial::avaliar($f['cliente'], (int) $f['reserva']['id_agendamento'], 5, 'Ótimo atendimento');
verificar(count(Diferencial::avaliacoes($f['cliente'])) === 1, 'Avaliação não foi registrada.');
Diferencial::atualizarComissao($f['profissional'], 30);
verificar((float) Diferencial::comissoes('2000-01-01', '2100-01-01')[0]['comissao'] === 24.0, 'Comissão não foi calculada.');
verificar(strlen(Diferencial::tokenCalendario($f['profissional'])) === 64, 'Token do calendário não foi criado.');
verificar(isset(Diferencial::exportarCliente($f['cliente'])['agendamentos']), 'Exportação de privacidade incompleta.');

$serieBase = Agendamento::criar(['id_cliente' => $f['cliente'], 'id_profissional' => $f['profissional'], 'id_servico' => $f['servico'], 'data' => $data, 'hora_inicio' => '14:00', 'observacao' => '', 'origem' => 'cliente'], ['ignorar_antecedencia' => true]);
$serie = Diferencial::criarRecorrencias((int) $serieBase['id_agendamento'], 3);
verificar($serie['criadas'] === 3, 'Agendamento recorrente não criou a série.');

// O encerramento da conta remove os dados pessoais e fecha cobranças ainda abertas.
$clientePrivacidade = Cliente::criar([
    'nome' => 'Cliente Privacidade', 'email' => 'privacidade@teste.local',
    'senha' => 'Teste12345!', 'telefone' => '11988887777', 'cpf' => '11144477735',
]);
$usuarioPrivacidade = (int) Cliente::porId($clientePrivacidade)['id_usuario'];
$reservaPrivacidade = Agendamento::criar([
    'id_cliente' => $clientePrivacidade, 'id_profissional' => $f['profissional'],
    'id_servico' => $f['servico'], 'data' => $data, 'hora_inicio' => '15:00',
    'observacao' => '', 'origem' => 'cliente',
], ['ignorar_antecedencia' => true]);
verificar($reservaPrivacidade['sucesso'] && count(Diferencial::pagamentos($clientePrivacidade)) === 1, 'Conta de privacidade não gerou a cobrança de teste.');
Diferencial::anonimizarCliente($clientePrivacidade, $usuarioPrivacidade);
verificar(Diferencial::pagamentos($clientePrivacidade)[0]['status'] === 'cancelado', 'Anonimização manteve cobrança pendente.');
verificar(Cliente::porId($clientePrivacidade)['status'] === 'inativo', 'Anonimização não desativou a conta.');
verificar(Agendamento::porId((int) $reservaPrivacidade['id_agendamento'])['status'] === 'cancelado', 'Anonimização não cancelou a reserva futura.');

empresa('empresa-b');
verificar(Diferencial::listaAdministrativa() === [], 'Lista de espera vazou entre estabelecimentos.');
verificar(Diferencial::pagamentos() === [], 'Pagamentos vazaram entre estabelecimentos.');
verificar(Diferencial::avaliacoes() === [], 'Avaliações vazaram entre estabelecimentos.');
// A conta master é global e não assume silenciosamente o contexto de uma empresa.
$master = Master::autenticar('master@agendei.com.br', 'agendei-master-2026');
verificar($master !== null, 'Conta master inicial não autenticou.');
registrarSessaoMaster($master);
Contexto::iniciar();
verificar(ehMaster() && Contexto::id() === 0, 'Sessão master recebeu vínculo de estabelecimento.');
verificar(Servico::listar() === [] && Cliente::listar() === [] && Profissional::listar() === [], 'Master recebeu dados locais sem escolher uma empresa.');
$empresasMaster = Estabelecimento::listarTodos();
verificar(count($empresasMaster) === 3, 'Master não enxerga a lista consolidada de estabelecimentos.');
foreach ($empresasMaster as $empresaMaster) verificar((int) $empresaMaster['admins_ativos'] >= ($empresaMaster['slug'] === 'agendei-studio' ? 0 : 1), 'Estabelecimento criado sem administrador.');
Estabelecimento::criarAdministrador((int) $empresasMaster[array_search('empresa-a', array_column($empresasMaster, 'slug'), true)]['id_estabelecimento'], ['nome' => 'Segundo Admin', 'email' => 'segundo@teste.local', 'senha' => 'Teste12345!']);
$empresaA = array_values(array_filter(Estabelecimento::listarTodos(), fn($e) => $e['slug'] === 'empresa-a'))[0];
verificar((int) $empresaA['admins_ativos'] === 2, 'Master não conseguiu criar administrador local adicional.');
Master::personalizar((int) $master['id_master'], 'Central Agendei', Tema::valores(['cor_primaria' => '#334455', 'cor_secundaria' => '#8899AA', 'cor_fundo' => '#F4F5F6', 'fonte' => 'garamond']), null);
Contexto::iniciar();
verificar(Contexto::dados()['nome'] === 'Central Agendei' && Contexto::dados()['fonte'] === 'garamond', 'Aparência master não foi aplicada ao próprio contexto.');
verificar(count(Tema::FONTES) >= 14, 'Catálogo ampliado de fontes não está disponível.');
verificar(Tema::valores(['cor_primaria' => '</style><script>'])['cor_primaria'] === Tema::PADRAO['cor_primaria'], 'CSS inválido aceito.');
verificar(count(Tema::validar(['cor_primaria' => '#123456', 'cor_secundaria' => '#ffffff', 'cor_fundo' => '#eeeeee', 'fonte' => 'arbitraria'])) === 1, 'Fonte arbitrária aceita.');
verificar(Tema::variaveis(['cor_primaria' => '#ffffff'])['--sobre-primaria'] === '#000000', 'Texto ilegível em cor clara.');
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
verificar(Tema::logoValida(Tema::validarImagem($png)), 'Imagem válida recusada.');
$rejeitou = false;
try {
    Tema::validarImagem('<svg onload="alert(1)"></svg>');
} catch (InvalidArgumentException $e) {
    $rejeitou = true;
}
verificar($rejeitou, 'Logo ativa aceita.');

// ---------------------------------------------------------------------
// Teto de agendamentos do plano
//
// O limite é conferido dentro de Agendamento::criar(), e não nas telas,
// porque o agendamento nasce por três caminhos. Aqui ele é exercitado com
// dados de verdade: as reservas criadas ao longo deste teste.
// ---------------------------------------------------------------------
$idEmpresaA = (int) $empresaA['id_estabelecimento'];
$usoAtual = Plano::uso($idEmpresaA)['agendamentos_mes'];
verificar($usoAtual > 0, 'Consumo do mês não acompanhou as reservas criadas.');

// O teto nasce exatamente no que a empresa já usa: a próxima reserva estoura.
$idPlanoTeto = Plano::criar(['nome' => 'Teto exato', 'limite_agendamentos_mes' => $usoAtual]);
Plano::atribuir($idEmpresaA, $idPlanoTeto);

empresa('empresa-a');
$fa = $fixtures['empresa-a'];
$dadosReserva = ['id_cliente' => $fa['cliente'], 'id_profissional' => $fa['profissional'], 'id_servico' => $fa['servico'], 'data' => $data, 'hora_inicio' => '15:00', 'observacao' => '', 'origem' => 'admin'];

$recusada = Agendamento::criar($dadosReserva, ['ignorar_antecedencia' => true]);
verificar($recusada['sucesso'] === false, 'Teto do plano não barrou o agendamento.');
verificar(str_contains($recusada['erros'][0] ?? '', 'Teto exato'), 'A recusa não explicou qual plano impôs o limite.');

// O teto é de quem contratou: a empresa vizinha não sente nada.
empresa('empresa-b');
$fb = $fixtures['empresa-b'];
$vizinha = Agendamento::criar(['id_cliente' => $fb['cliente'], 'id_profissional' => $fb['profissional'], 'id_servico' => $fb['servico'], 'data' => $data, 'hora_inicio' => '15:00', 'observacao' => '', 'origem' => 'admin'], ['ignorar_antecedencia' => true]);
verificar($vizinha['sucesso'] === true, 'Plano de uma empresa limitou outra: ' . json_encode($vizinha));

// Sem plano, tudo volta a ser ilimitado — e nada do que existia foi apagado.
Plano::atribuir($idEmpresaA, null);
empresa('empresa-a');
$liberada = Agendamento::criar($dadosReserva, ['ignorar_antecedencia' => true]);
verificar($liberada['sucesso'] === true, 'Remover o plano não liberou o agendamento: ' . json_encode($liberada));
verificar(Plano::doEstabelecimento($idEmpresaA) === null, 'Vínculo de plano continuou depois de removido.');

echo "OK: $checagens verificações de isolamento, migração e identidade visual.\n";
