<?php
/** Codigo de confirmacao e de recuperacao (so a sessao, sem banco). Execute com "php tests/verificacao_email.php". */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
define('RAIZ', getcwd());
require_once 'models/VerificacaoEmail.php';
$_SESSION = [];

$falhas = 0;
$ver = function (string $nome, $obtido, $esperado) use (&$falhas) {
    if ($obtido !== $esperado) { $falhas++; echo "FALHA: $nome\n  esperado: " . var_export($esperado, true) . "\n  obtido:   " . var_export($obtido, true) . "\n"; }
};

$codigo = VerificacaoEmail::codigoRecuperacao(7, 'ana@teste.local', 'token-abc');
$ver('codigo tem 6 digitos', (bool) preg_match('/^\d{6}$/', $codigo), true);
$ver('codigo nao fica em texto na sessao', str_contains(serialize($_SESSION), $codigo), false);
$errado = $codigo === '000000' ? '111111' : '000000';
$ver('codigo errado conta tentativa', VerificacaoEmail::conferirRecuperacao($errado), ['erro' => 'Código incorreto. Restam 4 tentativa(s).']);
$ver('codigo certo devolve o token', VerificacaoEmail::conferirRecuperacao(substr($codigo, 0, 3) . ' ' . substr($codigo, 3)), ['token' => 'token-abc']);
$ver('codigo so vale uma vez', VerificacaoEmail::conferirRecuperacao($codigo), ['erro' => 'Peça um código novo.']);

$codigo = VerificacaoEmail::codigoRecuperacao(7, 'ana@teste.local', 'token-abc');
for ($i = 0; $i < VerificacaoEmail::MAX_TENTATIVAS; $i++) VerificacaoEmail::conferirRecuperacao($errado);
$ver('depois de 5 erros nem o certo vale', VerificacaoEmail::conferirRecuperacao($codigo), ['erro' => 'Muitas tentativas erradas. Peça um novo código.']);

$codigo = VerificacaoEmail::codigoRecuperacao(null, 'ninguem@teste.local', null);
$ver('e-mail sem conta nunca confere', VerificacaoEmail::conferirRecuperacao($codigo), ['erro' => 'Código incorreto. Restam 4 tentativa(s).']);

VerificacaoEmail::codigoRecuperacao(7, 'ana@teste.local', 'token-abc');
$_SESSION['codigos_email']['senha']['expira'] = time() - 1;
$ver('codigo vencido', VerificacaoEmail::conferirRecuperacao('123456'), ['erro' => 'O código expirou. Peça um novo.']);
$ver('vencido nao conta como pendente', VerificacaoEmail::recuperacaoPendente(), null);
$ver('mascara o e-mail', VerificacaoEmail::mascarar('maria@teste.local'), 'ma***@teste.local');

echo $falhas === 0 ? "OK: verificacao de e-mail\n" : "$falhas falha(s)\n";
exit($falhas === 0 ? 0 : 1);
