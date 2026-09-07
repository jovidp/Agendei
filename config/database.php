<?php
/**
 * Conexao unica com o banco de dados (PDO).
 * Este e o unico arquivo do projeto que conhece as credenciais.
 */

class Database
{
    private const HOST    = 'localhost';
    private const PORTA   = '3306';
    private const BANCO   = 'agendei';
    private const USUARIO = 'root';
    private const SENHA   = '';
    private const CHARSET = 'utf8mb4';

    // Mantém a conexão em memória para evitar abrir uma nova a cada consulta.
    private static ?PDO $conexao = null;

    /** Abre a conexão PDO no primeiro uso e reutiliza a mesma instância na requisição. */
    public static function conexao(): PDO
    {
        if (self::$conexao === null) {
            $dsn = 'mysql:host=' . self::HOST . ';port=' . self::PORTA
                . ';dbname=' . (getenv('AGENDEI_DB_NAME') ?: self::BANCO) . ';charset=' . self::CHARSET;

            // Usa exceções, resultados associativos e consultas preparadas nativas do PDO.
            $opcoes = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ];

            try {
                self::$conexao = new PDO($dsn, self::USUARIO, self::SENHA, $opcoes);
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
}

/**
 * Atalho usado pelos models.
 */
function bd(): PDO
{
    return Database::conexao();
}
