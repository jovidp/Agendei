<?php

/**
 * Envio de e-mail por SMTP, sem dependencias.
 *
 * Hospedagens como Render e Koyeb nao tem servidor de e-mail, e mail() nao
 * funciona nelas. O sistema fala SMTP direto com um provedor (Gmail com senha
 * de aplicativo, Brevo, Mailgun, SendGrid, o SMTP da propria hospedagem...),
 * que e o que existe em qualquer lugar.
 *
 * A configuracao segue a do banco: variaveis de ambiente primeiro, porque e
 * assim que a hospedagem injeta segredo; depois config/email.local.php, que
 * nao e versionado. Sem configuracao o sistema segue funcionando: enviar()
 * devolve false, o motivo vai para o log do servidor e as telas mostram o
 * caminho manual (o link na tela, o telefone do responsavel).
 *
 * Um envio nunca derruba a operacao que o pediu: cadastro, aprovacao e
 * recuperacao de senha continuam valendo mesmo com o e-mail fora do ar.
 */
class Email
{
    /** Variavel de ambiente de cada chave de configuracao. */
    private const ENV = [
        'host'      => 'AGENDEI_EMAIL_HOST',
        'porta'     => 'AGENDEI_EMAIL_PORTA',
        'seguranca' => 'AGENDEI_EMAIL_SEGURANCA',
        'usuario'   => 'AGENDEI_EMAIL_USUARIO',
        'senha'     => 'AGENDEI_EMAIL_SENHA',
        'remetente' => 'AGENDEI_EMAIL_REMETENTE',
        'nome'      => 'AGENDEI_EMAIL_NOME',
    ];

    /** Segundos de espera por conexao e por cada resposta do servidor. */
    private const TEMPO_LIMITE = 12;

    private static ?array $local = null;

    /** Ultimo erro de envio, para a tela de teste do master. */
    private static string $ultimoErro = '';

    // -----------------------------------------------------------------
    // Configuracao
    // -----------------------------------------------------------------

    /** Valores em uso, com os padroes ja aplicados. A senha nao sai daqui. */
    public static function configuracao(): array
    {
        $porta = (int) self::valor('porta', '587');
        $seguranca = strtolower(self::valor('seguranca', $porta === 465 ? 'ssl' : 'tls'));
        if (!in_array($seguranca, ['tls', 'ssl', 'nenhuma'], true)) {
            $seguranca = 'tls';
        }

        return [
            'host'      => self::valor('host', ''),
            'porta'     => $porta,
            'seguranca' => $seguranca,
            'usuario'   => self::valor('usuario', ''),
            'senha'     => self::valor('senha', ''),
            'remetente' => mb_strtolower(self::valor('remetente', self::valor('usuario', ''))),
            'nome'      => self::valor('nome', NOME_SISTEMA),
        ];
    }

    /** Ha servidor e remetente configurados. */
    public static function configurado(): bool
    {
        $config = self::configuracao();
        return $config['host'] !== '' && validarEmail($config['remetente']);
    }

    public static function ultimoErro(): string
    {
        return self::$ultimoErro;
    }

    private static function valor(string $chave, string $padrao): string
    {
        $ambiente = getenv(self::ENV[$chave]);
        if ($ambiente !== false && $ambiente !== '') {
            return (string) $ambiente;
        }

        if (self::$local === null) {
            $arquivo = RAIZ . '/config/email.local.php';
            $valores = is_file($arquivo) ? require $arquivo : [];
            self::$local = is_array($valores) ? $valores : [];
        }

        return isset(self::$local[$chave]) && (string) self::$local[$chave] !== '' ? (string) self::$local[$chave] : $padrao;
    }

    // -----------------------------------------------------------------
    // Envio
    // -----------------------------------------------------------------

    /**
     * Envia uma mensagem com versao em texto e, opcionalmente, em HTML.
     * Devolve false, sem lancar excecao, quando nao ha configuracao ou o
     * servidor recusa; o motivo fica em ultimoErro() e no log.
     */
    public static function enviar(string $para, string $assunto, string $texto, ?string $html = null, string $nomeDestinatario = ''): bool
    {
        self::$ultimoErro = '';

        if (!self::configurado()) {
            self::$ultimoErro = 'Envio de e-mail nao configurado.';
            error_log('[email] nao enviado para ' . $para . ': ' . self::$ultimoErro);
            return false;
        }
        if (!validarEmail($para)) {
            self::$ultimoErro = 'Destinatario invalido.';
            return false;
        }

        $config = self::configuracao();
        $mensagem = self::montar($config['remetente'], $config['nome'], $para, $nomeDestinatario, $assunto, $texto, $html);

        try {
            self::transmitir($config, $para, $mensagem);
            return true;
        } catch (Throwable $erro) {
            self::$ultimoErro = $erro->getMessage();
            error_log('[email] falha ao enviar para ' . $para . ': ' . $erro->getMessage());
            return false;
        }
    }

    /**
     * Monta a mensagem completa (cabecalhos e corpo) no formato MIME.
     * Publico para que o teste confira o resultado sem servidor.
     */
    public static function montar(string $remetente, string $nomeRemetente, string $para, string $nomeDestinatario, string $assunto, string $texto, ?string $html): string
    {
        $limite = 'agendei-' . bin2hex(random_bytes(12));
        $dominio = substr(strrchr($remetente, '@') ?: '@localhost', 1);

        $cabecalhos = [
            'Date: ' . date('r'),
            'From: ' . self::endereco($remetente, $nomeRemetente),
            'To: ' . self::endereco($para, $nomeDestinatario),
            'Subject: ' . self::codificar($assunto),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $dominio . '>',
            'MIME-Version: 1.0',
            'X-Mailer: ' . NOME_SISTEMA,
        ];

        $textoCodificado = self::corpo($texto);

        if ($html === null) {
            $cabecalhos[] = 'Content-Type: text/plain; charset=UTF-8';
            $cabecalhos[] = 'Content-Transfer-Encoding: base64';
            return implode("\r\n", $cabecalhos) . "\r\n\r\n" . $textoCodificado;
        }

        $cabecalhos[] = 'Content-Type: multipart/alternative; boundary="' . $limite . '"';

        return implode("\r\n", $cabecalhos) . "\r\n\r\n"
            . '--' . $limite . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . $textoCodificado . "\r\n"
            . '--' . $limite . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . self::corpo($html) . "\r\n"
            . '--' . $limite . '--';
    }

    /** "Nome <endereco>", com o nome codificado quando tem acento. */
    private static function endereco(string $email, string $nome): string
    {
        $nome = trim(preg_replace('/[\r\n"<>]/', '', $nome) ?? '');
        return $nome === '' ? $email : self::codificar($nome) . ' <' . $email . '>';
    }

    /** Cabecalho em UTF-8 no formato que os clientes de e-mail entendem. */
    private static function codificar(string $valor): string
    {
        $valor = preg_replace('/[\r\n]+/', ' ', $valor) ?? '';
        return preg_match('/[^\x20-\x7E]/', $valor) ? '=?UTF-8?B?' . base64_encode($valor) . '?=' : $valor;
    }

    /** Corpo em base64 quebrado em linhas curtas, como o padrao pede. */
    private static function corpo(string $conteudo): string
    {
        return rtrim(chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $conteudo)), 76, "\r\n"));
    }

    // -----------------------------------------------------------------
    // Conversa SMTP
    // -----------------------------------------------------------------

    private static function transmitir(array $config, string $para, string $mensagem): void
    {
        $esquema = $config['seguranca'] === 'ssl' ? 'ssl://' : 'tcp://';
        $contexto = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $conexao = @stream_socket_client($esquema . $config['host'] . ':' . $config['porta'], $codigo, $erro, self::TEMPO_LIMITE, STREAM_CLIENT_CONNECT, $contexto);
        if ($conexao === false) {
            throw new RuntimeException('Nao foi possivel conectar em ' . $config['host'] . ':' . $config['porta'] . ' (' . $erro . ').');
        }
        stream_set_timeout($conexao, self::TEMPO_LIMITE);

        try {
            self::esperar($conexao, [220]);
            $meuNome = (string) (($_SERVER['SERVER_NAME'] ?? '') ?: 'localhost');
            self::comando($conexao, 'EHLO ' . $meuNome, [250]);

            if ($config['seguranca'] === 'tls') {
                self::comando($conexao, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($conexao, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('O servidor nao aceitou a conexao segura (STARTTLS).');
                }
                self::comando($conexao, 'EHLO ' . $meuNome, [250]);
            }

            if ($config['usuario'] !== '') {
                self::comando($conexao, 'AUTH LOGIN', [334], 'autenticacao');
                self::comando($conexao, base64_encode($config['usuario']), [334], 'autenticacao');
                self::comando($conexao, base64_encode($config['senha']), [235], 'autenticacao');
            }

            self::comando($conexao, 'MAIL FROM:<' . $config['remetente'] . '>', [250]);
            self::comando($conexao, 'RCPT TO:<' . $para . '>', [250, 251]);
            self::comando($conexao, 'DATA', [354]);

            // Linha que comeca com ponto ganha outro ponto, senao encerra a mensagem.
            $dados = preg_replace('/(^|\r\n)\./', '$1..', $mensagem) ?? $mensagem;
            fwrite($conexao, $dados . "\r\n.\r\n");
            self::esperar($conexao, [250]);
            self::comando($conexao, 'QUIT', [221]);
        } finally {
            fclose($conexao);
        }
    }

    /**
     * Envia um comando e confere o codigo da resposta. O rotulo descreve o
     * passo na mensagem de erro; os passos de autenticacao usam um generico
     * para a senha nunca aparecer no log.
     */
    private static function comando($conexao, string $linha, array $esperados, ?string $rotulo = null): string
    {
        fwrite($conexao, $linha . "\r\n");
        return self::esperar($conexao, $esperados, $rotulo ?? (string) strtok($linha, ' '));
    }

    /** Le a resposta (todas as linhas de um bloco "250-...") e valida o codigo. */
    private static function esperar($conexao, array $esperados, string $rotulo = 'conexao'): string
    {
        $resposta = '';
        do {
            $linha = fgets($conexao, 1024);
            if ($linha === false) {
                $meta = stream_get_meta_data($conexao);
                throw new RuntimeException(($meta['timed_out'] ?? false) ? 'O servidor demorou para responder.' : 'O servidor encerrou a conexao.');
            }
            $resposta .= $linha;
        } while (isset($linha[3]) && $linha[3] === '-');

        $codigo = (int) substr($resposta, 0, 3);
        if (!in_array($codigo, $esperados, true)) {
            throw new RuntimeException('Recusado em ' . $rotulo . ': ' . trim($resposta));
        }
        return $resposta;
    }
}
