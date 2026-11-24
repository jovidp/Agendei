<?php
/**
 * Consultas agregadas para o dashboard e para a area de relatorios.
 */
class Relatorio
{
    /** Indicadores do dashboard administrativo. */
    public static function indicadores(): array
    {
        // Define os períodos usados pelos indicadores diários, semanais e mensais.
        $hoje         = date('Y-m-d');
        $inicioSemana = date('Y-m-d', strtotime('monday this week'));
        $fimSemana    = date('Y-m-d', strtotime('sunday this week'));
        $inicioMes    = date('Y-m-01');
        $fimMes       = date('Y-m-t');

        $ativos = ['agendado', 'confirmado', 'concluido'];
        $agregados = self::agregar([
            'agendamentos_hoje'         => ['conta', $hoje, $hoje, $ativos],
            'agendamentos_semana'       => ['conta', $inicioSemana, $fimSemana, $ativos],
            'servicos_realizados'       => ['conta', $inicioMes, $fimMes, ['concluido']],
            'faturamento_mes'           => ['soma', $inicioMes, $fimMes, $ativos],
            'faturamento_realizado_mes' => ['soma', $inicioMes, $fimMes, ['concluido']],
            'cancelamentos_mes'         => ['conta', $inicioMes, $fimMes, ['cancelado']],
        ]);

        return $agregados + [
            'clientes_total'       => Cliente::total(),
            'clientes_ativos'      => Cliente::total('ativo'),
            'profissionais_ativos' => Profissional::total('ativo'),
            'servicos_ativos'      => Servico::total('ativo'),
        ];
    }

    /**
     * Varias contagens e somas de agendamentos numa consulta so. Cada metrica e
     * [tipo 'conta'|'soma', data inicial, data final, status aceitos]. O dashboard
     * pedia uma consulta por numero, e cada uma e uma ida ao banco remoto.
     */
    private static function agregar(array $metricas): array
    {
        $colunas = [];
        $parametros = [];
        $datas = [];
        foreach (array_values($metricas) as $i => [$tipo, $de, $ate, $status]) {
            $parametros[":de$i"] = $de;
            $parametros[":ate$i"] = $ate;
            $marcadores = [];
            foreach (array_values($status) as $j => $valor) {
                $parametros[":s{$i}_$j"] = $valor;
                $marcadores[] = ":s{$i}_$j";
            }
            $colunas[] = 'COALESCE(SUM(CASE WHEN data_agendamento BETWEEN :de' . $i . ' AND :ate' . $i
                . ' AND status IN (' . implode(', ', $marcadores) . ') THEN ' . ($tipo === 'soma' ? 'valor' : '1')
                . ' ELSE 0 END), 0) AS m' . $i;
            $datas[] = $de;
            $datas[] = $ate;
        }
        // O intervalo total deixa o indice de data recortar as linhas antes das somas.
        $parametros[':de_total'] = min($datas);
        $parametros[':ate_total'] = max($datas);

        $consulta = bd()->prepare('SELECT ' . implode(', ', $colunas) . ' FROM agendamentos
            WHERE id_estabelecimento = ' . Contexto::id() . ' AND data_agendamento BETWEEN :de_total AND :ate_total');
        $consulta->execute($parametros);
        $linha = $consulta->fetch();

        $resultado = [];
        foreach (array_keys($metricas) as $i => $nome) {
            $resultado[$nome] = $metricas[$nome][0] === 'soma' ? (float) $linha['m' . $i] : (int) $linha['m' . $i];
        }
        return $resultado;
    }

    /**
     * Mesmos indicadores no periodo anterior, ate o mesmo ponto: semana de
     * segunda ate hoje contra segunda ate o mesmo dia da semana passada; mes
     * do dia 1 ate hoje contra o mesmo trecho do mes anterior. Assim a
     * comparacao nao pune o meio da semana ou do mes.
     */
    public static function comparativos(?string $hoje = null): array
    {
        $hoje ??= date('Y-m-d');
        $dia = new DateTimeImmutable($hoje);
        $inicioSemana = $dia->modify('monday this week');
        $inicioMes = $dia->modify('first day of this month');
        $inicioMesAnterior = $inicioMes->modify('-1 month');
        // Dia 31 vira o ultimo dia do mes anterior, quando ele for mais curto.
        $fimMesAnterior = $inicioMesAnterior->modify('+' . (min((int) $dia->format('j'), (int) $inicioMesAnterior->format('t')) - 1) . ' days');
        $f = fn (DateTimeImmutable $d) => $d->format('Y-m-d');
        $ativos = ['agendado', 'confirmado', 'concluido'];
        $mesAnterior = [$f($inicioMesAnterior), $f($fimMesAnterior)];

        return self::agregar([
            'semana_atual'        => ['conta', $f($inicioSemana), $hoje, $ativos],
            'semana_anterior'     => ['conta', $f($inicioSemana->modify('-7 days')), $f($dia->modify('-7 days')), $ativos],
            'concluidos_atual'    => ['conta', $f($inicioMes), $hoje, ['concluido']],
            'concluidos_anterior' => ['conta', ...$mesAnterior, ['concluido']],
            'realizado_atual'     => ['soma', $f($inicioMes), $hoje, ['concluido']],
            'realizado_anterior'  => ['soma', ...$mesAnterior, ['concluido']],
            'cancelados_atual'    => ['conta', $f($inicioMes), $hoje, ['cancelado']],
            'cancelados_anterior' => ['conta', ...$mesAnterior, ['cancelado']],
        ]);
    }

    /**
     * Agendamentos validos (sem os cancelados) e valor de cada um dos ultimos
     * $dias dias, ate hoje, com zero nos dias sem movimento.
     */
    public static function serieDiaria(int $dias = 14, ?string $hoje = null): array
    {
        $fim = new DateTimeImmutable($hoje ?? date('Y-m-d'));
        $inicio = $fim->modify('-' . ($dias - 1) . ' days');
        $porDia = [];
        foreach (self::movimentoPorDia($inicio->format('Y-m-d'), $fim->format('Y-m-d')) as $linha) {
            $porDia[substr((string) $linha['data_agendamento'], 0, 10)] = $linha;
        }
        $serie = [];
        for ($d = $inicio; $d <= $fim; $d = $d->modify('+1 day')) {
            $linha = $porDia[$d->format('Y-m-d')] ?? null;
            $serie[] = [
                'data'  => $d->format('Y-m-d'),
                'total' => $linha ? (int) $linha['total'] - (int) $linha['cancelados'] : 0,
                'valor' => $linha ? (float) $linha['valor_total'] : 0.0,
            ];
        }
        return $serie;
    }

    /** Soma dos valores dos agendamentos que nao foram cancelados. */
    public static function faturamento(string $dataInicial, string $dataFinal, ?array $status = null): float
    {
        // Por padrão, o total inclui reservas agendadas e confirmadas; apenas concluídas representa o realizado.
        $status = $status ?? ['agendado', 'confirmado', 'concluido'];
        $marcadores = [];
        $parametros = [':inicio' => $dataInicial, ':fim' => $dataFinal];

        foreach (array_values($status) as $indice => $valor) {
            $chave = ':status' . $indice;
            $marcadores[] = $chave;
            $parametros[$chave] = $valor;
        }

        $sql = 'SELECT COALESCE(SUM(valor), 0) AS total
                FROM agendamentos
                WHERE id_estabelecimento = ' . Contexto::id() . ' AND data_agendamento BETWEEN :inicio AND :fim
                  AND status IN (' . implode(', ', $marcadores) . ')';

        $consulta = bd()->prepare($sql);
        $consulta->execute($parametros);
        return (float) ($consulta->fetch()['total'] ?? 0);
    }

    /** Ranking de servicos mais agendados no periodo. */
    public static function servicosMaisAgendados(string $dataInicial, string $dataFinal, int $limite = 5): array
    {
        $sql = 'SELECT s.id_servico, s.nome, COUNT(*) AS total,
                       COALESCE(SUM(CASE WHEN a.status <> \'cancelado\' THEN a.valor ELSE 0 END), 0) AS valor_total
                FROM agendamentos a
                INNER JOIN servicos s ON s.id_servico = a.id_servico
                WHERE a.id_estabelecimento = ' . Contexto::id() . ' AND a.data_agendamento BETWEEN :inicio AND :fim
                  AND a.status <> \'cancelado\'
                GROUP BY s.id_servico, s.nome
                ORDER BY total DESC, s.nome ASC
                LIMIT ' . (int) $limite;

        $consulta = bd()->prepare($sql);
        $consulta->execute([':inicio' => $dataInicial, ':fim' => $dataFinal]);
        return $consulta->fetchAll();
    }

    /** Ranking de profissionais por atendimentos no periodo. */
    public static function profissionaisMaisAtendimentos(string $dataInicial, string $dataFinal, int $limite = 10): array
    {
        $sql = 'SELECT p.id_profissional, u.nome,
                       COUNT(*) AS total,
                       SUM(CASE WHEN a.status = \'concluido\' THEN 1 ELSE 0 END) AS concluidos,
                       SUM(CASE WHEN a.status = \'cancelado\' THEN 1 ELSE 0 END) AS cancelados,
                       COALESCE(SUM(CASE WHEN a.status <> \'cancelado\' THEN a.valor ELSE 0 END), 0) AS valor_total
                FROM agendamentos a
                INNER JOIN profissionais p ON p.id_profissional = a.id_profissional
                INNER JOIN usuarios u ON u.id_usuario = p.id_usuario
                WHERE a.id_estabelecimento = ' . Contexto::id() . ' AND a.data_agendamento BETWEEN :inicio AND :fim
                GROUP BY p.id_profissional, u.nome
                ORDER BY total DESC, u.nome ASC
                LIMIT ' . (int) $limite;

        $consulta = bd()->prepare($sql);
        $consulta->execute([':inicio' => $dataInicial, ':fim' => $dataFinal]);
        return $consulta->fetchAll();
    }

    /** Clientes mais frequentes no periodo. */
    public static function clientesMaisFrequentes(string $dataInicial, string $dataFinal, int $limite = 10): array
    {
        $sql = 'SELECT c.id_cliente, u.nome, u.telefone,
                       COUNT(*) AS total,
                       COALESCE(SUM(CASE WHEN a.status <> \'cancelado\' THEN a.valor ELSE 0 END), 0) AS valor_total,
                       MAX(a.data_agendamento) AS ultimo_atendimento
                FROM agendamentos a
                INNER JOIN clientes c ON c.id_cliente = a.id_cliente
                INNER JOIN usuarios u ON u.id_usuario = c.id_usuario
                WHERE a.id_estabelecimento = ' . Contexto::id() . ' AND a.data_agendamento BETWEEN :inicio AND :fim
                  AND a.status <> \'cancelado\'
                GROUP BY c.id_cliente, u.nome, u.telefone
                ORDER BY total DESC, u.nome ASC
                LIMIT ' . (int) $limite;

        $consulta = bd()->prepare($sql);
        $consulta->execute([':inicio' => $dataInicial, ':fim' => $dataFinal]);
        return $consulta->fetchAll();
    }

    /** Totais por status dentro do periodo. */
    public static function resumoPorStatus(string $dataInicial, string $dataFinal): array
    {
        $sql = 'SELECT status, COUNT(*) AS total, COALESCE(SUM(valor), 0) AS valor_total
                FROM agendamentos
                WHERE id_estabelecimento = ' . Contexto::id() . ' AND data_agendamento BETWEEN :inicio AND :fim
                GROUP BY status';

        $consulta = bd()->prepare($sql);
        $consulta->execute([':inicio' => $dataInicial, ':fim' => $dataFinal]);

        // Inicializa todas as situações com zero para manter os cartões mesmo sem resultados no período.
        $resumo = [];
        foreach (Agendamento::STATUS as $status) {
            $resumo[$status] = ['total' => 0, 'valor_total' => 0.0];
        }
        foreach ($consulta->fetchAll() as $linha) {
            $resumo[$linha['status']] = [
                'total'       => (int) $linha['total'],
                'valor_total' => (float) $linha['valor_total'],
            ];
        }

        return $resumo;
    }

    /** Faturamento e volume agrupados por dia. */
    public static function movimentoPorDia(string $dataInicial, string $dataFinal): array
    {
        $sql = 'SELECT data_agendamento,
                       COUNT(*) AS total,
                       SUM(CASE WHEN status = \'cancelado\' THEN 1 ELSE 0 END) AS cancelados,
                       COALESCE(SUM(CASE WHEN status <> \'cancelado\' THEN valor ELSE 0 END), 0) AS valor_total
                FROM agendamentos
                WHERE id_estabelecimento = ' . Contexto::id() . ' AND data_agendamento BETWEEN :inicio AND :fim
                GROUP BY data_agendamento
                ORDER BY data_agendamento ASC';

        $consulta = bd()->prepare($sql);
        $consulta->execute([':inicio' => $dataInicial, ':fim' => $dataFinal]);
        return $consulta->fetchAll();
    }

    /** Ultimos agendamentos criados (nao necessariamente os proximos). */
    public static function agendamentosRecentes(int $limite = 5): array
    {
        $sql = 'SELECT a.id_agendamento, a.data_agendamento, a.hora_inicio, a.status, a.valor, a.data_criacao,
                       uc.nome AS nome_cliente, up.nome AS nome_profissional, s.nome AS nome_servico
                FROM agendamentos a
                INNER JOIN clientes c ON c.id_cliente = a.id_cliente
                INNER JOIN usuarios uc ON uc.id_usuario = c.id_usuario
                INNER JOIN profissionais p ON p.id_profissional = a.id_profissional
                INNER JOIN usuarios up ON up.id_usuario = p.id_usuario
                INNER JOIN servicos s ON s.id_servico = a.id_servico
                WHERE a.id_estabelecimento = ' . Contexto::id() . ' ORDER BY a.data_criacao DESC
                LIMIT ' . (int) $limite;

        return bd()->query($sql)->fetchAll();
    }

    /** Ocupacao do profissional no dia: minutos agendados x minutos de expediente. */
    public static function ocupacaoDoDia(int $idProfissional, string $data): array
    {
        $diaSemana = (int) date('w', strtotime($data));
        // Compara minutos de atendimentos com minutos de expediente para calcular a ocupação do dia.
        $minutosExpediente = 0;

        foreach (Horario::faixasAtivas($idProfissional, $diaSemana) as $faixa) {
            $minutosExpediente += diferencaMinutos($faixa['hora_inicio'], $faixa['hora_fim']);
        }

        $consulta = bd()->prepare(
            'SELECT COALESCE(SUM(' . Sql::diferencaMinutos('hora_inicio', 'hora_fim') . '), 0) AS minutos
             FROM agendamentos
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_profissional = :profissional
               AND data_agendamento = :data
               AND status IN (\'agendado\',\'confirmado\',\'concluido\')'
        );
        $consulta->execute([':profissional' => $idProfissional, ':data' => $data]);
        $minutosAgendados = (int) ($consulta->fetch()['minutos'] ?? 0);

        return [
            'minutos_expediente' => $minutosExpediente,
            'minutos_agendados'  => $minutosAgendados,
            'percentual'         => $minutosExpediente > 0
                ? (int) round(($minutosAgendados / $minutosExpediente) * 100)
                : 0,
        ];
    }
}
