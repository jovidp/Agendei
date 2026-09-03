<?php
/**
 * Clientes: perfil de quem agenda os servicos.
 */
class Cliente
{
    private const CAMPOS = 'c.id_cliente, c.id_usuario, c.cpf, c.data_nascimento, c.observacoes,
                            c.data_cadastro, u.nome, u.email, u.telefone, u.status';

    /** Cria usuario + cliente em uma unica transacao. Retorna o id_cliente. */
    public static function criar(array $dados): int
    {
        $conexao = bd();
        $conexao->beginTransaction();

        try {
            $idUsuario = Usuario::criar([
                'nome'     => $dados['nome'],
                'email'    => $dados['email'],
                'senha'    => $dados['senha'],
                'telefone' => $dados['telefone'] ?? null,
                'tipo'     => 'cliente',
                'status'   => $dados['status'] ?? 'ativo',
            ]);

            $consulta = $conexao->prepare(
                'INSERT INTO clientes (id_usuario, cpf, data_nascimento)
                 VALUES (:id_usuario, :cpf, :data_nascimento)'
            );
            $consulta->execute([
                ':id_usuario'      => $idUsuario,
                ':cpf'             => apenasNumeros($dados['cpf'] ?? '') ?: null,
                ':data_nascimento' => $dados['data_nascimento'] ?? null,
            ]);

            $idCliente = (int) $conexao->lastInsertId();
            $conexao->commit();

            return $idCliente;
        } catch (Throwable $erro) {
            $conexao->rollBack();
            throw $erro;
        }
    }

    public static function porId(int $idCliente): ?array
    {
        $sql = 'SELECT ' . self::CAMPOS . '
                FROM clientes c
                INNER JOIN usuarios u ON u.id_usuario = c.id_usuario
                WHERE c.id_cliente = :id LIMIT 1';

        $consulta = bd()->prepare($sql);
        $consulta->execute([':id' => $idCliente]);
        return $consulta->fetch() ?: null;
    }

    public static function porUsuario(int $idUsuario): ?array
    {
        $sql = 'SELECT ' . self::CAMPOS . '
                FROM clientes c
                INNER JOIN usuarios u ON u.id_usuario = c.id_usuario
                WHERE c.id_usuario = :id LIMIT 1';

        $consulta = bd()->prepare($sql);
        $consulta->execute([':id' => $idUsuario]);
        return $consulta->fetch() ?: null;
    }

    public static function cpfEmUso(string $cpf, ?int $ignorarIdCliente = null): bool
    {
        $cpf = apenasNumeros($cpf);
        if ($cpf === '') {
            return false;
        }

        $sql = 'SELECT id_cliente FROM clientes WHERE cpf = :cpf';
        $parametros = [':cpf' => $cpf];

        if ($ignorarIdCliente !== null) {
            $sql .= ' AND id_cliente <> :id';
            $parametros[':id'] = $ignorarIdCliente;
        }

        $consulta = bd()->prepare($sql . ' LIMIT 1');
        $consulta->execute($parametros);
        return (bool) $consulta->fetch();
    }

    /**
     * Filtros aceitos: busca, status, limite, deslocamento.
     */
    public static function listar(array $filtros = []): array
    {
        [$where, $parametros] = self::montarFiltros($filtros);

        $sql = 'SELECT ' . self::CAMPOS . ',
                       (SELECT COUNT(*) FROM agendamentos a WHERE a.id_cliente = c.id_cliente) AS total_agendamentos
                FROM clientes c
                INNER JOIN usuarios u ON u.id_usuario = c.id_usuario
                ' . $where . '
                ORDER BY u.nome ASC';

        if (!empty($filtros['limite'])) {
            $sql .= ' LIMIT ' . (int) $filtros['limite'] . ' OFFSET ' . (int) ($filtros['deslocamento'] ?? 0);
        }

        $consulta = bd()->prepare($sql);
        $consulta->execute($parametros);
        return $consulta->fetchAll();
    }

    public static function contar(array $filtros = []): int
    {
        [$where, $parametros] = self::montarFiltros($filtros);

        $sql = 'SELECT COUNT(*) AS total
                FROM clientes c
                INNER JOIN usuarios u ON u.id_usuario = c.id_usuario ' . $where;

        $consulta = bd()->prepare($sql);
        $consulta->execute($parametros);
        return (int) ($consulta->fetch()['total'] ?? 0);
    }

    private static function montarFiltros(array $filtros): array
    {
        $condicoes  = [];
        $parametros = [];

        if (!empty($filtros['busca'])) {
            $condicoes[] = '(u.nome LIKE :busca OR u.email LIKE :busca OR c.cpf LIKE :buscaNumeros)';
            $parametros[':busca']        = '%' . $filtros['busca'] . '%';
            $parametros[':buscaNumeros'] = '%' . apenasNumeros($filtros['busca']) . '%';
        }

        if (!empty($filtros['status'])) {
            $condicoes[] = 'u.status = :status';
            $parametros[':status'] = $filtros['status'];
        }

        $where = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';
        return [$where, $parametros];
    }

    /** Atualiza os dados especificos do cliente (os comuns ficam em Usuario::atualizar). */
    public static function atualizar(int $idCliente, array $dados): bool
    {
        $consulta = bd()->prepare(
            'UPDATE clientes SET cpf = :cpf, data_nascimento = :data_nascimento, observacoes = :observacoes
             WHERE id_cliente = :id'
        );

        return $consulta->execute([
            ':cpf'             => apenasNumeros($dados['cpf'] ?? '') ?: null,
            ':data_nascimento' => $dados['data_nascimento'] ?: null,
            ':observacoes'     => $dados['observacoes'] ?? null,
            ':id'              => $idCliente,
        ]);
    }

    public static function total(?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) AS total FROM clientes c
                INNER JOIN usuarios u ON u.id_usuario = c.id_usuario';
        $parametros = [];

        if ($status !== null) {
            $sql .= ' WHERE u.status = :status';
            $parametros[':status'] = $status;
        }

        $consulta = bd()->prepare($sql);
        $consulta->execute($parametros);
        return (int) ($consulta->fetch()['total'] ?? 0);
    }

    public static function cadastradosDesde(string $data): int
    {
        $consulta = bd()->prepare('SELECT COUNT(*) AS total FROM clientes WHERE data_cadastro >= :data');
        $consulta->execute([':data' => $data]);
        return (int) ($consulta->fetch()['total'] ?? 0);
    }
}
