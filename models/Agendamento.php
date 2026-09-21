<?php
/**
 * Agendamentos: criacao com verificacao transacional de disponibilidade,
 * consultas para os paineis e mudanca de status.
 */
class Agendamento
{
    public const STATUS = ['agendado', 'confirmado', 'concluido', 'cancelado'];

    // Reutiliza a seleção de campos e as junções que acrescentam nomes aos dados da reserva.
    private const SELECAO = 'a.*,
        uc.nome AS nome_cliente, uc.telefone AS telefone_cliente, uc.email AS email_cliente,
        c.cpf AS cpf_cliente,
        up.nome AS nome_profissional,
        s.nome AS nome_servico, s.duracao_minutos';

    private const JUNCOES = '
        FROM agendamentos a
        INNER JOIN clientes c        ON c.id_cliente = a.id_cliente
        INNER JOIN usuarios uc       ON uc.id_usuario = c.id_usuario
        INNER JOIN profissionais p   ON p.id_profissional = a.id_profissional
        INNER JOIN usuarios up       ON up.id_usuario = p.id_usuario
        INNER JOIN servicos s        ON s.id_servico = a.id_servico';

    // -----------------------------------------------------------------
    // Consultas
    // -----------------------------------------------------------------

    /** Busca o registro pelo identificador; retorna null quando ele não existe. */
    public static function porId(int $idAgendamento): ?array
    {
        $consulta = bd()->prepare(
            'SELECT ' . self::SELECAO . self::JUNCOES . ' WHERE a.id_estabelecimento = ' . Contexto::id() . ' AND a.id_agendamento = :id LIMIT 1'
        );
        $consulta->execute([':id' => $idAgendamento]);
        return $consulta->fetch() ?: null;
    }

    /**
     * Filtros: id_cliente, id_profissional, id_servico, status, status_em (array),
     * data, data_inicial, data_final, busca, ordem ('asc'|'desc'), limite, deslocamento.
     */
    public static function listar(array $filtros = []): array
    {
        [$where, $parametros] = self::montarFiltros($filtros);

        $ordem = strtolower($filtros['ordem'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $sql = 'SELECT ' . self::SELECAO . self::JUNCOES . ' ' . $where .
               " ORDER BY a.data_agendamento {$ordem}, a.hora_inicio {$ordem}";

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

        $consulta = bd()->prepare('SELECT COUNT(*) AS total' . self::JUNCOES . ' ' . $where);
        $consulta->execute($parametros);
        return (int) ($consulta->fetch()['total'] ?? 0);
    }

    /** Separa as condições SQL dos valores enviados ao PDO para reutilizar os filtros. */
    private static function montarFiltros(array $filtros): array
    {
        $condicoes = ['a.id_estabelecimento = ' . Contexto::id()];
        $parametros = [];

        if (!empty($filtros['id_cliente'])) {
            $condicoes[] = 'a.id_cliente = :id_cliente';
            $parametros[':id_cliente'] = (int) $filtros['id_cliente'];
        }

        if (!empty($filtros['id_profissional'])) {
            $condicoes[] = 'a.id_profissional = :id_profissional';
            $parametros[':id_profissional'] = (int) $filtros['id_profissional'];
        }

        if (!empty($filtros['id_servico'])) {
            $condicoes[] = 'a.id_servico = :id_servico';
            $parametros[':id_servico'] = (int) $filtros['id_servico'];
        }

        if (!empty($filtros['status'])) {
            $condicoes[] = 'a.status = :status';
            $parametros[':status'] = $filtros['status'];
        }

        if (!empty($filtros['status_em']) && is_array($filtros['status_em'])) {
            $lista = [];
            foreach (array_values($filtros['status_em']) as $indice => $status) {
                if (!in_array($status, self::STATUS, true)) {
                    continue;
                }
                $chave = ':status_em' . $indice;
                $lista[] = $chave;
                $parametros[$chave] = $status;
            }
            if ($lista !== []) {
                $condicoes[] = 'a.status IN (' . implode(', ', $lista) . ')';
            }
        }

        if (!empty($filtros['data'])) {
            $condicoes[] = 'a.data_agendamento = :data';
            $parametros[':data'] = $filtros['data'];
        }

        if (!empty($filtros['data_inicial'])) {
            $condicoes[] = 'a.data_agendamento >= :data_inicial';
            $parametros[':data_inicial'] = $filtros['data_inicial'];
        }

        if (!empty($filtros['data_final'])) {
            $condicoes[] = 'a.data_agendamento <= :data_final';
            $parametros[':data_final'] = $filtros['data_final'];
        }

        if (!empty($filtros['a_partir_de_hoje'])) {
            $condicoes[] = '(a.data_agendamento > CURRENT_DATE OR (a.data_agendamento = CURRENT_DATE AND a.hora_fim >= CURRENT_TIME))';
        }

        if (!empty($filtros['ate_hoje'])) {
            $condicoes[] = '(a.data_agendamento < CURRENT_DATE OR (a.data_agendamento = CURRENT_DATE AND a.hora_fim < CURRENT_TIME))';
        }

        if (!empty($filtros['busca'])) {
            $condicoes[] = '(uc.nome ' . Sql::como() . ' :busca OR up.nome ' . Sql::como() . ' :buscaProfissional OR s.nome ' . Sql::como() . ' :buscaServico)';
            $parametros[':busca'] = '%' . $filtros['busca'] . '%';
            $parametros[':buscaProfissional'] = $parametros[':busca'];
            $parametros[':buscaServico'] = $parametros[':busca'];
        }

        $where = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';
        return [$where, $parametros];
    }

    /** Agendamentos de um dia, opcionalmente de um profissional. */
    public static function doDia(string $data, ?int $idProfissional = null): array
    {
        return self::listar([
            'data'            => $data,
            'id_profissional' => $idProfissional,
            'status_em'       => ['agendado', 'confirmado', 'concluido'],
            'ordem'           => 'asc',
        ]);
    }

    /** Agendamentos de um intervalo, para a visao semanal da agenda. */
    public static function doPeriodo(string $dataInicial, string $dataFinal, ?int $idProfissional = null): array
    {
        return self::listar([
            'data_inicial'    => $dataInicial,
            'data_final'      => $dataFinal,
            'id_profissional' => $idProfissional,
            'status_em'       => ['agendado', 'confirmado', 'concluido'],
            'ordem'           => 'asc',
        ]);
    }

    /** Seleciona os próximos agendamentos do cliente para o resumo do painel. */
    public static function proximosDoCliente(int $idCliente, int $limite = 5): array
    {
        return self::listar([
            'id_cliente'       => $idCliente,
            'status_em'        => ['agendado', 'confirmado'],
            'a_partir_de_hoje' => true,
            'ordem'            => 'asc',
            'limite'           => $limite,
        ]);
    }

    /** Intervalos ocupados de um profissional em uma data (usado pelo motor de disponibilidade). */
    public static function ocupacoesDoDia(int $idProfissional, string $data, ?int $ignorarAgendamento = null): array
    {
        $sql = 'SELECT hora_inicio, hora_fim FROM agendamentos
                WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_profissional = :profissional
                  AND data_agendamento = :data
                  AND status IN (\'agendado\',\'confirmado\',\'concluido\')';

        $parametros = [':profissional' => $idProfissional, ':data' => $data];

        if ($ignorarAgendamento !== null) {
            $sql .= ' AND id_agendamento <> :ignorar';
            $parametros[':ignorar'] = $ignorarAgendamento;
        }

        $consulta = bd()->prepare($sql);
        $consulta->execute($parametros);
        return $consulta->fetchAll();
    }

    /**
     * Existe agendamento ativo do profissional sobrepondo o intervalo?
     * Com $bloquearLinhas = true a leitura trava as linhas ate o fim da transacao,
     * impedindo que dois clientes reservem o mesmo horario simultaneamente.
     */
    public static function existeConflito(
        int $idProfissional,
        string $data,
        string $horaInicio,
        string $horaFim,
        ?int $ignorarAgendamento = null,
        bool $bloquearLinhas = false
    ): bool {
        $sql = 'SELECT id_agendamento FROM agendamentos
                WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_profissional = :profissional
                  AND data_agendamento = :data
                  AND status IN (\'agendado\',\'confirmado\',\'concluido\')
                  AND hora_inicio < :fim
                  AND hora_fim > :inicio';

        $parametros = [
            ':profissional' => $idProfissional,
            ':data'         => $data,
            ':inicio'       => $horaInicio,
            ':fim'          => $horaFim,
        ];

        if ($ignorarAgendamento !== null) {
            $sql .= ' AND id_agendamento <> :ignorar';
            $parametros[':ignorar'] = $ignorarAgendamento;
        }

        if ($bloquearLinhas) {
            $sql .= ' FOR UPDATE';
        }

        $consulta = bd()->prepare($sql);
        $consulta->execute($parametros);
        return (bool) $consulta->fetch();
    }

    /** O proprio cliente ja tem outro atendimento neste intervalo? */
    public static function clientePossuiConflito(
        int $idCliente,
        string $data,
        string $horaInicio,
        string $horaFim,
        ?int $ignorarAgendamento = null
    ): bool {
        $sql = 'SELECT id_agendamento FROM agendamentos
                WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_cliente = :cliente
                  AND data_agendamento = :data
                  AND status IN (\'agendado\',\'confirmado\')
                  AND hora_inicio < :fim
                  AND hora_fim > :inicio';

        $parametros = [
            ':cliente' => $idCliente,
            ':data'    => $data,
            ':inicio'  => $horaInicio,
            ':fim'     => $horaFim,
        ];

        if ($ignorarAgendamento !== null) {
            $sql .= ' AND id_agendamento <> :ignorar';
            $parametros[':ignorar'] = $ignorarAgendamento;
        }

        $consulta = bd()->prepare($sql . ' LIMIT 1');
        $consulta->execute($parametros);
        return (bool) $consulta->fetch();
    }

    // -----------------------------------------------------------------
    // Criacao
    // -----------------------------------------------------------------

    /**
     * Cria o agendamento validando tudo novamente dentro de uma transacao.
     *
     * $dados:  id_cliente, id_profissional, id_servico, data, hora_inicio, observacao, origem
     * $opcoes: ignorar_antecedencia (admin/profissional podem encaixar horarios)
     *
     * @return array{sucesso: bool, erros: string[], id_agendamento?: int}
     */
    public static function criar(array $dados, array $opcoes = []): array
    {
        $idCliente      = (int) ($dados['id_cliente'] ?? 0);
        $idProfissional = (int) ($dados['id_profissional'] ?? 0);
        $idServico      = (int) ($dados['id_servico'] ?? 0);
        $data           = (string) ($dados['data'] ?? '');
        $horaInicio     = (string) ($dados['hora_inicio'] ?? '');

        if (strlen($horaInicio) === 5) {
            $horaInicio .= ':00';
        }

        $cliente = Cliente::porId($idCliente);
        if (!$cliente) {
            return ['sucesso' => false, 'erros' => ['Cliente nao encontrado.']];
        }
        if ($cliente['status'] !== 'ativo') {
            return ['sucesso' => false, 'erros' => ['Este cliente esta inativo e nao pode agendar.']];
        }

        $conexao = bd();
        // Agrupa as gravações para confirmar o conjunto ou desfazê-lo em caso de falha.
        $conexao->beginTransaction();

        try {
            // Revalida a disponibilidade dentro da transação, pois a consulta da interface pode estar desatualizada.
            $erros = Disponibilidade::validar(
                [
                    'id_profissional' => $idProfissional,
                    'id_servico'      => $idServico,
                    'data'            => $data,
                    'hora_inicio'     => $horaInicio,
                ],
                [
                    'bloquear_linhas'      => true,
                    'ignorar_antecedencia' => !empty($opcoes['ignorar_antecedencia']),
                ]
            );

            if ($erros !== []) {
                // Desfaz as operações pendentes para não deixar dados parcialmente gravados.
                $conexao->rollBack();
                return ['sucesso' => false, 'erros' => $erros];
            }

            // Obtém preço e duração no servidor para calcular o horário final e registrar o valor da reserva.
            $servico = Servico::porId($idServico);
            $horaFim = somarMinutos($horaInicio, (int) $servico['duracao_minutos']);

            if (self::clientePossuiConflito($idCliente, $data, $horaInicio, $horaFim)) {
                $conexao->rollBack();
                return ['sucesso' => false, 'erros' => ['Voce ja possui outro agendamento neste horario.']];
            }

            $status = $dados['status'] ?? (Configuracao::ativa('confirmar_automaticamente') ? 'confirmado' : 'agendado');
            if (!in_array($status, self::STATUS, true)) {
                $status = 'agendado';
            }

            $consulta = $conexao->prepare(
                'INSERT INTO agendamentos
                    (id_estabelecimento, id_cliente, id_profissional, id_servico, data_agendamento, hora_inicio, hora_fim,
                     valor, status, observacao, origem, grupo_recorrencia)
                 VALUES (' . Contexto::id() . ', :cliente, :profissional, :servico, :data, :inicio, :fim,
                     :valor, :status, :observacao, :origem, :grupo_recorrencia)'
            );

            $consulta->execute([
                ':cliente'      => $idCliente,
                ':profissional' => $idProfissional,
                ':servico'      => $idServico,
                ':data'         => $data,
                ':inicio'       => $horaInicio,
                ':fim'          => $horaFim,
                ':valor'        => $servico['preco'],
                ':status'       => $status,
                ':observacao'   => $dados['observacao'] ?: null,
                ':origem'       => in_array($dados['origem'] ?? '', ['cliente', 'admin', 'profissional'], true)
                    ? $dados['origem']
                    : 'cliente',
                ':grupo_recorrencia' => $dados['grupo_recorrencia'] ?? null,
            ]);

            $idAgendamento = (int) $conexao->lastInsertId();
            // Confirma as alterações depois que todas as operações da transação terminam.
            $conexao->commit();
            try {
                if (!Diferencial::usarCreditoPacote($idAgendamento)) {
                    Diferencial::criarPagamentoSinal($idAgendamento);
                }
            } catch (Throwable $erroRecurso) {
                error_log('Agendamento criado, mas o benefício financeiro falhou: ' . $erroRecurso->getMessage());
            }

            return ['sucesso' => true, 'erros' => [], 'id_agendamento' => $idAgendamento];
        } catch (Throwable $erro) {
            if ($conexao->inTransaction()) {
                $conexao->rollBack();
            }
            error_log('Falha ao criar agendamento: ' . $erro->getMessage());
            return ['sucesso' => false, 'erros' => ['Nao foi possivel concluir o agendamento. Tente novamente.']];
        }
    }

    // -----------------------------------------------------------------
    // Mudanca de status
    // -----------------------------------------------------------------

    /** Atualiza a situação do registro identificado pelo ID. */
    public static function alterarStatus(int $idAgendamento, string $status): bool
    {
        if (!in_array($status, self::STATUS, true)) {
            return false;
        }

        $consulta = bd()->prepare(
            'UPDATE agendamentos SET status = :status, motivo_cancelamento = NULL, id_usuario_cancelou = NULL
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_agendamento = :id'
        );

        $alterado = $consulta->execute([':status' => $status, ':id' => $idAgendamento]);
        if ($alterado && $status === 'concluido') {
            try {
                Diferencial::pontuar($idAgendamento);
            } catch (Throwable $erroRecurso) {
                error_log('Status alterado, mas os pontos não foram creditados: ' . $erroRecurso->getMessage());
            }
        }
        return $alterado;
    }

    /** Registra o cancelamento junto ao motivo e ao usuário responsável pela ação. */
    public static function cancelar(int $idAgendamento, ?int $idUsuario, ?string $motivo = null): bool
    {
        if ($idUsuario !== null && !Usuario::porId($idUsuario)) return false;
        $agendamento = self::porId($idAgendamento);
        if (!$agendamento) return false;
        $consulta = bd()->prepare(
            'UPDATE agendamentos
             SET status = \'cancelado\', motivo_cancelamento = :motivo, id_usuario_cancelou = :usuario
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_agendamento = :id AND status <> \'cancelado\''
        );

        $cancelado = $consulta->execute([
            ':motivo'  => $motivo ?: null,
            ':usuario' => $idUsuario,
            ':id'      => $idAgendamento,
        ]);
        if ($cancelado && $consulta->rowCount()) {
            try {
                Diferencial::cancelarFinanceiro($agendamento);
                Diferencial::avisarListaEspera($agendamento);
            } catch (Throwable $erroRecurso) {
                error_log('Cancelamento concluído, mas o aviso de encaixe falhou: ' . $erroRecurso->getMessage());
            }
        }
        return $cancelado;
    }

    /** Altera somente a observação da reserva, usando null para um texto vazio. */
    public static function atualizarObservacao(int $idAgendamento, ?string $observacao): bool
    {
        $consulta = bd()->prepare('UPDATE agendamentos SET observacao = :observacao WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_agendamento = :id');
        return $consulta->execute([':observacao' => $observacao ?: null, ':id' => $idAgendamento]);
    }

    /** Regra de cancelamento pelo cliente: respeita o limite de horas configurado. */
    public static function clientePodeCancelar(array $agendamento): bool
    {
        if (!in_array($agendamento['status'], ['agendado', 'confirmado'], true)) {
            return false;
        }

        $limiteHoras = Configuracao::obterInteiro('cancelamento_limite_horas', 4);
        $inicio      = strtotime($agendamento['data_agendamento'] . ' ' . $agendamento['hora_inicio']);

        return $inicio - ($limiteHoras * 3600) > time();
    }

    /** Ja passou do horario de inicio? Usado para liberar "concluir atendimento". */
    public static function jaComecou(array $agendamento): bool
    {
        return strtotime($agendamento['data_agendamento'] . ' ' . $agendamento['hora_inicio']) <= time();
    }

    /** Reutiliza a contagem de agendamentos com o cliente e o status solicitados. */
    public static function totalPorCliente(int $idCliente, ?string $status = null): int
    {
        // Reúne os critérios usados para consultar a lista e calcular os totais.
        $filtros = ['id_cliente' => $idCliente];
        if ($status !== null) {
            $filtros['status'] = $status;
        }
        return self::contar($filtros);
    }
}
