<?php
/**
 * Dados institucionais do estabelecimento (registro unico).
 */
class Estabelecimento
{
    private static ?array $cache = null;

    public static function dados(): array
    {
        if (self::$cache === null) {
            $registro = bd()->query('SELECT * FROM estabelecimento ORDER BY id_estabelecimento ASC LIMIT 1')->fetch();
            self::$cache = $registro ?: ['nome' => NOME_SISTEMA];
        }

        return self::$cache;
    }

    public static function campo(string $campo, string $padrao = ''): string
    {
        $dados = self::dados();
        $valor = $dados[$campo] ?? null;
        return ($valor === null || $valor === '') ? $padrao : (string) $valor;
    }

    public static function atualizar(array $dados): bool
    {
        $atual = self::dados();

        $campos = [
            'nome', 'slogan', 'descricao', 'telefone', 'whatsapp', 'email',
            'endereco', 'bairro', 'cidade', 'uf', 'cep', 'horario_funcionamento',
            'instagram', 'facebook',
        ];

        $valores = [];
        foreach ($campos as $campo) {
            $valores[':' . $campo] = $dados[$campo] ?? null;
        }

        if (empty($atual['id_estabelecimento'])) {
            $sql = 'INSERT INTO estabelecimento (' . implode(', ', $campos) . ')
                    VALUES (' . implode(', ', array_map(fn ($c) => ':' . $c, $campos)) . ')';
            $consulta = bd()->prepare($sql);
            $resultado = $consulta->execute($valores);
        } else {
            $atribuicoes = implode(', ', array_map(fn ($c) => "{$c} = :{$c}", $campos));
            $consulta = bd()->prepare("UPDATE estabelecimento SET {$atribuicoes} WHERE id_estabelecimento = :id");
            $valores[':id'] = $atual['id_estabelecimento'];
            $resultado = $consulta->execute($valores);
        }

        self::$cache = null;
        return $resultado;
    }

    /** Endereco em linha unica para exibicao. */
    public static function enderecoCompleto(): string
    {
        $dados = self::dados();
        $partes = array_filter([
            $dados['endereco'] ?? '',
            $dados['bairro'] ?? '',
            trim(($dados['cidade'] ?? '') . (!empty($dados['uf']) ? ' - ' . $dados['uf'] : '')),
            $dados['cep'] ?? '',
        ]);

        return implode(', ', $partes);
    }
}
