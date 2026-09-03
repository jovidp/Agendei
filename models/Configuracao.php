<?php
/**
 * Configuracoes do sistema (chave/valor), com cache em memoria por requisicao.
 */
class Configuracao
{
    private static ?array $cache = null;

    public static function todas(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (bd()->query('SELECT chave, valor, descricao FROM configuracoes')->fetchAll() as $linha) {
                self::$cache[$linha['chave']] = $linha['valor'];
            }
        }

        return self::$cache;
    }

    public static function obter(string $chave, string $padrao = ''): string
    {
        $configuracoes = self::todas();
        return $configuracoes[$chave] ?? $padrao;
    }

    public static function obterInteiro(string $chave, int $padrao = 0): int
    {
        $valor = self::obter($chave, (string) $padrao);
        return is_numeric($valor) ? (int) $valor : $padrao;
    }

    public static function ativa(string $chave, bool $padrao = false): bool
    {
        return self::obter($chave, $padrao ? '1' : '0') === '1';
    }

    public static function definir(string $chave, string $valor, ?string $descricao = null): void
    {
        $consulta = bd()->prepare(
            'INSERT INTO configuracoes (chave, valor, descricao)
             VALUES (:chave, :valor, :descricao)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
        );

        $consulta->execute([
            ':chave'     => $chave,
            ':valor'     => $valor,
            ':descricao' => $descricao,
        ]);

        self::$cache = null;
    }

    /** Lista completa com descricao, para a tela de configuracoes. */
    public static function listar(): array
    {
        return bd()->query('SELECT * FROM configuracoes ORDER BY id_configuracao ASC')->fetchAll();
    }
}
