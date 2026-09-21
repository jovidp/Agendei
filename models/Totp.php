<?php

/**
 * Segundo fator por codigo de uso unico (TOTP, RFC 6238).
 *
 * O aplicativo autenticador do usuario (Google Authenticator, Authy, 2FAS,
 * Microsoft Authenticator...) e o servidor guardam o mesmo segredo e derivam
 * dele um codigo de seis digitos que muda a cada 30 segundos. Nada trafega pela
 * rede na hora de conferir: nao ha e-mail para enviar, SMS para pagar nem
 * servidor externo para depender.
 *
 * Implementado em PHP nativo de proposito — o projeto nao usa Composer, e o
 * algoritmo cabe em poucas dezenas de linhas sobre hash_hmac().
 *
 * O segredo e guardado cifrado no banco (ver cifrar/decifrar): se o banco
 * vazar, os segredos nao servem para gerar codigo sem a chave da aplicacao.
 */
class Totp
{
    /** Digitos do codigo mostrado pelo aplicativo. */
    public const DIGITOS = 6;

    /** Duracao de cada codigo, em segundos. Trinta e o padrao dos aplicativos. */
    public const JANELA = 30;

    /**
     * Janelas aceitas para tras e para frente.
     *
     * O relogio do celular quase nunca bate exatamente com o do servidor. Uma
     * janela de tolerancia cobre ate 30 segundos de diferenca para cada lado,
     * que e o suficiente na pratica sem alargar demais a validade do codigo.
     */
    public const TOLERANCIA = 1;

    // -----------------------------------------------------------------
    // Segredo
    // -----------------------------------------------------------------

    /**
     * Sorteia um segredo novo, em Base32 — o formato que os aplicativos leem.
     *
     * Vinte bytes (160 bits) e o tamanho recomendado pela RFC 4226 para o
     * HMAC-SHA1, e e o que os aplicativos esperam.
     */
    public static function gerarSegredo(int $bytes = 20): string
    {
        return self::base32Codificar(random_bytes($bytes));
    }

    /** Apresenta o segredo em blocos de quatro, para quem digita na mao. */
    public static function formatarSegredo(string $segredo): string
    {
        return trim(chunk_split($segredo, 4, ' '));
    }

    /**
     * Endereco que o aplicativo entende, usado no QR code e no link direto.
     *
     * O emissor aparece duas vezes de proposito: no rotulo, porque os
     * aplicativos antigos so leem dali, e no parametro issuer, que e o que os
     * atuais usam para agrupar as contas.
     */
    public static function uri(string $segredo, string $conta, string $emissor): string
    {
        // O endereco vira um QR code, e o QR tem limite de tamanho. O emissor
        // aparece duas vezes e um nome comprido de estabelecimento — ainda mais
        // depois de escapado, onde cada espaco vira tres caracteres — estoura a
        // maior versao que o gerador monta. Cortar aqui mantem o QR possivel; o
        // nome truncado so muda o rotulo mostrado no aplicativo, nada mais.
        $emissor = trim(mb_substr(trim($emissor), 0, 40));
        $conta   = trim(mb_substr(trim($conta), 0, 64));

        $rotulo = rawurlencode($emissor) . ':' . rawurlencode($conta);

        return 'otpauth://totp/' . $rotulo . '?' . http_build_query([
            'secret'    => $segredo,
            'issuer'    => $emissor,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITOS,
            'period'    => self::JANELA,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    // -----------------------------------------------------------------
    // Geracao e conferencia do codigo
    // -----------------------------------------------------------------

    /** Numero da janela de 30 segundos em que o instante cai. */
    public static function contador(?int $instante = null): int
    {
        return intdiv($instante ?? time(), self::JANELA);
    }

    /**
     * Codigo correspondente a uma janela.
     *
     * E o HOTP da RFC 4226: HMAC-SHA1 do contador em oito bytes big-endian,
     * seguido do "truncamento dinamico" — os quatro ultimos bits do hash dizem
     * de onde tirar os quatro bytes que viram o numero.
     */
    public static function codigo(string $segredo, ?int $contador = null): string
    {
        $chave = self::base32Decodificar($segredo);
        if ($chave === '') {
            return '';
        }

        $contador ??= self::contador();

        // pack('J') exige 64 bits big-endian; montar a mao mantem o mesmo
        // resultado em qualquer plataforma, inclusive PHP de 32 bits.
        $bytes = '';
        for ($i = 7; $i >= 0; $i--) {
            $bytes .= chr(($contador >> ($i * 8)) & 0xFF);
        }

        $hash = hash_hmac('sha1', $bytes, $chave, true);
        $deslocamento = ord($hash[19]) & 0x0F;

        $numero = ((ord($hash[$deslocamento]) & 0x7F) << 24)
            | ((ord($hash[$deslocamento + 1]) & 0xFF) << 16)
            | ((ord($hash[$deslocamento + 2]) & 0xFF) << 8)
            | (ord($hash[$deslocamento + 3]) & 0xFF);

        return str_pad((string) ($numero % (10 ** self::DIGITOS)), self::DIGITOS, '0', STR_PAD_LEFT);
    }

    /**
     * Confere o codigo digitado contra as janelas aceitas.
     *
     * $contadorUsado devolve a janela que casou, para que o chamador possa
     * recusar a reapresentacao do mesmo codigo (ver Usuario::totpContadorUsado).
     * Sem esse cuidado, um codigo capturado continuaria valendo pelos segundos
     * que restassem da janela.
     */
    public static function confere(string $segredo, string $codigo, ?int &$contadorUsado = null): bool
    {
        $contadorUsado = null;
        $digitado = preg_replace('/\D/', '', $codigo) ?? '';

        if (strlen($digitado) !== self::DIGITOS || $segredo === '') {
            return false;
        }

        $atual = self::contador();

        for ($desvio = -self::TOLERANCIA; $desvio <= self::TOLERANCIA; $desvio++) {
            $contador = $atual + $desvio;
            // hash_equals evita que o tempo de resposta revele quantos digitos
            // iniciais do codigo estavam certos.
            if (hash_equals(self::codigo($segredo, $contador), $digitado)) {
                $contadorUsado = $contador;
                return true;
            }
        }

        return false;
    }

    /** Segundos que faltam para o codigo atual expirar, para mostrar na tela. */
    public static function segundosRestantes(?int $instante = null): int
    {
        return self::JANELA - (($instante ?? time()) % self::JANELA);
    }

    // -----------------------------------------------------------------
    // Guarda do segredo no banco
    // -----------------------------------------------------------------

    /**
     * Chave usada para cifrar os segredos, vinda do ambiente.
     *
     * Sem AGENDEI_CHAVE_TOTP definida, cai para uma chave derivada das
     * credenciais do banco: nao e ideal, mas mantem o sistema funcionando em
     * quem atualizar o codigo sem ler a documentacao. O aviso no log existe
     * para que isso nao passe despercebido em producao.
     */
    private static function chave(): string
    {
        $doAmbiente = (string) (getenv('AGENDEI_CHAVE_TOTP') ?: '');

        if ($doAmbiente !== '') {
            return hash('sha256', $doAmbiente, true);
        }

        if (AMBIENTE === 'producao') {
            error_log('[seguranca] AGENDEI_CHAVE_TOTP nao definida: segredos de 2FA cifrados com chave derivada.');
        }

        return hash('sha256', 'agendei-totp|' . (getenv('AGENDEI_DB_PASS') ?: '') . '|' . RAIZ, true);
    }

    /**
     * Cifra o segredo para gravar no banco.
     *
     * AES-256-GCM porque, alem de esconder, ele autentica: um segredo alterado
     * dentro do banco e recusado na leitura em vez de virar codigo invalido
     * silencioso. O nonce vai junto do texto cifrado, como e de praxe.
     */
    public static function cifrar(string $segredo): string
    {
        $nonce = random_bytes(12);
        $etiqueta = '';

        $cifrado = openssl_encrypt($segredo, 'aes-256-gcm', self::chave(), OPENSSL_RAW_DATA, $nonce, $etiqueta);

        if ($cifrado === false) {
            throw new RuntimeException('Nao foi possivel proteger o segredo do segundo fator.');
        }

        return 'v1.' . base64_encode($nonce . $etiqueta . $cifrado);
    }

    /** Recupera o segredo gravado. Devolve string vazia quando nao da para ler. */
    public static function decifrar(?string $guardado): string
    {
        $guardado = (string) $guardado;

        if ($guardado === '') {
            return '';
        }

        // Um segredo gravado antes desta protecao entra em Base32 puro: aceita,
        // para que ninguem perca o 2FA ja cadastrado numa atualizacao.
        if (!str_starts_with($guardado, 'v1.')) {
            return preg_match('/^[A-Z2-7]+$/D', $guardado) ? $guardado : '';
        }

        $bruto = base64_decode(substr($guardado, 3), true);
        if ($bruto === false || strlen($bruto) < 29) {
            return '';
        }

        $aberto = openssl_decrypt(
            substr($bruto, 28),
            'aes-256-gcm',
            self::chave(),
            OPENSSL_RAW_DATA,
            substr($bruto, 0, 12),
            substr($bruto, 12, 16)
        );

        return $aberto === false ? '' : $aberto;
    }

    // -----------------------------------------------------------------
    // Base32 (RFC 4648, sem preenchimento)
    // -----------------------------------------------------------------

    /** Alfabeto do Base32: as letras maiusculas e os digitos de 2 a 7. */
    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Converte bytes crus no texto que o aplicativo autenticador aceita. */
    public static function base32Codificar(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        // Sobra de bits vira um grupo completo com zeros a direita.
        $bits = str_pad($bits, (int) (ceil(strlen($bits) / 5) * 5), '0', STR_PAD_RIGHT);

        $texto = '';
        foreach (str_split($bits, 5) as $grupo) {
            $texto .= self::ALFABETO[bindec($grupo)];
        }

        return $texto;
    }

    /** Caminho inverso. Ignora espacos e caixa, como os aplicativos fazem. */
    public static function base32Decodificar(string $base32): string
    {
        $limpo = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $base32) ?? '');

        if ($limpo === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($limpo) as $caractere) {
            $posicao = strpos(self::ALFABETO, $caractere);
            if ($posicao === false) {
                return '';
            }
            $bits .= str_pad(decbin($posicao), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $grupo) {
            // O ultimo grupo pode vir incompleto: sao os bits de enchimento.
            if (strlen($grupo) === 8) {
                $bytes .= chr(bindec($grupo));
            }
        }

        return $bytes;
    }
}
