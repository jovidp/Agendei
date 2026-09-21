<?php
/**
 * Profissionais e o vinculo N:N com os servicos que executam.
 */
class Profissional
{
    private const CAMPOS = 'p.id_profissional, p.id_usuario, p.especialidade, p.bio, p.foto,
                            p.pode_bloquear_agenda, p.data_cadastro,
                            u.nome, u.email, u.telefone, u.status';

    /** Cria usuario + profissional + vinculos de servico. Retorna o id_profissional. */
    public static function criar(array $dados): int
    {
        $conexao = bd();
        // Agrupa as gravações para confirmar o conjunto ou desfazê-lo em caso de falha.
        $conexao->beginTransaction();

        try {
            $idUsuario = Usuario::criar([
                'nome'     => $dados['nome'],
                'email'    => $dados['email'],
                'senha'    => $dados['senha'],
                'telefone' => $dados['telefone'] ?? null,
                'tipo'     => 'profissional',
                'status'   => $dados['status'] ?? 'ativo',
            ]);

            $consulta = $conexao->prepare(
                'INSERT INTO profissionais (id_estabelecimento, id_usuario, especialidade, bio, pode_bloquear_agenda)
                 VALUES (' . Contexto::id() . ', :id_usuario, :especialidade, :bio, :pode_bloquear)'
            );
            $consulta->execute([
                ':id_usuario'     => $idUsuario,
                ':especialidade'  => $dados['especialidade'] ?: null,
                ':bio'            => $dados['bio'] ?? null,
                ':pode_bloquear'  => !empty($dados['pode_bloquear_agenda']) ? 1 : 0,
            ]);

            $idProfissional = (int) $conexao->lastInsertId();
            self::definirServicos($idProfissional, $dados['servicos'] ?? []);

            // Confirma as alterações depois que todas as operações da transação terminam.
            $conexao->commit();
            return $idProfissional;
        } catch (Throwable $erro) {
            // Desfaz as operações pendentes para não deixar dados parcialmente gravados.
            $conexao->rollBack();
            throw $erro;
        }
    }

    /** Persiste os campos editáveis do cadastro identificado pelo ID. */
    public static function atualizar(int $idProfissional, array $dados): bool
    {
        $consulta = bd()->prepare(
            'UPDATE profissionais
             SET especialidade = :especialidade, bio = :bio, pode_bloquear_agenda = :pode_bloquear
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_profissional = :id'
        );

        return $consulta->execute([
            ':especialidade' => $dados['especialidade'] ?: null,
            ':bio'           => $dados['bio'] ?? null,
            ':pode_bloquear' => !empty($dados['pode_bloquear_agenda']) ? 1 : 0,
            ':id'            => $idProfissional,
        ]);
    }

    /** Busca o registro pelo identificador; retorna null quando ele não existe. */
    public static function porId(int $idProfissional): ?array
    {
        $sql = 'SELECT ' . self::CAMPOS . '
                FROM profissionais p
                INNER JOIN usuarios u ON u.id_usuario = p.id_usuario AND u.id_estabelecimento = ' . Contexto::id() . '
                WHERE p.id_estabelecimento = ' . Contexto::id() . ' AND p.id_profissional = :id LIMIT 1';

        $consulta = bd()->prepare($sql);
        $consulta->execute([':id' => $idProfissional]);
        return $consulta->fetch() ?: null;
    }

    /** Localiza os dados do perfil a partir do identificador da conta de acesso. */
    public static function porUsuario(int $idUsuario): ?array
    {
        $sql = 'SELECT ' . self::CAMPOS . '
                FROM profissionais p
                INNER JOIN usuarios u ON u.id_usuario = p.id_usuario AND u.id_estabelecimento = ' . Contexto::id() . '
                WHERE p.id_estabelecimento = ' . Contexto::id() . ' AND p.id_usuario = :id LIMIT 1';

        $consulta = bd()->prepare($sql);
        $consulta->execute([':id' => $idUsuario]);
        return $consulta->fetch() ?: null;
    }

    /** Filtros: busca, status, apenas_ativos. */
    public static function listar(array $filtros = []): array
    {
        $condicoes = ['p.id_estabelecimento = ' . Contexto::id()];
        $parametros = [];

        if (!empty($filtros['busca'])) {
            $condicoes[] = '(u.nome ' . Sql::como() . ' :busca OR u.email ' . Sql::como() . ' :buscaEmail OR p.especialidade ' . Sql::como() . ' :buscaEspecialidade)';
            $parametros[':busca'] = '%' . $filtros['busca'] . '%';
            $parametros[':buscaEmail'] = $parametros[':busca'];
            $parametros[':buscaEspecialidade'] = $parametros[':busca'];
        }

        if (!empty($filtros['status'])) {
            $condicoes[] = 'u.status = :status';
            $parametros[':status'] = $filtros['status'];
        }

        $where = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';

        $sql = 'SELECT ' . self::CAMPOS . ',
                       (SELECT COUNT(*) FROM profissional_servico ps WHERE ps.id_estabelecimento = ' . Contexto::id() . ' AND ps.id_profissional = p.id_profissional) AS total_servicos
                FROM profissionais p
                INNER JOIN usuarios u ON u.id_usuario = p.id_usuario AND u.id_estabelecimento = ' . Contexto::id() . '
                ' . $where . '
                ORDER BY u.nome ASC';

        $consulta = bd()->prepare($sql);
        $consulta->execute($parametros);
        return $consulta->fetchAll();
    }

    /** Retorna apenas os cadastros ativos para preencher as opções da interface. */
    public static function ativos(): array
    {
        return self::listar(['status' => 'ativo']);
    }

    /** Profissionais ativos que executam um servico ativo. */
    public static function porServico(int $idServico): array
    {
        $sql = 'SELECT ' . self::CAMPOS . '
                FROM profissionais p
                INNER JOIN usuarios u ON u.id_usuario = p.id_usuario AND u.id_estabelecimento = ' . Contexto::id() . '
                INNER JOIN profissional_servico ps ON ps.id_profissional = p.id_profissional
                WHERE ps.id_estabelecimento = ' . Contexto::id() . ' AND ps.id_servico = :id_servico AND u.status = \'ativo\'
                ORDER BY u.nome ASC';

        $consulta = bd()->prepare($sql);
        $consulta->execute([':id_servico' => $idServico]);
        return $consulta->fetchAll();
    }

    /** Confere o vínculo que permite ao profissional realizar o serviço. */
    public static function executaServico(int $idProfissional, int $idServico): bool
    {
        $consulta = bd()->prepare(
            'SELECT 1 FROM profissional_servico
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_profissional = :profissional AND id_servico = :servico LIMIT 1'
        );
        $consulta->execute([':profissional' => $idProfissional, ':servico' => $idServico]);
        return (bool) $consulta->fetch();
    }

    /** @return int[] ids dos servicos vinculados */
    public static function idsServicos(int $idProfissional): array
    {
        $consulta = bd()->prepare(
            'SELECT id_servico FROM profissional_servico WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_profissional = :id'
        );
        $consulta->execute([':id' => $idProfissional]);
        return array_map('intval', $consulta->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Consulta os dados dos serviços vinculados ao profissional. */
    public static function servicos(int $idProfissional): array
    {
        $sql = 'SELECT s.*
                FROM servicos s
                INNER JOIN profissional_servico ps ON ps.id_servico = s.id_servico
                WHERE ps.id_estabelecimento = ' . Contexto::id() . ' AND ps.id_profissional = :id
                ORDER BY s.nome ASC';

        $consulta = bd()->prepare($sql);
        $consulta->execute([':id' => $idProfissional]);
        return $consulta->fetchAll();
    }

    /** Substitui os vinculos de servico do profissional. */
    public static function definirServicos(int $idProfissional, array $idsServicos): void
    {
        // Valida todos os vínculos antes de remover a seleção anterior.
        if (!self::porId($idProfissional)) throw new InvalidArgumentException('Profissional não pertence ao estabelecimento.');
        foreach ($idsServicos as $idServico) {
            if (!Servico::porId((int) $idServico)) throw new InvalidArgumentException('Serviço não pertence ao estabelecimento.');
        }
        $conexao = bd();

        $remover = $conexao->prepare('DELETE FROM profissional_servico WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_profissional = :id');
        $remover->execute([':id' => $idProfissional]);

        if ($idsServicos === []) {
            return;
        }

        $inserir = $conexao->prepare(
            'INSERT INTO profissional_servico (id_estabelecimento, id_profissional, id_servico) VALUES (' . Contexto::id() . ', :profissional, :servico)'
        );

        foreach (array_unique(array_map('intval', $idsServicos)) as $idServico) {
            if ($idServico > 0) {
                $inserir->execute([':profissional' => $idProfissional, ':servico' => $idServico]);
            }
        }
    }

    /** Conta os registros cadastrados, restringindo pelo status quando informado. */
    public static function total(?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) AS total FROM profissionais p
                INNER JOIN usuarios u ON u.id_usuario = p.id_usuario AND u.id_estabelecimento = ' . Contexto::id() . '';
        $parametros = [];

        if ($status !== null) {
            $sql .= ' WHERE u.id_estabelecimento = ' . Contexto::id() . ' AND u.status = :status';
            $parametros[':status'] = $status;
        }

        $consulta = bd()->prepare($sql);
        $consulta->execute($parametros);
        return (int) ($consulta->fetch()['total'] ?? 0);
    }

    /** Verifica se o cadastro existe e está habilitado para uso. */
    public static function estaAtivo(int $idProfissional): bool
    {
        $consulta = bd()->prepare(
            'SELECT 1 FROM profissionais p
             INNER JOIN usuarios u ON u.id_usuario = p.id_usuario AND u.id_estabelecimento = ' . Contexto::id() . '
             WHERE p.id_estabelecimento = ' . Contexto::id() . ' AND p.id_profissional = :id AND u.status = \'ativo\' LIMIT 1'
        );
        $consulta->execute([':id' => $idProfissional]);
        return (bool) $consulta->fetch();
    }

    /** Consulta a permissão individual de bloqueio de agenda do profissional. */
    public static function podeBloquearAgenda(int $idProfissional): bool
    {
        $consulta = bd()->prepare(
            'SELECT pode_bloquear_agenda FROM profissionais WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_profissional = :id LIMIT 1'
        );
        $consulta->execute([':id' => $idProfissional]);
        $registro = $consulta->fetch();

        return $registro && (int) $registro['pode_bloquear_agenda'] === 1;
    }
}
