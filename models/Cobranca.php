<?php

/**
 * Cobranca da assinatura em modo de demonstracao: Pix com QR de verdade e
 * boleto ilustrativo. Nao ha gateway; quem libera o acesso continua sendo o
 * financeiro (Assinatura::confirmarPagamento).
 *
 * O Pix segue o BR Code do Banco Central (EMV, campo 26 + CRC16), entao o QR
 * abre em qualquer app de banco. A chave vem de AGENDEI_PIX_CHAVE; sem ela usa
 * uma chave aleatoria nula, que o banco recusa — e o que a demonstracao quer.
 * shortcut: boleto e cartao sao simulados; trocar por um gateway (Asaas,
 * Mercado Pago) quando houver cobranca real.
 */
class Cobranca
{
    private const CHAVE_DEMO = '00000000-0000-0000-0000-000000000000';

    /** Codigo "copia e cola" do Pix (BR Code estatico com valor). */
    public static function pix(float $valor, string $identificador): string
    {
        $chave  = trim((string) getenv('AGENDEI_PIX_CHAVE')) ?: self::CHAVE_DEMO;
        $nome   = self::ascii(getenv('AGENDEI_PIX_NOME') ?: 'AGENDEI', 25);
        $cidade = self::ascii(getenv('AGENDEI_PIX_CIDADE') ?: 'SAO PAULO', 15);
        $txid   = substr(preg_replace('/[^A-Za-z0-9]/', '', $identificador), 0, 25) ?: '***';

        $codigo = self::campo('00', '01')
            . self::campo('26', self::campo('00', 'br.gov.bcb.pix') . self::campo('01', $chave))
            . self::campo('52', '0000')
            . self::campo('53', '986')
            . self::campo('54', number_format($valor, 2, '.', ''))
            . self::campo('58', 'BR')
            . self::campo('59', $nome)
            . self::campo('60', $cidade)
            . self::campo('62', self::campo('05', $txid))
            . '6304';

        return $codigo . self::crc16($codigo);
    }

    /**
     * Boleto de demonstracao: banco 999 (inexistente), vencimento em 3 dias e o
     * valor no fim da linha, como num boleto real. Nao paga em banco nenhum.
     * @return array{linha: string, vencimento: string}
     */
    public static function boleto(float $valor, int $idEstabelecimento, ?int $hoje = null): array
    {
        $vencimento = ($hoje ?? time()) + 3 * 86400;
        // Fator de vencimento (FEBRABAN, base reiniciada em 22/02/2025 = 1000).
        $fator = 1000 + intdiv($vencimento - strtotime('2025-02-22 00:00:00'), 86400);

        return [
            'linha' => sprintf('99990.%05d 00000.000000 00000.000000 0 %04d%010d',
                $idEstabelecimento % 100000, $fator % 10000, (int) round($valor * 100)),
            'vencimento' => date('Y-m-d', $vencimento),
        ];
    }

    /** CRC16-CCITT (polinomio 0x1021, inicio 0xFFFF), exigido no campo 63 do BR Code. */
    public static function crc16(string $texto): string
    {
        $crc = 0xFFFF;
        foreach (str_split($texto) as $byte) {
            $crc ^= ord($byte) << 8;
            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }
        return sprintf('%04X', $crc);
    }

    private static function campo(string $id, string $valor): string
    {
        return $id . sprintf('%02d', strlen($valor)) . $valor;
    }

    /** Nome e cidade do recebedor: so ASCII e com o tamanho maximo do BR Code. */
    private static function ascii(string $texto, int $maximo): string
    {
        $texto = strtr($texto, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c',
                                'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C']);
        return substr(strtoupper(preg_replace('/[^A-Za-z0-9 ]/', '', $texto)), 0, $maximo);
    }
}
