<?php

/**
 * Filiais (unidades) de um estabelecimento.
 *
 * Cada profissional pertence a uma filial. Os servicos oferecidos numa filial
 * sao derivados dos profissionais que trabalham nela, sem tabela propria: se
 * a unidade tem alguem que faz Corte, a unidade oferece Corte. Todo agendamento
 * grava a filial em que foi marcado, e e isso que alimenta o faturamento por
 * unidade. Tudo escopado ao estabelecimento da requisicao (Contexto::id()).
 */
class Filial
{
    private const CAMPOS = 'f.id_filial, f.nome, f.telefone, f.cep, f.logradouro, f.numero, f.complemento,
                            f.bairro, f.cidade, f.uf, f.foto, f.status, f.ordem, f.data_cadastro';

    /** Lista as filiais do estabelecimento; aceita o filtro status (ativo|inativo). */
    public static function listar(array $filtros = []): array
    {
        $condicoes  = ['f.id_estabelecimento = ' . Contexto::id()];
        $parametros = [];

        if (!empty($filtros['status'])) {
            $condicoes[]           = 'f.status = :status';
            $parametros[':status'] = $filtros['status'];
        }

        $consulta = bd()->prepare(
            'SELECT ' . self::CAMPOS . '
             FROM filiais f
             WHERE ' . implode(' AND ', $condicoes) . '
             ORDER BY f.ordem ASC, f.nome ASC'
        );
        $consulta->execute($parametros);

        return $consulta->fetchAll();
    }

    /** Somente as filiais ativas, na ordem de exibicao. */
    public static function ativas(): array
    {
        return self::listar(['status' => 'ativo']);
    }

    public static function porId(int $idFilial): ?array
    {
        $consulta = bd()->prepare(
            'SELECT ' . self::CAMPOS . '
             FROM filiais f
             WHERE f.id_estabelecimento = ' . Contexto::id() . ' AND f.id_filial = :id
             LIMIT 1'
        );
        $consulta->execute([':id' => $idFilial]);
        $filial = $consulta->fetch();

        return $filial ?: null;
    }

    /** Filial de reserva: a primeira ativa. Usada quando nada foi escolhido. */
    public static function padrao(): ?array
    {
        $ativas = self::ativas();

        return $ativas[0] ?? null;
    }

    /** Quantas filiais o estabelecimento tem (ativas ou nao). */
    public static function total(): int
    {
        $consulta = bd()->query(
            'SELECT COUNT(*) FROM filiais WHERE id_estabelecimento = ' . Contexto::id()
        );

        return (int) $consulta->fetchColumn();
    }

    /** Cria a filial e devolve o id. A foto ja chega como data URI (ver Tema::receberLogo). */
    public static function criar(array $dados): int
    {
        self::validar($dados);

        $consulta = bd()->prepare(
            'INSERT INTO filiais
                (id_estabelecimento, nome, telefone, cep, logradouro, numero, complemento, bairro, cidade, uf, foto, status, ordem)
             VALUES (' . Contexto::id() . ', :nome, :telefone, :cep, :logradouro, :numero, :complemento, :bairro, :cidade, :uf, :foto, :status, :ordem)'
        );
        $consulta->execute(self::parametros($dados));

        return (int) bd()->lastInsertId();
    }

    /** Atualiza os dados. Se a chave foto nao vier, a foto atual e mantida. */
    public static function atualizar(int $idFilial, array $dados): bool
    {
        self::validar($dados);

        $atribuicoes = 'nome = :nome, telefone = :telefone, cep = :cep, logradouro = :logradouro, numero = :numero,
                        complemento = :complemento, bairro = :bairro, cidade = :cidade, uf = :uf, status = :status, ordem = :ordem';
        $parametros  = self::parametros($dados);

        if (array_key_exists('foto', $dados)) {
            $atribuicoes .= ', foto = :foto';
        } else {
            unset($parametros[':foto']);
        }

        $parametros[':id'] = $idFilial;

        $consulta = bd()->prepare(
            'UPDATE filiais SET ' . $atribuicoes . '
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_filial = :id'
        );

        return $consulta->execute($parametros);
    }

    /** Ativa ou inativa. Filial inativa some do agendamento, mas o historico dela permanece. */
    public static function alterarStatus(int $idFilial, string $status): bool
    {
        if (!in_array($status, ['ativo', 'inativo'], true)) {
            throw new InvalidArgumentException('Status de filial invalido.');
        }

        $consulta = bd()->prepare(
            'UPDATE filiais SET status = :status
             WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_filial = :id'
        );

        return $consulta->execute([':status' => $status, ':id' => $idFilial]);
    }

    /**
     * Filiais ativas com ao menos um profissional ativo que executa o servico.
     * E o que o cliente ve depois de escolher o que quer fazer.
     */
    public static function porServico(int $idServico): array
    {
        $consulta = bd()->prepare(
            'SELECT DISTINCT ' . self::CAMPOS . '
             FROM filiais f
             INNER JOIN profissionais p ON p.id_filial = f.id_filial AND p.id_estabelecimento = f.id_estabelecimento
             INNER JOIN usuarios u ON u.id_usuario = p.id_usuario
             INNER JOIN profissional_servico ps ON ps.id_profissional = p.id_profissional AND ps.id_estabelecimento = f.id_estabelecimento
             WHERE f.id_estabelecimento = ' . Contexto::id() . '
               AND f.status = \'ativo\'
               AND u.status = \'ativo\'
               AND ps.id_servico = :id_servico
             ORDER BY f.ordem ASC, f.nome ASC'
        );
        $consulta->execute([':id_servico' => $idServico]);

        return $consulta->fetchAll();
    }

    /**
     * Faturamento e atendimentos por filial no periodo (cancelados nao somam).
     * Filiais sem movimento aparecem com zero, para o comparativo ficar completo.
     */
    public static function faturamento(string $dataInicial, string $dataFinal): array
    {
        $consulta = bd()->prepare(
            'SELECT f.id_filial, f.nome,
                    COUNT(a.id_agendamento) AS atendimentos,
                    SUM(CASE WHEN a.status = \'concluido\' THEN 1 ELSE 0 END) AS concluidos,
                    COALESCE(SUM(CASE WHEN a.status <> \'cancelado\' THEN a.valor ELSE 0 END), 0) AS valor_total
             FROM filiais f
             LEFT JOIN agendamentos a ON a.id_filial = f.id_filial
                  AND a.id_estabelecimento = f.id_estabelecimento
                  AND a.data_agendamento BETWEEN :inicio AND :fim
             WHERE f.id_estabelecimento = ' . Contexto::id() . '
             GROUP BY f.id_filial, f.nome
             ORDER BY valor_total DESC, f.nome ASC'
        );
        $consulta->execute([':inicio' => $dataInicial, ':fim' => $dataFinal]);

        return $consulta->fetchAll();
    }

    /** Endereco em uma linha, para listas e para a escolha do cliente. */
    public static function enderecoResumido(array $filial): string
    {
        $partes = [];

        if (!empty($filial['logradouro'])) {
            $partes[] = trim($filial['logradouro'] . ' ' . ($filial['numero'] ?? ''));
        }
        if (!empty($filial['bairro'])) {
            $partes[] = $filial['bairro'];
        }
        if (!empty($filial['cidade'])) {
            $partes[] = trim($filial['cidade'] . (!empty($filial['uf']) ? ' - ' . $filial['uf'] : ''));
        }

        return implode(', ', $partes);
    }

    // ---------------------------------------------------------------------
    // Apoio
    // ---------------------------------------------------------------------

    private static function validar(array $dados): void
    {
        $nome = trim((string) ($dados['nome'] ?? ''));
        if ($nome === '' || mb_strlen($nome) > 120) {
            throw new InvalidArgumentException('Informe o nome da filial (ate 120 caracteres).');
        }

        $uf = strtoupper(trim((string) ($dados['uf'] ?? '')));
        if ($uf !== '' && !validarUf($uf)) {
            throw new InvalidArgumentException('UF invalida.');
        }

        // Limites das colunas. Sem esta conferencia, um valor longo (o maxlength
        // do formulario e facil de contornar) estouraria no banco como erro 500
        // em vez de voltar como aviso no formulario.
        $limites = [
            'logradouro'  => ['Logradouro', 150],
            'numero'      => ['Numero', 20],
            'complemento' => ['Complemento', 60],
            'bairro'      => ['Bairro', 100],
            'cidade'      => ['Cidade', 100],
        ];
        foreach ($limites as $campo => [$rotulo, $maximo]) {
            if (mb_strlen(trim((string) ($dados[$campo] ?? ''))) > $maximo) {
                throw new InvalidArgumentException($rotulo . ' aceita ate ' . $maximo . ' caracteres.');
            }
        }

        $telefone = preg_replace('/\D/', '', (string) ($dados['telefone'] ?? ''));
        if ($telefone !== '' && !in_array(strlen($telefone), [10, 11], true)) {
            throw new InvalidArgumentException('Telefone invalido: informe DDD e numero (10 ou 11 digitos).');
        }

        $cep = preg_replace('/\D/', '', (string) ($dados['cep'] ?? ''));
        if ($cep !== '' && strlen($cep) !== 8) {
            throw new InvalidArgumentException('CEP invalido: use 8 digitos.');
        }

        $ordem = (int) ($dados['ordem'] ?? 0);
        if ($ordem < 0 || $ordem > 999) {
            throw new InvalidArgumentException('A ordem deve ficar entre 0 e 999.');
        }

        $status = $dados['status'] ?? 'ativo';
        if (!in_array($status, ['ativo', 'inativo'], true)) {
            throw new InvalidArgumentException('Status de filial invalido.');
        }
    }

    /** Parametros normalizados para INSERT e UPDATE. */
    private static function parametros(array $dados): array
    {
        $cep = preg_replace('/\D/', '', (string) ($dados['cep'] ?? ''));

        return [
            ':nome'        => trim((string) $dados['nome']),
            ':telefone'    => ($dados['telefone'] ?? '') !== '' ? preg_replace('/\D/', '', (string) $dados['telefone']) : null,
            ':cep'         => $cep !== '' ? $cep : null,
            ':logradouro'  => ($dados['logradouro'] ?? '') !== '' ? trim((string) $dados['logradouro']) : null,
            ':numero'      => ($dados['numero'] ?? '') !== '' ? trim((string) $dados['numero']) : null,
            ':complemento' => ($dados['complemento'] ?? '') !== '' ? trim((string) $dados['complemento']) : null,
            ':bairro'      => ($dados['bairro'] ?? '') !== '' ? trim((string) $dados['bairro']) : null,
            ':cidade'      => ($dados['cidade'] ?? '') !== '' ? trim((string) $dados['cidade']) : null,
            ':uf'          => ($dados['uf'] ?? '') !== '' ? strtoupper(trim((string) $dados['uf'])) : null,
            ':foto'        => $dados['foto'] ?? null,
            ':status'      => $dados['status'] ?? 'ativo',
            ':ordem'       => (int) ($dados['ordem'] ?? 0),
        ];
    }
}
