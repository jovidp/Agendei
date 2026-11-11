<?php

/**
 * Assinaturas que o estabelecimento vende aos seus clientes: planos com
 * servicos incluidos por mes e desconto no restante, mensalidade por Pix
 * com a chave da empresa e confirmacao do admin.
 *
 * Nao confundir com Assinatura/Plano, que sao a assinatura da empresa com o
 * Agendei. A situacao (suspensa, encerrada) e derivada das datas: nenhuma
 * tarefa precisa suspender ninguem; a tarefa diaria so gera as renovacoes.
 */
class AssinaturaCliente
{
    /** Dias antes do fim do periodo em que a proxima mensalidade e gerada. */
    public const DIAS_RENOVACAO = 3;

    // -----------------------------------------------------------------
    // Planos
    // -----------------------------------------------------------------

    /** Cria o plano; servicos = [id_servico => quantidade por mes, ou null para ilimitado]. */
    public static function criarPlano(array $dados): int
    {
        $plano = self::validarPlano($dados);
        $db = bd();
        $db->beginTransaction();
        try {
            $q = $db->prepare(
                'INSERT INTO planos_clientes (id_estabelecimento, nome, descricao, preco_mensal, desconto_percentual)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $q->execute([Contexto::id(), $plano['nome'], $plano['descricao'], $plano['preco_mensal'], $plano['desconto_percentual']]);
            $id = (int) $db->lastInsertId();
            self::gravarServicos($id, $plano['servicos']);
            $db->commit();
            return $id;
        } catch (Throwable $erro) {
            if ($db->inTransaction()) $db->rollBack();
            throw $erro;
        }
    }

    /** Altera o plano e troca a lista de servicos; vale para os proximos agendamentos. */
    public static function atualizarPlano(int $id, array $dados): void
    {
        if (!self::planoPorId($id)) {
            throw new InvalidArgumentException('Plano não encontrado.');
        }
        $plano = self::validarPlano($dados);
        $db = bd();
        $db->beginTransaction();
        try {
            $q = $db->prepare(
                'UPDATE planos_clientes SET nome = ?, descricao = ?, preco_mensal = ?, desconto_percentual = ?
                 WHERE id_estabelecimento = ? AND id_plano_cliente = ?'
            );
            $q->execute([$plano['nome'], $plano['descricao'], $plano['preco_mensal'], $plano['desconto_percentual'], Contexto::id(), $id]);
            $db->prepare('DELETE FROM plano_cliente_servicos WHERE id_estabelecimento = ? AND id_plano_cliente = ?')
                ->execute([Contexto::id(), $id]);
            self::gravarServicos($id, $plano['servicos']);
            $db->commit();
        } catch (Throwable $erro) {
            if ($db->inTransaction()) $db->rollBack();
            throw $erro;
        }
    }

    /** Ativa ou inativa; inativo sai da vitrine, mas quem ja assina continua. */
    public static function alterarStatusPlano(int $id, string $status): void
    {
        if (!in_array($status, ['ativo', 'inativo'], true)) {
            throw new InvalidArgumentException('Situação de plano inválida.');
        }
        bd()->prepare('UPDATE planos_clientes SET status = ? WHERE id_estabelecimento = ? AND id_plano_cliente = ?')
            ->execute([$status, Contexto::id(), $id]);
    }

    /** Planos da empresa, cada um com a lista 'servicos' (id_servico, nome, quantidade_mes). */
    public static function planos(bool $somenteAtivos = false): array
    {
        $sql = 'SELECT * FROM planos_clientes WHERE id_estabelecimento = ' . Contexto::id()
            . ($somenteAtivos ? " AND status = 'ativo'" : '') . ' ORDER BY nome, id_plano_cliente';
        $planos = bd()->query($sql)->fetchAll();
        if ($planos === []) return [];

        $servicos = [];
        $q = bd()->query(
            'SELECT pcs.id_plano_cliente, pcs.id_servico, pcs.quantidade_mes, s.nome
             FROM plano_cliente_servicos pcs
             JOIN servicos s ON s.id_estabelecimento = pcs.id_estabelecimento AND s.id_servico = pcs.id_servico
             WHERE pcs.id_estabelecimento = ' . Contexto::id() . ' ORDER BY s.nome'
        );
        foreach ($q->fetchAll() as $s) {
            $servicos[(int) $s['id_plano_cliente']][] = [
                'id_servico'     => (int) $s['id_servico'],
                'nome'           => $s['nome'],
                'quantidade_mes' => $s['quantidade_mes'] === null ? null : (int) $s['quantidade_mes'],
            ];
        }
        foreach ($planos as &$plano) {
            $plano['servicos'] = $servicos[(int) $plano['id_plano_cliente']] ?? [];
        }
        return $planos;
    }

    public static function planoPorId(int $id): ?array
    {
        foreach (self::planos() as $plano) {
            if ((int) $plano['id_plano_cliente'] === $id) return $plano;
        }
        return null;
    }

    // -----------------------------------------------------------------
    // Assinatura
    // -----------------------------------------------------------------

    /** Cria a assinatura pendente e a primeira mensalidade. Devolve o id da assinatura. */
    public static function assinar(int $idCliente, int $idPlano, ?string $hoje = null): int
    {
        $hoje ??= date('Y-m-d');
        if (Configuracao::obter('pix_chave') === '') {
            throw new DomainException('A empresa ainda não configurou a chave Pix para receber assinaturas.');
        }
        $plano = self::planoPorId($idPlano);
        if (!$plano || $plano['status'] !== 'ativo') {
            throw new DomainException('Este plano não está disponível.');
        }
        if (!Cliente::porId($idCliente)) {
            throw new DomainException('Cliente não encontrado.');
        }
        $db = bd();
        $db->beginTransaction();
        try {
            // Trava o cliente antes de conferir: dois pedidos juntos (duplo clique,
            // admin e cliente ao mesmo tempo) passam um de cada vez, e o segundo
            // ja ve a assinatura do primeiro. A leitura com trava ve o dado confirmado.
            $db->prepare('SELECT id_cliente FROM clientes WHERE id_estabelecimento = ? AND id_cliente = ? FOR UPDATE')
                ->execute([Contexto::id(), $idCliente]);
            $q = $db->prepare('SELECT * FROM cliente_assinaturas WHERE id_estabelecimento = ? AND id_cliente = ? ORDER BY id_cliente_assinatura DESC LIMIT 1 FOR UPDATE');
            $q->execute([Contexto::id(), $idCliente]);
            $atual = $q->fetch();
            if ($atual && self::situacao($atual, $hoje) !== 'encerrada') {
                throw new DomainException('Este cliente já tem uma assinatura neste estabelecimento. Cancele a atual antes de assinar outro plano.');
            }
            $db->prepare('INSERT INTO cliente_assinaturas (id_estabelecimento, id_cliente, id_plano_cliente) VALUES (?, ?, ?)')
                ->execute([Contexto::id(), $idCliente, $idPlano]);
            $id = (int) $db->lastInsertId();
            self::criarCobranca($id, (float) $plano['preco_mensal']);
            $db->commit();
            return $id;
        } catch (Throwable $erro) {
            if ($db->inTransaction()) $db->rollBack();
            throw $erro;
        }
    }

    /** pendente | ativa | suspensa | cancelada | encerrada, derivada das datas. */
    public static function situacao(array $assinatura, ?string $hoje = null): string
    {
        $hoje ??= date('Y-m-d');
        $fim = $assinatura['periodo_fim'] ?? null;
        return match ($assinatura['status']) {
            'pendente' => 'pendente',
            'ativa'    => $fim !== null && $hoje <= $fim ? 'ativa' : 'suspensa',
            default    => $fim !== null && $hoje <= $fim ? 'cancelada' : 'encerrada',
        };
    }

    /** Selo da situacao, com as cores dos status que ja existem. */
    public static function badgeSituacao(string $situacao): string
    {
        $estilo = ['pendente' => ['pendente', 'Aguardando pagamento'], 'ativa' => ['ativo', 'Ativa'], 'suspensa' => ['cancelado', 'Suspensa'],
                   'cancelada' => ['aguardando', 'Cancelada'], 'encerrada' => ['inativo', 'Encerrada']][$situacao] ?? ['inativo', $situacao];
        return '<span class="badge badge-' . e($estilo[0]) . '">' . e($estilo[1]) . '</span>';
    }

    /** A assinatura da beneficios para um agendamento nesta data? */
    public static function vigenteEm(array $assinatura, string $data): bool
    {
        return in_array($assinatura['status'], ['ativa', 'cancelada'], true)
            && !empty($assinatura['periodo_inicio'])
            && $assinatura['periodo_inicio'] <= $data && $data <= $assinatura['periodo_fim'];
    }

    /**
     * Mes da assinatura que contem $data, como [inicio, fim], ou null fora do
     * periodo pago. periodo_inicio e a ancora da sequencia paga sem
     * interrupcao; cada mes seguinte comeca no dia depois do fim do anterior,
     * o mesmo passo com que confirmarPagamento estende periodo_fim.
     */
    public static function janela(array $assinatura, string $data): ?array
    {
        if (!self::vigenteEm($assinatura, $data)) return null;
        $inicio = $assinatura['periodo_inicio'];
        while (($fim = self::periodoFim($inicio)) < $data) {
            $inicio = (new DateTimeImmutable($fim))->modify('+1 day')->format('Y-m-d');
        }
        return [$inicio, $fim];
    }

    /**
     * Ultimo dia do periodo de um mes que comeca em $inicio. Quando o mesmo dia
     * nao existe no mes seguinte (31/01), o periodo vai ate o fim dele (28/02)
     * em vez de invadir o outro mes, como faria o "+1 month" do PHP.
     */
    public static function periodoFim(string $inicio): string
    {
        $data = new DateTimeImmutable($inicio);
        $mesSeguinte = $data->modify('first day of next month');
        $proximo = (int) $data->format('j') <= (int) $mesSeguinte->format('t')
            ? $mesSeguinte->setDate((int) $mesSeguinte->format('Y'), (int) $mesSeguinte->format('n'), (int) $data->format('j'))
            : $mesSeguinte->modify('first day of next month');
        return $proximo->modify('-1 day')->format('Y-m-d');
    }

    /**
     * Marca a mensalidade como paga. Em dia, so estende periodo_fim por mais
     * um mes: o inicio fica, para o resto do mes ja pago continuar valendo
     * (janela() acha o mes de cada data). Atrasada (ou a primeira), a
     * sequencia recomeca hoje, para quem ficou suspenso nao pagar pelos dias
     * sem beneficio. Devolve false se a cobranca nao estava pendente (duplo
     * clique, outra empresa).
     */
    public static function confirmarPagamento(int $idCobranca, ?string $hoje = null): bool
    {
        $hoje ??= date('Y-m-d');
        $db = bd();
        $db->beginTransaction();
        try {
            $q = $db->prepare(
                "SELECT * FROM cobrancas_assinatura_cliente
                 WHERE id_estabelecimento = ? AND id_cobranca = ? AND status = 'pendente' FOR UPDATE"
            );
            $q->execute([Contexto::id(), $idCobranca]);
            $cobranca = $q->fetch();
            if (!$cobranca) {
                $db->rollBack();
                return false;
            }
            $assinatura = self::travar((int) $cobranca['id_cliente_assinatura']);
            $emDia = $assinatura['status'] === 'ativa' && !empty($assinatura['periodo_fim']) && $hoje <= $assinatura['periodo_fim'];
            $inicio = $emDia ? $assinatura['periodo_inicio'] : $hoje;
            $fim = $emDia
                ? self::periodoFim((new DateTimeImmutable($assinatura['periodo_fim']))->modify('+1 day')->format('Y-m-d'))
                : self::periodoFim($hoje);

            $db->prepare("UPDATE cobrancas_assinatura_cliente SET status = 'pago', data_pagamento = CURRENT_TIMESTAMP WHERE id_estabelecimento = ? AND id_cobranca = ?")
                ->execute([Contexto::id(), $idCobranca]);
            $db->prepare("UPDATE cliente_assinaturas SET status = 'ativa', periodo_inicio = ?, periodo_fim = ? WHERE id_estabelecimento = ? AND id_cliente_assinatura = ?")
                ->execute([$inicio, $fim, Contexto::id(), $assinatura['id_cliente_assinatura']]);
            $db->commit();
            return true;
        } catch (Throwable $erro) {
            if ($db->inTransaction()) $db->rollBack();
            throw $erro;
        }
    }

    /** Forma escolhida pelo cliente para a mensalidade pendente dele (Pix, boleto ou cartao). */
    public static function escolherMetodo(int $idCobranca, int $idCliente, string $metodo, ?string $cartaoFinal): void
    {
        $final = Cobranca::validarEscolha($metodo, $cartaoFinal);
        if (!self::cobrancaDoCliente($idCobranca, $idCliente)) {
            throw new DomainException('Esta mensalidade não está mais aguardando pagamento.');
        }
        bd()->prepare("UPDATE cobrancas_assinatura_cliente SET metodo = ?, cartao_final = ? WHERE id_estabelecimento = ? AND id_cobranca = ? AND status = 'pendente'")
            ->execute([$metodo, $final, Contexto::id(), $idCobranca]);
    }

    /** A mensalidade pendente do cliente; null se nao for dele ou nao estiver pendente. */
    public static function cobrancaDoCliente(int $idCobranca, int $idCliente): ?array
    {
        $q = bd()->prepare(
            "SELECT c.*, a.id_cliente FROM cobrancas_assinatura_cliente c
             JOIN cliente_assinaturas a ON a.id_estabelecimento = c.id_estabelecimento AND a.id_cliente_assinatura = c.id_cliente_assinatura
             WHERE c.id_estabelecimento = ? AND c.id_cobranca = ? AND a.id_cliente = ? AND c.status = 'pendente'"
        );
        $q->execute([Contexto::id(), $idCobranca, $idCliente]);
        return $q->fetch() ?: null;
    }

    public static function cancelarCobranca(int $idCobranca): void
    {
        bd()->prepare("UPDATE cobrancas_assinatura_cliente SET status = 'cancelado' WHERE id_estabelecimento = ? AND id_cobranca = ? AND status = 'pendente'")
            ->execute([Contexto::id(), $idCobranca]);
    }

    /**
     * Cancela a assinatura; os beneficios seguem ate o fim do periodo pago.
     * Com $idCliente, so cancela se a assinatura for dele (pedido vindo do cliente).
     */
    public static function cancelar(int $idAssinatura, ?int $idCliente = null): bool
    {
        $sql = "UPDATE cliente_assinaturas SET status = 'cancelada', data_cancelamento = CURRENT_TIMESTAMP
                WHERE id_estabelecimento = ? AND id_cliente_assinatura = ? AND status <> 'cancelada'";
        $params = [Contexto::id(), $idAssinatura];
        if ($idCliente !== null) {
            $sql .= ' AND id_cliente = ?';
            $params[] = $idCliente;
        }
        $q = bd()->prepare($sql);
        $q->execute($params);
        if ($q->rowCount() < 1) return false;
        bd()->prepare("UPDATE cobrancas_assinatura_cliente SET status = 'cancelado' WHERE id_estabelecimento = ? AND id_cliente_assinatura = ? AND status = 'pendente'")
            ->execute([Contexto::id(), $idAssinatura]);
        return true;
    }

    /** Gera a proxima mensalidade de quem vence em ate DIAS_RENOVACAO dias. Devolve quantas criou. */
    public static function gerarRenovacoes(?string $hoje = null): int
    {
        $hoje ??= date('Y-m-d');
        $limite = (new DateTimeImmutable($hoje))->modify('+' . self::DIAS_RENOVACAO . ' days')->format('Y-m-d');
        $q = bd()->prepare(
            "SELECT a.id_cliente_assinatura FROM cliente_assinaturas a
             WHERE a.id_estabelecimento = ? AND a.status = 'ativa' AND a.periodo_fim <= ?
               AND NOT EXISTS (SELECT 1 FROM cobrancas_assinatura_cliente c
                               WHERE c.id_estabelecimento = a.id_estabelecimento
                                 AND c.id_cliente_assinatura = a.id_cliente_assinatura AND c.status = 'pendente')"
        );
        $q->execute([Contexto::id(), $limite]);

        $criadas = 0;
        $db = bd();
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $idAssinatura) {
            $db->beginTransaction();
            try {
                // De novo sob trava: a pagina do cliente e a tarefa podem chegar juntas.
                $assinatura = self::travar((int) $idAssinatura);
                if ($assinatura['status'] === 'ativa' && self::cobrancaPendente((int) $idAssinatura) === null) {
                    $plano = self::planoPorId((int) $assinatura['id_plano_cliente']);
                    self::criarCobranca((int) $idAssinatura, (float) $plano['preco_mensal']);
                    $criadas++;
                }
                $db->commit();
            } catch (Throwable $erro) {
                if ($db->inTransaction()) $db->rollBack();
                throw $erro;
            }
        }
        return $criadas;
    }

    /** Renovacoes de todas as empresas; so a tarefa agendada percorre as empresas. */
    public static function renovarTodas(): array
    {
        if (!defined('TAREFA_AGENDADA')) {
            throw new LogicException('Somente a tarefa agendada renova as assinaturas de todas as empresas.');
        }
        $empresas = bd()->query("SELECT id_estabelecimento FROM estabelecimento WHERE status = 'ativo' ORDER BY id_estabelecimento")
            ->fetchAll(PDO::FETCH_COLUMN);
        $resumo = [];
        foreach ($empresas as $id) {
            $id = (int) $id;
            try {
                Contexto::assumirTarefa($id);
                $resumo[$id] = self::gerarRenovacoes();
            } catch (Throwable $erro) {
                error_log('Renovacoes da empresa ' . $id . ' falharam: ' . $erro->getMessage());
                $resumo[$id] = 'erro';
            }
        }
        return $resumo;
    }

    /**
     * A assinatura mais recente do cliente, com situacao, plano, cobranca
     * pendente e uso do periodo atual ([id_servico => quantidade]).
     */
    public static function daCliente(int $idCliente, ?string $hoje = null): ?array
    {
        $q = bd()->prepare(
            'SELECT * FROM cliente_assinaturas WHERE id_estabelecimento = ? AND id_cliente = ?
             ORDER BY id_cliente_assinatura DESC LIMIT 1'
        );
        $q->execute([Contexto::id(), $idCliente]);
        $assinatura = $q->fetch();
        if (!$assinatura) return null;

        $id = (int) $assinatura['id_cliente_assinatura'];
        $assinatura['situacao'] = self::situacao($assinatura, $hoje);
        $assinatura['plano'] = self::planoPorId((int) $assinatura['id_plano_cliente']);
        $assinatura['cobranca_pendente'] = self::cobrancaPendente($id);
        $assinatura['uso'] = [];
        // Uso do mes de hoje; 'mes_atual' e [inicio, fim] dele, ou null fora do periodo pago.
        $assinatura['mes_atual'] = self::janela($assinatura, $hoje ?? date('Y-m-d'));
        if ($assinatura['mes_atual'] !== null) {
            foreach ($assinatura['plano']['servicos'] ?? [] as $servico) {
                $assinatura['uso'][$servico['id_servico']] = self::uso($id, $servico['id_servico'], ...$assinatura['mes_atual']);
            }
        }
        return $assinatura;
    }

    /** Todas as assinaturas da empresa, mais recentes primeiro, para o admin. */
    public static function assinantes(?string $hoje = null): array
    {
        $lista = bd()->query(
            'SELECT a.*, u.nome nome_cliente, p.nome nome_plano
             FROM cliente_assinaturas a
             JOIN clientes c ON c.id_estabelecimento = a.id_estabelecimento AND c.id_cliente = a.id_cliente
             JOIN usuarios u ON u.id_usuario = c.id_usuario
             JOIN planos_clientes p ON p.id_estabelecimento = a.id_estabelecimento AND p.id_plano_cliente = a.id_plano_cliente
             WHERE a.id_estabelecimento = ' . Contexto::id() . ' ORDER BY a.id_cliente_assinatura DESC'
        )->fetchAll();
        foreach ($lista as &$assinatura) {
            $assinatura['situacao'] = self::situacao($assinatura, $hoje);
            $assinatura['cobranca_pendente'] = self::cobrancaPendente((int) $assinatura['id_cliente_assinatura']);
        }
        return $lista;
    }

    /**
     * Valor do agendamento para o cliente e a assinatura que o cobre (ou null).
     * Chamar dentro da transacao do agendamento: a assinatura fica travada
     * enquanto o uso e contado, para dois agendamentos simultaneos nao
     * passarem juntos da cota.
     * @return array{id_cliente_assinatura: ?int, valor: float}
     */
    public static function beneficio(int $idCliente, int $idServico, string $data, float $preco): array
    {
        $semBeneficio = ['id_cliente_assinatura' => null, 'valor' => round($preco, 2)];
        $q = bd()->prepare(
            'SELECT * FROM cliente_assinaturas WHERE id_estabelecimento = ? AND id_cliente = ?
             ORDER BY id_cliente_assinatura DESC LIMIT 1 FOR UPDATE'
        );
        $q->execute([Contexto::id(), $idCliente]);
        $assinatura = $q->fetch();
        if (!$assinatura || !self::vigenteEm($assinatura, $data)) return $semBeneficio;

        $plano = self::planoPorId((int) $assinatura['id_plano_cliente']);
        if (!$plano) return $semBeneficio;
        $id = (int) $assinatura['id_cliente_assinatura'];
        foreach ($plano['servicos'] as $servico) {
            if ($servico['id_servico'] !== $idServico) continue;
            [$inicio, $fim] = self::janela($assinatura, $data);
            $usado = self::uso($id, $idServico, $inicio, $fim, true);
            if ($servico['quantidade_mes'] === null || $usado < $servico['quantidade_mes']) {
                return ['id_cliente_assinatura' => $id, 'valor' => 0.0];
            }
        }
        return ['id_cliente_assinatura' => null, 'valor' => round($preco * (1 - (int) $plano['desconto_percentual'] / 100), 2)];
    }

    /**
     * Agendamentos nao cancelados do servico cobertos pela assinatura no periodo.
     * $travar faz leitura com trava: no MySQL (REPEATABLE READ) um COUNT comum
     * leria a foto do inicio da transacao e nao veria o agendamento que outra
     * transacao acabou de gravar; FOR UPDATE le o dado confirmado. O PostgreSQL
     * nao aceita FOR UPDATE com agregacao, por isso as linhas sao contadas aqui.
     */
    public static function uso(int $idAssinatura, int $idServico, string $inicio, string $fim, bool $travar = false): int
    {
        $q = bd()->prepare(
            "SELECT id_agendamento FROM agendamentos
             WHERE id_estabelecimento = ? AND id_cliente_assinatura = ? AND id_servico = ?
               AND data_agendamento BETWEEN ? AND ? AND status <> 'cancelado'" . ($travar ? ' FOR UPDATE' : '')
        );
        $q->execute([Contexto::id(), $idAssinatura, $idServico, $inicio, $fim]);
        return count($q->fetchAll(PDO::FETCH_COLUMN));
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private static function validarPlano(array $dados): array
    {
        $nome = trim((string) ($dados['nome'] ?? ''));
        if ($nome === '' || mb_strlen($nome) > 120) {
            throw new InvalidArgumentException('Informe o nome do plano (até 120 caracteres).');
        }
        $preco = $dados['preco_mensal'] ?? '';
        if (is_string($preco) && str_contains($preco, ',')) {
            $preco = str_replace(['.', ','], ['', '.'], $preco);
        }
        if (!is_numeric($preco) || (float) $preco <= 0) {
            throw new InvalidArgumentException('Informe um preço mensal maior que zero.');
        }
        $desconto = $dados['desconto_percentual'] ?? 0;
        if ($desconto === '' || $desconto === null) $desconto = 0;
        if (!is_numeric($desconto) || (int) $desconto != $desconto || $desconto < 0 || $desconto > 100) {
            throw new InvalidArgumentException('O desconto deve ser um número inteiro de 0 a 100.');
        }
        $servicos = [];
        foreach ((array) ($dados['servicos'] ?? []) as $idServico => $quantidade) {
            if ($quantidade !== null && (!is_numeric($quantidade) || (int) $quantidade < 1)) {
                throw new InvalidArgumentException('A quantidade por mês deve ser 1 ou mais (vazio para ilimitado).');
            }
            $servicos[(int) $idServico] = $quantidade === null ? null : (int) $quantidade;
        }
        if ($servicos === []) {
            throw new InvalidArgumentException('Escolha pelo menos um serviço incluído no plano.');
        }
        $q = bd()->prepare('SELECT COUNT(*) FROM servicos WHERE id_estabelecimento = ? AND id_servico IN (' . implode(',', array_fill(0, count($servicos), '?')) . ')');
        $q->execute(array_merge([Contexto::id()], array_keys($servicos)));
        if ((int) $q->fetchColumn() !== count($servicos)) {
            throw new InvalidArgumentException('Serviço inválido no plano.');
        }
        $descricao = trim((string) ($dados['descricao'] ?? ''));
        return [
            'nome'                => $nome,
            'descricao'           => $descricao === '' ? null : mb_substr($descricao, 0, 500),
            'preco_mensal'        => round((float) $preco, 2),
            'desconto_percentual' => (int) $desconto,
            'servicos'            => $servicos,
        ];
    }

    private static function gravarServicos(int $idPlano, array $servicos): void
    {
        $q = bd()->prepare('INSERT INTO plano_cliente_servicos (id_estabelecimento, id_plano_cliente, id_servico, quantidade_mes) VALUES (?, ?, ?, ?)');
        foreach ($servicos as $idServico => $quantidade) {
            $q->execute([Contexto::id(), $idPlano, $idServico, $quantidade]);
        }
    }

    private static function criarCobranca(int $idAssinatura, float $valor): int
    {
        bd()->prepare('INSERT INTO cobrancas_assinatura_cliente (id_estabelecimento, id_cliente_assinatura, valor) VALUES (?, ?, ?)')
            ->execute([Contexto::id(), $idAssinatura, $valor]);
        $id = (int) bd()->lastInsertId();
        bd()->prepare('UPDATE cobrancas_assinatura_cliente SET referencia = ? WHERE id_estabelecimento = ? AND id_cobranca = ?')
            ->execute(['AC' . $id, Contexto::id(), $id]);
        return $id;
    }

    private static function cobrancaPendente(int $idAssinatura): ?array
    {
        $q = bd()->prepare(
            "SELECT * FROM cobrancas_assinatura_cliente
             WHERE id_estabelecimento = ? AND id_cliente_assinatura = ? AND status = 'pendente'
             ORDER BY id_cobranca DESC LIMIT 1"
        );
        $q->execute([Contexto::id(), $idAssinatura]);
        return $q->fetch() ?: null;
    }

    /** Le a assinatura com FOR UPDATE; chamar dentro de transacao. */
    private static function travar(int $idAssinatura): array
    {
        $q = bd()->prepare('SELECT * FROM cliente_assinaturas WHERE id_estabelecimento = ? AND id_cliente_assinatura = ? FOR UPDATE');
        $q->execute([Contexto::id(), $idAssinatura]);
        $assinatura = $q->fetch();
        if (!$assinatura) {
            throw new RuntimeException('Assinatura não encontrada.');
        }
        return $assinatura;
    }

    /** Cria as tabelas e a coluna em agendamentos; pode rodar de novo sem efeito. */
    public static function garantirEstrutura(): void
    {
        foreach (self::ddl() as $comando) {
            bd()->exec($comando);
        }
        Sql::garantirColuna('agendamentos', 'id_cliente_assinatura', Database::ehPostgres() ? 'INTEGER DEFAULT NULL' : 'INT UNSIGNED DEFAULT NULL');
    }

    /** Comandos de criacao das tabelas em cada dialeto (os mesmos dos scripts .sql). */
    public static function ddl(): array
    {
        if (Database::ehPostgres()) {
            return [
                "CREATE TABLE IF NOT EXISTS planos_clientes (
                    id_plano_cliente    INTEGER GENERATED BY DEFAULT AS IDENTITY,
                    id_estabelecimento  INTEGER NOT NULL,
                    nome                VARCHAR(120) NOT NULL,
                    descricao           VARCHAR(500) DEFAULT NULL,
                    preco_mensal        NUMERIC(10,2) NOT NULL,
                    desconto_percentual SMALLINT NOT NULL DEFAULT 0,
                    status              VARCHAR(7) NOT NULL DEFAULT 'ativo',
                    data_criacao        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT pk_planos_clientes PRIMARY KEY (id_plano_cliente),
                    CONSTRAINT uk_estabelecimento_plano_cliente UNIQUE (id_estabelecimento, id_plano_cliente),
                    CONSTRAINT ck_plano_cliente_status CHECK (status IN ('ativo','inativo')),
                    CONSTRAINT ck_plano_cliente_desconto CHECK (desconto_percentual BETWEEN 0 AND 100),
                    CONSTRAINT fk_plano_cliente_empresa FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento (id_estabelecimento)
                )",
                "CREATE TABLE IF NOT EXISTS plano_cliente_servicos (
                    id_estabelecimento INTEGER NOT NULL,
                    id_plano_cliente   INTEGER NOT NULL,
                    id_servico         INTEGER NOT NULL,
                    quantidade_mes     SMALLINT DEFAULT NULL,
                    CONSTRAINT pk_plano_cliente_servicos PRIMARY KEY (id_estabelecimento, id_plano_cliente, id_servico),
                    CONSTRAINT ck_plano_cliente_quantidade CHECK (quantidade_mes IS NULL OR quantidade_mes >= 0),
                    CONSTRAINT fk_pcs_plano_tenant FOREIGN KEY (id_estabelecimento, id_plano_cliente) REFERENCES planos_clientes (id_estabelecimento, id_plano_cliente) ON DELETE CASCADE,
                    CONSTRAINT fk_pcs_servico_tenant FOREIGN KEY (id_estabelecimento, id_servico) REFERENCES servicos (id_estabelecimento, id_servico)
                )",
                "CREATE TABLE IF NOT EXISTS cliente_assinaturas (
                    id_cliente_assinatura INTEGER GENERATED BY DEFAULT AS IDENTITY,
                    id_estabelecimento    INTEGER NOT NULL,
                    id_cliente            INTEGER NOT NULL,
                    id_plano_cliente      INTEGER NOT NULL,
                    status                VARCHAR(9) NOT NULL DEFAULT 'pendente',
                    periodo_inicio        DATE DEFAULT NULL,
                    periodo_fim           DATE DEFAULT NULL,
                    data_cancelamento     TIMESTAMP DEFAULT NULL,
                    data_criacao          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT pk_cliente_assinaturas PRIMARY KEY (id_cliente_assinatura),
                    CONSTRAINT uk_estabelecimento_cliente_assinatura UNIQUE (id_estabelecimento, id_cliente_assinatura),
                    CONSTRAINT ck_cliente_assinatura_status CHECK (status IN ('pendente','ativa','cancelada')),
                    CONSTRAINT fk_cliente_assinatura_empresa FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento (id_estabelecimento),
                    CONSTRAINT fk_cliente_assinatura_cliente_tenant FOREIGN KEY (id_estabelecimento, id_cliente) REFERENCES clientes (id_estabelecimento, id_cliente) ON DELETE CASCADE,
                    CONSTRAINT fk_cliente_assinatura_plano_tenant FOREIGN KEY (id_estabelecimento, id_plano_cliente) REFERENCES planos_clientes (id_estabelecimento, id_plano_cliente)
                )",
                'CREATE INDEX IF NOT EXISTS idx_cliente_assinaturas ON cliente_assinaturas (id_estabelecimento, id_cliente, status)',
                "CREATE TABLE IF NOT EXISTS cobrancas_assinatura_cliente (
                    id_cobranca           INTEGER GENERATED BY DEFAULT AS IDENTITY,
                    id_estabelecimento    INTEGER NOT NULL,
                    id_cliente_assinatura INTEGER NOT NULL,
                    valor                 NUMERIC(10,2) NOT NULL,
                    status                VARCHAR(9) NOT NULL DEFAULT 'pendente',
                    referencia            VARCHAR(40) DEFAULT NULL,
                    metodo                VARCHAR(6) DEFAULT NULL,
                    cartao_final          CHAR(4) DEFAULT NULL,
                    data_pagamento        TIMESTAMP DEFAULT NULL,
                    data_criacao          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT pk_cobrancas_assinatura_cliente PRIMARY KEY (id_cobranca),
                    CONSTRAINT ck_cobranca_assinatura_status CHECK (status IN ('pendente','pago','cancelado')),
                    CONSTRAINT fk_cobranca_assinatura_empresa FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento (id_estabelecimento),
                    CONSTRAINT fk_cobranca_assinatura_tenant FOREIGN KEY (id_estabelecimento, id_cliente_assinatura) REFERENCES cliente_assinaturas (id_estabelecimento, id_cliente_assinatura) ON DELETE CASCADE
                )",
                'CREATE INDEX IF NOT EXISTS idx_cobrancas_assinatura ON cobrancas_assinatura_cliente (id_estabelecimento, id_cliente_assinatura, status)',
                // RLS: a API publica do Supabase nao le as tabelas; o app conecta como dono e nao e afetado.
                'ALTER TABLE planos_clientes ENABLE ROW LEVEL SECURITY',
                'ALTER TABLE plano_cliente_servicos ENABLE ROW LEVEL SECURITY',
                'ALTER TABLE cliente_assinaturas ENABLE ROW LEVEL SECURITY',
                'ALTER TABLE cobrancas_assinatura_cliente ENABLE ROW LEVEL SECURITY',
            ];
        }

        return [
            "CREATE TABLE IF NOT EXISTS planos_clientes (
                id_plano_cliente INT UNSIGNED NOT NULL AUTO_INCREMENT,
                id_estabelecimento INT UNSIGNED NOT NULL,
                nome VARCHAR(120) NOT NULL,
                descricao VARCHAR(500) NULL,
                preco_mensal DECIMAL(10,2) NOT NULL,
                desconto_percentual TINYINT UNSIGNED NOT NULL DEFAULT 0,
                status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
                data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id_plano_cliente),
                UNIQUE KEY uk_estabelecimento_plano_cliente (id_estabelecimento,id_plano_cliente),
                CONSTRAINT fk_plano_cliente_empresa FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento(id_estabelecimento)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS plano_cliente_servicos (
                id_estabelecimento INT UNSIGNED NOT NULL,
                id_plano_cliente INT UNSIGNED NOT NULL,
                id_servico INT UNSIGNED NOT NULL,
                quantidade_mes SMALLINT UNSIGNED NULL,
                PRIMARY KEY (id_estabelecimento,id_plano_cliente,id_servico),
                CONSTRAINT fk_pcs_plano_tenant FOREIGN KEY (id_estabelecimento,id_plano_cliente) REFERENCES planos_clientes(id_estabelecimento,id_plano_cliente) ON DELETE CASCADE,
                CONSTRAINT fk_pcs_servico_tenant FOREIGN KEY (id_estabelecimento,id_servico) REFERENCES servicos(id_estabelecimento,id_servico)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS cliente_assinaturas (
                id_cliente_assinatura INT UNSIGNED NOT NULL AUTO_INCREMENT,
                id_estabelecimento INT UNSIGNED NOT NULL,
                id_cliente INT UNSIGNED NOT NULL,
                id_plano_cliente INT UNSIGNED NOT NULL,
                status ENUM('pendente','ativa','cancelada') NOT NULL DEFAULT 'pendente',
                periodo_inicio DATE NULL,
                periodo_fim DATE NULL,
                data_cancelamento DATETIME NULL,
                data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id_cliente_assinatura),
                UNIQUE KEY uk_estabelecimento_cliente_assinatura (id_estabelecimento,id_cliente_assinatura),
                KEY idx_cliente_assinaturas (id_estabelecimento,id_cliente,status),
                CONSTRAINT fk_cliente_assinatura_empresa FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento(id_estabelecimento),
                CONSTRAINT fk_cliente_assinatura_cliente_tenant FOREIGN KEY (id_estabelecimento,id_cliente) REFERENCES clientes(id_estabelecimento,id_cliente) ON DELETE CASCADE,
                CONSTRAINT fk_cliente_assinatura_plano_tenant FOREIGN KEY (id_estabelecimento,id_plano_cliente) REFERENCES planos_clientes(id_estabelecimento,id_plano_cliente)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS cobrancas_assinatura_cliente (
                id_cobranca INT UNSIGNED NOT NULL AUTO_INCREMENT,
                id_estabelecimento INT UNSIGNED NOT NULL,
                id_cliente_assinatura INT UNSIGNED NOT NULL,
                valor DECIMAL(10,2) NOT NULL,
                status ENUM('pendente','pago','cancelado') NOT NULL DEFAULT 'pendente',
                referencia VARCHAR(40) NULL,
                metodo VARCHAR(6) NULL,
                cartao_final CHAR(4) NULL,
                data_pagamento DATETIME NULL,
                data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id_cobranca),
                KEY idx_cobrancas_assinatura (id_estabelecimento,id_cliente_assinatura,status),
                CONSTRAINT fk_cobranca_assinatura_empresa FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento(id_estabelecimento),
                CONSTRAINT fk_cobranca_assinatura_tenant FOREIGN KEY (id_estabelecimento,id_cliente_assinatura) REFERENCES cliente_assinaturas(id_estabelecimento,id_cliente_assinatura) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
}
