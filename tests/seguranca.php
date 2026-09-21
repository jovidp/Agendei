<?php

/**
 * Teste da camada de seguranca: contagem de tentativas, bloqueio por janela,
 * leitura do IP atras de proxy e politica de cabecalhos.
 *
 * Usa o armazenamento em arquivo do contador, entao nao depende de banco:
 * execute com "php tests/seguranca.php".
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(dirname(__DIR__));

// O contador precisa de AMBIENTE e das funcoes basicas; nenhuma consulta e feita.
define('AMBIENTE', 'desenvolvimento');
define('BASE_URL', '');
define('RAIZ', __DIR__ . '/..');

require_once 'includes/funcoes.php';
require_once 'includes/auth.php';
require_once 'includes/seguranca.php';
require_once 'models/Tentativa.php';

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
    echo 'FALHA: ' . $descricao . "\n";
    echo '  esperado: ' . var_export($esperado, true) . "\n";
    echo '  obtido:   ' . var_export($obtido, true) . "\n";
}

/**
 * O contador tenta o banco antes do arquivo. Sem conexao configurada no
 * ambiente de teste, a classe cai sozinha para o arquivo — que e justamente
 * o caminho que este teste precisa exercitar.
 *
 * Por isso a execucao imprime uma linha "Tabela de tentativas indisponivel,
 * usando arquivo": ela e o proprio aviso da reserva entrando em acao, e nao
 * uma falha do teste.
 */
$origem = 'teste-' . bin2hex(random_bytes(4));

// ---------------------------------------------------------------------
// Contagem e limpeza
// ---------------------------------------------------------------------
limparFalhas('login_conta', $origem);

verificar('conta nova comeca sem bloqueio', tempoBloqueado('login_conta', $origem), 0);

// A politica tolera 8 falhas por conta em 15 minutos.
for ($i = 0; $i < 7; $i++) {
    anotarFalha('login_conta', $origem);
}
verificar('abaixo do limite ainda passa', tempoBloqueado('login_conta', $origem), 0);

anotarFalha('login_conta', $origem);
verificar('limite atingido bloqueia', tempoBloqueado('login_conta', $origem) > 0, true);

// A espera nunca deve passar do que a politica define para o escopo.
verificar(
    'espera dentro do teto da politica',
    tempoBloqueado('login_conta', $origem) <= politicaLimites()['login_conta']['espera'],
    true
);

// Login bem-sucedido zera o historico da conta.
limparFalhas('login_conta', $origem);
verificar('acesso valido libera a conta', tempoBloqueado('login_conta', $origem), 0);

// ---------------------------------------------------------------------
// Isolamento entre escopos e entre chaves
// ---------------------------------------------------------------------
$outra = 'teste-' . bin2hex(random_bytes(4));
limparFalhas('login_conta', $outra);

for ($i = 0; $i < 9; $i++) {
    anotarFalha('login_conta', $origem);
}
verificar('bloqueio de uma conta nao atinge outra', tempoBloqueado('login_conta', $outra), 0);
verificar('bloqueio de conta nao atinge o escopo de IP', tempoBloqueado('login_ip', $origem), 0);

limparFalhas('login_conta', $origem);
limparFalhas('login_conta', $outra);

// Escopo inexistente nunca bloqueia, em vez de derrubar a pagina.
verificar('escopo desconhecido nao bloqueia', tempoBloqueado('inexistente', $origem), 0);

// ---------------------------------------------------------------------
// Chave gravada
// ---------------------------------------------------------------------
verificar('chave tem tamanho fixo de hash', strlen(Tentativa::chave('login_conta', 'a@b.com')), 64);
verificar(
    'chave nao guarda o valor em texto claro',
    str_contains(Tentativa::chave('login_conta', 'a@b.com'), 'a@b.com'),
    false
);
verificar(
    'a mesma conta gera a mesma chave',
    Tentativa::chave('login_conta', 'A@B.com ') === Tentativa::chave('login_conta', 'a@b.com'),
    true
);
verificar(
    'escopos diferentes geram chaves diferentes',
    Tentativa::chave('login_ip', 'a@b.com') === Tentativa::chave('login_conta', 'a@b.com'),
    false
);

// ---------------------------------------------------------------------
// Origem da requisicao
// ---------------------------------------------------------------------
$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, 198.51.100.7';

putenv('AGENDEI_PROXY_CONFIAVEL');
verificar('sem proxy confiavel o cabecalho e ignorado', ipCliente(), '10.0.0.5');

putenv('AGENDEI_PROXY_CONFIAVEL=1');
verificar('com proxy confiavel vale a ultima entrada', ipCliente(), '198.51.100.7');

$_SERVER['HTTP_X_FORWARDED_FOR'] = 'nao-e-um-ip';
verificar('cabecalho invalido volta para o endereco direto', ipCliente(), '10.0.0.5');

unset($_SERVER['HTTP_X_FORWARDED_FOR']);
verificar('sem cabecalho vale o endereco direto', ipCliente(), '10.0.0.5');
putenv('AGENDEI_PROXY_CONFIAVEL');

// O log nunca deve guardar o endereco inteiro.
verificar('ipv4 mascarado no ultimo octeto', mascararIp('203.0.113.42'), '203.0.113.x');
verificar('ipv6 mascarado apos o terceiro bloco', mascararIp('2001:db8:85a3:0:0:8a2e:370:7334'), '2001:db8:85a3:...');

// ---------------------------------------------------------------------
// Limites de sessao
// ---------------------------------------------------------------------
verificar('admin tem a janela mais curta', limiteInatividade('admin'), 20);
verificar('master tem a janela mais curta', limiteInatividade('master'), 20);
verificar('cliente tem a janela mais longa', limiteInatividade('cliente'), 45);
verificar('perfil desconhecido cai no padrao', limiteInatividade(null), 45);

// ---------------------------------------------------------------------
// Mensagem apresentada ao usuario
// ---------------------------------------------------------------------
verificar('mensagem no singular', mensagemBloqueio(30), 'Muitas tentativas seguidas. Aguarde um minuto antes de tentar novamente.');
verificar('mensagem no plural', mensagemBloqueio(600), 'Muitas tentativas seguidas. Aguarde 10 minutos antes de tentar novamente.');
verificar(
    'mensagem nao revela se a conta existe',
    str_contains(mb_strtolower(mensagemBloqueio(600)), 'conta') || str_contains(mb_strtolower(mensagemBloqueio(600)), 'senha'),
    false
);

// ---------------------------------------------------------------------
// Conferencia de origem do POST (defesa extra de CSRF)
// ---------------------------------------------------------------------
$_SERVER['HTTP_HOST'] = 'agendei.exemplo.br';
$_SERVER['HTTPS'] = 'on';

$_SERVER['HTTP_ORIGIN'] = 'https://agendei.exemplo.br';
verificar('origem propria e aceita', origemConfere(), true);

$_SERVER['HTTP_ORIGIN'] = 'https://site-falso.example';
verificar('origem externa e recusada', origemConfere(), false);

// Um subdominio parecido nao pode passar por comparacao de prefixo.
$_SERVER['HTTP_ORIGIN'] = 'https://agendei.exemplo.br.invasor.example';
verificar('dominio parecido e recusado', origemConfere(), false);

unset($_SERVER['HTTP_ORIGIN']);
$_SERVER['HTTP_REFERER'] = 'https://agendei.exemplo.br/admin/dashboard.php';
verificar('sem Origin, o Referer proprio vale', origemConfere(), true);

$_SERVER['HTTP_REFERER'] = 'https://outro.example/pagina.php';
verificar('sem Origin, o Referer externo e recusado', origemConfere(), false);

// Cliente que nao envia nenhum dos dois continua protegido pelo token.
unset($_SERVER['HTTP_REFERER']);
verificar('sem Origin nem Referer a verificacao se abstem', origemConfere(), true);

// ---------------------------------------------------------------------
// Politica de conteudo
// ---------------------------------------------------------------------
$politica = [];
foreach (politicaLimites() as $escopo => $regra) {
    $politica[] = $escopo;
    verificar("politica de {$escopo} tem limite positivo", $regra['limite'] > 0, true);
    verificar("politica de {$escopo} tem janela positiva", $regra['janela'] > 0, true);
    verificar("politica de {$escopo} tem espera positiva", $regra['espera'] > 0, true);
}

// Todo escopo citado no codigo das telas precisa existir na politica.
foreach (['login_conta', 'login_ip', 'master_ip', 'fator_ip', 'recuperar_ip', 'cadastro_ip', 'instalador_ip'] as $esperado) {
    verificar("escopo {$esperado} esta na politica", in_array($esperado, $politica, true), true);
}

// ---------------------------------------------------------------------
echo "\n{$total} verificacao(oes), {$falhas} falha(s).\n";
exit($falhas === 0 ? 0 : 1);
