<?php
/**
 * Recursos de relacionamento e receita: lista de espera, notificações,
 * sinal Pix, recorrência, fidelidade, pacotes, avaliações e comissões.
 */
class Diferencial
{
    public static function entrarLista(int $idCliente, int $idServico, ?int $idProfissional, string $data, string $periodo): void
    {
        if (!Cliente::porId($idCliente) || !Servico::porId($idServico)) {
            throw new InvalidArgumentException('Cliente ou serviço inválido.');
        }
        if ($idProfissional && !Profissional::porId($idProfissional)) {
            throw new InvalidArgumentException('Profissional inválido.');
        }
        if (!validarData($data) || $data < date('Y-m-d')) {
            throw new InvalidArgumentException('Escolha uma data válida.');
        }
        if (!in_array($periodo, ['qualquer', 'manha', 'tarde', 'noite'], true)) {
            $periodo = 'qualquer';
        }
        $q = bd()->prepare(
            'INSERT INTO lista_espera (id_estabelecimento,id_cliente,id_servico,id_profissional,data_desejada,periodo)
             VALUES (:empresa,:cliente,:servico,:profissional,:data,:periodo)'
            . Sql::aoDuplicarComValores(
                ['id_estabelecimento', 'id_cliente', 'id_servico', 'id_profissional', 'data_desejada'],
                ['periodo'],
                ['status' => "'aguardando'", 'data_aviso' => 'NULL']
            )
        );
        $q->execute([
            ':empresa' => Contexto::id(), ':cliente' => $idCliente, ':servico' => $idServico,
            ':profissional' => $idProfissional ?: null, ':data' => $data, ':periodo' => $periodo,
        ]);
    }

    public static function listaDoCliente(int $idCliente): array
    {
        $q = bd()->prepare(
            'SELECT l.*,s.nome nome_servico,u.nome nome_profissional
             FROM lista_espera l
             JOIN servicos s ON s.id_servico=l.id_servico
             LEFT JOIN profissionais p ON p.id_profissional=l.id_profissional
             LEFT JOIN usuarios u ON u.id_usuario=p.id_usuario
             WHERE l.id_estabelecimento=? AND l.id_cliente=?
             ORDER BY l.data_desejada,l.data_criacao'
        );
        $q->execute([Contexto::id(), $idCliente]);
        return $q->fetchAll();
    }

    public static function cancelarLista(int $idLista, int $idCliente): bool
    {
        $q = bd()->prepare('UPDATE lista_espera SET status=\'cancelado\' WHERE id_estabelecimento=? AND id_lista=? AND id_cliente=?');
        return $q->execute([Contexto::id(), $idLista, $idCliente]);
    }

    public static function listaAdministrativa(): array
    {
        return bd()->query(
            'SELECT l.*,uc.nome nome_cliente,uc.telefone,s.nome nome_servico,up.nome nome_profissional
             FROM lista_espera l
             JOIN clientes c ON c.id_cliente=l.id_cliente
             JOIN usuarios uc ON uc.id_usuario=c.id_usuario
             JOIN servicos s ON s.id_servico=l.id_servico
             LEFT JOIN profissionais p ON p.id_profissional=l.id_profissional
             LEFT JOIN usuarios up ON up.id_usuario=p.id_usuario
             WHERE l.id_estabelecimento=' . Contexto::id() . '
             ORDER BY CASE l.status WHEN \'aguardando\' THEN 1 WHEN \'avisado\' THEN 2
                                    WHEN \'convertido\' THEN 3 WHEN \'cancelado\' THEN 4 ELSE 0 END,
                      l.data_desejada'
        )->fetchAll();
    }

    /** Cria avisos para os clientes compatíveis com o horário que acabou de vagar. */
    public static function avisarListaEspera(array $agendamento): int
    {
        $hora = (int) substr($agendamento['hora_inicio'], 0, 2);
        $periodoVaga = $hora < 12 ? 'manha' : ($hora < 18 ? 'tarde' : 'noite');
        $q = bd()->prepare(
            'SELECT l.id_lista,c.id_usuario,u.telefone,u.nome
             FROM lista_espera l
             JOIN clientes c ON c.id_cliente=l.id_cliente
             JOIN usuarios u ON u.id_usuario=c.id_usuario
             WHERE l.id_estabelecimento=:empresa AND l.id_servico=:servico
               AND l.data_desejada=:data AND l.status=\'aguardando\'
               AND (l.id_profissional IS NULL OR l.id_profissional=:profissional)
               AND l.periodo IN (\'qualquer\',:periodo)'
        );
        $q->execute([
            ':empresa' => Contexto::id(), ':servico' => $agendamento['id_servico'],
            ':data' => $agendamento['data_agendamento'], ':profissional' => $agendamento['id_profissional'],
            ':periodo' => $periodoVaga,
        ]);
        $inserir = bd()->prepare(
            Sql::inserirIgnorando() . ' notificacoes
             (id_estabelecimento,id_usuario,id_agendamento,canal,tipo,destinatario,mensagem,data_programada)
             VALUES (?,?,?,?,?,?,?,NOW())' . Sql::ignorarConflito()
        );
        $atualizar = bd()->prepare('UPDATE lista_espera SET status=\'avisado\',data_aviso=NOW() WHERE id_estabelecimento=? AND id_lista=?');
        $total = 0;
        foreach ($q->fetchAll() as $item) {
            $mensagem = 'Olá, ' . explode(' ', $item['nome'])[0] . '! Surgiu uma vaga em '
                . formatarData($agendamento['data_agendamento']) . ' às ' . formatarHora($agendamento['hora_inicio'])
                . '. Entre no Agendei para reservar.';
            $inserir->execute([
                Contexto::id(), $item['id_usuario'], $agendamento['id_agendamento'], 'whatsapp',
                'vaga_lista_' . $item['id_lista'], $item['telefone'], $mensagem,
            ]);
            $atualizar->execute([Contexto::id(), $item['id_lista']]);
            $total++;
        }
        return $total;
    }

    /**
     * Gera a fila de lembretes dos agendamentos das proximas N horas.
     *
     * A fila e uma so: o painel a mostra com o link "Abrir WhatsApp" e a tarefa
     * periodica (Lembrete) a envia sozinha quando ha provedor configurado.
     * A chave unica (empresa, agendamento, tipo) garante um lembrete por reserva.
     */
    public static function gerarLembretes(): int
    {
        $horas = max(1, Configuracao::obterInteiro('lembrete_horas', 24));
        $q = bd()->prepare(
            'SELECT a.id_agendamento,c.id_usuario,u.nome,u.telefone,a.data_agendamento,a.hora_inicio,
                    s.nome servico,p.nome profissional
             FROM agendamentos a
             JOIN clientes c ON c.id_cliente=a.id_cliente
             JOIN usuarios u ON u.id_usuario=c.id_usuario
             JOIN servicos s ON s.id_servico=a.id_servico
             JOIN profissionais pr ON pr.id_profissional=a.id_profissional
             JOIN usuarios p ON p.id_usuario=pr.id_usuario
             WHERE a.id_estabelecimento=? AND a.status IN (\'agendado\',\'confirmado\')
               AND ' . Sql::dataHora('a.data_agendamento', 'a.hora_inicio')
                 . ' BETWEEN NOW() AND ' . Sql::somarHoras('NOW()', '?')
        );
        $q->execute([Contexto::id(), $horas]);
        $inserir = bd()->prepare(
            Sql::inserirIgnorando() . ' notificacoes
             (id_estabelecimento,id_usuario,id_agendamento,canal,tipo,destinatario,mensagem,data_programada)
             VALUES (?,?,?,?,?,?,?,NOW())' . Sql::ignorarConflito()
        );
        $empresa = Estabelecimento::campo('nome', NOME_SISTEMA);
        $total = 0;
        foreach ($q->fetchAll() as $item) {
            // O link assinado leva o cliente a confirmar.php. Sem host conhecido
            // (cron sem AGENDEI_URL) a mensagem volta ao pedido de aviso.
            $link = Confirmacao::link([
                'id_agendamento'   => $item['id_agendamento'],
                'data_agendamento' => $item['data_agendamento'],
                'hora_inicio'      => $item['hora_inicio'],
            ]);
            $mensagem = 'Olá, ' . explode(' ', trim($item['nome']))[0] . '! Lembrete do ' . $empresa . ': '
                . $item['servico'] . ' em ' . formatarData($item['data_agendamento'])
                . ' às ' . formatarHora($item['hora_inicio']) . ', com ' . explode(' ', trim($item['profissional']))[0]
                . '. ' . ($link !== ''
                    ? 'Confirme sua presença ou avise se não puder ir: ' . $link
                    : 'Se precisar remarcar, avise com antecedência.');
            $inserir->execute([
                Contexto::id(), $item['id_usuario'], $item['id_agendamento'], 'whatsapp',
                'lembrete_' . $horas . 'h', $item['telefone'], $mensagem,
            ]);
            $total += $inserir->rowCount();
        }
        return $total;
    }

    public static function notificacoesPendentes(): array
    {
        return bd()->query(
            'SELECT * FROM notificacoes WHERE id_estabelecimento=' . Contexto::id() . '
             AND status=\'pendente\' ORDER BY data_programada,data_criacao'
        )->fetchAll();
    }

    /** Ultimas mensagens que sairam da fila (enviadas ou canceladas), para o painel. */
    public static function notificacoesRecentes(int $limite = 15): array
    {
        $q = bd()->prepare(
            'SELECT * FROM notificacoes WHERE id_estabelecimento = ? AND status <> \'pendente\'
             ORDER BY COALESCE(data_envio, data_criacao) DESC, id_notificacao DESC LIMIT ' . max(1, $limite)
        );
        $q->execute([Contexto::id()]);
        return $q->fetchAll();
    }

    public static function marcarNotificacao(int $id): void
    {
        $q = bd()->prepare('UPDATE notificacoes SET status=\'enviada\',data_envio=NOW() WHERE id_estabelecimento=? AND id_notificacao=?');
        $q->execute([Contexto::id(), $id]);
    }

    public static function linkWhatsapp(array $notificacao): string
    {
        $numero = apenasNumeros($notificacao['destinatario'] ?? '');
        if (strlen($numero) <= 11) {
            $numero = '55' . $numero;
        }
        return 'https://wa.me/' . rawurlencode($numero) . '?text=' . rawurlencode($notificacao['mensagem']);
    }

    /** Consome um crédito pago e válido do serviço antes de gerar cobrança de sinal. */
    public static function usarCreditoPacote(int $idAgendamento): bool
    {
        $agendamento = Agendamento::porId($idAgendamento);
        if (!$agendamento) {
            return false;
        }
        $db = bd();
        $db->beginTransaction();
        try {
            $q = $db->prepare(
                'SELECT cp.id_cliente_pacote FROM cliente_pacotes cp
                 JOIN pacotes p ON p.id_pacote=cp.id_pacote
                 WHERE cp.id_estabelecimento=? AND cp.id_cliente=? AND p.id_servico=?
                   AND cp.status_pagamento=\'pago\' AND cp.creditos_restantes>0
                   AND cp.data_expiracao>=CURRENT_DATE
                 ORDER BY cp.data_expiracao,cp.id_cliente_pacote LIMIT 1 FOR UPDATE'
            );
            $q->execute([Contexto::id(), $agendamento['id_cliente'], $agendamento['id_servico']]);
            $idPacote = (int) $q->fetchColumn();
            if ($idPacote < 1) {
                $db->rollBack();
                return false;
            }
            $q = $db->prepare('UPDATE cliente_pacotes SET creditos_restantes=creditos_restantes-1
                               WHERE id_estabelecimento=? AND id_cliente_pacote=? AND creditos_restantes>0');
            $q->execute([Contexto::id(), $idPacote]);
            $q = $db->prepare('UPDATE agendamentos SET id_cliente_pacote=? WHERE id_estabelecimento=? AND id_agendamento=?');
            $q->execute([$idPacote, Contexto::id(), $idAgendamento]);
            $db->commit();
            return true;
        } catch (Throwable $erro) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $erro;
        }
    }
    /** Cancela o sinal pendente e devolve o crédito de pacote de uma reserva cancelada. */
    public static function cancelarFinanceiro(array $agendamento): void
    {
        $q = bd()->prepare('UPDATE pagamentos SET status=\'cancelado\' WHERE id_estabelecimento=? AND id_agendamento=? AND status=\'pendente\'');
        $q->execute([Contexto::id(), $agendamento['id_agendamento']]);
        $idPacote = (int) ($agendamento['id_cliente_pacote'] ?? 0);
        if ($idPacote > 0) {
            $q = bd()->prepare('UPDATE cliente_pacotes SET creditos_restantes=LEAST(creditos_total,creditos_restantes+1)
                                WHERE id_estabelecimento=? AND id_cliente_pacote=?');
            $q->execute([Contexto::id(), $idPacote]);
            $q = bd()->prepare('UPDATE agendamentos SET id_cliente_pacote=NULL WHERE id_estabelecimento=? AND id_agendamento=?');
            $q->execute([Contexto::id(), $agendamento['id_agendamento']]);
        }
    }
    public static function criarPagamentoSinal(int $idAgendamento): void
    {
        $percentual = max(0, min(100, Configuracao::obterInteiro('sinal_percentual', 0)));
        $chave = Configuracao::obter('pix_chave');
        if ($percentual < 1 || $chave === '') {
            return;
        }
        $agendamento = Agendamento::porId($idAgendamento);
        if (!$agendamento) {
            return;
        }
        $valor = round((float) $agendamento['valor'] * $percentual / 100, 2);
        if ($valor <= 0) {
            return;
        }
        $q = bd()->prepare(
            Sql::inserirIgnorando() . ' pagamentos
             (id_estabelecimento,id_agendamento,tipo,valor,metodo,status,referencia)
             VALUES (?,?,\'sinal\',?,\'pix\',\'pendente\',?)' . Sql::ignorarConflito()
        );
        $q->execute([Contexto::id(), $idAgendamento, $valor, 'AG-' . $idAgendamento]);
    }

    public static function pagamentos(?int $idCliente = null): array
    {
        $sql = 'SELECT pg.*,a.data_agendamento,a.hora_inicio,s.nome nome_servico,u.nome nome_cliente
                FROM pagamentos pg
                JOIN agendamentos a ON a.id_agendamento=pg.id_agendamento
                JOIN servicos s ON s.id_servico=a.id_servico
                JOIN clientes c ON c.id_cliente=a.id_cliente
                JOIN usuarios u ON u.id_usuario=c.id_usuario
                WHERE pg.id_estabelecimento=:empresa';
        $params = [':empresa' => Contexto::id()];
        if ($idCliente) {
            $sql .= ' AND a.id_cliente=:cliente';
            $params[':cliente'] = $idCliente;
        }
        $sql .= ' ORDER BY pg.data_criacao DESC';
        $q = bd()->prepare($sql);
        $q->execute($params);
        return $q->fetchAll();
    }

    public static function atualizarPagamento(int $id, string $status): void
    {
        if (!in_array($status, ['pendente', 'pago', 'cancelado', 'estornado'], true)) {
            return;
        }
        $q = bd()->prepare(
            'UPDATE pagamentos SET status=?,data_pagamento=CASE WHEN ?=\'pago\' THEN NOW() ELSE data_pagamento END
             WHERE id_estabelecimento=? AND id_pagamento=?'
        );
        $q->execute([$status, $status, Contexto::id(), $id]);
    }

    /** Credita uma única vez os pontos do atendimento concluído. */
    public static function pontuar(int $idAgendamento): void
    {
        $agendamento = Agendamento::porId($idAgendamento);
        if (!$agendamento || $agendamento['status'] !== 'concluido') {
            return;
        }
        $porReal = max(0, Configuracao::obterInteiro('pontos_por_real', 1));
        $pontos = (int) floor((float) $agendamento['valor'] * $porReal);
        if ($pontos < 1) {
            return;
        }
        $db = bd();
        $q = $db->prepare(
            Sql::inserirIgnorando() . ' fidelidade_movimentos
             (id_estabelecimento,id_cliente,id_agendamento,pontos,descricao)
             VALUES (?,?,?,?,?)' . Sql::ignorarConflito()
        );
        $q->execute([Contexto::id(), $agendamento['id_cliente'], $idAgendamento, $pontos, 'Atendimento concluído']);
        if ($q->rowCount()) {
            $u = $db->prepare('UPDATE clientes SET pontos_fidelidade=pontos_fidelidade+? WHERE id_estabelecimento=? AND id_cliente=?');
            $u->execute([$pontos, Contexto::id(), $agendamento['id_cliente']]);
        }
    }

    public static function movimentos(int $idCliente): array
    {
        $q = bd()->prepare('SELECT * FROM fidelidade_movimentos WHERE id_estabelecimento=? AND id_cliente=? ORDER BY data_criacao DESC');
        $q->execute([Contexto::id(), $idCliente]);
        return $q->fetchAll();
    }

    public static function criarPacote(array $dados): void
    {
        if (!Servico::porId((int) $dados['id_servico'])) {
            throw new InvalidArgumentException('Serviço inválido.');
        }
        $q = bd()->prepare(
            'INSERT INTO pacotes (id_estabelecimento,id_servico,nome,quantidade,validade_dias,preco)
             VALUES (?,?,?,?,?,?)'
        );
        $q->execute([
            Contexto::id(), (int) $dados['id_servico'], $dados['nome'],
            (int) $dados['quantidade'], (int) $dados['validade_dias'], (float) $dados['preco'],
        ]);
    }

    public static function pacotes(bool $somenteAtivos = false): array
    {
        $sql = 'SELECT p.*,s.nome nome_servico FROM pacotes p JOIN servicos s ON s.id_servico=p.id_servico
                WHERE p.id_estabelecimento=' . Contexto::id();
        if ($somenteAtivos) {
            $sql .= ' AND p.status=\'ativo\'';
        }
        return bd()->query($sql . ' ORDER BY p.nome')->fetchAll();
    }

    public static function adquirirPacote(int $idCliente, int $idPacote): void
    {
        $q = bd()->prepare('SELECT * FROM pacotes WHERE id_estabelecimento=? AND id_pacote=? AND status=\'ativo\'');
        $q->execute([Contexto::id(), $idPacote]);
        $pacote = $q->fetch();
        if (!$pacote || !Cliente::porId($idCliente)) {
            throw new InvalidArgumentException('Pacote indisponível.');
        }
        $q = bd()->prepare(
            'INSERT INTO cliente_pacotes
             (id_estabelecimento,id_cliente,id_pacote,creditos_total,creditos_restantes,data_expiracao)
             VALUES (?,?,?,?,?,' . Sql::somarDias('CURRENT_DATE', '?') . ')'
        );
        $q->execute([
            Contexto::id(), $idCliente, $idPacote, $pacote['quantidade'],
            $pacote['quantidade'], $pacote['validade_dias'],
        ]);
    }

    public static function pacotesDoCliente(int $idCliente): array
    {
        $q = bd()->prepare(
            'SELECT cp.*,p.nome,p.preco,s.nome nome_servico
             FROM cliente_pacotes cp JOIN pacotes p ON p.id_pacote=cp.id_pacote
             JOIN servicos s ON s.id_servico=p.id_servico
             WHERE cp.id_estabelecimento=? AND cp.id_cliente=? ORDER BY cp.data_criacao DESC'
        );
        $q->execute([Contexto::id(), $idCliente]);
        return $q->fetchAll();
    }

    public static function comprasPacotes(): array
    {
        return bd()->query(
            'SELECT cp.*,p.nome pacote,u.nome nome_cliente
             FROM cliente_pacotes cp JOIN pacotes p ON p.id_pacote=cp.id_pacote
             JOIN clientes c ON c.id_cliente=cp.id_cliente JOIN usuarios u ON u.id_usuario=c.id_usuario
             WHERE cp.id_estabelecimento=' . Contexto::id() . ' ORDER BY cp.data_criacao DESC'
        )->fetchAll();
    }

    public static function atualizarCompraPacote(int $id, string $status): void
    {
        if (!in_array($status, ['pendente', 'pago', 'cancelado'], true)) {
            return;
        }
        $q = bd()->prepare('UPDATE cliente_pacotes SET status_pagamento=? WHERE id_estabelecimento=? AND id_cliente_pacote=?');
        $q->execute([$status, Contexto::id(), $id]);
    }

    public static function avaliar(int $idCliente, int $idAgendamento, int $nota, string $comentario): void
    {
        $agendamento = Agendamento::porId($idAgendamento);
        if (!$agendamento || (int) $agendamento['id_cliente'] !== $idCliente || $agendamento['status'] !== 'concluido') {
            throw new InvalidArgumentException('Atendimento não disponível para avaliação.');
        }
        $nota = max(1, min(5, $nota));
        $q = bd()->prepare(
            'INSERT INTO avaliacoes (id_estabelecimento,id_agendamento,id_cliente,id_profissional,nota,comentario)
             VALUES (?,?,?,?,?,?)' . Sql::aoDuplicar(['id_estabelecimento', 'id_agendamento'], ['nota', 'comentario'])
        );
        $q->execute([
            Contexto::id(), $idAgendamento, $idCliente, $agendamento['id_profissional'],
            $nota, $comentario !== '' ? $comentario : null,
        ]);
    }

    public static function avaliacoes(?int $idCliente = null): array
    {
        $sql = 'SELECT av.*,uc.nome nome_cliente,up.nome nome_profissional,s.nome nome_servico,a.data_agendamento
                FROM avaliacoes av JOIN clientes c ON c.id_cliente=av.id_cliente
                JOIN usuarios uc ON uc.id_usuario=c.id_usuario
                JOIN profissionais p ON p.id_profissional=av.id_profissional
                JOIN usuarios up ON up.id_usuario=p.id_usuario
                JOIN agendamentos a ON a.id_agendamento=av.id_agendamento
                JOIN servicos s ON s.id_servico=a.id_servico
                WHERE av.id_estabelecimento=:empresa';
        $params = [':empresa' => Contexto::id()];
        if ($idCliente) {
            $sql .= ' AND av.id_cliente=:cliente';
            $params[':cliente'] = $idCliente;
        }
        $q = bd()->prepare($sql . ' ORDER BY av.data_criacao DESC');
        $q->execute($params);
        return $q->fetchAll();
    }

    public static function atendimentosParaAvaliar(int $idCliente): array
    {
        $q = bd()->prepare(
            'SELECT ' . AgendamentoSelecao::campos() . AgendamentoSelecao::juncoes() . '
             LEFT JOIN avaliacoes av ON av.id_agendamento=a.id_agendamento
             WHERE a.id_estabelecimento=:empresa AND a.id_cliente=:cliente
             AND a.status=\'concluido\' AND av.id_avaliacao IS NULL ORDER BY a.data_agendamento DESC'
        );
        $q->execute([':empresa' => Contexto::id(), ':cliente' => $idCliente]);
        return $q->fetchAll();
    }

    public static function atualizarComissao(int $idProfissional, float $percentual): void
    {
        if (!Profissional::porId($idProfissional)) {
            return;
        }
        $q = bd()->prepare('UPDATE profissionais SET comissao_percentual=? WHERE id_estabelecimento=? AND id_profissional=?');
        $q->execute([max(0, min(100, $percentual)), Contexto::id(), $idProfissional]);
    }

    public static function comissoes(string $inicio, string $fim): array
    {
        $q = bd()->prepare(
            'SELECT p.id_profissional,u.nome,p.comissao_percentual,
                    COUNT(a.id_agendamento) atendimentos,COALESCE(SUM(a.valor),0) faturamento,
                    COALESCE(SUM(a.valor*p.comissao_percentual/100),0) comissao
             FROM profissionais p JOIN usuarios u ON u.id_usuario=p.id_usuario
             LEFT JOIN agendamentos a ON a.id_profissional=p.id_profissional AND a.status=\'concluido\'
               AND a.data_agendamento BETWEEN :inicio AND :fim
             WHERE p.id_estabelecimento=:empresa GROUP BY p.id_profissional,u.nome,p.comissao_percentual ORDER BY u.nome'
        );
        $q->execute([':inicio' => $inicio, ':fim' => $fim, ':empresa' => Contexto::id()]);
        return $q->fetchAll();
    }

    public static function tokenCalendario(int $idProfissional): string
    {
        $profissional = Profissional::porId($idProfissional);
        if (!$profissional) {
            return '';
        }
        if (!empty($profissional['token_calendario'])) {
            return $profissional['token_calendario'];
        }
        $token = bin2hex(random_bytes(32));
        $q = bd()->prepare('UPDATE profissionais SET token_calendario=? WHERE id_estabelecimento=? AND id_profissional=?');
        $q->execute([$token, Contexto::id(), $idProfissional]);
        return $token;
    }

    /** Cria repetições semanais e informa quais datas não puderam ser reservadas. */
    public static function criarRecorrencias(int $idPrimeiro, int $repeticoes): array
    {
        $repeticoes = max(1, min(12, $repeticoes));
        if ($repeticoes === 1) {
            return ['criadas' => 1, 'falhas' => []];
        }
        $base = Agendamento::porId($idPrimeiro);
        if (!$base) {
            return ['criadas' => 0, 'falhas' => ['Agendamento inicial não encontrado.']];
        }
        $grupo = bin2hex(random_bytes(16));
        $q = bd()->prepare('UPDATE agendamentos SET grupo_recorrencia=? WHERE id_estabelecimento=? AND id_agendamento=?');
        $q->execute([$grupo, Contexto::id(), $idPrimeiro]);
        $criadas = 1;
        $falhas = [];
        for ($i = 1; $i < $repeticoes; $i++) {
            $data = date('Y-m-d', strtotime($base['data_agendamento'] . ' +' . $i . ' week'));
            $resultado = Agendamento::criar([
                'id_cliente' => $base['id_cliente'], 'id_profissional' => $base['id_profissional'],
                'id_servico' => $base['id_servico'], 'data' => $data, 'hora_inicio' => $base['hora_inicio'],
                'observacao' => $base['observacao'], 'origem' => $base['origem'], 'grupo_recorrencia' => $grupo,
            ]);
            if ($resultado['sucesso']) {
                $criadas++;
            } else {
                $falhas[] = formatarData($data) . ': ' . implode(' ', $resultado['erros']);
            }
        }
        return ['criadas' => $criadas, 'falhas' => $falhas];
    }

    public static function exportarCliente(int $idCliente): array
    {
        return [
            'cliente' => Cliente::porId($idCliente),
            'agendamentos' => Agendamento::listar(['id_cliente' => $idCliente]),
            'lista_espera' => self::listaDoCliente($idCliente),
            'pontos' => self::movimentos($idCliente),
            'pacotes' => self::pacotesDoCliente($idCliente),
            'avaliacoes' => self::avaliacoes($idCliente),
        ];
    }

    public static function anonimizarCliente(int $idCliente, int $idUsuario): void
    {
        $cliente = Cliente::porId($idCliente);
        if (!$cliente || (int) $cliente['id_usuario'] !== $idUsuario) {
            throw new InvalidArgumentException('Conta inválida.');
        }
        $db = bd();
        $db->beginTransaction();
        try {
            // Remove cobranças ainda abertas antes de preservar o histórico anonimizado.
            $q = $db->prepare(
                'UPDATE pagamentos SET status=\'cancelado\'
                 WHERE id_estabelecimento=? AND status=\'pendente\'
                   AND EXISTS (SELECT 1 FROM agendamentos a
                               WHERE a.id_agendamento=pagamentos.id_agendamento
                                 AND a.id_estabelecimento=pagamentos.id_estabelecimento
                                 AND a.id_cliente=?)'
            );
            $q->execute([Contexto::id(), $idCliente]);
            $q = $db->prepare('UPDATE agendamentos SET status=\'cancelado\',motivo_cancelamento=\'Conta encerrada\'
                               WHERE id_estabelecimento=? AND id_cliente=? AND status IN (\'agendado\',\'confirmado\')');
            $q->execute([Contexto::id(), $idCliente]);
            $q = $db->prepare('UPDATE clientes SET cpf=NULL,data_nascimento=NULL,observacoes=NULL WHERE id_estabelecimento=? AND id_cliente=?');
            $q->execute([Contexto::id(), $idCliente]);
            $q = $db->prepare('DELETE FROM lista_espera WHERE id_estabelecimento=? AND id_cliente=?');
            $q->execute([Contexto::id(), $idCliente]);
            $q = $db->prepare('DELETE FROM notificacoes WHERE id_estabelecimento=? AND id_usuario=?');
            $q->execute([Contexto::id(), $idUsuario]);
            $q = $db->prepare('UPDATE avaliacoes SET comentario=NULL,status=\'oculta\' WHERE id_estabelecimento=? AND id_cliente=?');
            $q->execute([Contexto::id(), $idCliente]);
            // Encerra o vinculo com esta empresa: sem login e desligado.
            $q = $db->prepare('UPDATE vinculos SET status=\'inativo\',login=NULL WHERE id_estabelecimento=? AND id_vinculo=?');
            $q->execute([Contexto::id(), (int) $cliente['id_vinculo']]);
            // A pessoa so e anonimizada quando este era o unico vinculo dela: se
            // ela tambem e cliente ou profissional em outra empresa, a identidade
            // continua valendo la, e o pedido diz respeito so a esta.
            $q = $db->prepare('SELECT COUNT(*) FROM vinculos WHERE id_usuario=? AND id_vinculo<>?');
            $q->execute([$idUsuario, (int) $cliente['id_vinculo']]);
            if ((int) $q->fetchColumn() === 0) {
                $q = $db->prepare('UPDATE usuarios SET nome=\'Cliente removido\',email=?,telefone=NULL,senha_hash=?,status=\'inativo\',
                                   token_recuperacao=NULL,token_expiracao=NULL WHERE id_usuario=?');
                $q->execute([
                    'removido-' . $idUsuario . '-' . bin2hex(random_bytes(4)) . '@anonimo.invalid',
                    password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $idUsuario,
                ]);
            }
            $db->commit();
        } catch (Throwable $erro) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $erro;
        }
    }
}

/** Expõe a seleção compartilhada sem tornar públicas as constantes internas do model principal. */
class AgendamentoSelecao
{
    public static function campos(): string
    {
        return 'a.*,uc.nome nome_cliente,uc.telefone telefone_cliente,uc.email email_cliente,
                c.cpf cpf_cliente,up.nome nome_profissional,s.nome nome_servico,s.duracao_minutos';
    }

    public static function juncoes(): string
    {
        return ' FROM agendamentos a JOIN clientes c ON c.id_cliente=a.id_cliente
                 JOIN usuarios uc ON uc.id_usuario=c.id_usuario
                 JOIN profissionais p ON p.id_profissional=a.id_profissional
                 JOIN usuarios up ON up.id_usuario=p.id_usuario JOIN servicos s ON s.id_servico=a.id_servico';
    }
}
