<?php

/**
 * Pagamento avulso online: quem nao tem plano (ou nao tem o servico no plano)
 * paga o atendimento pelo sistema, por Pix, boleto ou cartao. E opcional:
 * pagar no local continua valendo. Fica em pagamentos com tipo 'integral';
 * quem confirma e o admin (Diferencial::atualizarPagamento).
 */
class PagamentoAvulso
{
    /** Valor do atendimento menos o sinal ja pago; nunca negativo. */
    public static function valorAPagar(array $agendamento): float
    {
        $sinal = Diferencial::sinalDoAgendamento((int) $agendamento['id_agendamento']);
        $pago = $sinal !== null && $sinal['status'] === 'pago' ? (float) $sinal['valor'] : 0.0;
        return max(0.0, round((float) $agendamento['valor'] - $pago, 2));
    }

    /** O cliente pode pagar este atendimento online agora? */
    public static function pagavel(array $agendamento, int $idCliente): bool
    {
        return (int) $agendamento['id_cliente'] === $idCliente
            && in_array($agendamento['status'], ['agendado', 'confirmado'], true)
            && self::valorAPagar($agendamento) > 0
            && (self::pendente((int) $agendamento['id_agendamento'])['status'] ?? '') !== 'pago';
    }

    /** O pagamento avulso do atendimento (qualquer status), ou null. */
    public static function pendente(int $idAgendamento): ?array
    {
        $q = bd()->prepare("SELECT * FROM pagamentos WHERE id_estabelecimento = ? AND id_agendamento = ? AND tipo = 'integral'");
        $q->execute([Contexto::id(), $idAgendamento]);
        return $q->fetch() ?: null;
    }

    /**
     * Registra (ou troca) a forma escolhida. Ha um so avulso por atendimento:
     * trocar a forma ou clicar duas vezes atualiza o mesmo registro. Pagar o
     * total dispensa o sinal pendente, que e cancelado.
     */
    public static function solicitar(int $idAgendamento, int $idCliente, string $metodo, ?string $cartaoFinal): array
    {
        $final = Cobranca::validarEscolha($metodo, $cartaoFinal);
        $agendamento = Agendamento::porId($idAgendamento);
        if (!$agendamento || !self::pagavel($agendamento, $idCliente)) {
            throw new DomainException('Este atendimento não tem pagamento online disponível.');
        }
        $valor = self::valorAPagar($agendamento);
        $db = bd();
        $db->beginTransaction();
        try {
            $db->prepare(Sql::inserirIgnorando() . " pagamentos (id_estabelecimento, id_agendamento, tipo, valor, metodo, status, referencia)
                          VALUES (?, ?, 'integral', ?, ?, 'pendente', ?)" . Sql::ignorarConflito())
                ->execute([Contexto::id(), $idAgendamento, $valor, $metodo, 'AV' . $idAgendamento]);
            $db->prepare("UPDATE pagamentos SET valor = ?, metodo = ?, cartao_final = ?, status = 'pendente'
                          WHERE id_estabelecimento = ? AND id_agendamento = ? AND tipo = 'integral' AND status IN ('pendente', 'cancelado')")
                ->execute([$valor, $metodo, $final, Contexto::id(), $idAgendamento]);
            $db->prepare("UPDATE pagamentos SET status = 'cancelado' WHERE id_estabelecimento = ? AND id_agendamento = ? AND tipo = 'sinal' AND status = 'pendente'")
                ->execute([Contexto::id(), $idAgendamento]);
            $db->commit();
        } catch (Throwable $erro) {
            if ($db->inTransaction()) $db->rollBack();
            throw $erro;
        }
        return self::pendente($idAgendamento);
    }
}
