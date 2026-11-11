<?php
/** Confere o BR Code do Pix e o boleto de demonstracao. Execute com "php tests/cobranca.php". */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require_once 'models/Cobranca.php';
require_once 'models/QrCode.php';
function e(?string $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

$falhas = 0;
$ver = function (string $nome, $obtido, $esperado) use (&$falhas) {
    if ($obtido !== $esperado) { $falhas++; echo "FALHA: $nome\n  esperado: " . var_export($esperado, true) . "\n  obtido:   " . var_export($obtido, true) . "\n"; }
};

$ver('CRC16-CCITT do vetor padrao', Cobranca::crc16('123456789'), '29B1');
// Exemplo do manual do BR Code (Banco Central).
$exemplo = '00020126580014br.gov.bcb.pix0136123e4567-e12b-12d1-a456-4266554400005204000053039865802BR5913Fulano de Tal6008BRASILIA62070503***6304';
$ver('CRC do exemplo do Banco Central', Cobranca::crc16($exemplo), '1D3D');

putenv('AGENDEI_PIX_CHAVE=123e4567-e12b-12d1-a456-426655440000');
putenv('AGENDEI_PIX_NOME=Agendei Tecnologia Ltda');
putenv('AGENDEI_PIX_CIDADE=São Paulo');
$pix = Cobranca::pix(149.0, 'AGD-12');
$ver('Pix leva o valor', str_contains($pix, '5406149.00'), true);
$ver('Pix leva a cidade sem acento', str_contains($pix, '6009SAO PAULO'), true);
$ver('Pix leva o txid', str_contains($pix, '0505AGD12'), true);
$ver('Pix termina com o CRC certo', substr($pix, -4), Cobranca::crc16(substr($pix, 0, -4)));
$ver('QR do Pix e gerado', str_starts_with(QrCode::svg($pix), '<svg'), true);

// Mensalidade que o estabelecimento cobra do cliente: chave e nome da empresa, nao os do Agendei.
$daEmpresa = Cobranca::pix(99.0, 'AC7', 'chave@empresa.local', 'Barbearia Ção');
$ver('Pix da empresa leva a chave dela', str_contains($daEmpresa, '0119chave@empresa.local'), true);
$ver('Pix da empresa leva o nome dela sem acento', str_contains($daEmpresa, '5913BARBEARIA CAO'), true);
$ver('Pix da empresa nao leva a chave do Agendei', str_contains($daEmpresa, '123e4567'), false);
$ver('Pix da empresa leva o txid', str_contains($daEmpresa, '0503AC7'), true);
$ver('Pix da empresa termina com o CRC certo', substr($daEmpresa, -4), Cobranca::crc16(substr($daEmpresa, 0, -4)));
// A chave digitada pela empresa vira a forma que o banco procura no DICT.
foreach ([
    ' Pix@Empresa.COM ' => 'pix@empresa.com',
    '123.456.789-09' => '12345678909',
    '12345678909' => '12345678909',
    '(11) 98888-7777' => '+5511988887777',
    '11988887777' => '+5511988887777',
    '+55 11 98888-7777' => '+5511988887777',
    '12.345.678/0001-95' => '12345678000195',
    '123E4567-E12B-12D1-A456-426655440000' => '123e4567-e12b-12d1-a456-426655440000',
    '' => '',
] as $digitada => $esperada) {
    $ver("Chave Pix '$digitada'", Cobranca::normalizarChavePix($digitada), $esperada);
}
foreach (['abc', '123', str_repeat('a', 70) . '@x.com.br'] as $invalida) {
    try { Cobranca::normalizarChavePix($invalida); $ver("Chave invalida '$invalida' recusada", 'aceita', 'recusada'); }
    catch (InvalidArgumentException $e) { $ver("Chave invalida recusada", true, true); }
}
$ver('Pix da empresa usa a chave normalizada', str_contains(Cobranca::pix(10.0, 'AC1', '(11) 98888-7777', 'X'), '0114+5511988887777'), true);
$ver('Sem chave e nome continua o do Agendei',Cobranca::pix(149.0, 'AGD-12', null, null), $pix);

$boleto = Cobranca::boleto(149.0, 12, strtotime('2026-11-09 10:00:00'));
$ver('Vencimento em 3 dias', $boleto['vencimento'], '2026-11-12');
$ver('Linha do boleto', $boleto['linha'], '99990.00012 00000.000000 00000.000000 0 16280000014900');

echo $falhas === 0 ? "OK: cobranca\n" : "$falhas falha(s)\n";
exit($falhas === 0 ? 0 : 1);
