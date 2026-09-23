<?php

/**
 * Lembretes automaticos de agendamento pelo WhatsApp.
 *
 * O fluxo tem duas metades, e esta classe liga as duas:
 *
 *  1. Diferencial::gerarLembretes() poe na tabela notificacoes uma mensagem
 *     para cada agendamento que vai acontecer nas proximas N horas
 *     (configuracao lembrete_horas). Isso ja existia e continua valendo
 *     para a fila manual do painel.
 *
 *  2. enviarPendentes() pega o que esta na fila e manda pelo provedor
 *     configurado em WhatsApp. Sucesso vira status 'enviada'; falha conta
 *     uma tentativa e guarda a razao em 'erro'. Depois de MAX_TENTATIVAS a
 *     mensagem para de ser tentada, mas continua na fila do painel, onde o
 *     administrador ainda pode abrir o WhatsApp pelo link e mandar na mao.
 *
 * processarTodas() percorre as empresas ativas e faz as duas metades para
 * cada uma. E o que a tarefa periodica chama (scripts/enviar_lembretes.php
 * e tarefas.php); processar() faz o mesmo para a empresa do contexto e serve
 * ao botao "Enviar agora" do painel.
 *
 * Nada aqui decide sozinho se a empresa quer envio automatico: sem provedor
 * configurado a fila so e gerada, e o envio fica manual como antes.
 */
class Lembrete
{
    /** Quantas vezes uma mensagem e tentada antes de ficar so na fila manual. */
    public const MAX_TENTATIVAS = 3;

    /** Quantas mensagens saem por execucao e por empresa. */
    private const LOTE = 100;

    // -----------------------------------------------------------------
    // Execucao
    // -----------------------------------------------------------------

    /**
     * Gera e envia os lembretes de todas as empresas ativas.
     *
     * So roda sob TAREFA_AGENDADA: a troca de empresa fora da tarefa e
     * proibida pelo Contexto, e e isso que impede uma tela comum de agir em
     * nome de outra empresa. Devolve um resumo por empresa, pelo id.
     */
    public static function processarTodas(): array
    {
        if (!defined('TAREFA_AGENDADA')) {
            throw new LogicException('Somente a tarefa agendada processa todas as empresas.');
        }

        $empresas = bd()->query(
            'SELECT id_estabelecimento, nome FROM estabelecimento WHERE status = \'ativo\' ORDER BY id_estabelecimento'
        )->fetchAll();

        $resumo = [];
        foreach ($empresas as $empresa) {
            $id = (int) $empresa['id_estabelecimento'];
            try {
                Contexto::assumirTarefa($id);
                $resumo[$id] = ['nome' => $empresa['nome']] + self::processar();
            } catch (Throwable $erro) {
                error_log('Lembretes da empresa ' . $id . ' falharam: ' . $erro->getMessage());
                $resumo[$id] = ['nome' => $empresa['nome'], 'erro' => $erro->getMessage()];
            }
        }

        return $resumo;
    }

    /** Gera a fila e envia o que estiver pronto, para a empresa do contexto. */
    public static function processar(): array
    {
        $resultado = [
            'provedor'   => WhatsApp::provedor(),
            'geradas'    => Diferencial::gerarLembretes(),
            'enviadas'   => 0,
            'falhas'     => 0,
            'canceladas' => 0,
        ];

        if ($resultado['provedor'] === 'manual') {
            return $resultado;
        }

        $resultado = array_replace($resultado, self::enviarPendentes());
        Configuracao::definir('whatsapp_ultima_execucao', date('Y-m-d H:i:s'), 'Ultimo envio automatico de lembretes');

        return $resultado;
    }

    /**
     * Envia as notificacoes pendentes de WhatsApp da empresa do contexto.
     *
     * Antes de cada envio o agendamento e conferido de novo: reserva cancelada
     * ou concluida, ou horario que ja passou, vira notificacao 'cancelada' em
     * vez de mensagem indevida. Telefone que nao forma um numero valido tambem
     * cancela, com a razao guardada para o painel.
     */
    public static function enviarPendentes(): array
    {
        $provedor = WhatsApp::provedor();
        $totais   = ['enviadas' => 0, 'falhas' => 0, 'canceladas' => 0];

        if ($provedor === 'manual') {
            return $totais;
        }

        $q = bd()->prepare(
            'SELECT n.id_notificacao, n.tipo, n.destinatario, n.mensagem, n.tentativas,
                    a.status AS status_agendamento, a.data_agendamento, a.hora_inicio,
                    u.nome AS nome_cliente, s.nome AS nome_servico
             FROM notificacoes n
             LEFT JOIN agendamentos a ON a.id_estabelecimento = n.id_estabelecimento AND a.id_agendamento = n.id_agendamento
             LEFT JOIN clientes c ON c.id_estabelecimento = a.id_estabelecimento AND c.id_cliente = a.id_cliente
             LEFT JOIN usuarios u ON u.id_estabelecimento = c.id_estabelecimento AND u.id_usuario = c.id_usuario
             LEFT JOIN servicos s ON s.id_estabelecimento = a.id_estabelecimento AND s.id_servico = a.id_servico
             WHERE n.id_estabelecimento = :empresa AND n.canal = \'whatsapp\' AND n.status = \'pendente\'
               AND n.tentativas < :maximo
               AND (n.data_programada IS NULL OR n.data_programada <= NOW())
             ORDER BY n.data_programada, n.id_notificacao
             LIMIT ' . self::LOTE
        );
        $q->execute([':empresa' => Contexto::id(), ':maximo' => self::MAX_TENTATIVAS]);

        foreach ($q->fetchAll() as $item) {
            $id = (int) $item['id_notificacao'];

            $motivo = self::motivoParaCancelar($item);
            if ($motivo !== null) {
                self::cancelarNotificacao($id, $motivo);
                $totais['canceladas']++;
                continue;
            }

            // Na API da Meta so o lembrete tem modelo aprovado. Aviso de vaga e
            // outras mensagens ficam na fila manual, sem contar como falha.
            if ($provedor === 'meta' && !str_starts_with((string) $item['tipo'], 'lembrete_')) {
                continue;
            }

            $numero = WhatsApp::normalizarNumero($item['destinatario']);
            if ($numero === null) {
                self::cancelarNotificacao($id, 'Telefone do cliente incompleto ou invalido.');
                $totais['canceladas']++;
                continue;
            }

            try {
                if (str_starts_with((string) $item['tipo'], 'lembrete_')) {
                    WhatsApp::enviarLembrete($numero, (string) $item['mensagem'], self::parametros($item));
                } else {
                    WhatsApp::enviarTexto($numero, (string) $item['mensagem']);
                }
                self::marcarEnviada($id);
                $totais['enviadas']++;
            } catch (Throwable $erro) {
                self::registrarFalha($id, $erro->getMessage());
                $totais['falhas']++;
            }
        }

        return $totais;
    }

    /** Razao para nao enviar, ou null quando a mensagem deve sair. */
    private static function motivoParaCancelar(array $item): ?string
    {
        $status = $item['status_agendamento'] ?? null;

        // Notificacao sem agendamento (LEFT JOIN vazio): o registro foi apagado.
        if ($status === null && $item['data_agendamento'] === null && str_starts_with((string) $item['tipo'], 'lembrete_')) {
            return 'O agendamento nao existe mais.';
        }

        if ($status === 'cancelado') {
            return 'Agendamento cancelado antes do envio.';
        }

        if ($status === 'concluido') {
            return 'Atendimento ja concluido.';
        }

        if (
            str_starts_with((string) $item['tipo'], 'lembrete_')
            && $item['data_agendamento'] !== null
            && strtotime($item['data_agendamento'] . ' ' . $item['hora_inicio']) < time()
        ) {
            return 'O horario ja passou.';
        }

        return null;
    }

    /** Variaveis do modelo, na ordem que a Meta espera: nome, servico, data, hora. */
    private static function parametros(array $item): array
    {
        return [
            explode(' ', trim((string) ($item['nome_cliente'] ?? 'cliente')))[0],
            (string) ($item['nome_servico'] ?? 'atendimento'),
            formatarData($item['data_agendamento']),
            formatarHora($item['hora_inicio']),
        ];
    }

    // -----------------------------------------------------------------
    // Estado da notificacao
    // -----------------------------------------------------------------

    private static function marcarEnviada(int $id): void
    {
        $q = bd()->prepare(
            'UPDATE notificacoes SET status = \'enviada\', data_envio = NOW(), erro = NULL
             WHERE id_estabelecimento = ? AND id_notificacao = ?'
        );
        $q->execute([Contexto::id(), $id]);
    }

    private static function registrarFalha(int $id, string $erro): void
    {
        $q = bd()->prepare(
            'UPDATE notificacoes SET tentativas = tentativas + 1, erro = ?
             WHERE id_estabelecimento = ? AND id_notificacao = ?'
        );
        $q->execute([mb_substr($erro, 0, 255), Contexto::id(), $id]);
    }

    private static function cancelarNotificacao(int $id, string $motivo): void
    {
        $q = bd()->prepare(
            'UPDATE notificacoes SET status = \'cancelada\', erro = ?
             WHERE id_estabelecimento = ? AND id_notificacao = ?'
        );
        $q->execute([mb_substr($motivo, 0, 255), Contexto::id(), $id]);
    }

    /**
     * Cancela as mensagens pendentes de um agendamento.
     * Chamado quando a reserva e cancelada ou concluida, para o lembrete nao
     * sair depois que ele deixou de fazer sentido.
     */
    public static function cancelarDoAgendamento(int $idAgendamento, string $motivo): void
    {
        $q = bd()->prepare(
            'UPDATE notificacoes SET status = \'cancelada\', erro = ?
             WHERE id_estabelecimento = ? AND id_agendamento = ? AND status = \'pendente\''
        );
        $q->execute([mb_substr($motivo, 0, 255), Contexto::id(), $idAgendamento]);
    }

    // -----------------------------------------------------------------
    // Apoio ao painel
    // -----------------------------------------------------------------

    /**
     * Manda uma mensagem de teste para o numero informado, com o provedor da
     * empresa. Na Meta sai o proprio modelo do lembrete, com dados de exemplo,
     * porque texto livre nao e aceito. Lanca RuntimeException se falhar.
     */
    public static function enviarTeste(string $telefone): void
    {
        $numero = WhatsApp::normalizarNumero($telefone);
        if ($numero === null) {
            throw new RuntimeException('Informe um numero com DDD (10 ou 11 digitos).');
        }

        if (!WhatsApp::ativo()) {
            throw new RuntimeException(WhatsApp::pendencia() ?: 'Nenhum provedor de WhatsApp configurado.');
        }

        $empresa = Estabelecimento::campo('nome', NOME_SISTEMA);
        $texto   = 'Ola! Esta e uma mensagem de teste do ' . $empresa
            . '. Se voce a recebeu, os lembretes automaticos estao funcionando.';

        WhatsApp::enviarLembrete($numero, $texto, ['Teste', 'Mensagem de teste', formatarData(date('Y-m-d')), formatarHora(date('H:i:s'))]);
    }

    /** Linha de resumo de uma empresa, para a saida da tarefa. */
    public static function resumoTexto(int $id, array $empresa): string
    {
        $nome = $empresa['nome'] ?? ('empresa ' . $id);

        if (isset($empresa['erro'])) {
            return sprintf('[%d] %s: ERRO %s', $id, $nome, $empresa['erro']);
        }

        if (($empresa['provedor'] ?? 'manual') === 'manual') {
            return sprintf('[%d] %s: %d lembrete(s) na fila manual (sem provedor de envio)', $id, $nome, $empresa['geradas'] ?? 0);
        }

        return sprintf(
            '[%d] %s (%s): %d gerado(s), %d enviado(s), %d falha(s), %d cancelado(s)',
            $id,
            $nome,
            $empresa['provedor'],
            $empresa['geradas'] ?? 0,
            $empresa['enviadas'] ?? 0,
            $empresa['falhas'] ?? 0,
            $empresa['canceladas'] ?? 0
        );
    }
}
