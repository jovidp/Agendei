<?php

/**
 * Teste de integracao do fluxo exigido pela especificacao, em banco temporario.
 * Cobre cadastro do usuario comum, login pelo campo Login, segundo fator,
 * gravacao do log de autenticacao, pesquisa por substring e exclusao pelo master.
 *
 * Execute com "php tests/fluxo_projeto.php". Nunca toca o banco de uso normal.
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
            throw new RuntimeException('Falha no fluxo do projeto.');
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
// O tratador do bootstrap sairia com codigo 0 e esconderia a falha.
restore_exception_handler();
ob_end_clean();

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

/** Conta os eventos de um tipo gravados no log. */
function contarEvento(string $evento): int
{
    return LogAutenticacao::contar(['evento' => $evento]);
}

// -------------------------------------------------------------------------
// Cadastro do usuario comum
// -------------------------------------------------------------------------
$idCliente = Cliente::criar([
    'nome'            => 'Ana Beatriz Lima Souza',
    'email'           => 'ana@exemplo.com.br',
    'senha'           => 'agendeis',
    'login'           => 'anabea',
    'telefone'        => '11991111111',
    'telefone_fixo'   => '1133111111',
    'cpf'             => '52998224725',
    'data_nascimento' => '1995-04-18',
    'sexo'            => 'F',
    'nome_materno'    => 'Márcia Lima Souza',
    'cep'             => '01310100',
    'logradouro'      => 'Avenida Paulista',
    'numero'          => '1000',
    'complemento'     => 'Apto 52',
    'bairro'          => 'Bela Vista',
    'cidade'          => 'Sao Paulo',
    'uf'              => 'SP',
]);

verificar('cadastro devolve o id do cliente', $idCliente > 0, true);

$cliente = Cliente::porId($idCliente);
verificar('cliente gravado com o login', $cliente['login'], 'anabea');
verificar('cliente gravado com o sexo', $cliente['sexo'], 'F');
verificar('cliente gravado com o telefone fixo', $cliente['telefone_fixo'], '1133111111');
verificar('cliente gravado com o CEP', $cliente['cep'], '01310100');
verificar('cliente gravado com a cidade', $cliente['cidade'], 'Sao Paulo');

$usuarioBanco = Usuario::porId((int) $cliente['id_usuario']);
verificar('nome materno vai para usuarios', $usuarioBanco['nome_materno'], 'Márcia Lima Souza');
verificar('data de nascimento replicada em usuarios', $usuarioBanco['data_nascimento'], '1995-04-18');

// -------------------------------------------------------------------------
// Unicidade do login e do e-mail
// -------------------------------------------------------------------------
verificar('login existente e detectado', Usuario::loginEmUso('anabea'), true);
verificar('login livre nao acusa uso', Usuario::loginEmUso('zzzzzz'), false);
verificar('login em branco nao acusa uso', Usuario::loginEmUso(''), false);
verificar('e-mail existente e detectado', Usuario::emailEmUso('ana@exemplo.com.br'), true);

// -------------------------------------------------------------------------
// Login: aceita o login de 6 letras e tambem o e-mail
// -------------------------------------------------------------------------
verificar('busca pelo login encontra a conta', Usuario::porLoginOuEmail('anabea')['email'], 'ana@exemplo.com.br');
verificar('busca pelo e-mail encontra a conta', Usuario::porLoginOuEmail('ana@exemplo.com.br')['login'], 'anabea');
verificar('identificador inexistente devolve null', Usuario::porLoginOuEmail('naoexiste'), null);

$autenticado = autenticar('anabea', 'agendeis');
verificar('autentica pelo login', $autenticado !== null, true);
verificar('log registrou o sucesso do login', contarEvento('login_sucesso'), 1);

verificar('senha errada nao autentica', autenticar('anabea', 'erradaaa'), null);
verificar('log registrou a falha', contarEvento('login_falha'), 1);

verificar('login inexistente nao autentica', autenticar('naoexiste', 'agendeis'), null);
verificar('log registrou a falha do login inexistente', contarEvento('login_falha'), 2);

// -------------------------------------------------------------------------
// Segundo fator
// -------------------------------------------------------------------------
verificar('usuario comum exige segundo fator', exigeSegundoFator($autenticado), true);

iniciarSegundoFator($autenticado, 'anabea');
$pendente = segundoFatorPendente();
verificar('desafio pendente guarda o usuario', (int) $pendente['usuario_id'], (int) $autenticado['id_usuario']);
verificar('pergunta sorteada esta entre as tres', in_array($pendente['fator'], ['nome_materno', 'data_nascimento', 'cep'], true), true);
verificar('comeca com 3 tentativas', tentativasRestantesSegundoFator(), 3);

// A conta so entra na sessao depois do acerto: aqui ainda nao ha login.
verificar('desafio pendente nao autentica a sessao', estaLogado(), false);

// Respostas certas para cada uma das perguntas possiveis.
verificar('acerta o nome da mae', respostaSegundoFatorConfere('nome_materno', 'marcia lima souza', $autenticado), true);
verificar('acerta o nascimento', respostaSegundoFatorConfere('data_nascimento', '18/04/1995', $autenticado), true);
verificar('acerta o CEP', respostaSegundoFatorConfere('cep', '01310-100', $autenticado), true);

// Tres erros seguidos esgotam as tentativas.
for ($tentativa = 1; $tentativa <= 3; $tentativa++) {
    $_SESSION['segundo_fator']['tentativas'] = $tentativa;
    LogAutenticacao::registrar('2fa_falha', 'anabea', $autenticado, $pendente['fator'], $cliente['cpf']);
}
verificar('tentativas se esgotam no terceiro erro', tentativasRestantesSegundoFator(), 0);
verificar('log registrou as 3 falhas de 2FA', contarEvento('2fa_falha'), 3);

LogAutenticacao::registrar('2fa_bloqueio', 'anabea', $autenticado, $pendente['fator'], $cliente['cpf']);
verificar('log registrou o bloqueio', contarEvento('2fa_bloqueio'), 1);

cancelarSegundoFator();
verificar('desafio cancelado some da sessao', segundoFatorPendente(), null);

// Entrada concluida.
LogAutenticacao::registrar('2fa_sucesso', 'anabea', $autenticado, 'cep', $cliente['cpf']);
registrarSessao($autenticado);
verificar('sessao aberta apos o segundo fator', estaLogado(), true);
verificar('login exibido no topo', usuarioLogin(), 'anabea');
verificar('rotulo do perfil comum', perfilRotulo(), 'Usuario comum');

// -------------------------------------------------------------------------
// Tela de log: filtros por nome, CPF e todos
// -------------------------------------------------------------------------
$porNome = LogAutenticacao::listar(['campo' => 'nome', 'busca' => 'Beatriz']);
verificar('filtro por parte do nome encontra registros', count($porNome) > 0, true);

$porCpf = LogAutenticacao::listar(['campo' => 'cpf', 'busca' => '529.982.247-25']);
verificar('filtro por CPF com mascara encontra registros', count($porCpf) > 0, true);

$porNomeInexistente = LogAutenticacao::listar(['campo' => 'nome', 'busca' => 'Fulano']);
verificar('filtro por nome inexistente devolve vazio', $porNomeInexistente, []);

$todos = LogAutenticacao::listar(['campo' => 'todos', 'busca' => 'naoexiste']);
verificar('filtro "todos" acha pelo login informado', count($todos) > 0, true);

// A listagem sai da entrada mais recente para a mais antiga.
$ordenado = LogAutenticacao::listar([]);
$datas = array_column($ordenado, 'id_log');
$ordenadoDesc = $datas;
rsort($ordenadoDesc);
verificar('log listado do mais recente para o mais antigo', $datas, $ordenadoDesc);

$totalAntesDaExclusao = LogAutenticacao::contar([]);
verificar('log acumulou os eventos do fluxo', $totalAntesDaExclusao, 8);

// -------------------------------------------------------------------------
// Consulta de usuarios: pesquisa por substring do nome
// -------------------------------------------------------------------------
verificar('pesquisa por substring no meio do nome', count(Cliente::listar(['busca' => 'Beatriz'])), 1);
verificar('pesquisa por substring no fim do nome', count(Cliente::listar(['busca' => 'Souza'])), 1);
verificar('pesquisa sem correspondencia devolve vazio', Cliente::listar(['busca' => 'Fulano']), []);
verificar('contagem acompanha o filtro', Cliente::contar(['busca' => 'Beatriz']), 1);

// -------------------------------------------------------------------------
// Exclusao pelo master: o log precisa sobreviver
// -------------------------------------------------------------------------
$idUsuarioExcluido = (int) $cliente['id_usuario'];
verificar('exclusao do usuario', Usuario::excluir($idUsuarioExcluido), true);
verificar('usuario sumiu da consulta', Cliente::porId($idCliente), null);
verificar('lista de usuarios ficou vazia', Cliente::listar([]), []);

verificar('log continua completo apos a exclusao', LogAutenticacao::contar([]), $totalAntesDaExclusao);
$logDepois = LogAutenticacao::listar(['campo' => 'nome', 'busca' => 'Beatriz']);
verificar('log ainda encontra o usuario pelo nome', count($logDepois) > 0, true);
verificar('log preservou o nome do usuario excluido', $logDepois[0]['nome'], 'Ana Beatriz Lima Souza');
verificar('log preservou o CPF do usuario excluido', $logDepois[0]['cpf'], '52998224725');

// -------------------------------------------------------------------------
echo "OK: {$total} verificacoes do fluxo do projeto";
echo $falhas === 0 ? ".\n" : ", {$falhas} FALHA(S).\n";
exit($falhas === 0 ? 0 : 1);
