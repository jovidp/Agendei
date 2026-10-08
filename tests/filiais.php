<?php

/**
 * Teste de integracao das filiais (unidades), em banco temporario.
 * Cobre a Matriz semeada, o cadastro de uma segunda unidade, o vinculo do
 * profissional a uma filial, a derivacao dos servicos por unidade, a gravacao
 * da filial no agendamento, o faturamento por unidade e a repeticao da migracao.
 *
 * Execute com "php tests/filiais.php". Nunca toca o banco de uso normal.
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
        // O worker carrega o esquema: so ele esta conectado ao banco isolado.
        putenv('AGENDEI_DB_NAME=' . $nomeTeste);
        $processo = proc_open([PHP_BINARY, __FILE__, '--worker'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
        $codigo = proc_close($processo);

        if ($codigo !== 0) {
            throw new RuntimeException('Falha no teste das filiais.');
        }
    } finally {
        // O banco temporario e sempre descartado, mesmo quando o teste falha.
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

// Carrega o esquema do dialeto em uso. Os comandos de criacao de banco saem:
// o banco ja existe e ja estamos conectados a ele.
$arquivoEsquema = $ehPostgres ? 'banco_postgres.sql' : 'banco.sql';
if (!is_file($arquivoEsquema)) {
    throw new RuntimeException("Esquema {$arquivoEsquema} nao encontrado.");
}
$esquema = preg_replace('/^(CREATE DATABASE|USE)\b[^;]*;\r?\n/mi', '', file_get_contents($arquivoEsquema));
bd()->exec($esquema);

// A migracao roda antes do bootstrap para o esquema ja ter as colunas novas.
// No PostgreSQL nao ha o que migrar: banco_postgres.sql ja vem completo.
ob_start();
if (!$ehPostgres) {
    require 'scripts/migrar.php';
}
require 'config/config.php';
ob_end_clean();

// O bootstrap instala um tratador que redireciona para a tela de erro e sai
// com codigo 0. Aqui uma excecao nao tratada precisa derrubar o worker.
restore_exception_handler();

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

/** Ids das filiais de uma lista, como inteiros. */
function idsFiliais(array $filiais): array
{
    return array_map('intval', array_column($filiais, 'id_filial'));
}

/** Ids dos profissionais de uma lista, como inteiros. */
function idsProfissionais(array $profissionais): array
{
    return array_map('intval', array_column($profissionais, 'id_profissional'));
}

/** Mensagem da excecao de validacao lancada pela chamada, ou null se nada foi lancado. */
function mensagemRejeicao(callable $chamada): ?string
{
    try {
        $chamada();
    } catch (InvalidArgumentException $erro) {
        return $erro->getMessage();
    }

    return null;
}

// -------------------------------------------------------------------------
// 1. Toda instalacao nasce com a Matriz
// -------------------------------------------------------------------------
$totalInicial = Filial::total();
verificar('instalacao nasce com ao menos uma filial', $totalInicial >= 1, true);

$matriz = Filial::padrao();
verificar('filial padrao e a Matriz', $matriz['nome'] ?? null, 'Matriz');
verificar('Matriz nasce ativa', $matriz['status'] ?? null, 'ativo');
verificar('Matriz pertence ao estabelecimento da requisicao', count(Filial::listar()), $totalInicial);

$idMatriz = (int) $matriz['id_filial'];

// -------------------------------------------------------------------------
// 2. Cadastro de uma segunda unidade
// -------------------------------------------------------------------------
$dadosCentro = [
    'nome'        => 'Unidade Centro',
    'telefone'    => '(11) 3222-1000',
    'cep'         => '01001-000',
    'logradouro'  => 'Praca da Se',
    'numero'      => '100',
    'complemento' => 'Loja 2',
    'bairro'      => 'Se',
    'cidade'      => 'Sao Paulo',
    'uf'          => 'sp',
    'foto'        => 'data:image/png;base64,QUJDRA==',
    'status'      => 'ativo',
    'ordem'       => 1,
];

$idCentro = Filial::criar($dadosCentro);
verificar('criar devolve o id da filial', $idCentro > 0, true);
verificar('total passa a contar a nova unidade', Filial::total(), $totalInicial + 1);

$centro = Filial::porId($idCentro);
verificar('porId devolve o nome', $centro['nome'], 'Unidade Centro');
verificar('telefone gravado so com digitos', $centro['telefone'], '1132221000');
verificar('CEP gravado so com digitos', $centro['cep'], '01001000');
verificar('logradouro gravado', $centro['logradouro'], 'Praca da Se');
verificar('numero gravado', $centro['numero'], '100');
verificar('complemento gravado', $centro['complemento'], 'Loja 2');
verificar('bairro gravado', $centro['bairro'], 'Se');
verificar('cidade gravada', $centro['cidade'], 'Sao Paulo');
verificar('UF normalizada em maiusculas', $centro['uf'], 'SP');
verificar('foto gravada na criacao', $centro['foto'], 'data:image/png;base64,QUJDRA==');
verificar('status gravado', $centro['status'], 'ativo');
verificar('ordem gravada', (int) $centro['ordem'], 1);
verificar('porId de filial inexistente devolve null', Filial::porId(999999), null);

verificar('endereco resumido em uma linha', Filial::enderecoResumido($centro), 'Praca da Se 100, Se, Sao Paulo - SP');
verificar('endereco resumido sem endereco fica vazio', Filial::enderecoResumido($matriz), '');
verificar('endereco resumido sem UF nao deixa o separador solto', Filial::enderecoResumido(['cidade' => 'Campinas']), 'Campinas');

// Atualizar sem a chave foto: o nome muda e a foto atual fica.
$dadosEdicao = $dadosCentro;
unset($dadosEdicao['foto']);
$dadosEdicao['nome'] = 'Unidade Centro Historico';

verificar('atualizar devolve true', Filial::atualizar($idCentro, $dadosEdicao), true);
$centro = Filial::porId($idCentro);
verificar('atualizar muda o nome', $centro['nome'], 'Unidade Centro Historico');
verificar('atualizar sem a chave foto preserva a foto', $centro['foto'], 'data:image/png;base64,QUJDRA==');

// Atualizar com a chave foto: grava a nova; com null, remove.
Filial::atualizar($idCentro, $dadosEdicao + ['foto' => 'data:image/png;base64,QUJD']);
verificar('atualizar com a chave foto grava a nova foto', Filial::porId($idCentro)['foto'], 'data:image/png;base64,QUJD');

Filial::atualizar($idCentro, $dadosEdicao + ['foto' => null]);
verificar('atualizar com foto nula remove a foto', Filial::porId($idCentro)['foto'], null);

// Validacao: mensagens prontas para o usuario.
verificar('nome em branco e rejeitado', mensagemRejeicao(fn() => Filial::criar(['nome' => '  '])), 'Informe o nome da filial (ate 120 caracteres).');
verificar('nome longo demais e rejeitado', mensagemRejeicao(fn() => Filial::criar(['nome' => str_repeat('a', 121)])), 'Informe o nome da filial (ate 120 caracteres).');
verificar('UF invalida e rejeitada', mensagemRejeicao(fn() => Filial::criar(['nome' => 'Unidade X', 'uf' => 'ZZ'])), 'UF invalida.');
verificar('status desconhecido e rejeitado na criacao', mensagemRejeicao(fn() => Filial::criar(['nome' => 'Unidade X', 'status' => 'pendente'])), 'Status de filial invalido.');
verificar('telefone com digitos demais e rejeitado', mensagemRejeicao(fn() => Filial::criar(['nome' => 'Unidade X', 'telefone' => '1234567890123456789012345'])), 'Telefone invalido: informe DDD e numero (10 ou 11 digitos).');
verificar('numero longo demais e rejeitado', mensagemRejeicao(fn() => Filial::criar(['nome' => 'Unidade X', 'numero' => str_repeat('9', 21)])), 'Numero aceita ate 20 caracteres.');
verificar('logradouro longo demais e rejeitado', mensagemRejeicao(fn() => Filial::criar(['nome' => 'Unidade X', 'logradouro' => str_repeat('a', 151)])), 'Logradouro aceita ate 150 caracteres.');
verificar('CEP incompleto e rejeitado', mensagemRejeicao(fn() => Filial::criar(['nome' => 'Unidade X', 'cep' => '12345'])), 'CEP invalido: use 8 digitos.');
verificar('ordem fora da faixa e rejeitada', mensagemRejeicao(fn() => Filial::criar(['nome' => 'Unidade X', 'ordem' => 1000])), 'A ordem deve ficar entre 0 e 999.');
verificar('validacao nao grava nada', Filial::total(), $totalInicial + 1);

// Status: inativa some de ativas(), mas continua em listar() e no total.
verificar('alterarStatus inativa a unidade', Filial::alterarStatus($idCentro, 'inativo'), true);
verificar('unidade inativa some de ativas()', in_array($idCentro, idsFiliais(Filial::ativas()), true), false);
verificar('unidade inativa continua em listar()', in_array($idCentro, idsFiliais(Filial::listar()), true), true);
verificar('filtro por status inativo a encontra', idsFiliais(Filial::listar(['status' => 'inativo'])), [$idCentro]);
verificar('total conta ativas e inativas', Filial::total(), $totalInicial + 1);
verificar('padrao continua a Matriz', (int) Filial::padrao()['id_filial'], $idMatriz);
verificar('status desconhecido e rejeitado em alterarStatus', mensagemRejeicao(fn() => Filial::alterarStatus($idCentro, 'pendente')), 'Status de filial invalido.');

Filial::alterarStatus($idCentro, 'ativo');
verificar('unidade reativada volta para ativas()', in_array($idCentro, idsFiliais(Filial::ativas()), true), true);

// Padrao e a primeira ativa: sem a Matriz, passa a ser a proxima na ordem.
Filial::alterarStatus($idMatriz, 'inativo');
verificar('sem a Matriz ativa, a padrao passa a ser a proxima unidade', (int) Filial::padrao()['id_filial'], $idCentro);
Filial::alterarStatus($idMatriz, 'ativo');
verificar('Matriz reativada volta a ser a padrao', (int) Filial::padrao()['id_filial'], $idMatriz);

// -------------------------------------------------------------------------
// 3. Todo profissional pertence a uma filial
// -------------------------------------------------------------------------
$idServico = Servico::criar([
    'nome'            => 'Corte da unidade',
    'descricao'       => 'Servico usado pelo teste das filiais.',
    'preco'           => 50.00,
    'duracao_minutos' => 30,
    'status'          => 'ativo',
]);
verificar('servico criado para o teste', $idServico > 0, true);

$idProfissionalCentro = Profissional::criar([
    'nome'          => 'Bruno Cardoso',
    'email'         => 'bruno@exemplo.com.br',
    'senha'         => 'agendeis',
    'telefone'      => '11992222222',
    'especialidade' => 'Barbeiro',
    'id_filial'     => $idCentro,
    'servicos'      => [$idServico],
]);
verificar('profissional criado com id_filial explicito', (int) Profissional::porId($idProfissionalCentro)['id_filial'], $idCentro);
verificar('servicos vinculados na criacao', Profissional::idsServicos($idProfissionalCentro), [$idServico]);

$idProfissionalMatriz = Profissional::criar([
    'nome'          => 'Carla Mendes',
    'email'         => 'carla@exemplo.com.br',
    'senha'         => 'agendeis',
    'telefone'      => '11993333333',
    'especialidade' => 'Cabeleireira',
    'servicos'      => [],
]);
verificar('profissional sem id_filial cai na filial padrao', (int) Profissional::porId($idProfissionalMatriz)['id_filial'], $idMatriz);

// atualizar: a filial so muda quando informada.
Profissional::atualizar($idProfissionalMatriz, ['especialidade' => 'Cabeleireira', 'id_filial' => $idCentro]);
verificar('atualizar com id_filial troca a unidade', (int) Profissional::porId($idProfissionalMatriz)['id_filial'], $idCentro);
Profissional::atualizar($idProfissionalMatriz, ['especialidade' => 'Cabeleireira']);
verificar('atualizar sem id_filial mantem a unidade', (int) Profissional::porId($idProfissionalMatriz)['id_filial'], $idCentro);
Profissional::atualizar($idProfissionalMatriz, ['especialidade' => 'Cabeleireira', 'id_filial' => $idMatriz]);
verificar('profissional devolvido a Matriz', (int) Profissional::porId($idProfissionalMatriz)['id_filial'], $idMatriz);

$listado = Profissional::listar(['busca' => 'Bruno']);
verificar('listar traz o id_filial', (int) ($listado[0]['id_filial'] ?? 0), $idCentro);
verificar('porUsuario traz o id_filial', (int) Profissional::porUsuario((int) $listado[0]['id_usuario'])['id_filial'], $idCentro);

// -------------------------------------------------------------------------
// 4. Servicos de uma filial derivam dos profissionais dela
// -------------------------------------------------------------------------
$idNorte = Filial::criar(['nome' => 'Unidade Norte', 'ordem' => 2]);

$porServico = Filial::porServico($idServico);
verificar('porServico contem a unidade do profissional', in_array($idCentro, idsFiliais($porServico), true), true);
verificar('porServico nao contem a Matriz (profissional dela nao faz o servico)', in_array($idMatriz, idsFiliais($porServico), true), false);
verificar('porServico nao contem unidade sem profissional', in_array($idNorte, idsFiliais($porServico), true), false);
verificar('porServico devolve so uma unidade', count($porServico), 1);
verificar('porServico de servico inexistente devolve vazio', Filial::porServico(999999), []);

// Vincular o servico ao profissional da Matriz faz a Matriz oferece-lo.
Profissional::definirServicos($idProfissionalMatriz, [$idServico]);
$porServico = Filial::porServico($idServico);
verificar('vinculo novo inclui a Matriz em porServico', in_array($idMatriz, idsFiliais($porServico), true), true);
verificar('porServico segue a ordem de exibicao', idsFiliais($porServico), [$idMatriz, $idCentro]);

// Profissionais por servico e filial.
verificar('porServicoEFilial devolve so o profissional da unidade', idsProfissionais(Profissional::porServicoEFilial($idServico, $idCentro)), [$idProfissionalCentro]);
verificar('porServicoEFilial na Matriz devolve o outro', idsProfissionais(Profissional::porServicoEFilial($idServico, $idMatriz)), [$idProfissionalMatriz]);
verificar('porServicoEFilial em unidade sem equipe devolve vazio', Profissional::porServicoEFilial($idServico, $idNorte), []);
verificar('porServico sem filial devolve todos que fazem o servico', count(Profissional::porServico($idServico)), 2);

// Unidade inativa some de porServico e volta ao ser reativada.
Filial::alterarStatus($idCentro, 'inativo');
verificar('unidade inativa some de porServico', in_array($idCentro, idsFiliais(Filial::porServico($idServico)), true), false);
verificar('porServicoEFilial nao filtra pelo status da filial (a API filtra antes)', count(Profissional::porServicoEFilial($idServico, $idCentro)), 1);
Filial::alterarStatus($idCentro, 'ativo');
verificar('unidade reativada volta a porServico', in_array($idCentro, idsFiliais(Filial::porServico($idServico)), true), true);

// Profissional inativo nao sustenta a unidade em porServico.
$idUsuarioCentro = (int) Profissional::porId($idProfissionalCentro)['id_usuario'];
Usuario::alterarStatus($idUsuarioCentro, 'inativo');
verificar('unidade cujo unico profissional esta inativo some de porServico', in_array($idCentro, idsFiliais(Filial::porServico($idServico)), true), false);
verificar('profissional inativo some de porServicoEFilial', Profissional::porServicoEFilial($idServico, $idCentro), []);
Usuario::alterarStatus($idUsuarioCentro, 'ativo');
verificar('profissional reativado devolve a unidade a porServico', in_array($idCentro, idsFiliais(Filial::porServico($idServico)), true), true);

// -------------------------------------------------------------------------
// 5. Agendamento grava a filial do profissional; faturamento por unidade
// -------------------------------------------------------------------------
$idCliente = Cliente::criar([
    'nome'     => 'Ana Beatriz Lima Souza',
    'email'    => 'ana@exemplo.com.br',
    'senha'    => 'agendeis',
    'telefone' => '11991111111',
    'cpf'      => '52998224725',
]);
verificar('cliente criado para o agendamento', $idCliente > 0, true);

// Uma semana a frente: respeita a antecedencia minima (2h) e a maxima (60 dias).
$dataAgendamento = date('Y-m-d', strtotime('+7 days'));
$diaSemana       = (int) date('w', strtotime($dataAgendamento));

foreach ([$idProfissionalCentro, $idProfissionalMatriz] as $idProfissional) {
    Horario::criar([
        'id_profissional'   => $idProfissional,
        'dia_semana'        => $diaSemana,
        'hora_inicio'       => '08:00:00',
        'hora_fim'          => '18:00:00',
        'intervalo_minutos' => 30,
    ]);
}
verificar('expediente do dia cadastrado', Horario::possuiExpediente($idProfissionalCentro), true);

$resultado = Agendamento::criar([
    'id_cliente'      => $idCliente,
    'id_profissional' => $idProfissionalCentro,
    'id_servico'      => $idServico,
    'data'            => $dataAgendamento,
    'hora_inicio'     => '10:00',
    'observacao'      => '',
    'origem'          => 'cliente',
]);
verificar('agendamento na unidade Centro e criado', $resultado['sucesso'], true);
if (!$resultado['sucesso']) {
    echo '         erros: ' . implode(' | ', $resultado['erros']) . "\n";
}

$idAgendamentoCentro = (int) ($resultado['id_agendamento'] ?? 0);
$agendamentoCentro   = Agendamento::porId($idAgendamentoCentro);
verificar('agendamento grava a filial do profissional', (int) ($agendamentoCentro['id_filial'] ?? 0), $idCentro);
verificar('valor do agendamento vem do servico', (float) ($agendamentoCentro['valor'] ?? 0), 50.0);

// Segundo agendamento, na Matriz, que sera cancelado: nao pode somar no faturamento.
$resultado = Agendamento::criar([
    'id_cliente'      => $idCliente,
    'id_profissional' => $idProfissionalMatriz,
    'id_servico'      => $idServico,
    'data'            => $dataAgendamento,
    'hora_inicio'     => '14:00',
    'observacao'      => '',
    'origem'          => 'admin',
]);
verificar('agendamento na Matriz e criado', $resultado['sucesso'], true);
if (!$resultado['sucesso']) {
    echo '         erros: ' . implode(' | ', $resultado['erros']) . "\n";
}

$idAgendamentoMatriz = (int) ($resultado['id_agendamento'] ?? 0);
verificar('agendamento da Matriz grava a Matriz', (int) (Agendamento::porId($idAgendamentoMatriz)['id_filial'] ?? 0), $idMatriz);
verificar('cancelamento do agendamento da Matriz', Agendamento::cancelar($idAgendamentoMatriz, null, 'Teste das filiais'), true);
verificar('conclusao do agendamento do Centro', Agendamento::alterarStatus($idAgendamentoCentro, 'concluido'), true);

$faturamento = Filial::faturamento($dataAgendamento, $dataAgendamento);
$porFilial   = [];
foreach ($faturamento as $linha) {
    $porFilial[(int) $linha['id_filial']] = $linha;
}

verificar('faturamento lista todas as unidades, com ou sem movimento', count($faturamento), Filial::total());
verificar('faturamento soma o valor na unidade do agendamento', (float) $porFilial[$idCentro]['valor_total'], 50.0);
verificar('faturamento conta o atendimento na unidade certa', (int) $porFilial[$idCentro]['atendimentos'], 1);
verificar('faturamento conta o concluido na unidade certa', (int) $porFilial[$idCentro]['concluidos'], 1);
verificar('cancelado conta como atendimento na Matriz', (int) $porFilial[$idMatriz]['atendimentos'], 1);
verificar('cancelado nao soma valor na Matriz', (float) $porFilial[$idMatriz]['valor_total'], 0.0);
verificar('unidade sem movimento traz zero', (float) $porFilial[$idNorte]['valor_total'], 0.0);
verificar('unidade sem movimento traz zero atendimentos', (int) $porFilial[$idNorte]['atendimentos'], 0);
verificar('unidade com maior faturamento vem primeiro', (int) $faturamento[0]['id_filial'], $idCentro);

$diaAnterior = date('Y-m-d', strtotime($dataAgendamento . ' -1 day'));
$foraDoPeriodo = array_sum(array_map(fn(array $linha) => (float) $linha['valor_total'], Filial::faturamento($diaAnterior, $diaAnterior)));
verificar('fora do periodo nenhuma unidade soma valor', $foraDoPeriodo, 0.0);

// A filial gravada e a da marcacao: trocar o profissional de unidade nao reescreve o historico.
Profissional::atualizar($idProfissionalCentro, ['especialidade' => 'Barbeiro', 'id_filial' => $idNorte]);
verificar('agendamento mantem a filial da marcacao apos o profissional mudar de unidade', (int) Agendamento::porId($idAgendamentoCentro)['id_filial'], $idCentro);
Profissional::atualizar($idProfissionalCentro, ['especialidade' => 'Barbeiro', 'id_filial' => $idCentro]);

// -------------------------------------------------------------------------
// 6. Migracao repetida: nada duplica, nada falha
// -------------------------------------------------------------------------
$totalAntesDaMigracao = Filial::total();

ob_start();
require 'scripts/migrar_filiais.php';
ob_end_clean();

verificar('migracao repetida nao cria filial', Filial::total(), $totalAntesDaMigracao);
verificar('migracao repetida nao duplica a Matriz', count(array_filter(Filial::listar(), fn(array $filial) => $filial['nome'] === 'Matriz')), 1);
verificar('profissional mantem a unidade apos a migracao', (int) Profissional::porId($idProfissionalCentro)['id_filial'], $idCentro);
verificar('agendamento mantem a unidade apos a migracao', (int) Agendamento::porId($idAgendamentoCentro)['id_filial'], $idCentro);

// -------------------------------------------------------------------------
echo "OK: {$total} verificacoes das filiais";
echo $falhas === 0 ? ".\n" : ", {$falhas} FALHA(S).\n";
exit($falhas === 0 ? 0 : 1);
