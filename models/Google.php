<?php

/**
 * Entrar com o Google (OpenID Connect).
 *
 * O botao "Entrar com o Google" nao cria conta: o cadastro continua sendo o
 * caminho para nascer no sistema, porque a especificacao exige dados que o
 * Google nao fornece (CPF, login de 6 letras, nome materno...). O que o Google
 * faz e provar a quem pertence um e-mail; com isso o sistema abre a conta
 * daquele e-mail sem pedir a senha. O segundo fator, quando a conta exige,
 * continua valendo (google_login.php).
 *
 * Fluxo, em tres passos, todos em google_login.php:
 *   1. o formulario de login envia o pedido; o sistema guarda um "state"
 *      aleatorio na sessao e redireciona para o Google;
 *   2. o Google volta com um codigo e o mesmo state;
 *   3. o sistema troca o codigo pelo id_token, direto com o Google, por HTTPS,
 *      usando o segredo do cliente. Como o token veio dessa conversa direta,
 *      nao e preciso conferir a assinatura: basta conferir emissor, cliente,
 *      validade e e-mail verificado (lerIdToken()).
 *
 * Credenciais: variaveis AGENDEI_GOOGLE_CLIENT_ID e AGENDEI_GOOGLE_CLIENT_SECRET,
 * ou config/google.local.php (fora do Git) com 'client_id' e 'client_secret'.
 * Sem elas o botao simplesmente nao aparece.
 */
class Google
{
    public const URL_AUTORIZACAO = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const URL_TOKEN       = 'https://oauth2.googleapis.com/token';

    private const ENV = [
        'client_id'     => 'AGENDEI_GOOGLE_CLIENT_ID',
        'client_secret' => 'AGENDEI_GOOGLE_CLIENT_SECRET',
    ];

    /** Segundos de espera pela resposta do Google. */
    private const TEMPO_LIMITE = 10;

    /**
     * Substituido pelos testes para nao falar com o Google de verdade.
     * Recebe (url, campos do formulario) e devolve [codigo HTTP, corpo].
     * @var callable|null
     */
    public static $postar = null;

    /** Credenciais atuais: ambiente primeiro, depois config/google.local.php. */
    public static function configuracao(): array
    {
        $local = null;
        $configuracao = [];

        foreach (self::ENV as $chave => $variavel) {
            $valor = trim((string) (getenv($variavel) ?: ''));

            if ($valor === '') {
                if ($local === null) {
                    $arquivo = RAIZ . '/config/google.local.php';
                    $local = is_file($arquivo) ? (array) (include $arquivo) : [];
                }
                $valor = trim((string) ($local[$chave] ?? ''));
            }

            $configuracao[$chave] = $valor;
        }

        return $configuracao;
    }

    public static function configurado(): bool
    {
        $configuracao = self::configuracao();

        return $configuracao['client_id'] !== '' && $configuracao['client_secret'] !== '';
    }

    /**
     * Endereco absoluto de google_login.php: e o que precisa estar cadastrado
     * no Google Cloud como URI de redirecionamento autorizado.
     */
    public static function urlRedirecionamento(): string
    {
        $esquema = requisicaoSegura() ? 'https' : 'http';
        $hospedeiro = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return $esquema . '://' . $hospedeiro . BASE_URL . '/google_login.php';
    }

    /** Para onde mandar o navegador para a pessoa escolher a conta Google. */
    public static function urlAutorizacao(string $estado, string $redirecionamento): string
    {
        $parametros = [
            'client_id'     => self::configuracao()['client_id'],
            'redirect_uri'  => $redirecionamento,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $estado,
            // Sempre mostra a lista de contas Google disponiveis no navegador.
            'prompt'        => 'select_account',
        ];

        return self::URL_AUTORIZACAO . '?' . http_build_query($parametros, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Troca o codigo devolvido pelo Google pelos dados da pessoa. Devolve as
     * afirmacoes do id_token (email, name, sub...). Lanca RuntimeException com
     * uma mensagem propria para a tela quando algo nao confere.
     */
    public static function trocarCodigo(string $codigo, string $redirecionamento): array
    {
        $configuracao = self::configuracao();

        [$status, $corpo] = self::postarFormulario(self::URL_TOKEN, [
            'code'          => $codigo,
            'client_id'     => $configuracao['client_id'],
            'client_secret' => $configuracao['client_secret'],
            'redirect_uri'  => $redirecionamento,
            'grant_type'    => 'authorization_code',
        ]);

        $dados = json_decode($corpo, true);
        if ($status !== 200 || !is_array($dados) || empty($dados['id_token'])) {
            $motivo = is_array($dados) ? (string) ($dados['error_description'] ?? $dados['error'] ?? '') : '';
            error_log('Google recusou o codigo (HTTP ' . $status . '): ' . $motivo);
            throw new RuntimeException('O Google nao confirmou a entrada. Tente novamente.');
        }

        return self::lerIdToken((string) $dados['id_token'], $configuracao['client_id']);
    }

    /**
     * Le e confere o id_token (um JWT). A assinatura nao e verificada: o token
     * chegou pela troca direta com o Google, por HTTPS e com o segredo do
     * cliente, entao a origem ja esta garantida. O que se confere aqui e se ele
     * e para este sistema, se ainda vale e se o e-mail foi verificado.
     */
    public static function lerIdToken(string $token, string $clientId, ?int $agora = null): array
    {
        $partes = explode('.', $token);
        if (count($partes) !== 3) {
            throw new RuntimeException('Resposta do Google em formato inesperado.');
        }

        $carga = json_decode(self::base64UrlDecodificar($partes[1]), true);
        if (!is_array($carga)) {
            throw new RuntimeException('Resposta do Google em formato inesperado.');
        }

        $agora ??= time();
        $emissor = (string) ($carga['iss'] ?? '');

        if (!in_array($emissor, ['https://accounts.google.com', 'accounts.google.com'], true)) {
            throw new RuntimeException('A resposta nao veio do Google.');
        }
        if ((string) ($carga['aud'] ?? '') !== $clientId) {
            throw new RuntimeException('A resposta do Google nao pertence a este sistema.');
        }
        if ((int) ($carga['exp'] ?? 0) < $agora) {
            throw new RuntimeException('A confirmacao do Google expirou. Tente novamente.');
        }

        $email = mb_strtolower(trim((string) ($carga['email'] ?? '')));
        $verificado = filter_var($carga['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($email === '' || !$verificado) {
            throw new RuntimeException('O Google nao confirmou o e-mail desta conta.');
        }

        $carga['email'] = $email;

        return $carga;
    }

    /**
     * Conta ativa, em empresa ativa, do e-mail que o Google confirmou.
     * O retorno continua sendo uma lista para tolerar bases antigas ainda nao
     * migradas. Nunca devolve o hash da senha.
     */
    public static function contas(string $email, string $slug = ''): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return [];
        }

        $sql = 'SELECT u.id_usuario, u.id_estabelecimento, u.nome, u.tipo, u.status,
                       e.nome AS estabelecimento_nome, e.slug AS estabelecimento_slug, e.status AS estabelecimento_status
                FROM usuarios u
                JOIN estabelecimento e ON e.id_estabelecimento = u.id_estabelecimento
                WHERE u.email = :email';
        $parametros = [':email' => $email];

        if ($slug !== '') {
            $sql .= ' AND e.slug = :slug';
            $parametros[':slug'] = $slug;
        }

        $consulta = bd()->prepare($sql . ' ORDER BY e.nome, u.id_usuario');
        $consulta->execute($parametros);

        $contas = [];
        foreach ($consulta->fetchAll() as $conta) {
            if ($conta['status'] === 'ativo' && $conta['estabelecimento_status'] === 'ativo') {
                $contas[] = $conta;
            }
        }

        return $contas;
    }

    // -----------------------------------------------------------------
    // Cadastro iniciado pelo Google
    //
    // O Google nao cria a conta: confirma o e-mail e a tela de cadastro
    // termina o resto. Nome e e-mail esperam na sessao, presos a tela de
    // origem ('cadastro' ou 'cadastro_empresa') e com prazo, para uma
    // confirmacao esquecida nao reaparecer em outro cadastro nem dias depois.
    // -----------------------------------------------------------------

    /** Tempo para a pessoa completar o cadastro depois que o Google confirmou o e-mail. */
    public const CADASTRO_SEGUNDOS = 1800;

    /** Guarda nome e e-mail confirmados para a tela de origem preencher. */
    public static function guardarCadastro(array $dados, string $origem): void
    {
        $_SESSION['google_cadastro'] = [
            'nome'   => trim((string) ($dados['name'] ?? '')),
            'email'  => (string) ($dados['email'] ?? ''),
            'origem' => $origem,
            'expira' => time() + self::CADASTRO_SEGUNDOS,
        ];
    }

    /** Nome e e-mail confirmados para esta tela, ou null se nao ha, venceu ou pertence a outra tela. */
    public static function cadastroPendente(string $origem): ?array
    {
        $pendente = $_SESSION['google_cadastro'] ?? null;
        if (!is_array($pendente)) {
            return null;
        }
        if ((int) ($pendente['expira'] ?? 0) < time()) {
            self::limparCadastro();
            return null;
        }
        return ($pendente['origem'] ?? '') === $origem ? $pendente : null;
    }

    /** Esquece a confirmacao: a conta foi criada ou a pessoa seguiu sem o Google. */
    public static function limparCadastro(): void
    {
        unset($_SESSION['google_cadastro']);
    }

    // -----------------------------------------------------------------
    // Transporte
    // -----------------------------------------------------------------

    /** POST de formulario. Devolve [codigo HTTP, corpo da resposta]. */
    private static function postarFormulario(string $url, array $campos): array
    {
        if (self::$postar !== null) {
            return (self::$postar)($url, $campos);
        }

        $corpo = http_build_query($campos, '', '&');
        $cabecalhos = ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'];

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $corpo,
                CURLOPT_HTTPHEADER     => $cabecalhos,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::TEMPO_LIMITE,
                CURLOPT_TIMEOUT        => self::TEMPO_LIMITE * 2,
            ]);
            $resposta = curl_exec($curl);
            if ($resposta === false) {
                $erro = curl_error($curl);
                curl_close($curl);
                throw new RuntimeException('Nao foi possivel falar com o Google (' . $erro . ').');
            }
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);

            return [$status, (string) $resposta];
        }

        $contexto = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $cabecalhos),
            'content'       => $corpo,
            'timeout'       => self::TEMPO_LIMITE * 2,
            'ignore_errors' => true,
        ]]);
        $resposta = @file_get_contents($url, false, $contexto);
        if ($resposta === false) {
            throw new RuntimeException('Nao foi possivel falar com o Google.');
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $linha) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $linha, $m)) {
                $status = (int) $m[1];
            }
        }

        return [$status, $resposta];
    }

    private static function base64UrlDecodificar(string $texto): string
    {
        $texto = strtr($texto, '-_', '+/');
        $resto = strlen($texto) % 4;
        if ($resto > 0) {
            $texto .= str_repeat('=', 4 - $resto);
        }

        return (string) base64_decode($texto, true);
    }
}
