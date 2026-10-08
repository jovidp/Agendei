<?php
/**
 * Regressao das telas que tem o botao do Google (cadastro do cliente, cadastro
 * de empresa, login e entrada geral).
 *
 * O navegador trata o primeiro botao de envio de um formulario como o botao
 * padrao: e ele que o Enter em qualquer campo aciona. Quando "Cadastrar com o
 * Google" ficava dentro do formulario de cadastro, antes de "Enviar",
 * preencher tudo e dar Enter mandava a pessoa ao Google e perdia o que ela
 * tinha digitado. O teste sobe o servidor embutido do PHP sobre um banco
 * temporario, baixa as quatro telas e confere no HTML final qual botao cada
 * formulario aciona e para onde o botao do Google envia.
 *
 * Uso: php tests/cadastro_google.php (banco temporario, apenas MySQL/MariaDB,
 * como tests/multitenancy.php)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(dirname(__DIR__));
require_once 'config/database.php';

if (Database::ehPostgres()) {
    fwrite(STDERR, "Este teste roda apenas no MySQL/MariaDB: ele importa banco.sql em um banco temporario.\n"
        . "Aponte AGENDEI_DB_DRIVER=mysql (e host, porta, usuario e senha) para um servidor local.\n");
    exit(1);
}

$nomeTeste = 'agendei_test_' . bin2hex(random_bytes(6));
if (!preg_match('/^agendei_test_[a-f0-9]{12}$/D', $nomeTeste)) throw new RuntimeException('Nome de banco de teste invalido.');

$checagens = 0;
function verificar(string $descricao, mixed $obtido, mixed $esperado): void
{
    global $checagens;
    if ($obtido !== $esperado) {
        throw new RuntimeException($descricao . ' | obtido: ' . var_export($obtido, true) . ' | esperado: ' . var_export($esperado, true));
    }
    $checagens++;
}

/**
 * Le os formularios de uma pagina como o navegador os enxerga: para cada um,
 * o botao padrao (primeiro botao de envio na ordem da arvore cujo dono e o
 * formulario, pelo atributo form ou por ser descendente) e os campos ocultos.
 * Devolve tambem o dono e o destino efetivo de cada botao que cita o Google.
 *
 * Um <form> dentro de outro e recusado antes de tudo: o libxml fecharia o
 * externo em silencio, enquanto o navegador ignora a tag interna e faz o
 * botao do Google pertencer ao formulario de cadastro, o defeito original.
 */
function formularios(string $html, string $pagina): array
{
    preg_match_all('/<form\b|<\/form>/i', $html, $marcas);
    $abertos = 0;
    foreach ($marcas[0] as $marca) {
        $abertos += strtolower($marca) === '</form>' ? -1 : 1;
        if ($abertos > 1 || $abertos < 0) {
            throw new RuntimeException('Formulario aninhado ou fechado fora de ordem em ' . $pagina . '.');
        }
    }

    $doc = new DOMDocument();
    @$doc->loadHTML($html);
    $xp = new DOMXPath($doc);

    // Chave: o id quando existe, senao a posicao no documento, para dois
    // formularios sem id nao se sobreporem.
    $chaveDe = static function (DOMElement $form, int $posicao): string {
        return $form->getAttribute('id') ?: 'form#' . $posicao;
    };
    $forms = [];
    foreach ($xp->query('//form') as $posicao => $form) {
        if (!$form instanceof DOMElement) continue;
        $chave = $chaveDe($form, $posicao);
        $forms[$chave] = ['action' => $form->getAttribute('action'), 'padrao' => null, 'ocultos' => []];
        foreach ($xp->query('.//input[@type="hidden"]', $form) as $oculto) {
            if (!$oculto instanceof DOMElement) continue;
            $forms[$chave]['ocultos'][$oculto->getAttribute('name')] = $oculto->getAttribute('value');
        }
    }

    $google = [];
    foreach ($xp->query('//button[not(@type) or @type="submit"] | //input[@type="submit"]') as $botao) {
        if (!$botao instanceof DOMElement) continue;
        $dono = $botao->getAttribute('form');
        if ($dono === '') {
            for ($pai = $botao->parentNode; $pai !== null; $pai = $pai->parentNode) {
                if ($pai instanceof DOMElement && $pai->tagName === 'form') {
                    foreach ($xp->query('//form') as $posicao => $form) {
                        if ($form instanceof DOMElement && $form->isSameNode($pai)) $dono = $chaveDe($form, $posicao);
                    }
                    break;
                }
            }
        }
        $rotulo = trim(preg_replace('/\s+/', ' ', $botao->textContent)) ?: $botao->getAttribute('value');
        if (isset($forms[$dono]) && $forms[$dono]['padrao'] === null) {
            $forms[$dono]['padrao'] = $rotulo;
        }
        if (str_contains($rotulo, 'Google')) {
            $google[] = [
                'rotulo'  => $rotulo,
                'dono'    => $dono,
                'destino' => $botao->getAttribute('formaction') ?: ($forms[$dono]['action'] ?? ''),
            ];
        }
    }

    return ['forms' => $forms, 'google' => $google];
}

/** Baixa uma pagina do servidor embutido; qualquer resposta que nao seja 200 encerra o teste com o log do servidor. */
function baixar(string $url, string $log): string
{
    $contexto = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
    $html = @file_get_contents($url, false, $contexto);
    $status = 0;
    foreach ($http_response_header ?? [] as $cabecalho) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $cabecalho, $m)) $status = (int) $m[1];
    }
    if ($status !== 200) {
        throw new RuntimeException($url . ' respondeu ' . $status . ".\nInicio da resposta: " . substr(strip_tags((string) $html), 0, 400)
            . "\nLog do servidor:\n" . (string) @file_get_contents($log));
    }
    return (string) $html;
}

$db = bd();
$db->exec("CREATE DATABASE `$nomeTeste` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$servidor = null;
$log = sys_get_temp_dir() . '/agendei_cadastro_google_' . getmypid() . '.log';
try {
    $schema = str_replace('`agendei`', '`' . $nomeTeste . '`', file_get_contents('banco.sql'));
    $db->exec($schema);
    $slug = (string) $db->query("SELECT slug FROM `$nomeTeste`.estabelecimento WHERE status = 'ativo' ORDER BY id_estabelecimento LIMIT 1")->fetchColumn();
    verificar('o banco temporario traz um estabelecimento ativo', $slug !== '', true);

    // O servidor embutido herda o ambiente: banco temporario e um Google "configurado"
    // (credenciais falsas bastam para as telas mostrarem o botao; nada e chamado no Google).
    putenv('AGENDEI_DB_NAME=' . $nomeTeste);
    putenv('AGENDEI_GOOGLE_CLIENT_ID=teste.apps.googleusercontent.com');
    putenv('AGENDEI_GOOGLE_CLIENT_SECRET=segredo-de-teste');

    // Porta sorteada; se estiver ocupada o php -S morre na hora e outra e sorteada.
    $porta = 0;
    for ($sorteio = 0; $sorteio < 5 && $porta === 0; $sorteio++) {
        $candidata = random_int(20000, 40000);
        @unlink($log);
        $servidor = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $candidata, '-t', getcwd()],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            getcwd()
        );
        for ($tentativa = 0; $tentativa < 50; $tentativa++) {
            if (!proc_get_status($servidor)['running']) {
                break;
            }
            $sonda = @stream_socket_client('tcp://127.0.0.1:' . $candidata, $codigo, $erro, 0.2);
            if ($sonda !== false) {
                fclose($sonda);
                $porta = $candidata;
                break;
            }
            usleep(100000);
        }
        if ($porta === 0) {
            proc_terminate($servidor);
            proc_close($servidor);
            $servidor = null;
        }
    }
    if ($porta === 0) {
        throw new RuntimeException("O servidor embutido nao subiu.\nLog do servidor:\n" . (string) @file_get_contents($log));
    }
    $base = 'http://127.0.0.1:' . $porta . '/';

    // ---------------------------------------------------------------------
    // Cadastro do cliente: o Enter envia o cadastro, nunca o Google.
    // ---------------------------------------------------------------------
    $tela = formularios(baixar($base . 'cadastro.php?estabelecimento=' . rawurlencode($slug), $log), 'cadastro.php');
    verificar('o cadastro tem o botao do Google', count($tela['google']), 1);
    verificar('o botao padrao do cadastro e o de enviar o cadastro', $tela['forms']['formCadastro']['padrao'], 'Enviar');
    verificar('o botao do Google nao pertence ao formulario de cadastro', $tela['google'][0]['dono'] !== 'formCadastro', true);
    verificar('o botao do Google envia para google_login.php', str_contains($tela['google'][0]['destino'], 'google_login.php'), true);
    $formGoogle = $tela['forms'][$tela['google'][0]['dono']];
    verificar('o pedido ao Google leva o token CSRF', isset($formGoogle['ocultos']['csrf_token']) && $formGoogle['ocultos']['csrf_token'] !== '', true);
    verificar('o pedido ao Google leva a origem "cadastro"', $formGoogle['ocultos']['origem'] ?? null, 'cadastro');
    verificar('o pedido ao Google leva o link da empresa', $formGoogle['ocultos']['estabelecimento'] ?? null, $slug);
    verificar('o cadastro continua com o token CSRF', isset($tela['forms']['formCadastro']['ocultos']['csrf_token']), true);
    verificar('o token e o mesmo nos dois formularios (mesma sessao)', $tela['forms']['formCadastro']['ocultos']['csrf_token'], $formGoogle['ocultos']['csrf_token']);

    // ---------------------------------------------------------------------
    // Cadastro de empresa: mesma regra.
    // ---------------------------------------------------------------------
    $tela = formularios(baixar($base . 'cadastro_empresa.php', $log), 'cadastro_empresa.php');
    verificar('o cadastro de empresa tem o botao do Google', count($tela['google']), 1);
    verificar('o botao padrao do cadastro de empresa nao e o Google', str_contains((string) $tela['forms']['formCadastroEmpresa']['padrao'], 'Google'), false);
    verificar('o botao do Google nao pertence ao cadastro de empresa', $tela['google'][0]['dono'] !== 'formCadastroEmpresa', true);
    verificar('o botao do Google envia para google_login.php', str_contains($tela['google'][0]['destino'], 'google_login.php'), true);
    $formGoogle = $tela['forms'][$tela['google'][0]['dono']];
    verificar('o pedido ao Google leva o token CSRF', isset($formGoogle['ocultos']['csrf_token']) && $formGoogle['ocultos']['csrf_token'] !== '', true);
    verificar('o pedido ao Google leva a origem "cadastro_empresa"', $formGoogle['ocultos']['origem'] ?? null, 'cadastro_empresa');
    verificar('o cadastro de empresa continua com o token CSRF', isset($tela['forms']['formCadastroEmpresa']['ocultos']['csrf_token']), true);

    // ---------------------------------------------------------------------
    // Login e entrada geral: o botao do Google fica no mesmo formulario (ele
    // leva "manter conectado" e o link da empresa), mas depois de "Entrar".
    // ---------------------------------------------------------------------
    $tela = formularios(baixar($base . 'login.php?estabelecimento=' . rawurlencode($slug), $log), 'login.php');
    verificar('o login tem o botao do Google', count($tela['google']), 1);
    verificar('o botao padrao do login e "Entrar"', $tela['forms']['formLogin']['padrao'], 'Entrar');
    verificar('no login o Google envia para google_login.php', str_contains($tela['google'][0]['destino'], 'google_login.php'), true);
    verificar('o login leva o link da empresa ao Google', $tela['forms'][$tela['google'][0]['dono']]['ocultos']['estabelecimento'] ?? null, $slug);

    // ---------------------------------------------------------------------
    // Adesao (vincular.php): o Google fica num formulario proprio; o Enter no
    // e-mail ou na senha confirma pela senha.
    // ---------------------------------------------------------------------
    $tela = formularios(baixar($base . 'vincular.php?estabelecimento=' . rawurlencode($slug), $log), 'vincular.php');
    verificar('a adesao tem o botao do Google', count($tela['google']), 1);
    verificar('o botao padrao da adesao e o de confirmar pela senha', $tela['forms']['formConfirmar']['padrao'], 'Confirmar');
    verificar('o botao do Google nao pertence ao formulario de senha', $tela['google'][0]['dono'] !== 'formConfirmar', true);
    verificar('na adesao o Google envia para google_login.php', str_contains($tela['google'][0]['destino'], 'google_login.php'), true);
    verificar('a adesao pelo Google leva a origem "cadastro"', $tela['forms'][$tela['google'][0]['dono']]['ocultos']['origem'] ?? null, 'cadastro');
    verificar('o formulario de senha continua com o token CSRF', isset($tela['forms']['formConfirmar']['ocultos']['csrf_token']), true);

    $tela = formularios(baixar($base . 'entrar.php', $log), 'entrar.php');
    verificar('a entrada geral tem o botao do Google', count($tela['google']), 1);
    foreach ($tela['forms'] as $chave => $form) {
        verificar('na entrada geral nenhum formulario tem o Google como botao padrao (' . $chave . ')', str_contains((string) $form['padrao'], 'Google'), false);
    }
    verificar('na entrada geral o botao padrao e "Entrar"', $tela['forms'][$tela['google'][0]['dono']]['padrao'], 'Entrar');
    verificar('na entrada geral o Google envia para google_login.php', str_contains($tela['google'][0]['destino'], 'google_login.php'), true);

    echo "OK: $checagens verificacoes do botao do Google nos formularios.\n";
} finally {
    if (is_resource($servidor)) {
        proc_terminate($servidor);
        proc_close($servidor);
    }
    @unlink($log);
    $db->exec("DROP DATABASE `$nomeTeste`");
}
