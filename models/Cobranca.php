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

    /** Formas que o cliente escolhe na mensalidade e no avulso. */
    public const METODOS = ['pix' => 'Pix', 'boleto' => 'Boleto', 'cartao' => 'Cartão'];

    /** Formas oferecidas pela empresa: Pix so com chave cadastrada. */
    public static function metodosDisponiveis(): array
    {
        $metodos = self::METODOS;
        if (Configuracao::obter('pix_chave') === '') unset($metodos['pix']);
        return $metodos;
    }

    /** "Pix", "Boleto", "Cartão final 1234"; "-" sem escolha. */
    public static function rotuloPagamento(?string $metodo, ?string $final): string
    {
        if ($metodo === null || $metodo === '') return '-';
        $rotulo = self::METODOS[$metodo] ?? ucfirst($metodo);
        return $metodo === 'cartao' && $final ? "$rotulo final $final" : $rotulo;
    }

    /** Confere a escolha do cliente; devolve o final do cartao (ou null fora do cartao). */
    public static function validarEscolha(string $metodo, ?string $cartaoFinal): ?string
    {
        if (!isset(self::metodosDisponiveis()[$metodo])) {
            throw new InvalidArgumentException('Escolha uma forma de pagamento disponível.');
        }
        if ($metodo !== 'cartao') return null;
        if (!preg_match('/^\d{4}$/', (string) $cartaoFinal)) {
            throw new InvalidArgumentException('Preencha os dados do cartão para continuar.');
        }
        return $cartaoFinal;
    }

    /** Colunas da forma escolhida e 'boleto' nos pagamentos; pode rodar de novo. */
    public static function garantirEstrutura(): void
    {
        Sql::garantirColuna('cobrancas_assinatura_cliente', 'metodo', 'VARCHAR(6) DEFAULT NULL');
        Sql::garantirColuna('cobrancas_assinatura_cliente', 'cartao_final', 'CHAR(4) DEFAULT NULL');
        Sql::garantirColuna('pagamentos', 'cartao_final', 'CHAR(4) DEFAULT NULL');
        $db = bd();
        if (Database::ehPostgres()) {
            $definicao = (string) $db->query("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'ck_pagamento_metodo'")->fetchColumn();
            if ($definicao !== '' && !str_contains($definicao, 'boleto')) {
                $db->exec('ALTER TABLE pagamentos DROP CONSTRAINT ck_pagamento_metodo');
                $db->exec("ALTER TABLE pagamentos ADD CONSTRAINT ck_pagamento_metodo CHECK (metodo IN ('pix','dinheiro','cartao','outro','boleto'))");
            }
            return;
        }
        $ddl = $db->query('SHOW CREATE TABLE pagamentos')->fetch(PDO::FETCH_NUM)[1] ?? '';
        if (!str_contains($ddl, "'boleto'")) {
            $db->exec("ALTER TABLE pagamentos MODIFY metodo ENUM('pix','dinheiro','cartao','outro','boleto') NOT NULL DEFAULT 'pix'");
        }
    }

    /** Codigo "copia e cola" do Pix (BR Code estatico com valor). */
    /** Sem $chave/$nome recebe o Agendei; a mensalidade de cliente passa os da empresa. */
    public static function pix(float $valor, string $identificador, ?string $chave = null, ?string $nome = null): string
    {
        if ($chave !== null) {
            // Chave salva antes da validacao pode estar com mascara: normaliza se der.
            try { $chave = self::normalizarChavePix($chave); } catch (InvalidArgumentException) {}
        }
        $chave  = trim((string) ($chave ?? getenv('AGENDEI_PIX_CHAVE'))) ?: self::CHAVE_DEMO;
        $nome   = self::ascii($nome ?? (getenv('AGENDEI_PIX_NOME') ?: 'AGENDEI'), 25);
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
     * Poe a chave Pix digitada na forma que o banco procura: e-mail em
     * minusculas, CPF/CNPJ so com digitos, celular como +55DDDNUMERO e chave
     * aleatoria em minusculas. Com mascara, o QR teria CRC valido e o banco
     * nao acharia a chave. 11 digitos soltos: CPF se o digito verificador
     * fechar, senao celular. Lanca InvalidArgumentException se nao reconhecer.
     */
    public static function normalizarChavePix(string $chave): string
    {
        $chave = trim($chave);
        if ($chave === '') return '';
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $chave)) {
            return strtolower($chave);
        }
        if (str_contains($chave, '@')) {
            $email = mb_strtolower(preg_replace('/\s+/', '', $chave));
            if (strlen($email) > 77 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Chave Pix de e-mail inválida (até 77 caracteres).');
            }
            return $email;
        }
        $digitos = preg_replace('/\D/', '', $chave);
        $pareceTelefone = str_starts_with($chave, '+') || str_contains($chave, '(');
        if (!$pareceTelefone && strlen($digitos) === 11 && self::cpfValido($digitos)) return $digitos;
        if (!$pareceTelefone && strlen($digitos) === 14) return $digitos;
        if (in_array(strlen($digitos), [12, 13], true) && str_starts_with($digitos, '55')) return '+' . $digitos;
        if (in_array(strlen($digitos), [10, 11], true)) return '+55' . $digitos;
        throw new InvalidArgumentException('Chave Pix não reconhecida. Use e-mail, CPF, CNPJ, celular com DDD ou a chave aleatória.');
    }

    private static function cpfValido(string $cpf): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $cpf)) return false;
        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) $soma += (int) $cpf[$i] * ($t + 1 - $i);
            if ((int) $cpf[$t] !== ((10 * $soma) % 11) % 10) return false;
        }
        return true;
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
