<?php

/**
 * Envio de mensagens pelo WhatsApp.
 *
 * Dois provedores sao atendidos:
 *
 *  - evolution  Evolution API (codigo aberto, hospedada por voce, conectada a
 *               um numero comum de WhatsApp pelo QR code). Chamada:
 *               POST {url}/message/sendText/{instancia}, cabecalho apikey.
 *
 *  - meta       WhatsApp Cloud API oficial da Meta. Chamada:
 *               POST https://graph.facebook.com/{versao}/{telefone_id}/messages
 *               com token Bearer. Mensagem que a empresa inicia precisa de um
 *               modelo (template) aprovado; o lembrete usa um modelo com quatro
 *               variaveis, na ordem: nome do cliente, servico, data e hora.
 *
 * A configuracao e lida do painel da empresa (tabela configuracoes). Empresa
 * que deixa o provedor em "plataforma" usa as credenciais da hospedagem, que
 * vem das variaveis AGENDEI_WHATSAPP_*. Assim quem vende o sistema pode
 * enviar por um numero unico para todas as empresas, e a empresa que preferir
 * o proprio numero configura sozinha.
 *
 * Nenhum numero sai daqui sem passar por normalizarNumero(): so numeros
 * completos, com DDI, chegam ao provedor.
 */
class WhatsApp
{
    /** Provedores que a empresa pode escolher no painel. */
    public const PROVEDORES = [
        'plataforma' => 'Padrao da plataforma',
        'manual'     => 'Somente manual (abrir o WhatsApp pelo link)',
        'evolution'  => 'Evolution API (numero proprio)',
        'meta'       => 'WhatsApp Cloud API (Meta, oficial)',
    ];

    /** Versao do Graph API usada nas chamadas a Meta. */
    private const VERSAO_META = 'v21.0';

    /** Idioma do modelo aprovado na Meta. */
    private const IDIOMA_MODELO = 'pt_BR';

    /** Tempo maximo de cada chamada HTTP, em segundos. */
    private const TEMPO_LIMITE = 15;

    /** Substituto da chamada HTTP, usado pelos testes. */
    private static $simulador = null;

    // -----------------------------------------------------------------
    // Configuracao
    // -----------------------------------------------------------------

    /**
     * Configuracao efetiva da empresa do contexto.
     *
     * Devolve provedor, url, instancia, token, telefone_id, modelo e origem
     * ('empresa' ou 'plataforma'). O provedor volta como 'manual' quando as
     * credenciais obrigatorias estao em branco: sem elas nao ha o que enviar.
     */
    public static function configuracao(): array
    {
        $escolha = Configuracao::obter('whatsapp_provedor', 'plataforma');

        if ($escolha === 'plataforma' || !array_key_exists($escolha, self::PROVEDORES)) {
            return self::daPlataforma();
        }

        return self::validar([
            'provedor'    => $escolha,
            'url'         => Configuracao::obter('whatsapp_url'),
            'instancia'   => Configuracao::obter('whatsapp_instancia'),
            'token'       => Configuracao::obter('whatsapp_token'),
            'telefone_id' => Configuracao::obter('whatsapp_telefone_id'),
            'modelo'      => Configuracao::obter('whatsapp_modelo'),
            'origem'      => 'empresa',
        ]);
    }

    /** Credenciais da hospedagem, lidas das variaveis de ambiente. */
    public static function daPlataforma(): array
    {
        return self::validar([
            'provedor'    => strtolower((string) (getenv('AGENDEI_WHATSAPP_PROVEDOR') ?: 'manual')),
            'url'         => (string) (getenv('AGENDEI_WHATSAPP_URL') ?: ''),
            'instancia'   => (string) (getenv('AGENDEI_WHATSAPP_INSTANCIA') ?: ''),
            'token'       => (string) (getenv('AGENDEI_WHATSAPP_TOKEN') ?: ''),
            'telefone_id' => (string) (getenv('AGENDEI_WHATSAPP_TELEFONE_ID') ?: ''),
            'modelo'      => (string) (getenv('AGENDEI_WHATSAPP_MODELO') ?: ''),
            'origem'      => 'plataforma',
        ]);
    }

    /** Rebaixa para 'manual' a configuracao que nao tem o minimo para enviar. */
    private static function validar(array $config): array
    {
        $config['url'] = rtrim(trim($config['url']), '/');

        $completa = match ($config['provedor']) {
            'evolution' => $config['url'] !== '' && $config['instancia'] !== '' && $config['token'] !== '',
            'meta'      => $config['telefone_id'] !== '' && $config['token'] !== '' && $config['modelo'] !== '',
            default     => false,
        };

        if (!$completa) {
            $config['provedor'] = 'manual';
        }

        return $config;
    }

    /** Provedor efetivo: 'manual', 'evolution' ou 'meta'. */
    public static function provedor(): string
    {
        return self::configuracao()['provedor'];
    }

    /** Ha um provedor configurado capaz de enviar sozinho. */
    public static function ativo(): bool
    {
        return self::provedor() !== 'manual';
    }

    /** O que falta para o provedor escolhido funcionar, em texto para o painel. */
    public static function pendencia(): string
    {
        $escolha = Configuracao::obter('whatsapp_provedor', 'plataforma');

        if (self::provedor() !== 'manual') {
            return '';
        }

        return match ($escolha) {
            'evolution'  => 'Informe a URL, a instancia e a chave (apikey) da Evolution API.',
            'meta'       => 'Informe o ID do numero, o token de acesso e o nome do modelo aprovado.',
            'plataforma' => 'A hospedagem nao tem um provedor configurado. Escolha um provedor proprio ou peca ao responsavel pela plataforma.',
            default      => '',
        };
    }

    // -----------------------------------------------------------------
    // Numero
    // -----------------------------------------------------------------

    /**
     * Numero no formato que os provedores esperam: DDI + DDD + numero, so digitos.
     *
     * Telefone brasileiro com 10 ou 11 digitos ganha o 55. Numero que ja veio
     * com DDI e aceito entre 12 e 15 digitos (E.164). Qualquer outra coisa
     * devolve null, e a mensagem nao sai.
     */
    public static function normalizarNumero(?string $telefone): ?string
    {
        $numero = apenasNumeros((string) $telefone);

        if (strlen($numero) === 10 || strlen($numero) === 11) {
            $numero = '55' . $numero;
        }

        if (strlen($numero) < 12 || strlen($numero) > 15) {
            return null;
        }

        return $numero;
    }

    // -----------------------------------------------------------------
    // Envio
    // -----------------------------------------------------------------

    /**
     * Envia um lembrete de agendamento.
     *
     * $texto e a mensagem pronta; $parametros sao [nome, servico, data, hora],
     * usados pelo provedor que trabalha com modelo (Meta). Cada provedor usa
     * o que precisa. Lanca RuntimeException com a razao quando o envio falha.
     */
    public static function enviarLembrete(string $numero, string $texto, array $parametros): void
    {
        $config = self::configuracao();

        match ($config['provedor']) {
            'evolution' => self::enviarTextoEvolution($config, $numero, $texto),
            'meta'      => self::enviarModeloMeta($config, $numero, $parametros),
            default     => throw new RuntimeException('Nenhum provedor de WhatsApp configurado.'),
        };
    }

    /**
     * Envia um texto livre. So a Evolution API aceita; na Meta um texto livre
     * so chega dentro de 24h apos o cliente escrever, entao a mensagem que a
     * empresa inicia precisa ser um modelo.
     */
    public static function enviarTexto(string $numero, string $texto): void
    {
        $config = self::configuracao();

        match ($config['provedor']) {
            'evolution' => self::enviarTextoEvolution($config, $numero, $texto),
            'meta'      => throw new RuntimeException('A API oficial da Meta so envia texto livre em resposta ao cliente. Use um modelo aprovado.'),
            default     => throw new RuntimeException('Nenhum provedor de WhatsApp configurado.'),
        };
    }

    private static function enviarTextoEvolution(array $config, string $numero, string $texto): void
    {
        self::requisitar(
            $config['url'] . '/message/sendText/' . rawurlencode($config['instancia']),
            ['apikey: ' . $config['token']],
            [
                'number' => $numero,
                'text'   => $texto,
                // Versoes 1.x da Evolution leem o texto daqui; a 2.x, do campo acima.
                'textMessage' => ['text' => $texto],
            ]
        );
    }

    private static function enviarModeloMeta(array $config, string $numero, array $parametros): void
    {
        $valores = array_map(
            static fn($valor): array => ['type' => 'text', 'text' => (string) $valor],
            array_values($parametros)
        );

        self::requisitar(
            'https://graph.facebook.com/' . self::VERSAO_META . '/' . rawurlencode($config['telefone_id']) . '/messages',
            ['Authorization: Bearer ' . $config['token']],
            [
                'messaging_product' => 'whatsapp',
                'to'                => $numero,
                'type'              => 'template',
                'template'          => [
                    'name'       => $config['modelo'],
                    'language'   => ['code' => self::IDIOMA_MODELO],
                    'components' => [['type' => 'body', 'parameters' => $valores]],
                ],
            ]
        );
    }

    // -----------------------------------------------------------------
    // HTTP
    // -----------------------------------------------------------------

    /**
     * POST em JSON. Devolve a resposta decodificada; lanca RuntimeException
     * com a mensagem do provedor (ou o status HTTP) quando algo falha.
     */
    private static function requisitar(string $url, array $cabecalhos, array $corpo): array
    {
        if (self::$simulador !== null) {
            $resposta = (self::$simulador)(['url' => $url, 'cabecalhos' => $cabecalhos, 'corpo' => $corpo]);
            return is_array($resposta) ? $resposta : [];
        }

        $json = json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $cabecalhos[] = 'Content-Type: application/json';
        $cabecalhos[] = 'Accept: application/json';

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => $cabecalhos,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::TEMPO_LIMITE,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            $resposta = curl_exec($curl);
            $status   = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $falha    = curl_error($curl);
            curl_close($curl);

            if ($resposta === false) {
                throw new RuntimeException('Sem resposta do provedor: ' . $falha);
            }
        } else {
            $contexto = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $cabecalhos),
                'content'       => $json,
                'timeout'       => self::TEMPO_LIMITE,
                'ignore_errors' => true,
            ]]);
            $resposta = @file_get_contents($url, false, $contexto);
            $status   = 0;
            foreach ($http_response_header ?? [] as $linha) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linha, $partes)) {
                    $status = (int) $partes[1];
                }
            }

            if ($resposta === false) {
                throw new RuntimeException('Sem resposta do provedor.');
            }
        }

        $dados = json_decode((string) $resposta, true);
        $dados = is_array($dados) ? $dados : [];

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(self::mensagemDeErro($dados, $status));
        }

        return $dados;
    }

    /** Extrai a razao da falha no formato de cada provedor. */
    private static function mensagemDeErro(array $dados, int $status): string
    {
        // Meta: {"error":{"message":"...","code":...}}
        if (isset($dados['error']['message'])) {
            return 'Meta: ' . $dados['error']['message'];
        }

        // Evolution: {"response":{"message":["..."]}} ou {"message":"..."}
        $mensagem = $dados['response']['message'] ?? $dados['message'] ?? null;
        if (is_array($mensagem)) {
            $mensagem = implode('; ', array_map('strval', $mensagem));
        }
        if (is_string($mensagem) && $mensagem !== '') {
            return 'Provedor: ' . $mensagem;
        }

        return 'O provedor respondeu HTTP ' . $status . '.';
    }

    // -----------------------------------------------------------------
    // Testes
    // -----------------------------------------------------------------

    /**
     * Substitui a chamada HTTP por uma funcao. Ela recebe
     * ['url' => ..., 'cabecalhos' => [...], 'corpo' => [...]] e pode lancar
     * RuntimeException para simular uma falha. Passe null para voltar ao envio real.
     */
    public static function simular(?callable $simulador): void
    {
        self::$simulador = $simulador;
    }
}
