<?php

/**
 * Teste de integracao da administracao master, em banco temporario.
 * Cobre a trilha de auditoria, a gestao das contas globais, a manutencao dos
 * administradores locais e a leitura dos bloqueios de forca bruta.
 *
 * Execute com "php tests/master.php". Nunca toca o banco de uso normal.
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
            throw new RuntimeException('Falha na administracao master.');
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

$arquivoEsquema = $ehPostgres ? 'banco_postgres.sql' : 'banco.sql';
if (!is_file($arquivoEsquema)) {
    throw new RuntimeException("Esquema {$arquivoEsquema} nao encontrado.");
}
$esquema = preg_replace('/^(CREATE DATABASE|USE)\b[^;]*;\r?\n/mi', '', file_get_contents($arquivoEsquema));
bd()->exec($esquema);

ob_start();
if (!$ehPostgres) {
    require 'scripts/migrar.php';
}
require 'config/config.php';
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

// -------------------------------------------------------------------------
// Sessao master, como ela fica depois de registrarSessaoMaster()
// -------------------------------------------------------------------------
$idMaster = (int) bd()->query('SELECT id_master FROM administradores_master ORDER BY id_master LIMIT 1')->fetchColumn();
if ($idMaster === 0) {
    $consulta = bd()->prepare('INSERT INTO administradores_master (nome, email, senha_hash) VALUES (?, ?, ?)');
    $consulta->execute(['Administrador Master', 'master@agendei.com.br', password_hash('agendei-master-2026', PASSWORD_DEFAULT)]);
    $idMaster = (int) bd()->lastInsertId();
}

$_SESSION['master_id']     = $idMaster;
$_SESSION['usuario_tipo']  = 'master';
$_SESSION['usuario_nome']  = 'Administrador Master';
$_SESSION['usuario_email'] = 'master@agendei.com.br';

// -------------------------------------------------------------------------
// Auditoria: grava, le e recusa acao fora do catalogo
// -------------------------------------------------------------------------
verificar('auditoria comeca vazia', LogMaster::contar(), 0);

LogMaster::registrar('login');
verificar('a entrada na area master e registrada', LogMaster::contar(), 1);

$evento = LogMaster::listar(['limite' => 1])[0] ?? [];
verificar('a auditoria guarda quem fez a acao', $evento['master_email'] ?? '', 'master@agendei.com.br');
verificar('a auditoria guarda o id da conta', (int) ($evento['id_master'] ?? 0), $idMaster);
verificar('a auditoria guarda a origem', $evento['ip'] ?? '', '127.0.0.1');

LogMaster::registrar('acao_inexistente');
verificar('acao fora do catalogo e ignorada', LogMaster::contar(), 1);

// -------------------------------------------------------------------------
// Contratacao e contas administrativas do estabelecimento
// -------------------------------------------------------------------------
$idEmpresa = Estabelecimento::contratar([
    'estabelecimento' => 'Studio Teste',
    'slug'            => 'studio-teste',
    'nome'            => 'Ana Responsavel',
    'email'           => 'ana@studio.test',
    'senha'           => 'senha123',
]);
LogMaster::registrar('estabelecimento_criado', [
    'estabelecimento'      => $idEmpresa,
    'estabelecimento_nome' => 'Studio Teste',
    'alvo'                 => 'ana@studio.test',
]);
verificar('filtro por estabelecimento na auditoria', LogMaster::contar(['estabelecimento' => $idEmpresa]), 1);
verificar('filtro por acao na auditoria', LogMaster::contar(['acao' => 'login']), 1);
verificar('busca livre na auditoria', LogMaster::contar(['busca' => 'ana@studio']), 1);

$administradores = Estabelecimento::administradores($idEmpresa);
verificar('o estabelecimento nasce com um administrador', count($administradores), 1);
$idAdmin = (int) $administradores[0]['id_usuario'];

// O isolamento nao vem mais da sessao: cada consulta declara a empresa.
verificar('administrador e lido pela empresa certa', is_array(Estabelecimento::administrador($idEmpresa, $idAdmin)), true);
verificar('administrador nao e lido por outra empresa', Estabelecimento::administrador(1, $idAdmin), null);

// -------------------------------------------------------------------------
// A empresa nunca fica sem administrador ativo
// -------------------------------------------------------------------------
verificar('contagem de administradores ativos', Estabelecimento::administradoresAtivos($idEmpresa), 1);
verificar('o ultimo administrador ativo nao e desativado', Estabelecimento::alterarStatusAdministrador($idEmpresa, $idAdmin, 'inativo'), false);
verificar('a conta continua ativa depois da recusa', Estabelecimento::administrador($idEmpresa, $idAdmin)['status'], 'ativo');

$idAdmin2 = Estabelecimento::criarAdministrador($idEmpresa, [
    'nome'  => 'Bruno Socio',
    'email' => 'bruno@studio.test',
    'senha' => 'senha123',
]);
verificar('com dois administradores, um pode ser desativado', Estabelecimento::alterarStatusAdministrador($idEmpresa, $idAdmin, 'inativo'), true);
verificar('sobra um administrador ativo', Estabelecimento::administradoresAtivos($idEmpresa), 1);

// -------------------------------------------------------------------------
// Redefinicao de senha pelo master
// -------------------------------------------------------------------------
$consulta = bd()->prepare('UPDATE usuarios SET token_recuperacao = ?, token_expiracao = NOW() WHERE id_usuario = ?');
$consulta->execute([str_repeat('a', 64), $idAdmin2]);

Estabelecimento::definirSenhaAdministrador($idEmpresa, $idAdmin2, 'nova-senha-1');

$consulta = bd()->prepare('SELECT senha_hash, token_recuperacao FROM usuarios WHERE id_usuario = ?');
$consulta->execute([$idAdmin2]);
$conta = $consulta->fetch();
verificar('a nova senha do administrador vale', password_verify('nova-senha-1', $conta['senha_hash']), true);
verificar('o token de recuperacao pendente e descartado', $conta['token_recuperacao'], null);

// Uma empresa nao redefine a senha de quem nao e dela.
Estabelecimento::definirSenhaAdministrador(1, $idAdmin2, 'invasao');
$consulta->execute([$idAdmin2]);
verificar('redefinir senha exige a empresa correta', password_verify('nova-senha-1', $consulta->fetch()['senha_hash']), true);

// -------------------------------------------------------------------------
// Contas master: a plataforma nunca fica sem administracao
// -------------------------------------------------------------------------
verificar('existe uma conta master ativa', Master::contarAtivos(), 1);
verificar('a ultima conta master ativa nao e desativada', Master::alterarStatus($idMaster, 'inativo'), false);
verificar('a conta master continua ativa depois da recusa', Master::porId($idMaster)['status'], 'ativo');

$idMaster2 = Master::criar(['nome' => 'Segunda Conta', 'email' => 'segunda@agendei.test', 'senha' => 'senha123']);
verificar('a segunda conta master nasce ativa', Master::contarAtivos(), 2);
verificar('e-mail repetido e recusado', Master::emailEmUso('segunda@agendei.test'), true);
verificar('a propria conta nao conflita consigo mesma', Master::emailEmUso('segunda@agendei.test', $idMaster2), false);
verificar('a listagem nao expoe o hash da senha', array_key_exists('senha_hash', Master::listar()[0]), false);
verificar('com duas contas, uma pode ser desativada', Master::alterarStatus($idMaster2, 'inativo'), true);
verificar('conta master inativa nao autentica', Master::autenticar('segunda@agendei.test', 'senha123'), null);

// -------------------------------------------------------------------------
// Log de autenticacao visto de cima
// -------------------------------------------------------------------------
LogAutenticacao::registrar('login_falha', 'ana@studio.test');
verificar('a consulta global enxerga o log de autenticacao', LogAutenticacao::contarGlobal(), 1);
verificar('filtro por evento no log global', LogAutenticacao::contarGlobal(['evento' => 'login_sucesso']), 0);
verificar('busca por login informado no log global', LogAutenticacao::contarGlobal(['busca' => 'ana@studio']), 1);
verificar('o resumo conta a falha de login', LogAutenticacao::resumoGlobal(24)['login_falha'] ?? 0, 1);

// -------------------------------------------------------------------------
// Bloqueios de forca bruta: listar e liberar
// -------------------------------------------------------------------------
verificar('nenhum bloqueio no comeco', Tentativa::bloqueiosAtivos(), []);

for ($i = 0; $i < 6; $i++) {
    anotarFalha('master_ip', '203.0.113.9');
}

$bloqueios = Tentativa::bloqueiosAtivos();
verificar('o bloqueio aparece na listagem do painel', count($bloqueios), 1);
verificar('o escopo do bloqueio e identificado', $bloqueios[0]['escopo'] ?? '', 'master_ip');
verificar('a listagem conta as falhas', $bloqueios[0]['total'] ?? 0, 6);
verificar('o bloqueio tem tempo restante', ($bloqueios[0]['restante'] ?? 0) > 0, true);
verificar('a chave exposta na tela e um hash', (bool) preg_match('/^[a-f0-9]{64}$/D', (string) ($bloqueios[0]['chave'] ?? '')), true);
verificar('o login master esta bloqueado', tempoBloqueado('master_ip', '203.0.113.9') > 0, true);

Tentativa::limpar((string) $bloqueios[0]['escopo'], (string) $bloqueios[0]['chave']);
verificar('liberar pela chave da listagem remove o bloqueio', Tentativa::bloqueiosAtivos(), []);
verificar('depois de liberar, a tentativa e aceita', tempoBloqueado('master_ip', '203.0.113.9'), 0);

// -------------------------------------------------------------------------
// A auditoria sobrevive ao fim da empresa citada
// -------------------------------------------------------------------------
$idInexistente = $idEmpresa + 9999;
LogMaster::registrar('estabelecimento_status', [
    'estabelecimento'      => $idInexistente,
    'estabelecimento_nome' => 'Empresa Encerrada',
    'detalhe'              => 'de ativo para inativo',
]);
$historico = LogMaster::listar(['estabelecimento' => $idInexistente]);
verificar('o historico aceita empresa que nao existe mais', count($historico), 1);
verificar('o nome da empresa segue legivel no historico', $historico[0]['estabelecimento_nome'], 'Empresa Encerrada');

// -------------------------------------------------------------------------
// Simulacao: o master entrando no painel de um estabelecimento
// -------------------------------------------------------------------------
$empresaSimulada = Estabelecimento::porIdGlobal($idEmpresa);
$contaSimulada = Estabelecimento::administrador($idEmpresa, $idAdmin2);
$masterAtual = Master::porId($idMaster);

verificar('a sessao master nao e simulacao', ehSimulacao(), false);
verificar('a conta administrativa traz o tipo, exigido pela sessao', $contaSimulada['tipo'], 'admin');

$acessoAntes = $contaSimulada['ultimo_acesso'];
iniciarSimulacao($contaSimulada, $masterAtual, Estabelecimento::idAdministrador($idEmpresa, $idAdmin2));

verificar('a sessao passa a ser a do administrador', perfil(), 'admin');
verificar('a sessao aponta para a empresa simulada', (int) $_SESSION['estabelecimento_id'], $idEmpresa);
verificar('a marca de simulacao fica na sessao', ehSimulacao(), true);
verificar('a simulacao guarda quem a abriu', (int) simulacaoAtual()['master_id'], $idMaster);
verificar('a sessao deixa de ser master', isset($_SESSION['master_id']), false);
verificar('o perfil_id aponta para o registro de administrador', perfilId(), Estabelecimento::idAdministrador($idEmpresa, $idAdmin2));

// "Ultimo acesso" diz quando o responsavel entrou: o master no lugar dele nao
// pode reescrever esse dado, que serve para saber se a conta esta em uso.
verificar('o ultimo acesso da conta nao e alterado', Estabelecimento::administrador($idEmpresa, $idAdmin2)['ultimo_acesso'], $acessoAntes);

$masterVoltou = encerrarSimulacao();
verificar('a volta restaura a conta master', (int) ($masterVoltou['id_master'] ?? 0), $idMaster);
verificar('a sessao volta a ser master', perfil(), 'master');
verificar('a marca de simulacao sai da sessao', ehSimulacao(), false);
verificar('a sessao perde o vinculo com a empresa', isset($_SESSION['usuario_id']), false);

// -------------------------------------------------------------------------
// Uso por estabelecimento
// -------------------------------------------------------------------------
$uso = Estabelecimento::uso(30);
verificar('o uso cobre todos os estabelecimentos', count($uso), count(Estabelecimento::listarTodos()));

$usoEmpresa = null;
foreach ($uso as $linha) {
    if ((int) $linha['id_estabelecimento'] === $idEmpresa) {
        $usoEmpresa = $linha;
    }
}
verificar('a empresa criada aparece no uso', is_array($usoEmpresa), true);
verificar('empresa recem-criada nao tem agendamento no periodo', (int) $usoEmpresa['agendamentos_periodo'], 0);
verificar('a coluna de clientes responde', (int) $usoEmpresa['clientes_total'], 0);
verificar('o ultimo acesso vem nulo enquanto ninguem entrou', $usoEmpresa['ultimo_acesso'], null);

// -------------------------------------------------------------------------
// Saude do sistema
// -------------------------------------------------------------------------
$diagnostico = Saude::verificacoes();
verificar('o diagnostico devolve varios itens', count($diagnostico) > 5, true);

$porNome = [];
foreach ($diagnostico as $item) {
    $porNome[$item['nome']] = $item;
}
verificar('o banco aparece no diagnostico', $porNome['Conexao com o banco']['estado'], Saude::OK);
verificar('a auditoria aparece no diagnostico', $porNome['Auditoria da administracao']['estado'], Saude::OK);

// Neste ponto a segunda conta master esta desativada e o estabelecimento de
// demonstracao do esquema ainda nao tem administrador: os dois itens devem
// acusar isso em vez de passar batido.
verificar('conta master unica vira aviso', $porNome['Contas master ativas']['estado'], Saude::AVISO);
verificar('empresa sem administrador e apontada como problema', $porNome['Empresas sem administrador']['estado'], Saude::ERRO);

// Corrigido o que faltava, o mesmo diagnostico precisa voltar a ficar limpo.
Master::alterarStatus($idMaster2, 'ativo');
Estabelecimento::criarAdministrador(1, [
    'nome'  => 'Responsavel Demo',
    'email' => 'demo@estabelecimento.test',
    'senha' => 'senha123',
]);

$porNomeDepois = [];
foreach (Saude::verificacoes() as $item) {
    $porNomeDepois[$item['nome']] = $item;
}
verificar('duas contas master deixam o item em ordem', $porNomeDepois['Contas master ativas']['estado'], Saude::OK);
verificar('com administrador, a empresa sai da lista', $porNomeDepois['Empresas sem administrador']['estado'], Saude::OK);

$resumoSaude = Saude::resumo($diagnostico);
verificar(
    'o resumo soma todos os itens',
    $resumoSaude[Saude::OK] + $resumoSaude[Saude::AVISO] + $resumoSaude[Saude::ERRO],
    count($diagnostico)
);

// -------------------------------------------------------------------------
// Planos: o teto so existe depois de atribuido
// -------------------------------------------------------------------------
verificar('as tabelas de plano estao disponiveis', Plano::disponivel(), true);
verificar('empresa sem plano nao tem teto', Plano::limiteDe('servicos', $idEmpresa), null);
verificar('empresa sem plano nunca bloqueia', Plano::bloqueio('servicos', $idEmpresa), null);

$idPlano = Plano::criar([
    'nome'                    => 'Basico',
    'descricao'               => 'Plano de teste',
    'limite_profissionais'    => 1,
    'limite_servicos'         => 2,
    'limite_agendamentos_mes' => 1,
]);
verificar('o plano foi criado', Plano::porId($idPlano)['nome'], 'Basico');
verificar('campo em branco vira ilimitado', Plano::criar(['nome' => 'Sem teto']) > 0, true);
verificar('plano sem limite devolve nulo na coluna', Plano::porNome('Sem teto')['limite_servicos'], null);

Plano::atribuir($idEmpresa, $idPlano);
verificar('o plano fica vinculado a empresa', Plano::doEstabelecimento($idEmpresa)['nome'], 'Basico');
verificar('o teto passa a valer', Plano::limiteDe('servicos', $idEmpresa), 2);
verificar('o mapa de vinculos traz a empresa', isset(Plano::porEstabelecimento()[$idEmpresa]), true);
verificar('dentro do teto nao ha bloqueio', Plano::bloqueio('servicos', $idEmpresa), null);

// Preenche o catalogo ate o limite direto no banco: os models de servico
// filtram pela empresa da sessao, que aqui e a da area master.
$insercao = bd()->prepare(
    "INSERT INTO servicos (id_estabelecimento, nome, preco, duracao_minutos, status)
     VALUES (?, ?, 10, 30, 'ativo')"
);
$insercao->execute([$idEmpresa, 'Corte']);
$insercao->execute([$idEmpresa, 'Barba']);

verificar('o consumo acompanha os cadastros', Plano::uso($idEmpresa)['servicos'], 2);
verificar('no teto, a criacao e recusada', is_string(Plano::bloqueio('servicos', $idEmpresa)), true);
verificar('a mensagem recusada cita o plano', str_contains((string) Plano::bloqueio('servicos', $idEmpresa), 'Basico'), true);
verificar('a situacao marca o recurso como excedido', Plano::situacao($idEmpresa)['servicos']['excedido'], true);

// Servico desativado nao ocupa vaga: quem desfez volta a caber no teto.
bd()->prepare("UPDATE servicos SET status = 'inativo' WHERE id_estabelecimento = ? AND nome = ?")
    ->execute([$idEmpresa, 'Barba']);
verificar('servico desativado libera a vaga', Plano::bloqueio('servicos', $idEmpresa), null);

verificar('profissionais tem teto proprio', Plano::limiteDe('profissionais', $idEmpresa), 1);
verificar('recurso desconhecido nao inventa limite', Plano::limiteDe('inexistente', $idEmpresa), null);

// Tirar o plano devolve a empresa ao estado anterior, sem apagar nada.
Plano::atribuir($idEmpresa, null);
verificar('sem plano, o vinculo some', Plano::doEstabelecimento($idEmpresa), null);
verificar('sem plano, o teto some junto', Plano::bloqueio('servicos', $idEmpresa), null);
verificar('os servicos cadastrados continuam la', Plano::uso($idEmpresa)['servicos'], 1);

// -------------------------------------------------------------------------
echo "OK: {$total} verificacoes da administracao master";
echo $falhas === 0 ? ".\n" : ", {$falhas} FALHA(S).\n";
exit($falhas === 0 ? 0 : 1);
