<?php
/**
 * Clientes: perfil de quem agenda os servicos.
 *
 * O perfil e o vinculo da pessoa com a empresa como cliente: aponta para
 * vinculos (status, login, ultimo acesso) e para usuarios (dados pessoais).
 */
class Cliente
{
    private const CAMPOS = 'c.id_cliente, c.id_vinculo, c.id_usuario, c.cpf, c.data_nascimento, c.observacoes,
                            c.data_cadastro, c.pontos_fidelidade, u.nome, u.email, u.telefone, v.status,
                            v.login, u.sexo, u.nome_materno, u.telefone_fixo, u.cep, u.logradouro,
                            u.numero, u.complemento, u.bairro, u.cidade, u.uf, v.ultimo_acesso';

    /** Juncao do perfil com o vinculo (da empresa da sessao) e a pessoa. */
    private static function juncao(): string
    {
        return 'INNER JOIN vinculos v ON v.id_vinculo = c.id_vinculo AND v.id_estabelecimento = ' . Contexto::id() . '
                INNER JOIN usuarios u ON u.id_usuario = v.id_usuario';
    }

    /**
     * Cria o perfil de cliente em uma unica transacao. Retorna o id_cliente.
     * Sem 'id_usuario' cria a pessoa (nome, e-mail, senha e cadastro completo);
     * com 'id_usuario' vincula uma pessoa que ja existe em outra empresa.
     */
    public static function criar(array $dados): int
    {
        $conexao = bd();
        // Agrupa as gravações para confirmar o conjunto ou desfazê-lo em caso de falha.
        $conexao->beginTransaction();

        try {
            // Os dados pessoais ficam em usuarios; o vinculo guarda status e login;
            // clientes guarda o que é específico do perfil.
            $idUsuario = (int) ($dados['id_usuario'] ?? 0);
            if ($idUsuario <= 0) {
                $idUsuario = Usuario::criar($dados);
            }
            $idVinculo = Vinculo::criar(Contexto::id(), $idUsuario, 'cliente', [
                'status' => $dados['status'] ?? 'ativo',
                'login'  => $dados['login'] ?? '',
            ]);

            $consulta = $conexao->prepare(
                'INSERT INTO clientes (id_estabelecimento, id_vinculo, id_usuario, cpf, data_nascimento)
                 VALUES (' . Contexto::id() . ', :id_vinculo, :id_usuario, :cpf, :data_nascimento)'
            );
            $consulta->execute([
                ':id_vinculo'      => $idVinculo,
                ':id_usuario'      => $idUsuario,
                ':cpf'             => apenasNumeros($dados['cpf'] ?? '') ?: null,
                ':data_nascimento' => $dados['data_nascimento'] ?? null,
            ]);

            $idCliente = (int) $conexao->lastInsertId();
            // Confirma as alterações depois que todas as operações da transação terminam.
            $conexao->commit();

            return $idCliente;
        } catch (Throwable $erro) {
            // Desfaz as operações pendentes para não deixar dados parcialmente gravados.
            $conexao->rollBack();
            throw $erro;
        }
    }

    /** Busca o registro pelo identificador; retorna null quando ele não existe. */
    public static function porId(int $idCliente): ?array
    {
        $sql = 'SELECT ' . self::CAMPOS . '
                FROM clientes c
                ' . self::juncao() . '
                WHERE c.id_estabelecimento = ' . Contexto::id() . ' AND c.id_cliente = :id LIMIT 1';

        $consulta = bd()->prepare($sql);
        $consulta->execute([':id' => $idCliente]);
        return $consulta->fetch() ?: null;
    }

    /** Localiza os dados do perfil a partir do identificador da pessoa. */
    public static function porUsuario(int $idUsuario): ?array
    {
        $sql = 'SELECT ' . self::CAMPOS . '
                FROM clientes c
                ' . self::juncao() . '
                WHERE c.id_estabelecimento = ' . Contexto::id() . ' AND c.id_usuario = :id LIMIT 1';

        $consulta = bd()->prepare($sql);
        $consulta->execute([':id' => $idUsuario]);
        return $consulta->fetch() ?: null;
    }

    /** Detecta CPF duplicado, desconsiderando o próprio cliente durante a edição. */
    public static function cpfEmUso(string $cpf, ?int $ignorarIdCliente = null): bool
    {
        $cpf = apenasNumeros($cpf);
        if ($cpf === '') {
            return false;
        }

        $sql = 'SELECT id_cliente FROM clientes WHERE id_estabelecimento = ' . Contexto::id() . ' AND cpf = :cpf';
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
                       (SELECT COUNT(*) FROM agendamentos a WHERE a.id_estabelecimento = ' . Contexto::id() . ' AND a.id_cliente = c.id_cliente) AS total_agendamentos
                FROM clientes c
                ' . self::juncao() . '
                ' . $where . '
                ORDER BY u.nome ASC';

        if (!empty($filtros['limite'])) {
            $sql .= ' LIMIT ' . (int) $filtros['limite'] . ' OFFSET ' . (int) ($filtros['deslocamento'] ?? 0);
        }

        $consulta = bd()->prepare($sql);
        $consulta->execute($parametros);
        return $consulta->fetchAll();
    }

    /** Conta os registros com os mesmos filtros da listagem, sem aplicar paginação. */
    public static function contar(array $filtros = []): int
    {
        [$where, $parametros] = self::montarFiltros($filtros);

        $sql = 'SELECT COUNT(*) AS total
                FROM clientes c
                ' . self::juncao() . ' ' . $where;

        $consulta = bd()->prepare($sql);
        $consulta->execute($parametros);
        return (int) ($consulta->fetch()['total'] ?? 0);
    }

    /** Separa as condições SQL dos valores enviados ao PDO para reutilizar os filtros. */
    private static function montarFiltros(array $filtros): array
    {
        $condicoes = ['c.id_estabelecimento = ' . Contexto::id()];
        $parametros = [];

        if (!empty($filtros['busca'])) {
            $condicoes[] = '(u.nome ' . Sql::como() . ' :busca OR u.email ' . Sql::como() . ' :buscaEmail OR c.cpf ' . Sql::como() . ' :buscaNumeros)';
            $parametros[':busca']        = '%' . $filtros['busca'] . '%';
            $parametros[':buscaEmail'] = $parametros[':busca'];
            $numerosBusca = apenasNumeros($filtros['busca']);
            $parametros[':buscaNumeros'] = '%' . ($numerosBusca !== '' ? $numerosBusca : $filtros['busca']) . '%';
        }

        if (!empty($filtros['status'])) {
            $condicoes[] = 'v.status = :status';
            $parametros[':status'] = $filtros['status'];
        }

        $where = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';
        return [$where, $parametros];
    }

    /**
     * Atualiza os dados especificos do cliente (os comuns ficam em Usuario::atualizar).
     * A data de nascimento e replicada em usuarios porque o 2FA le a coluna de la.
     */
    public static function atualizar(int $idCliente, array $dados): bool
    {
        $consulta = bd()->prepare(
            'UPDATE clientes SET cpf = :cpf, data_nascimento = :data_nascimento, observacoes = :observacoes
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_cliente = :id'
        );

        $gravou = $consulta->execute([
            ':cpf'             => apenasNumeros($dados['cpf'] ?? '') ?: null,
            ':data_nascimento' => $dados['data_nascimento'] ?: null,
            ':observacoes'     => $dados['observacoes'] ?? null,
            ':id'              => $idCliente,
        ]);

        // Subconsulta no lugar de UPDATE com JOIN: a forma com JOIN so existe no MySQL.
        $sincroniza = bd()->prepare(
            'UPDATE usuarios
             SET data_nascimento = :data_nascimento
             WHERE id_usuario IN (SELECT c.id_usuario FROM clientes c
                                  WHERE c.id_estabelecimento = ' . Contexto::id() . ' AND c.id_cliente = :id)'
        );
        $sincroniza->execute([
            ':data_nascimento' => $dados['data_nascimento'] ?: null,
            ':id'              => $idCliente,
        ]);

        return $gravou;
    }

    /** Conta os registros cadastrados, restringindo pelo status do vinculo quando informado. */
    public static function total(?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) AS total FROM clientes c
                ' . self::juncao() . '
                WHERE c.id_estabelecimento = ' . Contexto::id();
        $parametros = [];

        if ($status !== null) {
            $sql .= ' AND v.status = :status';
            $parametros[':status'] = $status;
        }

        $consulta = bd()->prepare($sql);
        $consulta->execute($parametros);
        return (int) ($consulta->fetch()['total'] ?? 0);
    }

    /** Conta os clientes cadastrados a partir da data recebida. */
    public static function cadastradosDesde(string $data): int
    {
        $consulta = bd()->prepare('SELECT COUNT(*) AS total FROM clientes WHERE id_estabelecimento = ' . Contexto::id() . ' AND data_cadastro >= :data');
        $consulta->execute([':data' => $data]);
        return (int) ($consulta->fetch()['total'] ?? 0);
    }
}
