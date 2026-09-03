<?php
/**
 * Consultas agregadas para o dashboard e para a area de relatorios.
 */
class Relatorio
{
    /** Indicadores do dashboard administrativo. */
    public static function indicadores(): array
    {
        $hoje         = date('Y-m-d');
        $inicioSemana = date('Y-m-d', strtotime('monday this week'));
        $fimSemana    = date('Y-m-d', strtotime('sunday this week'));
        $inicioMes    = date('Y-m-01');
        $fimMes       = date('Y-m-t');

        return [
            'agendamentos_hoje'   => Agendamento::contar([
                'data'      => $hoje,
                'status_em' => ['agendado', 'confirmado', 'concluido'],
            ]),
            'agendamentos_semana' => Agendamento::contar([
                'data_inicial' => $inicioSemana,
                'data_final'   => $fimSemana,
                'status_em'    => ['agendado', 'confirmado', 'concluido'],
            ]),
            'clientes_total'      => Cliente::total(),
            'clientes_ativos'     => Cliente::total('ativo'),
            'servicos_realizados' => Agendamento::contar([
                'data_inicial' => $inicioMes,
                'data_final'   => $fimMes,
                'status'       => 'concluido',
            ]),
            'faturamento_mes'     => self::faturamento($inicioMes, $fimMes),
            'faturamento_realizado_mes' => self::faturamento($inicioMes, $fimMes, ['concluido']),
            'cancelamentos_mes'   => Agendamento::contar([
                'data_inicial' => $inicioMes,
                'data_final'   => $fimMes,
                'status'       => 'cancelado',
            ]),
            'profissionais_ativos' => Profissional::total('ativo'),
            'servicos_ativos'      => Servico::total('ativo'),
        ];
    }

    /** Soma dos valores dos agendamentos que nao foram cancelados. */
    public static function faturamento(string $dataInicial, string $dataFinal, ?array $status = null): float
    {
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
                WHERE data_agendamento BETWEEN :inicio AND :fim
                  AND status IN (' . implode(', ', $marcadores) . ')';

        $consulta = bd()->prepare($sql);
        $consulta->execute($parametros);
        return (float) ($consulta->fetch()['total'] ?? 0);
    }

    /** Ranking de servicos mais agendados no periodo. */
    public static function servicosMaisAgendados(string $dataInicial, string $dataFinal, int $limite = 5): array
    {
        $sql = 'SELECT s.id_servico, s.nome, COUNT(*) AS total,
                       COALESCE(SUM(CASE WHEN a.status <> "cancelado" THEN a.valor ELSE 0 END), 0) AS valor_total
                FROM agendamentos a
                INNER JOIN servicos s ON s.id_servico = a.id_servico
                WHERE a.data_agendamento BETWEEN :inicio AND :fim
                  AND a.status <> "cancelado"
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
                       SUM(CASE WHEN a.status = "concluido" THEN 1 ELSE 0 END) AS concluidos,
                       SUM(CASE WHEN a.status = "cancelado" THEN 1 ELSE 0 END) AS cancelados,
                       COALESCE(SUM(CASE WHEN a.status <> "cancelado" THEN a.valor ELSE 0 END), 0) AS valor_total
                FROM agendamentos a
                INNER JOIN profissionais p ON p.id_profissional = a.id_profissional
                INNER JOIN usuarios u ON u.id_usuario = p.id_usuario
                WHERE a.data_agendamento BETWEEN :inicio AND :fim
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
                       COALESCE(SUM(CASE WHEN a.status <> "cancelado" THEN a.valor ELSE 0 END), 0) AS valor_total,
                       MAX(a.data_agendamento) AS ultimo_atendimento
                FROM agendamentos a
                INNER JOIN clientes c ON c.id_cliente = a.id_cliente
                INNER JOIN usuarios u ON u.id_usuario = c.id_usuario
                WHERE a.data_agendamento BETWEEN :inicio AND :fim
                  AND a.status <> "cancelado"
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
                WHERE data_agendamento BETWEEN :inicio AND :fim
                GROUP BY status';

        $consulta = bd()->prepare($sql);
        $consulta->execute([':inicio' => $dataInicial, ':fim' => $dataFinal]);

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
                       SUM(CASE WHEN status = "cancelado" THEN 1 ELSE 0 END) AS cancelados,
                       COALESCE(SUM(CASE WHEN status <> "cancelado" THEN valor ELSE 0 END), 0) AS valor_total
                FROM agendamentos
                WHERE data_agendamento BETWEEN :inicio AND :fim
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
                ORDER BY a.data_criacao DESC
                LIMIT ' . (int) $limite;

        return bd()->query($sql)->fetchAll();
    }

    /** Ocupacao do profissional no dia: minutos agendados x minutos de expediente. */
    public static function ocupacaoDoDia(int $idProfissional, string $data): array
    {
        $diaSemana = (int) date('w', strtotime($data));
        $minutosExpediente = 0;

        foreach (Horario::faixasAtivas($idProfissional, $diaSemana) as $faixa) {
            $minutosExpediente += diferencaMinutos($faixa['hora_inicio'], $faixa['hora_fim']);
        }

        $consulta = bd()->prepare(
            'SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, hora_inicio, hora_fim)), 0) AS minutos
             FROM agendamentos
             WHERE id_profissional = :profissional
               AND data_agendamento = :data
               AND status IN ("agendado","confirmado","concluido")'
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
