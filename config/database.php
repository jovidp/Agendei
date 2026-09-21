<?php
/**
 * Conexao unica com o banco de dados (PDO).
 * Este e o unico arquivo do projeto que conhece as credenciais.
 *
 * O sistema fala dois dialetos: MySQL/MariaDB (padrao, exigido pela
 * especificacao do projeto) e PostgreSQL (Supabase e afins). A escolha
 * fica em config/database.local.php, na chave 'driver'.
 *
 * Os valores abaixo sao os padroes de desenvolvimento. Para producao, crie
 * config/database.local.php (fora do versionamento) devolvendo um array com
 * as chaves que quiser sobrescrever: driver, host, porta, banco, usuario,
 * senha, charset, emular_prepares.
 */

class Database
{
    private const DRIVER  = 'mysql';
    private const HOST    = 'localhost';
    private const BANCO   = 'agendei';
    private const USUARIO = 'root';
    private const SENHA   = '';
    private const CHARSET = 'utf8mb4';

    /** Porta padrao de cada dialeto, usada quando o arquivo local nao informa uma. */
    private const PORTAS = ['mysql' => '3306', 'pgsql' => '5432'];

    // Mantém a conexão em memória para evitar abrir uma nova a cada consulta.
    private static ?PDO $conexao = null;

    // Cache do arquivo de override local, para nao tocar o disco a cada chamada.
    private static ?array $local = null;

    /**
     * Lê config/database.local.php uma única vez. O arquivo é opcional e deve
     * devolver um array, ex.: ['usuario' => 'agendei', 'senha' => 'segredo'].
     */
    private static function local(): array
    {
        if (self::$local === null) {
            $arquivo = __DIR__ . '/database.local.php';
            $valores = is_file($arquivo) ? require $arquivo : [];
            self::$local = is_array($valores) ? $valores : [];
        }

        return self::$local;
    }

    /** Variavel de ambiente correspondente a cada chave de credencial. */
    private const ENV = [
        'host'    => 'AGENDEI_DB_HOST',
        'porta'   => 'AGENDEI_DB_PORT',
        'usuario' => 'AGENDEI_DB_USER',
        'senha'   => 'AGENDEI_DB_PASS',
        'charset' => 'AGENDEI_DB_CHARSET',
    ];

    /**
     * Valor de configuracao, na ordem: variavel de ambiente, secao do dialeto
     * no arquivo local, chave solta no arquivo, e por fim o padrao embutido.
     *
     * As variaveis de ambiente vem primeiro porque e assim que as hospedagens
     * (Render, Fly, Koyeb...) injetam segredo: o codigo vai para o servidor sem
     * nenhuma senha, e as credenciais ficam so na configuracao da plataforma.
     * O arquivo aceita uma secao por dialeto — ['pgsql' => ['host' => ...]] —
     * para guardar as credenciais dos dois bancos ao mesmo tempo.
     */
    private static function valor(string $chave, string $padrao): string
    {
        if (isset(self::ENV[$chave])) {
            $ambiente = getenv(self::ENV[$chave]);
            if ($ambiente !== false) {
                return (string) $ambiente;
            }
        }

        $local = self::local();
        $secao = $local[self::driver()] ?? null;

        if (is_array($secao) && isset($secao[$chave])) {
            return (string) $secao[$chave];
        }

        return isset($local[$chave]) ? (string) $local[$chave] : $padrao;
    }

    /**
     * Dialeto em uso: 'mysql' ou 'pgsql'.
     *
     * A classe Sql consulta este metodo para montar os trechos que divergem
     * entre os dois bancos, por isso ele precisa responder antes de existir
     * conexao aberta. A variavel de ambiente vem primeiro: os testes a usam
     * para rodar a mesma suite nos dois dialetos.
     */
    public static function driver(): string
    {
        // Lê o array local direto, sem passar por valor(): aquele método
        // consulta o driver para escolher a seção, e a ida e volta seria infinita.
        $local = self::local();
        $driver = strtolower((string) (getenv('AGENDEI_DB_DRIVER') ?: ($local['driver'] ?? self::DRIVER)));

        return $driver === 'pgsql' || $driver === 'postgres' || $driver === 'postgresql' ? 'pgsql' : 'mysql';
    }

    /** Indica se o dialeto atual e PostgreSQL. */
    public static function ehPostgres(): bool
    {
        return self::driver() === 'pgsql';
    }

    /** Monta a string de conexao conforme o dialeto. */
    private static function dsn(string $banco): string
    {
        $host  = self::valor('host', self::HOST);
        $porta = self::valor('porta', self::PORTAS[self::driver()]);

        if (self::ehPostgres()) {
            // O client_encoding entra pelo DSN para nao depender de um SET posterior.
            return 'pgsql:host=' . $host . ';port=' . $porta . ';dbname=' . $banco . ";options='--client_encoding=UTF8'";
        }

        return 'mysql:host=' . $host . ';port=' . $porta . ';dbname=' . $banco
            . ';charset=' . self::valor('charset', self::CHARSET);
    }

    /**
     * Decide entre consulta preparada nativa e emulada.
     *
     * Poolers em modo transacao (Supabase na porta 6543, PgBouncer) nao suportam
     * prepared statements do servidor: a consulta seguinte pode cair em outra
     * conexao fisica, que nunca viu o PREPARE. Nesse caso a emulacao e obrigatoria.
     * A deteccao pela porta cobre o caso comum; 'emular_prepares' permite forcar.
     */
    private static function emularPrepares(): bool
    {
        $local = self::local();
        if (isset($local['emular_prepares'])) {
            return (bool) $local['emular_prepares'];
        }

        return self::ehPostgres() && self::valor('porta', self::PORTAS['pgsql']) === '6543';
    }

    /** Abre a conexão PDO no primeiro uso e reutiliza a mesma instância na requisição. */
    public static function conexao(): PDO
    {
        if (self::$conexao === null) {
            // A variável de ambiente vem primeiro: os testes a usam para isolar o banco.
            $banco = getenv('AGENDEI_DB_NAME') ?: self::valor('banco', self::BANCO);

            // Usa exceções, resultados associativos e consultas preparadas do PDO.
            $opcoes = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => self::emularPrepares(),
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ];

            try {
                self::$conexao = new PDO(
                    self::dsn($banco),
                    self::valor('usuario', self::USUARIO),
                    self::valor('senha', self::SENHA),
                    $opcoes
                );

                // O PostgreSQL (Supabase) roda em UTC por padrao, mas o app assume
                // America/Sao_Paulo (config/config.php). Sem alinhar o fuso da sessao,
                // NOW() e CURRENT_DATE ficariam 3h a frente, deslocando a fronteira
                // de datas e a validade de tokens. No MySQL o comportamento ja segue
                // o fuso do servidor, entao nada muda la.
                if (self::ehPostgres()) {
                    self::$conexao->exec("SET TIME ZONE 'America/Sao_Paulo'");
                }
            } catch (PDOException $erro) {
                if (defined('AMBIENTE') && AMBIENTE === 'desenvolvimento') {
                    exit('Erro de conexao com o banco de dados: ' . $erro->getMessage());
                }
                error_log('Falha na conexao com o banco: ' . $erro->getMessage());
                exit('Nao foi possivel conectar ao banco de dados. Tente novamente mais tarde.');
            }
        }

        return self::$conexao;
    }

    /**
     * Descarta a conexao aberta.
     * Serve aos testes, que trocam de banco e de dialeto no mesmo processo.
     */
    public static function reiniciar(): void
    {
        self::$conexao = null;
        self::$local = null;
    }
}

/**
 * Atalho usado pelos models.
 */
function bd(): PDO
{
    return Database::conexao();
}
