<?php
/**
 * Bloqueios pontuais na agenda de um profissional (folgas, ferias, compromissos).
 */
class Bloqueio
{
    /** Busca o registro pelo identificador; retorna null quando ele não existe. */
    public static function porId(int $idBloqueio): ?array
    {
        $sql = 'SELECT b.*, u.nome AS nome_profissional
                FROM bloqueios_agenda b
                INNER JOIN profissionais p ON p.id_profissional = b.id_profissional
                INNER JOIN usuarios u ON u.id_usuario = p.id_usuario
                WHERE b.id_estabelecimento = ' . Contexto::id() . ' AND b.id_bloqueio = :id LIMIT 1';

        $consulta = bd()->prepare($sql);
        $consulta->execute([':id' => $idBloqueio]);
        return $consulta->fetch() ?: null;
    }

    /** Filtros: id_profissional, data_inicial, data_final, futuros. */
    public static function listar(array $filtros = []): array
    {
        $condicoes = ['b.id_estabelecimento = ' . Contexto::id()];
        $parametros = [];

        if (!empty($filtros['id_profissional'])) {
            $condicoes[] = 'b.id_profissional = :profissional';
            $parametros[':profissional'] = (int) $filtros['id_profissional'];
        }

        if (!empty($filtros['data_inicial'])) {
            $condicoes[] = 'b.data_bloqueio >= :data_inicial';
            $parametros[':data_inicial'] = $filtros['data_inicial'];
        }

        if (!empty($filtros['data_final'])) {
            $condicoes[] = 'b.data_bloqueio <= :data_final';
            $parametros[':data_final'] = $filtros['data_final'];
        }

        if (!empty($filtros['futuros'])) {
            $condicoes[] = 'b.data_bloqueio >= CURRENT_DATE';
        }

        $where = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';

        $sql = 'SELECT b.*, u.nome AS nome_profissional
                FROM bloqueios_agenda b
                INNER JOIN profissionais p ON p.id_profissional = b.id_profissional
                INNER JOIN usuarios u ON u.id_usuario = p.id_usuario
                ' . $where . '
                ORDER BY b.data_bloqueio DESC, b.hora_inicio ASC';

        $consulta = bd()->prepare($sql);
        $consulta->execute($parametros);
        return $consulta->fetchAll();
    }

    /** Bloqueios de um profissional em uma data. */
    public static function porData(int $idProfissional, string $data): array
    {
        $consulta = bd()->prepare(
            'SELECT * FROM bloqueios_agenda
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_profissional = :profissional AND data_bloqueio = :data
             ORDER BY hora_inicio ASC'
        );
        $consulta->execute([':profissional' => $idProfissional, ':data' => $data]);
        return $consulta->fetchAll();
    }

    /** Existe bloqueio cobrindo o intervalo informado? */
    public static function conflita(int $idProfissional, string $data, string $horaInicio, string $horaFim): bool
    {
        $consulta = bd()->prepare(
            'SELECT 1 FROM bloqueios_agenda
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_profissional = :profissional
               AND data_bloqueio = :data
               AND hora_inicio < :fim
               AND hora_fim > :inicio
             LIMIT 1'
        );

        $consulta->execute([
            ':profissional' => $idProfissional,
            ':data'         => $data,
            ':inicio'       => $horaInicio,
            ':fim'          => $horaFim,
        ]);

        return (bool) $consulta->fetch();
    }

    /** Registra uma indisponibilidade pontual e retorna o identificador do bloqueio. */
    public static function criar(array $dados): int
    {
        if (!Profissional::porId((int) $dados['id_profissional'])) throw new InvalidArgumentException('Profissional não pertence ao estabelecimento.');
        if (!empty($dados['id_usuario_criou']) && !Usuario::porId((int) $dados['id_usuario_criou'])) throw new InvalidArgumentException('Usuário não pertence ao estabelecimento.');
        $consulta = bd()->prepare(
            'INSERT INTO bloqueios_agenda
                (id_estabelecimento, id_profissional, data_bloqueio, hora_inicio, hora_fim, motivo, id_usuario_criou)
             VALUES (' . Contexto::id() . ', :profissional, :data, :inicio, :fim, :motivo, :usuario)'
        );

        $consulta->execute([
            ':profissional' => $dados['id_profissional'],
            ':data'         => $dados['data_bloqueio'],
            ':inicio'       => $dados['hora_inicio'],
            ':fim'          => $dados['hora_fim'],
            ':motivo'       => $dados['motivo'] ?: null,
            ':usuario'      => $dados['id_usuario_criou'] ?? null,
        ]);

        return (int) bd()->lastInsertId();
    }

    /** Executa a exclusão pelo ID; as restrições do banco continuam sendo aplicadas. */
    public static function excluir(int $idBloqueio): bool
    {
        $consulta = bd()->prepare('DELETE FROM bloqueios_agenda WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_bloqueio = :id');
        return $consulta->execute([':id' => $idBloqueio]);
    }

    /** Agendamentos ativos que ficariam dentro de um bloqueio - usado para avisar antes de criar. */
    public static function agendamentosNoIntervalo(int $idProfissional, string $data, string $horaInicio, string $horaFim): array
    {
        $sql = 'SELECT a.id_agendamento, a.hora_inicio, a.hora_fim, u.nome AS nome_cliente, s.nome AS nome_servico
                FROM agendamentos a
                INNER JOIN clientes c ON c.id_cliente = a.id_cliente
                INNER JOIN usuarios u ON u.id_usuario = c.id_usuario
                INNER JOIN servicos s ON s.id_servico = a.id_servico
                WHERE a.id_estabelecimento = ' . Contexto::id() . ' AND a.id_profissional = :profissional
                  AND a.data_agendamento = :data
                  AND a.status IN (\'agendado\',\'confirmado\')
                  AND a.hora_inicio < :fim
                  AND a.hora_fim > :inicio
                ORDER BY a.hora_inicio ASC';

        $consulta = bd()->prepare($sql);
        $consulta->execute([
            ':profissional' => $idProfissional,
            ':data'         => $data,
            ':inicio'       => $horaInicio,
            ':fim'          => $horaFim,
        ]);

        return $consulta->fetchAll();
    }
}
