<?php
/**
 * Motor de disponibilidade.
 *
 * Responsavel por gerar os horarios livres de um profissional e por validar,
 * no servidor, se um horario solicitado realmente pode ser agendado.
 * O JavaScript apenas consome estes dados: toda decisao acontece aqui.
 */
class Disponibilidade
{
    /** Status que ocupam a agenda do profissional. */
    public const STATUS_OCUPAM = ['agendado', 'confirmado', 'concluido'];

    /**
     * Horarios livres para um profissional executar um servico em uma data.
     * @return array<int, array{inicio: string, fim: string}>
     */
    public static function slots(int $idProfissional, int $idServico, string $data, array $opcoes = []): array
    {
        $servico = Servico::porId($idServico);

        if (!$servico || $servico['status'] !== 'ativo') {
            return [];
        }
        if (!validarData($data)) {
            return [];
        }
        if (!Profissional::estaAtivo($idProfissional) || !Profissional::executaServico($idProfissional, $idServico)) {
            return [];
        }

        $duracao   = (int) $servico['duracao_minutos'];
        $diaSemana = (int) date('w', strtotime($data));
        // Combina o expediente do dia com os agendamentos ocupados e os bloqueios existentes.
        $faixas    = Horario::faixasAtivas($idProfissional, $diaSemana);

        if ($faixas === []) {
            return [];
        }

        $ocupados  = Agendamento::ocupacoesDoDia($idProfissional, $data, $opcoes['ignorar_agendamento'] ?? null);
        $bloqueios = Bloqueio::porData($idProfissional, $data);

        $limiteMinimo = empty($opcoes['ignorar_antecedencia'])
            ? time() + (Configuracao::obterInteiro('antecedencia_minima_horas', 2) * 3600)
            : 0;

        $slots = [];

        foreach ($faixas as $faixa) {
            $intervalo   = max(5, (int) $faixa['intervalo_minutos']);
            $inicioFaixa = horaParaMinutos($faixa['hora_inicio']);
            $fimFaixa    = horaParaMinutos($faixa['hora_fim']);

            // Avança pelo intervalo da faixa e só oferece inícios cuja duração inteira cabe no expediente.
            for ($minuto = $inicioFaixa; $minuto + $duracao <= $fimFaixa; $minuto += $intervalo) {
                $inicio = minutosParaHora($minuto);
                $fim    = minutosParaHora($minuto + $duracao);

                if ($limiteMinimo > 0 && strtotime($data . ' ' . $inicio) < $limiteMinimo) {
                    continue;
                }
                if (self::conflitaComLista($inicio, $fim, $ocupados)) {
                    continue;
                }
                if (self::conflitaComLista($inicio, $fim, $bloqueios)) {
                    continue;
                }

                // Usa o horário inicial como chave para não repetir opções vindas de faixas sobrepostas.
                $slots[substr($inicio, 0, 5)] = [
                    'inicio' => substr($inicio, 0, 5),
                    'fim'    => substr($fim, 0, 5),
                ];
            }
        }

        // Ordena os horários antes de retornar uma lista sequencial para a API.
        ksort($slots);
        return array_values($slots);
    }

    /**
     * Valida uma solicitacao de agendamento. Retorna a lista de erros (vazia = liberado).
     *
     * $dados:   id_profissional, id_servico, data, hora_inicio
     * $opcoes:  ignorar_antecedencia, ignorar_agendamento, bloquear_linhas
     */
    public static function validar(array $dados, array $opcoes = []): array
    {
        $erros = [];

        $idProfissional = (int) ($dados['id_profissional'] ?? 0);
        $idServico      = (int) ($dados['id_servico'] ?? 0);
        $data           = (string) ($dados['data'] ?? '');
        $horaInicio     = (string) ($dados['hora_inicio'] ?? '');

        if (strlen($horaInicio) === 5) {
            $horaInicio .= ':00';
        }

        if (!validarData($data)) {
            return ['Data invalida.'];
        }
        if (!validarHora($horaInicio)) {
            return ['Horario invalido.'];
        }

        $servico = Servico::porId($idServico);
        if (!$servico) {
            return ['Servico nao encontrado.'];
        }
        if ($servico['status'] !== 'ativo') {
            $erros[] = 'Este servico nao esta disponivel para agendamento.';
        }

        $profissional = Profissional::porId($idProfissional);
        if (!$profissional) {
            return ['Profissional nao encontrado.'];
        }
        if ($profissional['status'] !== 'ativo') {
            $erros[] = 'Este profissional nao esta disponivel para agendamento.';
        }
        if (!Profissional::executaServico($idProfissional, $idServico)) {
            $erros[] = 'Este profissional nao executa o servico selecionado.';
        }

        if ($erros !== []) {
            return $erros;
        }

        $duracao = (int) $servico['duracao_minutos'];
        $horaFim = somarMinutos($horaInicio, $duracao);

        // O atendimento nao pode atravessar a virada do dia.
        if (horaParaMinutos($horaFim) <= horaParaMinutos($horaInicio)) {
            return ['O horario selecionado ultrapassa o fim do dia.'];
        }

        // Antecedencia minima e maxima.
        $inicioTimestamp = strtotime($data . ' ' . $horaInicio);

        if (empty($opcoes['ignorar_antecedencia'])) {
            $antecedenciaMinima = Configuracao::obterInteiro('antecedencia_minima_horas', 2);
            if ($inicioTimestamp < time() + ($antecedenciaMinima * 3600)) {
                $erros[] = $antecedenciaMinima > 0
                    ? "Agendamentos precisam de no minimo {$antecedenciaMinima}h de antecedencia."
                    : 'Nao e possivel agendar em um horario que ja passou.';
            }

            $antecedenciaMaxima = Configuracao::obterInteiro('antecedencia_maxima_dias', 60);
            if ($antecedenciaMaxima > 0 && $inicioTimestamp > strtotime("+{$antecedenciaMaxima} days")) {
                $erros[] = "So e possivel agendar com ate {$antecedenciaMaxima} dias de antecedencia.";
            }
        } elseif ($inicioTimestamp < strtotime('today')) {
            $erros[] = 'Nao e possivel agendar em datas passadas.';
        }

        // Precisa caber inteiro dentro de uma faixa de expediente.
        if (!self::dentroDoExpediente($idProfissional, $data, $horaInicio, $horaFim)) {
            $erros[] = 'O horario selecionado esta fora do expediente do profissional.';
        }

        if (Bloqueio::conflita($idProfissional, $data, $horaInicio, $horaFim)) {
            $erros[] = 'O profissional possui um bloqueio de agenda neste horario.';
        }

        if (Agendamento::existeConflito(
            $idProfissional,
            $data,
            $horaInicio,
            $horaFim,
            $opcoes['ignorar_agendamento'] ?? null,
            !empty($opcoes['bloquear_linhas'])
        )) {
            $erros[] = 'Este horario nao esta mais disponivel. Escolha outro horario.';
        }

        return $erros;
    }

    /** O intervalo cabe inteiro em alguma faixa ativa de expediente? */
    public static function dentroDoExpediente(int $idProfissional, string $data, string $horaInicio, string $horaFim): bool
    {
        $diaSemana = (int) date('w', strtotime($data));
        $inicio    = horaParaMinutos($horaInicio);
        $fim       = horaParaMinutos($horaFim);

        foreach (Horario::faixasAtivas($idProfissional, $diaSemana) as $faixa) {
            if ($inicio >= horaParaMinutos($faixa['hora_inicio']) && $fim <= horaParaMinutos($faixa['hora_fim'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Datas com pelo menos um horario livre dentro de um intervalo.
     * Usado para destacar os dias disponiveis no calendario.
     * @return string[] datas no formato Y-m-d
     */
    public static function datasComVagas(int $idProfissional, int $idServico, string $dataInicial, int $dias = 30): array
    {
        $disponiveis = [];
        $data = date_create($dataInicial);

        if (!$data) {
            return [];
        }

        for ($contador = 0; $contador < $dias; $contador++) {
            $dia = $data->format('Y-m-d');
            if (self::slots($idProfissional, $idServico, $dia) !== []) {
                $disponiveis[] = $dia;
            }
            $data->modify('+1 day');
        }

        return $disponiveis;
    }

    /** @param array<int, array{hora_inicio: string, hora_fim: string}> $intervalos */
    private static function conflitaComLista(string $inicio, string $fim, array $intervalos): bool
    {
        $inicioMinutos = horaParaMinutos($inicio);
        $fimMinutos    = horaParaMinutos($fim);

        foreach ($intervalos as $intervalo) {
            $outroInicio = horaParaMinutos($intervalo['hora_inicio']);
            $outroFim    = horaParaMinutos($intervalo['hora_fim']);

            // Há conflito somente com sobreposição; terminar exatamente quando outro começa é permitido.
            if ($inicioMinutos < $outroFim && $fimMinutos > $outroInicio) {
                return true;
            }
        }

        return false;
    }
}
