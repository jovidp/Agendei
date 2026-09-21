<?php

/**
 * Teste do segundo fator por codigo (TOTP).
 *
 * A parte central confere a implementacao contra os vetores oficiais das
 * RFC 4226 (HOTP) e RFC 6238 (TOTP): se o codigo gerado aqui bate com o da
 * norma, ele tambem bate com o que qualquer aplicativo autenticador mostra.
 *
 * Nao depende de banco: execute com "php tests/totp.php".
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(dirname(__DIR__));

define('AMBIENTE', 'desenvolvimento');
define('RAIZ', __DIR__ . '/..');

require_once 'models/Totp.php';

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

// O segredo das duas normas e a cadeia ASCII "12345678901234567890".
$segredoRfc = Totp::base32Codificar('12345678901234567890');

// ---------------------------------------------------------------------
// Base32 (RFC 4648)
// ---------------------------------------------------------------------
verificar('Base32 do segredo das RFCs', $segredoRfc, 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
verificar('Base32 ida e volta', Totp::base32Decodificar($segredoRfc), '12345678901234567890');
verificar('Base32 de "f"', Totp::base32Codificar('f'), 'MY');
verificar('Base32 de "fo"', Totp::base32Codificar('fo'), 'MZXQ');
verificar('Base32 de "foo"', Totp::base32Codificar('foo'), 'MZXW6');
verificar('Base32 de "foob"', Totp::base32Codificar('foob'), 'MZXW6YQ');
verificar('Base32 de "fooba"', Totp::base32Codificar('fooba'), 'MZXW6YTB');
verificar('Base32 de "foobar"', Totp::base32Codificar('foobar'), 'MZXW6YTBOI');
verificar('decodificar aceita espacos e minusculas', Totp::base32Decodificar('mzxw 6ytb oi'), 'foobar');
verificar('decodificar recusa alfabeto invalido', Totp::base32Decodificar('!!!'), '');

// ---------------------------------------------------------------------
// HOTP - RFC 4226, apendice D
// Mesmo segredo, contadores de 0 a 9.
// ---------------------------------------------------------------------
$esperadosHotp = [
    0 => '755224', 1 => '287082', 2 => '359152', 3 => '969429', 4 => '338314',
    5 => '254676', 6 => '287922', 7 => '162583', 8 => '399871', 9 => '520489',
];

foreach ($esperadosHotp as $contador => $esperado) {
    verificar("RFC 4226: contador {$contador}", Totp::codigo($segredoRfc, $contador), $esperado);
}

// ---------------------------------------------------------------------
// TOTP - RFC 6238, apendice B
// A norma publica oito digitos; o sistema usa seis, que sao os finais.
// ---------------------------------------------------------------------
$esperadosTotp = [
    59          => '94287082',
    1111111109  => '07081804',
    1111111111  => '14050471',
    1234567890  => '89005924',
    2000000000  => '69279037',
    20000000000 => '65353130',
];

foreach ($esperadosTotp as $instante => $oitoDigitos) {
    verificar(
        "RFC 6238: instante {$instante}",
        Totp::codigo($segredoRfc, Totp::contador($instante)),
        substr($oitoDigitos, -6)
    );
}

// ---------------------------------------------------------------------
// Contador e janela
// ---------------------------------------------------------------------
verificar('contador do instante 0', Totp::contador(0), 0);
verificar('contador do instante 29', Totp::contador(29), 0);
verificar('contador do instante 30', Totp::contador(30), 1);
verificar('contador do instante 59', Totp::contador(59), 1);
verificar('segundos restantes no inicio da janela', Totp::segundosRestantes(0), 30);
verificar('segundos restantes no fim da janela', Totp::segundosRestantes(29), 1);

// ---------------------------------------------------------------------
// Conferencia
// ---------------------------------------------------------------------
$segredo = Totp::gerarSegredo();
$agora = Totp::contador();

verificar('codigo do momento e aceito', Totp::confere($segredo, Totp::codigo($segredo, $agora)), true);
verificar('codigo da janela anterior e aceito', Totp::confere($segredo, Totp::codigo($segredo, $agora - 1)), true);
verificar('codigo da janela seguinte e aceito', Totp::confere($segredo, Totp::codigo($segredo, $agora + 1)), true);
verificar('codigo de duas janelas atras e recusado', Totp::confere($segredo, Totp::codigo($segredo, $agora - 2)), false);
verificar('codigo de duas janelas a frente e recusado', Totp::confere($segredo, Totp::codigo($segredo, $agora + 2)), false);

verificar('codigo de outro segredo e recusado', Totp::confere($segredo, Totp::codigo(Totp::gerarSegredo(), $agora)), false);
verificar('codigo vazio e recusado', Totp::confere($segredo, ''), false);
verificar('codigo curto e recusado', Totp::confere($segredo, '12345'), false);
verificar('codigo longo e recusado', Totp::confere($segredo, '1234567'), false);
verificar('texto no lugar do codigo e recusado', Totp::confere($segredo, 'abcdef'), false);
verificar('segredo vazio recusa qualquer codigo', Totp::confere('', '123456'), false);

// O aplicativo costuma mostrar o codigo com um espaco no meio.
$comEspaco = substr(Totp::codigo($segredo, $agora), 0, 3) . ' ' . substr(Totp::codigo($segredo, $agora), 3);
verificar('codigo digitado com espaco e aceito', Totp::confere($segredo, $comEspaco), true);

// A janela que casou volta pelo parametro, para bloquear a reapresentacao.
$contadorUsado = null;
Totp::confere($segredo, Totp::codigo($segredo, $agora), $contadorUsado);
verificar('a janela usada e devolvida', $contadorUsado, $agora);

$contadorUsado = 999;
Totp::confere($segredo, '000000', $contadorUsado);
verificar('codigo recusado nao devolve janela', $contadorUsado, null);

// ---------------------------------------------------------------------
// Formato do segredo e do endereco do aplicativo
// ---------------------------------------------------------------------
verificar('segredo tem 32 caracteres', strlen(Totp::gerarSegredo()), 32);
verificar('segredo usa so o alfabeto Base32', (bool) preg_match('/^[A-Z2-7]+$/D', Totp::gerarSegredo()), true);
verificar('dois segredos seguidos sao diferentes', Totp::gerarSegredo() === Totp::gerarSegredo(), false);
verificar('segredo formatado em blocos de quatro', Totp::formatarSegredo('ABCDEFGH'), 'ABCD EFGH');

$uri = Totp::uri('JBSWY3DPEHPK3PXP', 'maria@exemplo.br', 'Agendei Barbearia');
verificar('endereco comeca com otpauth', str_starts_with($uri, 'otpauth://totp/'), true);
verificar('endereco leva o segredo', str_contains($uri, 'secret=JBSWY3DPEHPK3PXP'), true);
verificar('endereco leva o emissor', str_contains($uri, 'issuer=Agendei%20Barbearia'), true);
verificar('endereco declara 6 digitos', str_contains($uri, 'digits=6'), true);
verificar('endereco declara janela de 30s', str_contains($uri, 'period=30'), true);
verificar('rotulo traz emissor e conta', str_contains($uri, 'Agendei%20Barbearia:maria%40exemplo.br'), true);

// ---------------------------------------------------------------------
// Guarda cifrada do segredo
// ---------------------------------------------------------------------
putenv('AGENDEI_CHAVE_TOTP=chave-de-teste-bem-longa-para-o-exemplo');

$original = Totp::gerarSegredo();
$cifrado = Totp::cifrar($original);

verificar('o cifrado nao contem o segredo', str_contains($cifrado, $original), false);
verificar('o cifrado se identifica pela versao', str_starts_with($cifrado, 'v1.'), true);
verificar('decifrar devolve o segredo', Totp::decifrar($cifrado), $original);
verificar('cifrar duas vezes nao repete o resultado', Totp::cifrar($original) === Totp::cifrar($original), false);

// Conteudo adulterado no banco e recusado, em vez de virar segredo errado.
$adulterado = 'v1.' . base64_encode(substr(base64_decode(substr($cifrado, 3)), 0, -1) . 'X');
verificar('cifrado adulterado e recusado', Totp::decifrar($adulterado), '');
verificar('cifrado com chave errada e recusado', (function () use ($cifrado) {
    putenv('AGENDEI_CHAVE_TOTP=outra-chave-completamente-diferente');
    $resultado = Totp::decifrar($cifrado);
    putenv('AGENDEI_CHAVE_TOTP=chave-de-teste-bem-longa-para-o-exemplo');
    return $resultado;
})(), '');

verificar('valor vazio devolve vazio', Totp::decifrar(''), '');
verificar('valor nulo devolve vazio', Totp::decifrar(null), '');
verificar('lixo sem versao devolve vazio', Totp::decifrar('nao-e-base32!'), '');

// Segredo gravado antes da cifragem continua sendo lido.
verificar('Base32 puro e aceito por compatibilidade', Totp::decifrar('GEZDGNBVGY3TQOJQ'), 'GEZDGNBVGY3TQOJQ');

// O ciclo completo: cifra, guarda, le e o codigo ainda confere.
$guardado = Totp::cifrar($original);
verificar(
    'codigo gerado apos o ciclo de cifragem confere',
    Totp::confere(Totp::decifrar($guardado), Totp::codigo($original)),
    true
);

// ---------------------------------------------------------------------
// Escolha do fator: quem tem aplicativo cadastrado sempre cai no codigo
//
// Estas funcoes decidem qual desafio aparece no login. Cabem aqui porque a
// decisao depende so do registro recebido, sem consultar o banco.
// ---------------------------------------------------------------------
require_once 'includes/funcoes.php';
require_once 'includes/auth.php';
require_once 'models/Usuario.php';
require_once 'models/Master.php';

putenv('AGENDEI_CHAVE_TOTP=chave-de-teste-bem-longa-para-o-exemplo');

$segredoConta = Totp::gerarSegredo();
$cifrado = Totp::cifrar($segredoConta);

// Conta comum com os tres dados cadastrais e sem aplicativo.
$semApp = [
    'id_usuario' => 1, 'tipo' => 'cliente',
    'nome_materno' => 'Maria Silva', 'data_nascimento' => '1990-03-10', 'cep' => '01310100',
    'totp_segredo' => null, 'totp_ativado_em' => null,
];

// Mesma conta, com segredo guardado mas ainda nao confirmado.
$appPendente = ['totp_segredo' => $cifrado, 'totp_ativado_em' => null] + $semApp;

// Mesma conta, com o aplicativo confirmado.
$appAtivo = ['totp_segredo' => $cifrado, 'totp_ativado_em' => '2026-09-21 10:00:00'] + $semApp;

verificar('sem segredo o app nao esta ativo', Usuario::totpAtivo($semApp), false);
verificar('segredo sem confirmacao nao ativa', Usuario::totpAtivo($appPendente), false);
verificar('segredo confirmado ativa', Usuario::totpAtivo($appAtivo), true);
verificar('segredo aberto volta igual', Usuario::totpSegredo($appAtivo), $segredoConta);
verificar('conta sem segredo devolve vazio', Usuario::totpSegredo($semApp), '');

// Data de ativacao presente, porem segredo ilegivel (chave trocada no ambiente):
// a conta nao pode ser dada como protegida, ou ninguem mais entraria nela.
$appQuebrado = ['totp_segredo' => 'v1.' . base64_encode(str_repeat('x', 40)), 'totp_ativado_em' => '2026-09-21 10:00:00'] + $semApp;
verificar('segredo ilegivel nao conta como ativo', Usuario::totpAtivo($appQuebrado), false);

// exigeSegundoFator: com aplicativo, qualquer perfil passa pelo desafio.
verificar('cliente com dados exige 2FA', exigeSegundoFator($semApp), true);
verificar('cliente com app exige 2FA', exigeSegundoFator($appAtivo), true);

$profissionalSemNada = ['id_usuario' => 2, 'tipo' => 'profissional', 'totp_segredo' => null, 'totp_ativado_em' => null];
verificar('profissional sem nada nao exige 2FA', exigeSegundoFator($profissionalSemNada), false);

$profissionalComApp = ['totp_segredo' => $cifrado, 'totp_ativado_em' => '2026-09-21 10:00:00'] + $profissionalSemNada;
verificar('profissional com app exige 2FA', exigeSegundoFator($profissionalComApp), true);

$clienteSemDados = ['id_usuario' => 3, 'tipo' => 'cliente', 'totp_segredo' => null, 'totp_ativado_em' => null];
verificar('cliente sem dado nenhum nao exige 2FA', exigeSegundoFator($clienteSemDados), false);

// iniciarSegundoFator: o aplicativo sempre ganha da pergunta cadastral.
$_SESSION = [];
iniciarSegundoFator($appAtivo, 'teste');
verificar('com app o fator escolhido e totp', $_SESSION['segundo_fator']['fator'], 'totp');
verificar('o desafio comeca com zero tentativas', $_SESSION['segundo_fator']['tentativas'], 0);

$_SESSION = [];
iniciarSegundoFator($appPendente, 'teste');
verificar('app pendente ainda cai na pergunta', in_array($_SESSION['segundo_fator']['fator'], ['nome_materno', 'data_nascimento', 'cep'], true), true);

$_SESSION = [];
iniciarSegundoFator($semApp, 'teste');
verificar('sem app cai na pergunta cadastral', in_array($_SESSION['segundo_fator']['fator'], ['nome_materno', 'data_nascimento', 'cep'], true), true);

verificar('totp e reconhecido como codigo', segundoFatorEhCodigo('totp'), true);
verificar('cep nao e codigo', segundoFatorEhCodigo('cep'), false);
verificar('fator nulo nao e codigo', segundoFatorEhCodigo(null), false);

// A conta master usa a mesma mecanica, em tabela propria.
$masterSemApp = ['id_master' => 1, 'totp_segredo' => null, 'totp_ativado_em' => null];
$masterComApp = ['id_master' => 1, 'totp_segredo' => $cifrado, 'totp_ativado_em' => '2026-09-21 10:00:00'];

verificar('master sem app nao esta ativo', Master::totpAtivo($masterSemApp), false);
verificar('master com app esta ativo', Master::totpAtivo($masterComApp), true);
verificar('segredo do master volta igual', Master::totpSegredo($masterComApp), $segredoConta);

// O desafio master e independente do desafio das contas comuns.
$_SESSION = [];
verificar('sem desafio master pendente', segundoFatorMasterPendente(), null);
iniciarSegundoFatorMaster($masterComApp);
verificar('desafio master guardado', segundoFatorMasterPendente()['master_id'], 1);
verificar('master comeca com 3 tentativas', tentativasRestantesSegundoFatorMaster(), 3);
$_SESSION['segundo_fator_master']['tentativas'] = 2;
verificar('tentativas master descontam', tentativasRestantesSegundoFatorMaster(), 1);
cancelarSegundoFatorMaster();
verificar('desafio master cancelado', segundoFatorMasterPendente(), null);

// ---------------------------------------------------------------------
echo "\n{$total} verificacao(oes), {$falhas} falha(s).\n";
exit($falhas === 0 ? 0 : 1);
