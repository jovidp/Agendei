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

        // Combina o expediente do dia com os agendamentos ocupados e os bloqueios existentes.
        $faixas = Horario::faixasAtivas($idProfissional, (int) date('w', strtotime($data)));

        if ($faixas === []) {
            return [];
        }

        return self::calcular(
            $data,
            (int) $servico['duracao_minutos'],
            $faixas,
            Agendamento::ocupacoesDoDia($idProfissional, $data, $opcoes['ignorar_agendamento'] ?? null),
            Bloqueio::porData($idProfissional, $data),
            self::limiteMinimo($opcoes)
        );
    }

    /**
     * Dias de um intervalo com pelo menos um horario livre. Faz as mesmas contas
     * de slots(), mas com poucas consultas para o periodo inteiro: chamar slots()
     * dia a dia custava umas 7 idas ao banco por dia e deixava o calendario lento.
     * @return string[] datas no formato Y-m-d
     */
    public static function diasComVagas(int $idProfissional, int $idServico, string $inicio, string $fim, array $opcoes = []): array
    {
        $servico = Servico::porId($idServico);

        if (!$servico || $servico['status'] !== 'ativo' || !validarData($inicio) || !validarData($fim)) {
            return [];
        }
        if (!Profissional::estaAtivo($idProfissional) || !Profissional::executaServico($idProfissional, $idServico)) {
            return [];
        }

        $faixasPorDia = array_fill(0, 7, []);
        foreach (Horario::porProfissional($idProfissional) as $faixa) {
            if ($faixa['status'] === 'ativo') {
                $faixasPorDia[(int) $faixa['dia_semana']][] = $faixa;
            }
        }

        $ocupados  = Agendamento::ocupacoesDoPeriodo($idProfissional, $inicio, $fim);
        $bloqueios = Bloqueio::porPeriodo($idProfissional, $inicio, $fim);
        $limite    = self::limiteMinimo($opcoes);
        $duracao   = (int) $servico['duracao_minutos'];
        $dias      = [];

        for ($dia = $inicio; $dia <= $fim; $dia = date('Y-m-d', strtotime($dia . ' +1 day'))) {
            $faixas = $faixasPorDia[(int) date('w', strtotime($dia))];
            if ($faixas !== [] && self::calcular($dia, $duracao, $faixas, $ocupados[$dia] ?? [], $bloqueios[$dia] ?? [], $limite) !== []) {
                $dias[] = $dia;
            }
        }

        return $dias;
    }

    /** Momento antes do qual nenhum horario e oferecido (0 = sem limite). */
    private static function limiteMinimo(array $opcoes): int
    {
        return empty($opcoes['ignorar_antecedencia'])
            ? time() + (Configuracao::obterInteiro('antecedencia_minima_horas', 2) * 3600)
            : 0;
    }

    /** Horarios livres de uma data a partir do expediente, das ocupacoes e dos bloqueios ja carregados. */
    private static function calcular(string $data, int $duracao, array $faixas, array $ocupados, array $bloqueios, int $limiteMinimo): array
    {
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
            return ['Data inválida.'];
        }
        if (!validarHora($horaInicio)) {
            return ['Horário inválido.'];
        }

        $servico = Servico::porId($idServico);
        if (!$servico) {
            return ['Serviço não encontrado.'];
        }
        if ($servico['status'] !== 'ativo') {
            $erros[] = 'Este serviço não está disponível para agendamento.';
        }

        $profissional = Profissional::porId($idProfissional);
        if (!$profissional) {
            return ['Profissional não encontrado.'];
        }
        if ($profissional['status'] !== 'ativo') {
            $erros[] = 'Este profissional não está disponível para agendamento.';
        }
        if (!Profissional::executaServico($idProfissional, $idServico)) {
            $erros[] = 'Este profissional não executa o serviço selecionado.';
        }

        if ($erros !== []) {
            return $erros;
        }

        $duracao = (int) $servico['duracao_minutos'];
        $horaFim = somarMinutos($horaInicio, $duracao);

        // O atendimento nao pode atravessar a virada do dia.
        if (horaParaMinutos($horaFim) <= horaParaMinutos($horaInicio)) {
            return ['O horário selecionado ultrapassa o fim do dia.'];
        }

        // Antecedencia minima e maxima.
        $inicioTimestamp = strtotime($data . ' ' . $horaInicio);

        if (empty($opcoes['ignorar_antecedencia'])) {
            $antecedenciaMinima = Configuracao::obterInteiro('antecedencia_minima_horas', 2);
            if ($inicioTimestamp < time() + ($antecedenciaMinima * 3600)) {
                $erros[] = $antecedenciaMinima > 0
                    ? "Agendamentos precisam de no mínimo {$antecedenciaMinima}h de antecedência."
                    : 'Não é possível agendar em um horário que já passou.';
            }

            $antecedenciaMaxima = Configuracao::obterInteiro('antecedencia_maxima_dias', 60);
            if ($antecedenciaMaxima > 0 && $inicioTimestamp > strtotime("+{$antecedenciaMaxima} days")) {
                $erros[] = "Só é possível agendar com até {$antecedenciaMaxima} dias de antecedência.";
            }
        } elseif ($inicioTimestamp < strtotime('today')) {
            $erros[] = 'Não é possível agendar em datas passadas.';
        }

        // Precisa caber inteiro dentro de uma faixa de expediente.
        if (!self::dentroDoExpediente($idProfissional, $data, $horaInicio, $horaFim)) {
            $erros[] = 'O horário selecionado está fora do expediente do profissional.';
        }

        if (Bloqueio::conflita($idProfissional, $data, $horaInicio, $horaFim)) {
            $erros[] = 'O profissional possui um bloqueio de agenda neste horário.';
        }

        if (Agendamento::existeConflito(
            $idProfissional,
            $data,
            $horaInicio,
            $horaFim,
            $opcoes['ignorar_agendamento'] ?? null,
            !empty($opcoes['bloquear_linhas'])
        )) {
            $erros[] = 'Este horário não está mais disponível. Escolha outro horário.';
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
