<?php
/**
 * Servicos oferecidos pelo estabelecimento.
 */
class Servico
{
    /** Busca o registro pelo identificador; retorna null quando ele não existe. */
    public static function porId(int $idServico): ?array
    {
        $consulta = bd()->prepare('SELECT * FROM servicos WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_servico = :id LIMIT 1');
        $consulta->execute([':id' => $idServico]);
        return $consulta->fetch() ?: null;
    }

    /** Filtros: busca, status. */
    public static function listar(array $filtros = []): array
    {
        $condicoes = ['id_estabelecimento = ' . Contexto::id()];
        $parametros = [];

        if (!empty($filtros['busca'])) {
            $condicoes[] = '(nome ' . Sql::como() . ' :busca OR descricao ' . Sql::como() . ' :buscaDescricao)';
            $parametros[':busca'] = '%' . $filtros['busca'] . '%';
            $parametros[':buscaDescricao'] = $parametros[':busca'];
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

    /** Retorna apenas os cadastros ativos para preencher as opções da interface. */
    public static function ativos(): array
    {
        return self::listar(['status' => 'ativo']);
    }

    /** Seleciona serviços ativos destacados para os cartões da página inicial. */
    public static function destaques(int $limite = 6): array
    {
        $consulta = bd()->prepare(
            'SELECT * FROM servicos
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND status = \'ativo\'
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
                WHERE s.id_estabelecimento = ' . Contexto::id() . ' AND s.status = \'ativo\' AND u.status = \'ativo\'
                ORDER BY s.nome ASC';

        return bd()->query($sql)->fetchAll();
    }

    /** Grava preço, duração e demais dados do serviço, retornando o novo ID. */
    public static function criar(array $dados): int
    {
        $consulta = bd()->prepare(
            'INSERT INTO servicos (id_estabelecimento, nome, descricao, preco, duracao_minutos, status, destaque)
             VALUES (' . Contexto::id() . ', :nome, :descricao, :preco, :duracao, :status, :destaque)'
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

    /** Persiste os campos editáveis do cadastro identificado pelo ID. */
    public static function atualizar(int $idServico, array $dados): bool
    {
        $consulta = bd()->prepare(
            'UPDATE servicos
             SET nome = :nome, descricao = :descricao, preco = :preco,
                 duracao_minutos = :duracao, status = :status, destaque = :destaque
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_servico = :id'
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

    /** Atualiza a situação do registro identificado pelo ID. */
    public static function alterarStatus(int $idServico, string $status): bool
    {
        $status = $status === 'ativo' ? 'ativo' : 'inativo';
        $consulta = bd()->prepare('UPDATE servicos SET status = :status WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_servico = :id');
        return $consulta->execute([':status' => $status, ':id' => $idServico]);
    }

    /** Executa a exclusão pelo ID; as restrições do banco continuam sendo aplicadas. */
    public static function excluir(int $idServico): bool
    {
        $consulta = bd()->prepare('DELETE FROM servicos WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_servico = :id');
        return $consulta->execute([':id' => $idServico]);
    }

    /** Servico so pode ser excluido se nunca foi agendado. */
    public static function podeExcluir(int $idServico): bool
    {
        return self::totalAgendamentos($idServico) === 0;
    }

    /** Conta as reservas vinculadas ao serviço, inclusive as do histórico. */
    public static function totalAgendamentos(int $idServico): int
    {
        $consulta = bd()->prepare('SELECT COUNT(*) AS total FROM agendamentos WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_servico = :id');
        $consulta->execute([':id' => $idServico]);
        return (int) ($consulta->fetch()['total'] ?? 0);
    }

    /** Conta os registros cadastrados, restringindo pelo status quando informado. */
    public static function total(?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) AS total FROM servicos WHERE id_estabelecimento = ' . Contexto::id();
        $parametros = [];

        if ($status !== null) {
            $sql .= ' AND status = :status';
            $parametros[':status'] = $status;
        }

        $consulta = bd()->prepare($sql);
        $consulta->execute($parametros);
        return (int) ($consulta->fetch()['total'] ?? 0);
    }

    /** Verifica se o cadastro existe e está habilitado para uso. */
    public static function estaAtivo(int $idServico): bool
    {
        $consulta = bd()->prepare('SELECT 1 FROM servicos WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_servico = :id AND status = \'ativo\' LIMIT 1');
        $consulta->execute([':id' => $idServico]);
        return (bool) $consulta->fetch();
    }
}
