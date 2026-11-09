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

$boleto = Cobranca::boleto(149.0, 12, strtotime('2026-11-09 10:00:00'));
$ver('Vencimento em 3 dias', $boleto['vencimento'], '2026-11-12');
$ver('Linha do boleto', $boleto['linha'], '99990.00012 00000.000000 00000.000000 0 16280000014900');

echo $falhas === 0 ? "OK: cobranca\n" : "$falhas falha(s)\n";
exit($falhas === 0 ? 0 : 1);
