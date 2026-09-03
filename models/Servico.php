<?php
/**
 * Servicos oferecidos pelo estabelecimento.
 */
class Servico
{
    public static function porId(int $idServico): ?array
    {
        $consulta = bd()->prepare('SELECT * FROM servicos WHERE id_servico = :id LIMIT 1');
        $consulta->execute([':id' => $idServico]);
        return $consulta->fetch() ?: null;
    }

    /** Filtros: busca, status. */
    public static function listar(array $filtros = []): array
    {
        $condicoes  = [];
        $parametros = [];

        if (!empty($filtros['busca'])) {
            $condicoes[] = '(nome LIKE :busca OR descricao LIKE :busca)';
            $parametros[':busca'] = '%' . $filtros['busca'] . '%';
        }

        if (!empty($filtros['status'])) {
            $condicoes[] = 'status = :status';
            $parametros[':status'] = $filtros['status'];
        }

        $where = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';

        $consulta = bd()->prepare('SELECT * FROM servicos ' . $where . ' ORDER BY nome ASC');
        $consulta->execute($parametros);
        return $consulta->fetchAll();
    }

    public static function ativos(): array
    {
        return self::listar(['status' => 'ativo']);
    }

    public static function destaques(int $limite = 6): array
    {
        $consulta = bd()->prepare(
            'SELECT * FROM servicos
             WHERE status = "ativo"
             ORDER BY destaque DESC, nome ASC
             LIMIT ' . (int) $limite
        );
        $consulta->execute();
        return $consulta->fetchAll();
    }

    /** Servicos ativos que possuem ao menos um profissional ativo disponivel. */
    public static function ativosComProfissional(): array
    {
        $sql = 'SELECT DISTINCT s.*
                FROM servicos s
                INNER JOIN profissional_servico ps ON ps.id_servico = s.id_servico
                INNER JOIN profissionais p ON p.id_profissional = ps.id_profissional
                INNER JOIN usuarios u ON u.id_usuario = p.id_usuario
                WHERE s.status = "ativo" AND u.status = "ativo"
                ORDER BY s.nome ASC';

        return bd()->query($sql)->fetchAll();
    }

    public static function criar(array $dados): int
    {
        $consulta = bd()->prepare(
            'INSERT INTO servicos (nome, descricao, preco, duracao_minutos, status, destaque)
             VALUES (:nome, :descricao, :preco, :duracao, :status, :destaque)'
        );

        $consulta->execute([
            ':nome'      => $dados['nome'],
            ':descricao' => $dados['descricao'] ?: null,
            ':preco'     => $dados['preco'],
            ':duracao'   => $dados['duracao_minutos'],
            ':status'    => $dados['status'] ?? 'ativo',
            ':destaque'  => !empty($dados['destaque']) ? 1 : 0,
        ]);

        return (int) bd()->lastInsertId();
    }

    public static function atualizar(int $idServico, array $dados): bool
    {
        $consulta = bd()->prepare(
            'UPDATE servicos
             SET nome = :nome, descricao = :descricao, preco = :preco,
                 duracao_minutos = :duracao, status = :status, destaque = :destaque
             WHERE id_servico = :id'
        );

        return $consulta->execute([
            ':nome'      => $dados['nome'],
            ':descricao' => $dados['descricao'] ?: null,
            ':preco'     => $dados['preco'],
            ':duracao'   => $dados['duracao_minutos'],
            ':status'    => $dados['status'] ?? 'ativo',
            ':destaque'  => !empty($dados['destaque']) ? 1 : 0,
            ':id'        => $idServico,
        ]);
    }

    public static function alterarStatus(int $idServico, string $status): bool
    {
        $status = $status === 'ativo' ? 'ativo' : 'inativo';
        $consulta = bd()->prepare('UPDATE servicos SET status = :status WHERE id_servico = :id');
        return $consulta->execute([':status' => $status, ':id' => $idServico]);
    }

    public static function excluir(int $idServico): bool
    {
        $consulta = bd()->prepare('DELETE FROM servicos WHERE id_servico = :id');
        return $consulta->execute([':id' => $idServico]);
    }

    /** Servico so pode ser excluido se nunca foi agendado. */
    public static function podeExcluir(int $idServico): bool
    {
        return self::totalAgendamentos($idServico) === 0;
    }

    public static function totalAgendamentos(int $idServico): int
    {
        $consulta = bd()->prepare('SELECT COUNT(*) AS total FROM agendamentos WHERE id_servico = :id');
        $consulta->execute([':id' => $idServico]);
        return (int) ($consulta->fetch()['total'] ?? 0);
    }

    public static function total(?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) AS total FROM servicos';
        $parametros = [];

        if ($status !== null) {
            $sql .= ' WHERE status = :status';
            $parametros[':status'] = $status;
        }

        $consulta = bd()->prepare($sql);
        $consulta->execute($parametros);
        return (int) ($consulta->fetch()['total'] ?? 0);
    }

    public static function estaAtivo(int $idServico): bool
    {
        $consulta = bd()->prepare('SELECT 1 FROM servicos WHERE id_servico = :id AND status = "ativo" LIMIT 1');
        $consulta->execute([':id' => $idServico]);
        return (bool) $consulta->fetch();
    }
}
