<?php
/**
 * Confirmacao de presenca pelo cliente, sem login, por um link assinado.
 *
 * O lembrete leva um link para confirmar.php. La o cliente confirma que vai
 * ou avisa que nao vai. O link e uma assinatura (HMAC) dos dados do proprio
 * agendamento — empresa, numero, data e hora —, entao nao existe token
 * guardado no banco: remarcar invalida o link antigo, e o segredo fica fora
 * do banco, como o dos codigos de 2FA.
 *
 * liberarSemConfirmacao() e a outra ponta: N horas antes do atendimento
 * (configuracao liberar_sem_confirmacao_horas; 0 desliga), quem recebeu o
 * lembrete e nao respondeu perde o horario, que volta para a agenda e para a
 * lista de espera. Roda na tarefa periodica, junto com os lembretes.
 */
class Confirmacao
{
    /** Tipo da notificacao que avisa o cliente de que o horario foi liberado. */
    public const TIPO_LIBERACAO = 'liberado_sem_confirmacao';

    /** Horas que o cliente tem, no minimo, entre receber o lembrete e perder o horario. */
    public const REACAO_MINIMA_HORAS = 1;

    /** Chave da assinatura: a mesma origem dos segredos de 2FA, fora do banco. */
    private static function chave(): string
    {
        $segredo = (string) (getenv('AGENDEI_CHAVE_TOTP') ?: getenv('AGENDEI_DB_PASS') ?: '');

        return hash('sha256', 'agendei-confirmacao|' . $segredo . '|' . RAIZ, true);
    }

    /** Assinatura do agendamento: muda se a empresa, o numero, a data ou a hora mudarem. */
    public static function token(array $agendamento): string
    {
        $dados = implode('|', [
            (int) ($agendamento['id_estabelecimento'] ?? Contexto::id()),
            (int) $agendamento['id_agendamento'],
            (string) $agendamento['data_agendamento'],
            substr((string) $agendamento['hora_inicio'], 0, 5),
        ]);

        return substr(hash_hmac('sha256', $dados, self::chave()), 0, 32);
    }

    /** Confere a assinatura em tempo constante. */
    public static function tokenValido(array $agendamento, string $token): bool
    {
        return preg_match('/^[a-f0-9]{32}$/D', $token) === 1 && hash_equals(self::token($agendamento), $token);
    }

    /** Endereco completo da pagina de confirmacao, ou '' quando o host nao e conhecido. */
    public static function link(array $agendamento): string
    {
        // url() acrescenta a empresa (estabelecimento=slug) a qualquer pagina.
        $endereco = urlAbsoluta('confirmar.php?a=' . (int) $agendamento['id_agendamento'] . '&t=' . self::token($agendamento));

        return str_starts_with($endereco, 'http') ? $endereco : '';
    }

    /** Agendamento apontado pelo link, ou null quando o link nao confere. */
    public static function porLink(int $idAgendamento, string $token): ?array
    {
        if ($idAgendamento < 1) {
            return null;
        }
        $agendamento = Agendamento::porId($idAgendamento);

        return $agendamento !== null && self::tokenValido($agendamento, $token) ? $agendamento : null;
    }

    /** 'aberto' (pode confirmar ou recusar), 'confirmado', 'cancelado', 'concluido' ou 'passado'. */
    public static function situacao(array $agendamento): string
    {
        if ($agendamento['status'] === 'cancelado' || $agendamento['status'] === 'concluido') {
            return $agendamento['status'];
        }
        if (Agendamento::jaComecou($agendamento)) {
            return 'passado';
        }

        return $agendamento['status'] === 'confirmado' ? 'confirmado' : 'aberto';
    }

    /** O cliente confirma que vai. So muda o que ainda esta 'agendado' e no futuro. */
    public static function confirmar(array $agendamento): bool
    {
        if (self::situacao($agendamento) !== 'aberto') {
            return false;
        }
        $q = bd()->prepare(
            'UPDATE agendamentos SET status = \'confirmado\'
             WHERE id_estabelecimento = ? AND id_agendamento = ? AND status = \'agendado\''
        );
        $q->execute([Contexto::id(), (int) $agendamento['id_agendamento']]);

        return $q->rowCount() > 0;
    }

    /** O cliente avisa que nao vai: a reserva e cancelada e a lista de espera, avisada. */
    public static function recusar(array $agendamento): bool
    {
        if (!in_array(self::situacao($agendamento), ['aberto', 'confirmado'], true)) {
            return false;
        }

        return Agendamento::cancelar(
            (int) $agendamento['id_agendamento'],
            null,
            'O cliente avisou pelo link do lembrete que nao podera comparecer.'
        );
    }

    /**
     * Libera os horarios que nao foram confirmados a tempo, na empresa do contexto.
     *
     * Entram: reservas ainda 'agendado' que comecam em menos de N horas, cujo
     * lembrete foi enviado ha pelo menos REACAO_MINIMA_HORAS (na fila manual,
     * "enviado" e o que o painel marcou). Ficam de fora as que tem sinal pago:
     * quem pagou nao perde o horario por silencio. Cada liberacao cancela a
     * reserva — o que ja avisa a lista de espera — e poe na fila um aviso ao
     * cliente. Devolve quantas foram liberadas.
     */
    public static function liberarSemConfirmacao(): int
    {
        $horas = Configuracao::obterInteiro('liberar_sem_confirmacao_horas', 0);
        if ($horas < 1) {
            return 0;
        }

        $q = bd()->prepare(
            'SELECT a.id_agendamento, a.data_agendamento, a.hora_inicio, c.id_usuario, u.nome, u.telefone, s.nome AS servico
             FROM agendamentos a
             JOIN clientes c ON c.id_cliente = a.id_cliente
             JOIN usuarios u ON u.id_usuario = c.id_usuario
             JOIN servicos s ON s.id_servico = a.id_servico
             WHERE a.id_estabelecimento = ? AND a.status = \'agendado\'
               AND ' . Sql::dataHora('a.data_agendamento', 'a.hora_inicio') . ' BETWEEN NOW() AND ' . Sql::somarHoras('NOW()', '?') . '
               AND EXISTS (SELECT 1 FROM notificacoes n
                           WHERE n.id_estabelecimento = a.id_estabelecimento AND n.id_agendamento = a.id_agendamento
                             AND n.tipo LIKE \'lembrete_%\' AND n.status = \'enviada\'
                             AND n.data_envio <= ' . Sql::somarHoras('NOW()', (string) -self::REACAO_MINIMA_HORAS) . ')
               AND NOT EXISTS (SELECT 1 FROM pagamentos pg
                               WHERE pg.id_estabelecimento = a.id_estabelecimento AND pg.id_agendamento = a.id_agendamento
                                 AND pg.status = \'pago\')
             ORDER BY a.data_agendamento, a.hora_inicio'
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
            $cancelado = Agendamento::cancelar(
                (int) $item['id_agendamento'],
                null,
                'Horario liberado: sem confirmacao ate ' . $horas . ' h antes do atendimento.'
            );
            if (!$cancelado) {
                continue;
            }
            $mensagem = 'Olá, ' . explode(' ', trim((string) $item['nome']))[0] . '! Como não recebemos sua confirmação, o horário de '
                . $item['servico'] . ' em ' . formatarData($item['data_agendamento']) . ' às ' . formatarHora($item['hora_inicio'])
                . ' no ' . $empresa . ' foi liberado. Se ainda quiser ser atendido(a), marque um novo horário pelo Agendei.';
            $inserir->execute([
                Contexto::id(), $item['id_usuario'], $item['id_agendamento'], 'whatsapp',
                self::TIPO_LIBERACAO, $item['telefone'], $mensagem,
            ]);
            $total++;
        }

        return $total;
    }
}
