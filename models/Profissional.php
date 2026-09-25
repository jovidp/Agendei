<?php
/**
 * Profissionais e o vinculo N:N com os servicos que executam.
 *
 * O perfil e o vinculo da pessoa com a empresa como profissional: aponta para
 * vinculos (status, ultimo acesso) e para usuarios (nome, e-mail, telefone).
 * A agenda e por vinculo: a mesma pessoa em dois saloes tem duas agendas.
 */
class Profissional
{
    private const CAMPOS = 'p.id_profissional, p.id_vinculo, p.id_usuario, p.id_filial, p.especialidade, p.bio, p.foto,
                            p.pode_bloquear_agenda, p.data_cadastro, p.token_calendario,
                            u.nome, u.email, u.telefone, v.status';

    /** Juncao do perfil com o vinculo (da empresa da sessao) e a pessoa. */
    private static function juncao(): string
    {
        return 'INNER JOIN vinculos v ON v.id_vinculo = p.id_vinculo AND v.id_estabelecimento = ' . Contexto::id() . '
                INNER JOIN usuarios u ON u.id_usuario = v.id_usuario';
    }

    /**
     * Cria o perfil de profissional + vinculos de servico. Retorna o id_profissional.
     * Sem 'id_usuario' cria a pessoa (nome, e-mail, senha, telefone); com
     * 'id_usuario' vincula uma pessoa que ja existe (em outra empresa ou como
     * administradora desta).
     */
    public static function criar(array $dados): int
    {
        $conexao = bd();
        // Agrupa as gravações para confirmar o conjunto ou desfazê-lo em caso de falha.
        $conexao->beginTransaction();

        try {
            $idUsuario = (int) ($dados['id_usuario'] ?? 0);
            if ($idUsuario <= 0) {
                $idUsuario = Usuario::criar([
                    'nome'     => $dados['nome'],
                    'email'    => $dados['email'],
                    'senha'    => $dados['senha'],
                    'telefone' => $dados['telefone'] ?? null,
                ]);
            }
            $idVinculo = Vinculo::criar(Contexto::id(), $idUsuario, 'profissional', [
                'status' => $dados['status'] ?? 'ativo',
            ]);

            // Todo profissional pertence a uma filial; sem escolha explicita, vai para a padrao.
            $idFilial = (int) ($dados['id_filial'] ?? 0);
            if ($idFilial <= 0) {
                $padrao   = Filial::padrao();
                $idFilial = $padrao ? (int) $padrao['id_filial'] : null;
            }

            $consulta = $conexao->prepare(
                'INSERT INTO profissionais (id_estabelecimento, id_vinculo, id_usuario, id_filial, especialidade, bio, pode_bloquear_agenda)
                 VALUES (' . Contexto::id() . ', :id_vinculo, :id_usuario, :id_filial, :especialidade, :bio, :pode_bloquear)'
            );
            $consulta->execute([
                ':id_vinculo'     => $idVinculo,
                ':id_usuario'     => $idUsuario,
                ':id_filial'      => $idFilial,
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
        $atribuicoes = 'especialidade = :especialidade, bio = :bio, pode_bloquear_agenda = :pode_bloquear';
        $parametros  = [
            ':especialidade' => $dados['especialidade'] ?: null,
            ':bio'           => $dados['bio'] ?? null,
            ':pode_bloquear' => !empty($dados['pode_bloquear_agenda']) ? 1 : 0,
            ':id'            => $idProfissional,
        ];

        // A filial so muda quando o formulario a informa; nao ha troca silenciosa.
        if ((int) ($dados['id_filial'] ?? 0) > 0) {
            $atribuicoes             .= ', id_filial = :id_filial';
            $parametros[':id_filial'] = (int) $dados['id_filial'];
        }

        $consulta = bd()->prepare(
            'UPDATE profissionais
             SET ' . $atribuicoes . '
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_profissional = :id'
        );

        return $consulta->execute($parametros);
    }

    /** Busca o registro pelo identificador; retorna null quando ele não existe. */
    public static function porId(int $idProfissional): ?array
    {
        $sql = 'SELECT ' . self::CAMPOS . '
                FROM profissionais p
                ' . self::juncao() . '
                WHERE p.id_estabelecimento = ' . Contexto::id() . ' AND p.id_profissional = :id LIMIT 1';

        $consulta = bd()->prepare($sql);
        $consulta->execute([':id' => $idProfissional]);
        return $consulta->fetch() ?: null;
    }

    /** Localiza os dados do perfil a partir do identificador da pessoa. */
    public static function porUsuario(int $idUsuario): ?array
    {
        $sql = 'SELECT ' . self::CAMPOS . '
                FROM profissionais p
                ' . self::juncao() . '
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
            $condicoes[] = 'v.status = :status';
            $parametros[':status'] = $filtros['status'];
        }

        $where = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';

        $sql = 'SELECT ' . self::CAMPOS . ',
                       (SELECT COUNT(*) FROM profissional_servico ps WHERE ps.id_estabelecimento = ' . Contexto::id() . ' AND ps.id_profissional = p.id_profissional) AS total_servicos
                FROM profissionais p
                ' . self::juncao() . '
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
                ' . self::juncao() . '
                INNER JOIN profissional_servico ps ON ps.id_profissional = p.id_profissional
                WHERE ps.id_estabelecimento = ' . Contexto::id() . ' AND ps.id_servico = :id_servico AND v.status = \'ativo\'
                ORDER BY u.nome ASC';

        $consulta = bd()->prepare($sql);
        $consulta->execute([':id_servico' => $idServico]);
        return $consulta->fetchAll();
    }

    /** Profissionais ativos de uma filial que executam o servico: a etapa apos a escolha da unidade. */
    public static function porServicoEFilial(int $idServico, int $idFilial): array
    {
        $consulta = bd()->prepare(
            'SELECT ' . self::CAMPOS . '
             FROM profissionais p
             ' . self::juncao() . '
             INNER JOIN profissional_servico ps ON ps.id_profissional = p.id_profissional
             WHERE ps.id_estabelecimento = ' . Contexto::id() . '
               AND ps.id_servico = :id_servico
               AND p.id_filial = :id_filial
               AND v.status = \'ativo\'
             ORDER BY u.nome ASC'
        );
        $consulta->execute([':id_servico' => $idServico, ':id_filial' => $idFilial]);

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

    /** Conta os registros cadastrados, restringindo pelo status do vinculo quando informado. */
    public static function total(?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) AS total FROM profissionais p
                ' . self::juncao() . '
                WHERE p.id_estabelecimento = ' . Contexto::id();
        $parametros = [];

        if ($status !== null) {
            $sql .= ' AND v.status = :status';
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
             ' . self::juncao() . '
             WHERE p.id_estabelecimento = ' . Contexto::id() . ' AND p.id_profissional = :id AND v.status = \'ativo\' LIMIT 1'
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
