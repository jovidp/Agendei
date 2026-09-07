<?php
/**
 * Faixas de expediente dos profissionais (tabela horarios_profissionais).
 * dia_semana: 0 = domingo ... 6 = sabado.
 */
class Horario
{
    /** Busca o registro pelo identificador; retorna null quando ele não existe. */
    public static function porId(int $idHorario): ?array
    {
        $consulta = bd()->prepare('SELECT * FROM horarios_profissionais WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_horario = :id LIMIT 1');
        $consulta->execute([':id' => $idHorario]);
        return $consulta->fetch() ?: null;
    }

    /** Todas as faixas do profissional, ordenadas por dia e hora. */
    public static function porProfissional(int $idProfissional): array
    {
        $consulta = bd()->prepare(
            'SELECT * FROM horarios_profissionais
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_profissional = :id
             ORDER BY dia_semana ASC, hora_inicio ASC'
        );
        $consulta->execute([':id' => $idProfissional]);
        return $consulta->fetchAll();
    }

    /** Faixas ativas de um dia da semana especifico. */
    public static function faixasAtivas(int $idProfissional, int $diaSemana): array
    {
        $consulta = bd()->prepare(
            'SELECT * FROM horarios_profissionais
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_profissional = :id AND dia_semana = :dia AND status = "ativo"
             ORDER BY hora_inicio ASC'
        );
        $consulta->execute([':id' => $idProfissional, ':dia' => $diaSemana]);
        return $consulta->fetchAll();
    }

    /** Agrupa as faixas por dia da semana. */
    public static function agrupadoPorDia(int $idProfissional): array
    {
        $agrupado = array_fill(0, 7, []);

        foreach (self::porProfissional($idProfissional) as $faixa) {
            $agrupado[(int) $faixa['dia_semana']][] = $faixa;
        }

        return $agrupado;
    }

    /** Impede faixas sobrepostas no mesmo dia. */
    public static function existeSobreposicao(int $idProfissional, int $diaSemana, string $inicio, string $fim, ?int $ignorarId = null): bool
    {
        $sql = 'SELECT id_horario FROM horarios_profissionais
                WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_profissional = :profissional
                  AND dia_semana = :dia
                  AND hora_inicio < :fim
                  AND hora_fim > :inicio';

        $parametros = [
            ':profissional' => $idProfissional,
            ':dia'          => $diaSemana,
            ':inicio'       => $inicio,
            ':fim'          => $fim,
        ];

        if ($ignorarId !== null) {
            $sql .= ' AND id_horario <> :ignorar';
            $parametros[':ignorar'] = $ignorarId;
        }

        $consulta = bd()->prepare($sql . ' LIMIT 1');
        $consulta->execute($parametros);
        return (bool) $consulta->fetch();
    }

    /** Cadastra uma faixa de expediente vinculada ao profissional e ao dia da semana. */
    public static function criar(array $dados): int
    {
        $consulta = bd()->prepare(
            'INSERT INTO horarios_profissionais
                (id_estabelecimento, id_profissional, dia_semana, hora_inicio, hora_fim, intervalo_minutos, status)
             VALUES (' . Contexto::id() . ', :profissional, :dia, :inicio, :fim, :intervalo, :status)'
        );

        $consulta->execute([
            ':profissional' => $dados['id_profissional'],
            ':dia'          => $dados['dia_semana'],
            ':inicio'       => $dados['hora_inicio'],
            ':fim'          => $dados['hora_fim'],
            ':intervalo'    => $dados['intervalo_minutos'] ?? 30,
            ':status'       => $dados['status'] ?? 'ativo',
        ]);

        return (int) bd()->lastInsertId();
    }

    /** Persiste os campos editáveis do cadastro identificado pelo ID. */
    public static function atualizar(int $idHorario, array $dados): bool
    {
        $consulta = bd()->prepare(
            'UPDATE horarios_profissionais
             SET dia_semana = :dia, hora_inicio = :inicio, hora_fim = :fim,
                 intervalo_minutos = :intervalo, status = :status
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_horario = :id'
        );

        return $consulta->execute([
            ':dia'       => $dados['dia_semana'],
            ':inicio'    => $dados['hora_inicio'],
            ':fim'       => $dados['hora_fim'],
            ':intervalo' => $dados['intervalo_minutos'] ?? 30,
            ':status'    => $dados['status'] ?? 'ativo',
            ':id'        => $idHorario,
        ]);
    }

    /** Atualiza a situação do registro identificado pelo ID. */
    public static function alterarStatus(int $idHorario, string $status): bool
    {
        $status = $status === 'ativo' ? 'ativo' : 'inativo';
        $consulta = bd()->prepare('UPDATE horarios_profissionais SET status = :status WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_horario = :id');
        return $consulta->execute([':status' => $status, ':id' => $idHorario]);
    }

    /** Executa a exclusão pelo ID; as restrições do banco continuam sendo aplicadas. */
    public static function excluir(int $idHorario): bool
    {
        $consulta = bd()->prepare('DELETE FROM horarios_profissionais WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_horario = :id');
        return $consulta->execute([':id' => $idHorario]);
    }

    /** Verifica a existência de expediente ativo para o profissional. */
    public static function possuiExpediente(int $idProfissional): bool
    {
        $consulta = bd()->prepare(
            'SELECT 1 FROM horarios_profissionais
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_profissional = :id AND status = "ativo" LIMIT 1'
        );
        $consulta->execute([':id' => $idProfissional]);
        return (bool) $consulta->fetch();
    }

    /** Copia as faixas de um dia para outros dias da semana. */
    public static function replicarDia(int $idProfissional, int $diaOrigem, array $diasDestino): int
    {
        $faixas = self::faixasAtivas($idProfissional, $diaOrigem);
        $criadas = 0;

        foreach ($diasDestino as $dia) {
            $dia = (int) $dia;
            if ($dia < 0 || $dia > 6 || $dia === $diaOrigem) {
                continue;
            }

            foreach ($faixas as $faixa) {
                if (self::existeSobreposicao($idProfissional, $dia, $faixa['hora_inicio'], $faixa['hora_fim'])) {
                    continue;
                }

                self::criar([
                    'id_profissional'   => $idProfissional,
                    'dia_semana'        => $dia,
                    'hora_inicio'       => $faixa['hora_inicio'],
                    'hora_fim'          => $faixa['hora_fim'],
                    'intervalo_minutos' => $faixa['intervalo_minutos'],
                    'status'            => 'ativo',
                ]);
                $criadas++;
            }
        }

        return $criadas;
    }
}
