<?php
/**
 * Dados institucionais da empresa selecionada na requisição.
 */
class Estabelecimento
{
    /** Lê o cadastro institucional e reutiliza o resultado durante a requisição. */
    public static function dados(): array
    {
        return Contexto::dados();
    }

    /** Obtém um campo do estabelecimento com valor padrão quando necessário. */
    public static function campo(string $campo, string $padrao = ''): string
    {
        $dados = self::dados();
        $valor = $dados[$campo] ?? null;
        return ($valor === null || $valor === '') ? $padrao : (string) $valor;
    }

    /** Persiste os campos editáveis do cadastro identificado pelo ID. */
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

        Contexto::recarregar();
        return $resultado;
    }

    /** Salva apenas a identidade da empresa da sessão; o formulário não escolhe outro ID. */
    public static function personalizar(string $nome, array $tema, ?string $logo): void
    {
        $q = bd()->prepare('UPDATE estabelecimento SET nome = ?, cor_primaria = ?, cor_secundaria = ?, cor_fundo = ?, fonte = ?, logo = ? WHERE id_estabelecimento = ?');
        $q->execute([$nome, $tema['cor_primaria'], $tema['cor_secundaria'], $tema['cor_fundo'], $tema['fonte'], $logo, Contexto::id()]);
        Contexto::recarregar();
    }

    /** Cria uma empresa e sua primeira conta administrativa de forma atômica. */
    public static function contratar(array $dados): int
    {
        $db = bd();
        $db->beginTransaction();
        try {
            $q = $db->prepare('INSERT INTO estabelecimento (nome, slug) VALUES (?, ?)');
            $q->execute([$dados['estabelecimento'], $dados['slug']]);
            $id = (int) $db->lastInsertId();
            $q = $db->prepare('INSERT INTO usuarios (id_estabelecimento, nome, email, senha_hash, tipo) VALUES (?, ?, ?, ?, \'admin\')');
            $q->execute([$id, $dados['nome'], $dados['email'], password_hash($dados['senha'], PASSWORD_DEFAULT)]);
            $usuario = (int) $db->lastInsertId();
            $q = $db->prepare('INSERT INTO administradores (id_estabelecimento, id_usuario, nivel) VALUES (?, ?, \'super\')');
            $q->execute([$id, $usuario]);
            $db->commit();
            return $id;
        } catch (Throwable $erro) {
            if ($db->inTransaction()) $db->rollBack();
            throw $erro;
        }
    }

    /** Lista as empresas para a administração global, com totais isolados por vínculo. */
    public static function listarTodos(): array
    {
        $sql = 'SELECT e.*,
                       (SELECT COUNT(*) FROM usuarios u WHERE u.id_estabelecimento = e.id_estabelecimento AND u.tipo = \'admin\' AND u.status = \'ativo\') AS admins_ativos,
                       (SELECT COUNT(*) FROM clientes c WHERE c.id_estabelecimento = e.id_estabelecimento) AS total_clientes,
                       (SELECT COUNT(*) FROM profissionais p WHERE p.id_estabelecimento = e.id_estabelecimento) AS total_profissionais,
                       (SELECT COUNT(*) FROM servicos s WHERE s.id_estabelecimento = e.id_estabelecimento) AS total_servicos
                FROM estabelecimento e ORDER BY e.nome';
        return bd()->query($sql)->fetchAll();
    }

    public static function porIdGlobal(int $id): ?array
    {
        $q = bd()->prepare('SELECT * FROM estabelecimento WHERE id_estabelecimento = ? LIMIT 1');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    public static function alterarStatusGlobal(int $id, string $status): bool
    {
        $q = bd()->prepare('UPDATE estabelecimento SET status = ? WHERE id_estabelecimento = ?');
        return $q->execute([$status === 'ativo' ? 'ativo' : 'inativo', $id]);
    }

    /** Retorna os administradores da empresa sem expor hashes de senha. */
    public static function administradores(int $id): array
    {
        $q = bd()->prepare('SELECT u.id_usuario, u.nome, u.email, u.status, u.ultimo_acesso
                            FROM usuarios u WHERE u.id_estabelecimento = ? AND u.tipo = \'admin\' ORDER BY u.nome');
        $q->execute([$id]);
        return $q->fetchAll();
    }

    /** Cria uma conta administrativa adicional dentro da empresa indicada pelo master. */
    public static function criarAdministrador(int $id, array $dados): int
    {
        if (!self::porIdGlobal($id)) throw new InvalidArgumentException('Estabelecimento não encontrado.');
        $db = bd();
        $db->beginTransaction();
        try {
            $q = $db->prepare('INSERT INTO usuarios (id_estabelecimento, nome, email, senha_hash, tipo) VALUES (?, ?, ?, ?, \'admin\')');
            $q->execute([$id, $dados['nome'], mb_strtolower($dados['email']), password_hash($dados['senha'], PASSWORD_DEFAULT)]);
            $usuario = (int) $db->lastInsertId();
            $q = $db->prepare('INSERT INTO administradores (id_estabelecimento, id_usuario, nivel) VALUES (?, ?, \'super\')');
            $q->execute([$id, $usuario]);
            $db->commit();
            return $usuario;
        } catch (Throwable $erro) {
            if ($db->inTransaction()) $db->rollBack();
            throw $erro;
        }
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
