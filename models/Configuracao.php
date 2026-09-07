<?php
/**
 * Configuracoes do sistema (chave/valor), com cache em memoria por requisicao.
 */
class Configuracao
{
    private static ?array $cache = null;
    private static ?int $empresaCache = null;

    /** Carrega os valores uma vez por requisição e os organiza pela chave da configuração. */
    public static function todas(): array
    {
        if (self::$cache === null || self::$empresaCache !== Contexto::id()) {
            self::$empresaCache = Contexto::id();
            self::$cache = [];
            foreach (bd()->query('SELECT chave, valor, descricao FROM configuracoes WHERE id_estabelecimento = ' . Contexto::id())->fetchAll() as $linha) {
                self::$cache[$linha['chave']] = $linha['valor'];
            }
        }

        return self::$cache;
    }

    /** Lê uma configuração textual, usando o valor padrão quando a chave não existe. */
    public static function obter(string $chave, string $padrao = ''): string
    {
        $configuracoes = self::todas();
        return $configuracoes[$chave] ?? $padrao;
    }

    /** Converte uma configuração numérica em inteiro ou devolve o padrão. */
    public static function obterInteiro(string $chave, int $padrao = 0): int
    {
        $valor = self::obter($chave, (string) $padrao);
        return is_numeric($valor) ? (int) $valor : $padrao;
    }

    /** Interpreta o valor textual 1 como uma configuração habilitada. */
    public static function ativa(string $chave, bool $padrao = false): bool
    {
        return self::obter($chave, $padrao ? '1' : '0') === '1';
    }

    /** Insere ou atualiza a configuração e invalida o cache da requisição. */
    public static function definir(string $chave, string $valor, ?string $descricao = null): void
    {
        $consulta = bd()->prepare(
            'INSERT INTO configuracoes (id_estabelecimento, chave, valor, descricao)
             VALUES (' . Contexto::id() . ', :chave, :valor, :descricao)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
        );

        $consulta->execute([
            ':chave'     => $chave,
            ':valor'     => $valor,
            ':descricao' => $descricao,
        ]);

        // Força a próxima leitura a buscar os valores atualizados no banco.
        self::$cache = null;
    }

    /** Lista completa com descricao, para a tela de configuracoes. */
    public static function listar(): array
    {
        return bd()->query('SELECT * FROM configuracoes WHERE id_estabelecimento = ' . Contexto::id() . ' ORDER BY id_configuracao ASC')->fetchAll();
    }
}
