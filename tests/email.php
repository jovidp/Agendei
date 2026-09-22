<?php

/**
 * Teste do envio de e-mail por SMTP.
 *
 * Nao depende de banco nem de internet: um servidor SMTP de mentira
 * (tests/smtp_falso.php) atende na porta local e grava o que recebeu. Cobre a
 * configuracao, a montagem da mensagem, o dialogo completo com autenticacao,
 * a recusa de senha e o comportamento sem configuracao.
 *
 * Execute com "php tests/email.php".
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(dirname(__DIR__));

define('AMBIENTE', 'desenvolvimento');
define('RAIZ', dirname(__DIR__));
define('BASE_URL', '');
define('NOME_SISTEMA', 'Agendei');

require_once 'includes/funcoes.php';
require_once 'includes/marca.php';
require_once 'includes/emails.php';
require_once 'models/Email.php';

// Nada de configuracao herdada do ambiente ou do arquivo local: o teste define a sua.
foreach (['HOST', 'PORTA', 'SEGURANCA', 'USUARIO', 'SENHA', 'REMETENTE', 'NOME', 'API_CHAVE', 'API_URL'] as $chave) {
    putenv('AGENDEI_EMAIL_' . $chave . '=');
}

$total = 0;
$falhas = 0;

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

/** Sobe o servidor falso e devolve [processo, arquivo com o que ele recebeu]. */
function subirServidorFalso(int $porta, string $modo): array
{
    $arquivo = tempnam(sys_get_temp_dir(), 'smtp');
    $processo = proc_open(
        [PHP_BINARY, __DIR__ . '/smtp_falso.php', (string) $porta, $arquivo, $modo],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR],
        $pipes
    );
    // Espera o "pronto" para nao conectar antes de o servidor escutar.
    $linha = fgets($pipes[1]);
    if (trim((string) $linha) !== 'pronto') {
        throw new RuntimeException('O servidor SMTP falso nao subiu.');
    }
    return [$processo, $arquivo, $pipes];
}

function encerrarServidorFalso(array $servidor): string
{
    [$processo, $arquivo, $pipes] = $servidor;
    fclose($pipes[0]);
    fclose($pipes[1]);
    proc_close($processo);
    $recebido = (string) @file_get_contents($arquivo);
    @unlink($arquivo);
    return $recebido;
}

// -------------------------------------------------------------------------
// Sem configuracao: nada quebra, nada sai
// -------------------------------------------------------------------------
verificar('sem host nao esta configurado', Email::configurado(), false);
verificar('sem configuracao nao ha meio de envio', Email::meio(), '');
verificar('sem configuracao o envio devolve false', Email::enviar('alguem@teste.local', 'Oi', 'corpo'), false);
verificar('o motivo fica registrado', Email::ultimoErro(), 'Envio de e-mail nao configurado.');

putenv('AGENDEI_EMAIL_HOST=127.0.0.1');
putenv('AGENDEI_EMAIL_USUARIO=robo@agendei.test');
verificar('sem remetente valido continua nao configurado', Email::configurado() && validarEmail(Email::configuracao()['remetente']), true);
verificar('o remetente cai no usuario quando nao informado', Email::configuracao()['remetente'], 'robo@agendei.test');
verificar('a porta padrao e 587', Email::configuracao()['porta'], 587);
verificar('587 sobe com STARTTLS', Email::configuracao()['seguranca'], 'tls');
putenv('AGENDEI_EMAIL_PORTA=465');
verificar('465 sobe com SSL direto', Email::configuracao()['seguranca'], 'ssl');
putenv('AGENDEI_EMAIL_SEGURANCA=nenhuma');
verificar('a seguranca informada prevalece', Email::configuracao()['seguranca'], 'nenhuma');
verificar('o nome do remetente cai no nome do sistema', Email::configuracao()['nome'], 'Agendei');
verificar('com host e remetente o meio e SMTP', Email::meio(), 'smtp');
putenv('AGENDEI_EMAIL_API_CHAVE=xkeysib-teste');
verificar('com a chave da API o meio passa a ser a API', Email::meio(), 'api');
putenv('AGENDEI_EMAIL_API_CHAVE=');

// -------------------------------------------------------------------------
// Montagem da mensagem
// -------------------------------------------------------------------------
$mensagem = Email::montar('robo@agendei.test', 'Agendei', 'ana@teste.local', 'Ana Célia', 'Redefinição de senha', "Olá, Ana.\nLinha dois.", '<p>Olá</p>');
verificar('o assunto com acento e codificado', str_contains($mensagem, 'Subject: =?UTF-8?B?' . base64_encode('Redefinição de senha') . '?='), true);
verificar('o nome do destinatario com acento e codificado', str_contains($mensagem, 'To: =?UTF-8?B?' . base64_encode('Ana Célia') . '?= <ana@teste.local>'), true);
verificar('o remetente sem acento fica legivel', str_contains($mensagem, 'From: Agendei <robo@agendei.test>'), true);
verificar('a mensagem tem as duas versoes', substr_count($mensagem, 'Content-Transfer-Encoding: base64'), 2);
verificar('o texto vai em base64', str_contains($mensagem, base64_encode("Olá, Ana.\nLinha dois.")), true);
verificar('o HTML vai em base64', str_contains($mensagem, base64_encode('<p>Olá</p>')), true);
verificar('cabecalhos e corpo usam CRLF', str_contains($mensagem, "MIME-Version: 1.0\r\n"), true);

$simples = Email::montar('robo@agendei.test', '', 'ana@teste.local', '', 'Assunto simples', 'so texto', null);
verificar('sem HTML a mensagem e texto puro', str_contains($simples, 'Content-Type: text/plain; charset=UTF-8'), true);
verificar('sem nome o From e so o endereco', str_contains($simples, "From: robo@agendei.test\r\n"), true);

[$assunto, $texto, $html] = emailRecuperacaoSenha('Ana', 'https://agendei.test/redefinir_senha.php?token=abc', 'Studio Teste');
verificar('a mensagem de recuperacao leva o link no texto', str_contains($texto, 'https://agendei.test/redefinir_senha.php?token=abc'), true);
verificar('a mensagem de recuperacao leva o link no HTML', str_contains($html, 'href="https://agendei.test/redefinir_senha.php?token=abc"'), true);
verificar('o HTML escapa o conteudo', str_contains(emailLayout('T', ['<b>x</b>']), '&lt;b&gt;x&lt;/b&gt;'), true);
verificar('a assinatura leva o slogan', str_contains($texto, MARCA_SLOGAN), true);

// -------------------------------------------------------------------------
// Dialogo SMTP completo com o servidor falso
// -------------------------------------------------------------------------
$porta = random_int(20000, 40000);
putenv('AGENDEI_EMAIL_HOST=127.0.0.1');
putenv('AGENDEI_EMAIL_PORTA=' . $porta);
putenv('AGENDEI_EMAIL_SEGURANCA=nenhuma');
putenv('AGENDEI_EMAIL_USUARIO=robo@agendei.test');
putenv('AGENDEI_EMAIL_SENHA=segredo-123');
putenv('AGENDEI_EMAIL_REMETENTE=avisos@agendei.test');
putenv('AGENDEI_EMAIL_NOME=Agendei Avisos');

$servidor = subirServidorFalso($porta, 'ok');
$enviou = Email::enviar('ana@teste.local', 'Teste', "primeira linha\n.linha que comeca com ponto", '<p>oi</p>', 'Ana');
$recebido = encerrarServidorFalso($servidor);

verificar('o envio e aceito', $enviou, true);
verificar('nenhum erro fica registrado', Email::ultimoErro(), '');
verificar('o cliente se apresenta', str_contains($recebido, 'EHLO '), true);
verificar('o cliente autentica', str_contains($recebido, "AUTH LOGIN\r\n" . base64_encode('robo@agendei.test') . "\r\n" . base64_encode('segredo-123') . "\r\n"), true);
verificar('o remetente e o configurado', str_contains($recebido, 'MAIL FROM:<avisos@agendei.test>'), true);
verificar('o destinatario e o pedido', str_contains($recebido, 'RCPT TO:<ana@teste.local>'), true);
verificar('a mensagem e encerrada com ponto', str_contains($recebido, "\r\n.\r\nQUIT\r\n"), true);
verificar('o From leva o nome configurado', str_contains($recebido, 'From: Agendei Avisos <avisos@agendei.test>'), true);
verificar('o corpo chega em base64', str_contains($recebido, base64_encode("primeira linha\n.linha que comeca com ponto")), true);
verificar('nao pede STARTTLS sem seguranca', str_contains($recebido, 'STARTTLS'), false);

// -------------------------------------------------------------------------
// Senha recusada: devolve false com o motivo, sem vazar a senha
// -------------------------------------------------------------------------
$servidor = subirServidorFalso($porta, 'auth_falha');
$enviou = Email::enviar('ana@teste.local', 'Teste', 'corpo');
$recebido = encerrarServidorFalso($servidor);

verificar('senha recusada devolve false', $enviou, false);
verificar('o motivo cita a autenticacao', str_contains(Email::ultimoErro(), 'autenticacao'), true);
verificar('o motivo traz a resposta do servidor', str_contains(Email::ultimoErro(), '535'), true);
verificar('a senha nao aparece no motivo', str_contains(Email::ultimoErro(), base64_encode('segredo-123')), false);
verificar('o cliente nao manda a mensagem sem autenticar', str_contains($recebido, 'MAIL FROM'), false);

// -------------------------------------------------------------------------
// Servidor fora do ar: falha limpa
// -------------------------------------------------------------------------
putenv('AGENDEI_EMAIL_PORTA=' . ($porta + 1));
verificar('servidor fora do ar devolve false', Email::enviar('ana@teste.local', 'Teste', 'corpo'), false);
verificar('o motivo explica a conexao', str_contains(Email::ultimoErro(), 'conectar'), true);
verificar('destinatario invalido e recusado antes de conectar', Email::enviar('nao-e-email', 'Teste', 'corpo'), false);

// -------------------------------------------------------------------------
// API da Brevo: o caminho do Render, com um servidor HTTP falso
// -------------------------------------------------------------------------
$portaApi = random_int(20000, 40000);
$capturaApi = sys_get_temp_dir() . '/agendei_api_falsa.json';
@unlink($capturaApi);
$servidorApi = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $portaApi, __DIR__ . '/api_falsa.php'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipesApi
);
for ($tentativa = 0; $tentativa < 50; $tentativa++) {
    $sonda = @stream_socket_client('tcp://127.0.0.1:' . $portaApi, $codigo, $erro, 0.2);
    if ($sonda !== false) {
        fclose($sonda);
        break;
    }
    usleep(100000);
}

putenv('AGENDEI_EMAIL_HOST=');
putenv('AGENDEI_EMAIL_API_CHAVE=xkeysib-chave-de-teste');
putenv('AGENDEI_EMAIL_API_URL=http://127.0.0.1:' . $portaApi . '/v3/smtp/email');
putenv('AGENDEI_EMAIL_REMETENTE=avisos@agendei.test');
putenv('AGENDEI_EMAIL_NOME=Agendei Avisos');

verificar('so a chave da API ja configura o envio', Email::configurado(), true);
$enviou = Email::enviar('ana@teste.local', 'Assunto pela API', 'texto puro', '<p>html</p>', 'Ana');
$captura = json_decode((string) @file_get_contents($capturaApi), true) ?: [];

verificar('a API aceita o envio', $enviou, true);
verificar('a chamada e um POST', $captura['metodo'] ?? null, 'POST');
verificar('a chamada vai para o endereco da Brevo', $captura['caminho'] ?? null, '/v3/smtp/email');
verificar('a chave vai no cabecalho api-key', $captura['chave'] ?? null, 'xkeysib-chave-de-teste');
verificar('o corpo e JSON', str_starts_with((string) ($captura['tipo'] ?? ''), 'application/json'), true);
verificar('o remetente e o configurado', $captura['corpo']['sender']['email'] ?? null, 'avisos@agendei.test');
verificar('o nome do remetente acompanha', $captura['corpo']['sender']['name'] ?? null, 'Agendei Avisos');
verificar('o destinatario leva nome e e-mail', $captura['corpo']['to'][0] ?? null, ['email' => 'ana@teste.local', 'name' => 'Ana']);
verificar('o assunto vai como esta', $captura['corpo']['subject'] ?? null, 'Assunto pela API');
verificar('as duas versoes vao no corpo', ($captura['corpo']['textContent'] ?? '') === 'texto puro' && ($captura['corpo']['htmlContent'] ?? '') === '<p>html</p>', true);

@unlink($capturaApi);
putenv('AGENDEI_EMAIL_API_CHAVE=chave-errada');
$enviou = Email::enviar('ana@teste.local', 'Assunto', 'texto');
verificar('chave recusada devolve false', $enviou, false);
verificar('o motivo traz o codigo HTTP', str_contains(Email::ultimoErro(), 'HTTP 401'), true);
verificar('o motivo traz a mensagem da API', str_contains(Email::ultimoErro(), 'Key not found'), true);

proc_terminate($servidorApi);
foreach ($pipesApi as $pipe) {
    fclose($pipe);
}
proc_close($servidorApi);

putenv('AGENDEI_EMAIL_API_URL=http://127.0.0.1:' . ($portaApi + 1) . '/v3/smtp/email');
verificar('API fora do ar devolve false', Email::enviar('ana@teste.local', 'Assunto', 'texto'), false);
verificar('o motivo explica a conexao com a API', str_contains(Email::ultimoErro(), 'API de e-mail'), true);

echo "\n";
if ($falhas > 0) {
    echo "FALHOU: {$falhas} de {$total} verificacoes do envio de e-mail.\n";
    exit(1);
}
echo "OK: {$total} verificacoes do envio de e-mail.\n";
