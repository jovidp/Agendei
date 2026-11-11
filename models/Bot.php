<?php
/**
 * Bot de atendimento no WhatsApp (plano com o beneficio 'bot_whatsapp').
 *
 * Conversa por menu numerado, sem inteligencia artificial: cada mensagem do
 * cliente avanca uma etapa guardada em whatsapp_conversas. Marca, lista e
 * cancela horarios pelas mesmas regras do site (Disponibilidade, Agendamento,
 * sinal e prazo de cancelamento). Quem escreve e reconhecido pelo celular do
 * cadastro; sem cadastro, o bot manda o link para criar a conta.
 *
 * atender() so decide a resposta. Quem recebe o webhook e envia a mensagem e
 * api/whatsapp.php.
 */
class Bot
{
    /** Conversa parada ha mais que isto volta ao menu. */
    private const MINUTOS_CONVERSA = 30;
    /** Depois de pedir atendimento humano, o bot fica em silencio por este tempo. */
    private const HORAS_HUMANO = 2;
    /** Quantos dias e horarios o bot oferece de cada vez. */
    private const MAX_DIAS = 7;
    private const MAX_HORARIOS = 12;
    private const DIAS_BUSCA = 30;

    /** O bot responde nesta empresa? Plano, chave ligada e Evolution configurada. */
    public static function ligado(): bool
    {
        return Plano::temRecurso('bot_whatsapp')
            && Configuracao::ativa('whatsapp_bot_ativo')
            && WhatsApp::provedor() === 'evolution';
    }

    /** Chave do webhook da empresa; gera uma na primeira vez ou quando pedido. */
    public static function chave(bool $nova = false): string
    {
        $chave = Configuracao::obter('whatsapp_bot_chave');
        if ($nova || strlen($chave) < 32) {
            $chave = bin2hex(random_bytes(20));
            Configuracao::definir('whatsapp_bot_chave', $chave, 'Chave do webhook do bot de WhatsApp');
        }
        return $chave;
    }

    /**
     * Resposta para a mensagem $texto vinda de $numero (so digitos, com DDI),
     * ou null quando o bot deve ficar calado (atendimento humano em andamento
     * ou mensagem repetida). $idMensagem evita responder duas vezes a um reenvio.
     */
    public static function atender(string $numero, string $texto, string $idMensagem = ''): ?string
    {
        $conversa = self::conversa($numero);
        if ($idMensagem !== '' && ($conversa['dados']['ultima_mensagem'] ?? '') === $idMensagem) {
            return null;
        }
        $conversa['dados']['ultima_mensagem'] = $idMensagem;

        $entrada = mb_strtolower(trim($texto));
        $cliente = self::clientePorNumero($numero);

        // Atendimento humano: silencio ate o cliente pedir o menu ou o prazo passar.
        if ($conversa['etapa'] === 'humano' && !in_array($entrada, ['menu', 'bot'], true)
            && strtotime($conversa['atualizado_em']) > time() - self::HORAS_HUMANO * 3600) {
            self::salvar($numero, 'humano', $conversa['dados'], false);
            return null;
        }

        $parada = strtotime($conversa['atualizado_em']) < time() - self::MINUTOS_CONVERSA * 60;
        if ($conversa['etapa'] === '' || $parada || in_array($entrada, ['menu', 'oi', 'olá', 'ola', 'inicio', 'início'], true)) {
            return self::menu($numero, $cliente, $conversa['dados']);
        }
        if ($entrada === '0') {
            return self::menu($numero, $cliente, $conversa['dados']);
        }

        $escolha = ctype_digit($entrada) ? (int) $entrada : 0;
        $dados = $conversa['dados'];

        return match ($conversa['etapa']) {
            'menu'         => self::noMenu($numero, $cliente, $escolha, $dados),
            'servico'      => self::escolheuServico($numero, $escolha, $dados),
            'profissional' => self::escolheuProfissional($numero, $escolha, $dados),
            'dia'          => self::escolheuDia($numero, $escolha, $dados),
            'hora'         => self::escolheuHora($numero, $escolha, $dados),
            'confirmar'    => self::confirmou($numero, $cliente, $escolha, $dados),
            'cancelar'     => self::escolheuCancelar($numero, $cliente, $escolha, $dados),
            'confirmar_cancelamento' => self::confirmouCancelamento($numero, $cliente, $escolha, $dados),
            default        => self::menu($numero, $cliente, $dados),
        };
    }

    // -----------------------------------------------------------------
    // Etapas
    // -----------------------------------------------------------------

    private static function menu(string $numero, ?array $cliente, array $dados): string
    {
        self::salvar($numero, 'menu', ['ultima_mensagem' => $dados['ultima_mensagem'] ?? '']);
        return BotMenu::menu($cliente !== null, self::variaveis($cliente));
    }

    /** Variaveis dos textos personalizados ({nome}, {empresa}, {link_cadastro}). */
    private static function variaveis(?array $cliente): array
    {
        return [
            'nome'          => $cliente ? explode(' ', trim((string) $cliente['nome']))[0] : '',
            'empresa'       => Estabelecimento::dados()['nome'],
            'link_cadastro' => urlAbsoluta('cadastro.php'),
        ];
    }

    /** " Mande *N* ..." com o numero atual da opcao, ou nada se o cliente nao a ve. */
    private static function indicar(string $tipo, ?array $cliente, string $frase): string
    {
        $n = BotMenu::numeroDe($tipo, $cliente !== null);
        return $n === null ? '' : ' ' . str_replace('{n}', (string) $n, $frase);
    }

    private static function noMenu(string $numero, ?array $cliente, int $escolha, array $dados): string
    {
        $opcao = BotMenu::escolha($escolha, $cliente !== null);
        if ($opcao === null) {
            // Sem cadastro, escolha invalida so repete o menu, como antes.
            return $cliente === null
                ? self::menu($numero, null, $dados)
                : BotMenu::texto('nao_entendi', self::variaveis($cliente)) . "\n\n" . self::menu($numero, $cliente, $dados);
        }
        switch ($opcao['tipo']) {
            case 'resposta':
                return $opcao['resposta'] . "\n\nMande *menu* para ver as opções.";
            case 'servicos':
                return self::servicosEPrecos() . "\n\nMande *menu* para ver as opções.";
            case 'equipe':
                self::salvar($numero, 'humano', $dados);
                return BotMenu::texto('equipe', self::variaveis($cliente));
            case 'marcar':
                return self::listarServicos($numero, $cliente, $dados);
            case 'meus':
                $proximos = self::proximos($cliente);
                if ($proximos === []) {
                    return 'Você não tem horários marcados.' . self::indicar('marcar', $cliente, 'Responda *{n}* para marcar um.');
                }
                $linhas = array_map(fn($a) => '• ' . self::descreverAgendamento($a), $proximos);
                return "Seus próximos horários:\n\n" . implode("\n", $linhas) . "\n\nMande *menu* para ver as opções.";
            default: // cancelar
                return self::listarParaCancelar($numero, $cliente, $dados);
        }
    }

    private static function listarServicos(string $numero, ?array $cliente, array $dados): string
    {
        $servicos = Servico::ativosComProfissional();
        if ($servicos === []) {
            return 'No momento não há serviços disponíveis para agendamento.' . self::indicar('equipe', $cliente, 'Mande *{n}* para falar com a equipe.');
        }
        $dados['opcoes'] = array_map(fn($s) => (int) $s['id_servico'], $servicos);
        self::salvar($numero, 'servico', $dados);

        $linhas = [];
        foreach ($servicos as $i => $s) {
            $linhas[] = ($i + 1) . '. ' . $s['nome'] . ' — ' . formatarMoeda($s['preco']) . ' (' . duracaoTexto((int) $s['duracao_minutos']) . ')';
        }
        return "Qual serviço?\n\n" . implode("\n", $linhas) . "\n\n0. Voltar ao menu";
    }

    private static function escolheuServico(string $numero, int $escolha, array $dados): string
    {
        $idServico = $dados['opcoes'][$escolha - 1] ?? null;
        if ($idServico === null) {
            return 'Responda com o número de um dos serviços da lista, ou *0* para voltar ao menu.';
        }
        $dados['id_servico'] = $idServico;

        $profissionais = array_values(array_filter(
            Profissional::porServico($idServico),
            fn($p) => Disponibilidade::diasComVagas((int) $p['id_profissional'], $idServico, date('Y-m-d'), date('Y-m-d', strtotime('+' . self::DIAS_BUSCA . ' days'))) !== []
        ));
        if ($profissionais === []) {
            return 'Esse serviço não tem horários livres nos próximos ' . self::DIAS_BUSCA . " dias. Escolha outro número da lista ou *0* para o menu.";
        }
        if (count($profissionais) === 1) {
            $dados['id_profissional'] = (int) $profissionais[0]['id_profissional'];
            $dados['nome_profissional'] = $profissionais[0]['nome'];
            return self::listarDias($numero, $dados);
        }

        $dados['opcoes'] = array_map(fn($p) => [(int) $p['id_profissional'], $p['nome']], $profissionais);
        self::salvar($numero, 'profissional', $dados);
        $linhas = [];
        foreach ($profissionais as $i => $p) {
            $linhas[] = ($i + 1) . '. ' . $p['nome'];
        }
        return "Com quem?\n\n" . implode("\n", $linhas) . "\n\n0. Voltar ao menu";
    }

    private static function escolheuProfissional(string $numero, int $escolha, array $dados): string
    {
        $opcao = $dados['opcoes'][$escolha - 1] ?? null;
        if ($opcao === null) {
            return 'Responda com o número de um dos profissionais da lista, ou *0* para voltar ao menu.';
        }
        [$dados['id_profissional'], $dados['nome_profissional']] = $opcao;
        return self::listarDias($numero, $dados);
    }

    private static function listarDias(string $numero, array $dados): string
    {
        $dias = array_slice(Disponibilidade::diasComVagas(
            (int) $dados['id_profissional'],
            (int) $dados['id_servico'],
            date('Y-m-d'),
            date('Y-m-d', strtotime('+' . self::DIAS_BUSCA . ' days'))
        ), 0, self::MAX_DIAS);

        if ($dias === []) {
            self::salvar($numero, 'menu', $dados);
            return 'Não há horários livres nos próximos ' . self::DIAS_BUSCA . " dias. Mande *menu* para recomeçar.";
        }

        $dados['opcoes'] = $dias;
        self::salvar($numero, 'dia', $dados);
        $linhas = [];
        foreach ($dias as $i => $dia) {
            $linhas[] = ($i + 1) . '. ' . diaSemanaNome((int) date('w', strtotime($dia)), true) . ' ' . formatarData($dia);
        }
        return "Qual dia?\n\n" . implode("\n", $linhas) . "\n\n0. Voltar ao menu";
    }

    private static function escolheuDia(string $numero, int $escolha, array $dados): string
    {
        $dia = $dados['opcoes'][$escolha - 1] ?? null;
        if ($dia === null) {
            return 'Responda com o número de um dos dias da lista, ou *0* para voltar ao menu.';
        }
        $dados['data'] = $dia;
        return self::listarHorarios($numero, $dados);
    }

    private static function listarHorarios(string $numero, array $dados, string $aviso = ''): string
    {
        $horarios = array_slice(
            Disponibilidade::slots((int) $dados['id_profissional'], (int) $dados['id_servico'], $dados['data']),
            0,
            self::MAX_HORARIOS
        );
        if ($horarios === []) {
            return $aviso . self::listarDias($numero, $dados);
        }

        $dados['opcoes'] = array_column($horarios, 'inicio');
        self::salvar($numero, 'hora', $dados);
        $linhas = [];
        foreach ($horarios as $i => $h) {
            $linhas[] = ($i + 1) . '. ' . $h['inicio'];
        }
        return $aviso . 'Horários livres em ' . formatarData($dados['data']) . ":\n\n" . implode("\n", $linhas) . "\n\n0. Voltar ao menu";
    }

    private static function escolheuHora(string $numero, int $escolha, array $dados): string
    {
        $hora = $dados['opcoes'][$escolha - 1] ?? null;
        if ($hora === null) {
            return 'Responda com o número de um dos horários da lista, ou *0* para voltar ao menu.';
        }
        $dados['hora'] = $hora;
        self::salvar($numero, 'confirmar', $dados);

        $servico = Servico::porId((int) $dados['id_servico']);
        $sinal = self::previaSinal((float) $servico['preco']);
        return "Confira:\n\n"
            . '*' . $servico['nome'] . '* com ' . $dados['nome_profissional'] . "\n"
            . formatarData($dados['data']) . ' às ' . $hora . "\n"
            . 'Valor: ' . formatarMoeda($servico['preco']) . $sinal . "\n\n"
            . "1. Confirmar\n2. Escolher outro horário\n0. Voltar ao menu";
    }

    private static function confirmou(string $numero, ?array $cliente, int $escolha, array $dados): string
    {
        if ($escolha === 2) {
            return self::listarHorarios($numero, $dados);
        }
        if ($escolha !== 1 || $cliente === null) {
            return 'Responda *1* para confirmar, *2* para escolher outro horário ou *0* para o menu.';
        }

        $resultado = Agendamento::criar([
            'id_cliente'      => (int) $cliente['id_cliente'],
            'id_profissional' => (int) $dados['id_profissional'],
            'id_servico'      => (int) $dados['id_servico'],
            'data'            => $dados['data'],
            'hora_inicio'     => $dados['hora'],
            'observacao'      => 'Marcado pelo WhatsApp',
            'origem'          => 'cliente',
        ]);

        if (!$resultado['sucesso']) {
            // O horario pode ter sido ocupado enquanto a pessoa decidia: oferece os que sobraram.
            return self::listarHorarios($numero, $dados, 'Não deu para marcar: ' . ($resultado['erros'][0] ?? 'horário indisponível') . "\n\n");
        }

        self::salvar($numero, 'menu', ['ultima_mensagem' => $dados['ultima_mensagem'] ?? '']);
        $agendamento = Agendamento::porId((int) $resultado['id_agendamento']);
        $resposta = "Pronto, horário marcado! ✅\n\n" . self::descreverAgendamento($agendamento);

        $sinal = Diferencial::sinalDoAgendamento((int) $resultado['id_agendamento']);
        if ($sinal !== null && $sinal['status'] === 'pendente') {
            $resposta .= "\n\nSinal de " . formatarMoeda($sinal['valor']) . ' pelo Pix: *' . Configuracao::obter('pix_chave') . '*. Também dá para ver e pagar pelo painel: ' . urlAbsoluta('cliente/agendamentos.php');
        } elseif ($sinal !== null && str_starts_with((string) $sinal['referencia'], Diferencial::PREFIXO_CREDITO)) {
            $resposta .= "\n\nO sinal foi pago com o crédito de um cancelamento anterior.";
        }
        $minutos = Agendamento::minutosSemPerda();
        if ($sinal !== null) {
            $resposta .= "\n\nCancelando até {$minutos} min antes, o sinal pago vira crédito para o próximo horário.";
        }
        return $resposta . "\n\nMande *menu* para ver as opções.";
    }

    private static function listarParaCancelar(string $numero, array $cliente, array $dados): string
    {
        $proximos = array_values(array_filter(self::proximos($cliente), [Agendamento::class, 'clientePodeCancelar']));
        if ($proximos === []) {
            return 'Você não tem horários que possam ser cancelados. Mande *menu* para ver as opções.';
        }
        $dados['opcoes'] = array_map(fn($a) => (int) $a['id_agendamento'], $proximos);
        self::salvar($numero, 'cancelar', $dados);
        $linhas = [];
        foreach ($proximos as $i => $a) {
            $linhas[] = ($i + 1) . '. ' . self::descreverAgendamento($a);
        }
        return "Qual horário você quer cancelar?\n\n" . implode("\n", $linhas) . "\n\n0. Voltar ao menu";
    }

    private static function escolheuCancelar(string $numero, array $cliente, int $escolha, array $dados): string
    {
        $id = $dados['opcoes'][$escolha - 1] ?? null;
        $agendamento = $id ? Agendamento::porId($id) : null;
        if ($agendamento === null || (int) $agendamento['id_cliente'] !== (int) $cliente['id_cliente']) {
            return 'Responda com o número de um dos horários da lista, ou *0* para voltar ao menu.';
        }
        $dados['id_agendamento'] = $id;
        self::salvar($numero, 'confirmar_cancelamento', $dados);

        $aviso = '';
        $sinal = Diferencial::sinalDoAgendamento($id);
        if ($sinal !== null && $sinal['status'] === 'pago') {
            $reaproveitado = str_starts_with((string) $sinal['referencia'], Diferencial::PREFIXO_CREDITO);
            $aviso = "\n\n" . ((!$reaproveitado && Agendamento::prazoSemPerda($agendamento) > time())
                ? 'O sinal de ' . formatarMoeda($sinal['valor']) . ' vira crédito para o seu próximo horário.'
                : 'Atenção: o sinal de ' . formatarMoeda($sinal['valor']) . ' fica com o estabelecimento.');
        }
        return 'Cancelar ' . self::descreverAgendamento($agendamento) . '?' . $aviso . "\n\n1. Sim, cancelar\n0. Não, voltar ao menu";
    }

    private static function confirmouCancelamento(string $numero, array $cliente, int $escolha, array $dados): string
    {
        if ($escolha !== 1) {
            return 'Responda *1* para cancelar ou *0* para voltar ao menu.';
        }
        $id = (int) $dados['id_agendamento'];
        $agendamento = Agendamento::porId($id);
        self::salvar($numero, 'menu', ['ultima_mensagem' => $dados['ultima_mensagem'] ?? '']);

        if (!$agendamento || (int) $agendamento['id_cliente'] !== (int) $cliente['id_cliente'] || !Agendamento::clientePodeCancelar($agendamento)) {
            return 'Esse horário não pode mais ser cancelado por aqui.' . self::indicar('equipe', $cliente, 'Mande *{n}* no menu para falar com a equipe.');
        }

        Agendamento::cancelar($id, (int) $cliente['id_usuario'], 'Cancelado pelo WhatsApp', true);
        $sinal = Diferencial::sinalDoAgendamento($id);
        $destino = match ($sinal['status'] ?? '') {
            'credito' => ' O sinal de ' . formatarMoeda($sinal['valor']) . ' ficou como crédito para o seu próximo horário.',
            'retido'  => ' O sinal de ' . formatarMoeda($sinal['valor']) . ' ficou com o estabelecimento.',
            default   => '',
        };
        return 'Horário cancelado.' . $destino . "\n\nMande *menu* para ver as opções.";
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private static function servicosEPrecos(): string
    {
        $servicos = Servico::ativosComProfissional();
        if ($servicos === []) {
            return 'No momento não há serviços cadastrados.';
        }
        $linhas = array_map(fn($s) => '• ' . $s['nome'] . ' — ' . formatarMoeda($s['preco']) . ' (' . duracaoTexto((int) $s['duracao_minutos']) . ')', $servicos);
        return "Nossos serviços:\n\n" . implode("\n", $linhas);
    }

    /** Linha do sinal na confirmacao, quando o estabelecimento cobra sinal. */
    private static function previaSinal(float $preco): string
    {
        $percentual = Configuracao::obterInteiro('sinal_percentual', 0);
        if (!Plano::temRecurso('sinal_pix') || $percentual < 1 || Configuracao::obter('pix_chave') === '') {
            return '';
        }
        return "\nSinal: " . formatarMoeda(round($preco * $percentual / 100, 2)) . ' (pago pelo Pix depois de confirmar)';
    }

    private static function descreverAgendamento(array $a): string
    {
        return $a['nome_servico'] . ' com ' . $a['nome_profissional'] . ', '
            . diaSemanaNome((int) date('w', strtotime($a['data_agendamento'])), true) . ' ' . formatarData($a['data_agendamento'])
            . ' às ' . formatarHora($a['hora_inicio']);
    }

    private static function proximos(array $cliente): array
    {
        return Agendamento::listar([
            'id_cliente'       => (int) $cliente['id_cliente'],
            'status_em'        => ['agendado', 'confirmado'],
            'a_partir_de_hoje' => true,
            'ordem'            => 'asc',
            'limite'           => 10,
        ]);
    }

    /**
     * Cliente ativo da empresa com este celular. O WhatsApp entrega o numero
     * com DDI e, em alguns celulares antigos, sem o nono digito; por isso a
     * comparacao e pelo DDD e pelos oito ultimos digitos.
     */
    public static function clientePorNumero(string $numero): ?array
    {
        $digitos = preg_replace('/\D/', '', $numero);
        if (str_starts_with($digitos, '55') && strlen($digitos) >= 12) {
            $digitos = substr($digitos, 2);
        }
        if (strlen($digitos) < 10) {
            return null;
        }
        $ddd = substr($digitos, 0, 2);
        $final = substr($digitos, -8);

        $q = bd()->prepare(
            'SELECT c.id_cliente, c.id_usuario, u.nome, u.telefone
             FROM clientes c
             JOIN vinculos v ON v.id_vinculo = c.id_vinculo AND v.id_estabelecimento = ?
             JOIN usuarios u ON u.id_usuario = v.id_usuario
             WHERE c.id_estabelecimento = ? AND v.status = \'ativo\' AND u.telefone LIKE ?'
        );
        $q->execute([Contexto::id(), Contexto::id(), '%' . $final]);
        $achados = [];
        foreach ($q->fetchAll() as $linha) {
            $telefone = preg_replace('/\D/', '', (string) $linha['telefone']);
            if (substr($telefone, 0, 2) === $ddd) {
                $achados[(int) $linha['id_cliente']] = $linha;
            }
        }
        // Telefone dividido por duas contas (familia): nao da para saber quem escreve,
        // e responder pela primeira mostraria e cancelaria os horarios da outra pessoa.
        // shortcut: as duas caem no menu sem cadastro; perguntar o CPF resolveria se isso for comum.
        return count($achados) === 1 ? reset($achados) : null;
    }

    /** Conversa salva (etapa, dados e hora da ultima mensagem) ou uma vazia. */
    private static function conversa(string $numero): array
    {
        $q = bd()->prepare('SELECT etapa, dados, atualizado_em FROM whatsapp_conversas WHERE id_estabelecimento = ? AND numero = ?');
        $q->execute([Contexto::id(), $numero]);
        $linha = $q->fetch();
        if (!$linha) {
            return ['etapa' => '', 'dados' => [], 'atualizado_em' => date('Y-m-d H:i:s')];
        }
        $linha['dados'] = json_decode((string) $linha['dados'], true) ?: [];
        return $linha;
    }

    /** Grava a etapa. $tocar = false mantem a hora (o silencio do humano nao se renova sozinho). */
    private static function salvar(string $numero, string $etapa, array $dados, bool $tocar = true): void
    {
        $json = json_encode($dados, JSON_UNESCAPED_UNICODE);
        $agora = date('Y-m-d H:i:s');
        $q = bd()->prepare(
            'UPDATE whatsapp_conversas SET etapa = ?, dados = ?' . ($tocar ? ', atualizado_em = ?' : '') . ' WHERE id_estabelecimento = ? AND numero = ?'
        );
        $q->execute($tocar ? [$etapa, $json, $agora, Contexto::id(), $numero] : [$etapa, $json, Contexto::id(), $numero]);
        if ($q->rowCount() === 0) {
            $q = bd()->prepare(Sql::inserirIgnorando() . ' whatsapp_conversas (id_estabelecimento, numero, etapa, dados, atualizado_em) VALUES (?, ?, ?, ?, ?)' . Sql::ignorarConflito());
            $q->execute([Contexto::id(), $numero, $etapa, $json, $agora]);
        }
    }

    /** Tabelas das conversas e do menu personalizado. Roda na subida do container e em migrar.php. */
    public static function ddl(): array
    {
        return [...self::ddlConversas(), Database::ehPostgres()
            ? "CREATE TABLE IF NOT EXISTS bot_opcoes (
                id_bot_opcao       INTEGER GENERATED BY DEFAULT AS IDENTITY,
                id_estabelecimento INTEGER NOT NULL,
                tipo               VARCHAR(10) NOT NULL,
                rotulo             VARCHAR(60) NOT NULL,
                resposta           VARCHAR(1000) DEFAULT NULL,
                ordem              SMALLINT NOT NULL DEFAULT 0,
                ativo              SMALLINT NOT NULL DEFAULT 1,
                data_criacao       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_bot_opcoes PRIMARY KEY (id_bot_opcao),
                CONSTRAINT ck_bot_opcao_tipo CHECK (tipo IN ('marcar','meus','cancelar','servicos','equipe','resposta')),
                CONSTRAINT fk_bot_opcao_empresa FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento (id_estabelecimento) ON DELETE CASCADE
            )"
            : "CREATE TABLE IF NOT EXISTS bot_opcoes (
                id_bot_opcao       INT UNSIGNED NOT NULL AUTO_INCREMENT,
                id_estabelecimento INT UNSIGNED NOT NULL,
                tipo               ENUM('marcar','meus','cancelar','servicos','equipe','resposta') NOT NULL,
                rotulo             VARCHAR(60) NOT NULL,
                resposta           VARCHAR(1000) DEFAULT NULL,
                ordem              SMALLINT NOT NULL DEFAULT 0,
                ativo              TINYINT(1) NOT NULL DEFAULT 1,
                data_criacao       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id_bot_opcao),
                KEY idx_bot_opcoes_empresa (id_estabelecimento, ordem),
                CONSTRAINT fk_bot_opcao_empresa FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento (id_estabelecimento) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            // Os textos do bot ficam em configuracoes e passam dos 255 da coluna antiga.
            // RLS: a API publica do Supabase nao le a tabela; o app conecta como dono e nao e afetado.
            ...(Database::ehPostgres()
                ? ['CREATE INDEX IF NOT EXISTS idx_bot_opcoes_empresa ON bot_opcoes (id_estabelecimento, ordem)',
                   'ALTER TABLE bot_opcoes ENABLE ROW LEVEL SECURITY',
                   'ALTER TABLE configuracoes ALTER COLUMN valor TYPE VARCHAR(1000)']
                : ['ALTER TABLE configuracoes MODIFY valor VARCHAR(1000) NOT NULL'])];
    }

    private static function ddlConversas(): array
    {
        return [Database::ehPostgres()
            ? 'CREATE TABLE IF NOT EXISTS whatsapp_conversas (
                id_estabelecimento INTEGER NOT NULL,
                numero             VARCHAR(20) NOT NULL,
                etapa              VARCHAR(30) NOT NULL DEFAULT \'menu\',
                dados              TEXT NULL,
                atualizado_em      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_whatsapp_conversas PRIMARY KEY (id_estabelecimento, numero),
                CONSTRAINT fk_whatsapp_conversas_empresa FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento (id_estabelecimento) ON DELETE CASCADE
            )'
            : 'CREATE TABLE IF NOT EXISTS whatsapp_conversas (
                id_estabelecimento INT UNSIGNED NOT NULL,
                numero             VARCHAR(20) NOT NULL,
                etapa              VARCHAR(30) NOT NULL DEFAULT \'menu\',
                dados              TEXT NULL,
                atualizado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id_estabelecimento, numero),
                CONSTRAINT fk_whatsapp_conversas_empresa FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento (id_estabelecimento) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'];
    }
}
