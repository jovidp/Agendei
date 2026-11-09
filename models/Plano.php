<?php

/**
 * Planos de contratacao e os limites que eles impoem a cada estabelecimento.
 *
 * O plano nao cobra nada: ele descreve o que a empresa pode ter. Tres tetos,
 * porque sao os tres numeros que crescem com o uso real — equipe, catalogo e
 * volume de agendamentos no mes. Campo vazio significa ilimitado, e um
 * estabelecimento sem plano continua sem teto nenhum: assim a funcionalidade
 * nasce sem mudar o comportamento de quem ja usa o sistema.
 *
 * Os limites sao conferidos na criacao, nunca no que ja existe. Baixar o plano
 * de uma empresa nao apaga o excedente — ela apenas para de crescer ate voltar
 * para dentro do teto. Apagar dado de cliente por causa de contrato seria uma
 * decisao comercial tomada pelo codigo.
 */
class Plano
{
    /** Recursos limitados, com o rotulo usado nas telas e nas mensagens. */
    public const RECURSOS = [
        'profissionais'    => 'Profissionais',
        'servicos'         => 'Serviços',
        'agendamentos_mes' => 'Agendamentos por mês',
    ];

    /**
     * Beneficios que um plano liga ou desliga, com o rotulo das telas. Ficam
     * gravados em planos.recursos, separados por virgula.
     */
    public const BENEFICIOS = [
        'lembretes_whatsapp' => 'Lembretes automáticos por WhatsApp',
        'sinal_pix'          => 'Sinal por Pix no agendamento',
        'diferenciais'       => 'Fidelidade, pacotes, avaliações e comissões',
        'bot_whatsapp'       => 'Bot de atendimento no WhatsApp (agenda pela conversa)',
    ];

    /**
     * Beneficio novo que empresa sem plano nao ganha. As demais seguem a regra
     * de sempre (sem plano, sem teto), para nada mudar para quem ja usa o sistema.
     */
    private const SO_COM_PLANO = ['bot_whatsapp'];

    /** Planos criados na instalacao, se ainda nao existir um com o mesmo nome. */
    public const PADRAO = [
        ['nome' => 'Básico', 'preco_mensal' => 79.00, 'descricao' => 'Para quem está começando: agenda on-line, link próprio e lembretes pelo painel.',
         'limite_profissionais' => 2, 'limite_servicos' => 15, 'limite_agendamentos_mes' => null, 'recursos' => []],
        ['nome' => 'Profissional', 'preco_mensal' => 149.00, 'descricao' => 'Para a equipe que cresce: lembretes automáticos, sinal por Pix e fidelização.',
         'limite_profissionais' => 5, 'limite_servicos' => null, 'limite_agendamentos_mes' => null, 'recursos' => ['lembretes_whatsapp', 'sinal_pix', 'diferenciais']],
        ['nome' => 'Premium', 'preco_mensal' => 249.00, 'descricao' => 'Tudo do Profissional, sem limites, e o bot que agenda pelo WhatsApp.',
         'limite_profissionais' => null, 'limite_servicos' => null, 'limite_agendamentos_mes' => null, 'recursos' => ['lembretes_whatsapp', 'sinal_pix', 'diferenciais', 'bot_whatsapp']],
    ];

    /** Plano que a empresa recebe ao se cadastrar (durante a demonstracao). */
    public const PADRAO_CADASTRO = 'Profissional';

    /** Estado das tabelas: null = ainda nao verificado, false = indisponivel. */
    private static ?bool $tabelasProntas = null;

    /** Vinculo de cada empresa ja consultado nesta requisicao. */
    private static array $vinculos = [];

    // -----------------------------------------------------------------
    // Cadastro dos planos
    // -----------------------------------------------------------------

    /** Todos os planos, do mais novo para o mais antigo no desempate. */
    public static function listar(): array
    {
        if (!self::disponivel()) {
            return [];
        }

        return bd()->query(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM estabelecimento_plano ep WHERE ep.id_plano = p.id_plano) AS contratantes
             FROM planos p ORDER BY p.nome'
        )->fetchAll();
    }

    public static function porId(int $id): ?array
    {
        if (!self::disponivel()) {
            return null;
        }

        $q = bd()->prepare('SELECT * FROM planos WHERE id_plano = ? LIMIT 1');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    public static function porNome(string $nome): ?array
    {
        if (!self::disponivel()) {
            return null;
        }

        $q = bd()->prepare('SELECT * FROM planos WHERE nome = ? LIMIT 1');
        $q->execute([trim($nome)]);
        return $q->fetch() ?: null;
    }

    /** Cria um plano. Limites nulos significam "sem teto". */
    public static function criar(array $dados): int
    {
        self::exigirTabelas();

        $q = bd()->prepare(
            'INSERT INTO planos (nome, descricao, limite_profissionais, limite_servicos, limite_agendamentos_mes, preco_mensal, recursos)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $q->execute([
            trim($dados['nome']),
            self::texto($dados['descricao'] ?? null),
            self::limite($dados['limite_profissionais'] ?? null),
            self::limite($dados['limite_servicos'] ?? null),
            self::limite($dados['limite_agendamentos_mes'] ?? null),
            self::preco($dados['preco_mensal'] ?? null),
            self::listaRecursos($dados['recursos'] ?? []),
        ]);

        return (int) bd()->lastInsertId();
    }

    public static function atualizar(int $id, array $dados): bool
    {
        self::exigirTabelas();

        $q = bd()->prepare(
            'UPDATE planos
             SET nome = ?, descricao = ?, limite_profissionais = ?, limite_servicos = ?, limite_agendamentos_mes = ?,
                 preco_mensal = ?, recursos = ?
             WHERE id_plano = ?'
        );

        // O plano mudou: o que foi lido nesta requisicao nao vale mais.
        self::$vinculos = [];

        return $q->execute([
            trim($dados['nome']),
            self::texto($dados['descricao'] ?? null),
            self::limite($dados['limite_profissionais'] ?? null),
            self::limite($dados['limite_servicos'] ?? null),
            self::limite($dados['limite_agendamentos_mes'] ?? null),
            self::preco($dados['preco_mensal'] ?? null),
            self::listaRecursos($dados['recursos'] ?? []),
            $id,
        ]);
    }

    /**
     * Liga ou desliga o plano para novas contratacoes.
     * Quem ja esta nele continua onde esta: desligar o plano nao pode, sozinho,
     * mudar o que uma empresa contratou.
     */
    public static function alterarStatus(int $id, string $status): bool
    {
        self::exigirTabelas();

        $q = bd()->prepare('UPDATE planos SET status = ? WHERE id_plano = ?');
        return $q->execute([$status === 'ativo' ? 'ativo' : 'inativo', $id]);
    }

    // -----------------------------------------------------------------
    // Vinculo com o estabelecimento
    // -----------------------------------------------------------------

    /**
     * Plano vigente da empresa, com os dados do plano ja embutidos.
     *
     * O resultado fica guardado pela duracao da requisicao: a tela de planos
     * percorre todos os estabelecimentos perguntando o teto de tres recursos
     * cada, e sem isso a mesma linha seria buscada quatro vezes por empresa.
     */
    public static function doEstabelecimento(int $idEstabelecimento): ?array
    {
        if (array_key_exists($idEstabelecimento, self::$vinculos)) {
            return self::$vinculos[$idEstabelecimento];
        }

        if (!self::disponivel()) {
            return null;
        }

        $q = bd()->prepare(
            'SELECT p.*, ep.data_inicio, ep.observacao
             FROM estabelecimento_plano ep
             JOIN planos p ON p.id_plano = ep.id_plano
             WHERE ep.id_estabelecimento = ? LIMIT 1'
        );
        $q->execute([$idEstabelecimento]);

        return self::$vinculos[$idEstabelecimento] = ($q->fetch() ?: null);
    }

    /** Plano de cada empresa, indexado pelo id do estabelecimento. */
    public static function porEstabelecimento(): array
    {
        if (!self::disponivel()) {
            return [];
        }

        $linhas = bd()->query(
            'SELECT ep.id_estabelecimento, p.nome, p.id_plano
             FROM estabelecimento_plano ep JOIN planos p ON p.id_plano = ep.id_plano'
        )->fetchAll();

        $mapa = [];
        foreach ($linhas as $linha) {
            $mapa[(int) $linha['id_estabelecimento']] = $linha;
        }
        return $mapa;
    }

    /** Coloca a empresa num plano, ou tira dela o plano quando $idPlano e nulo. */
    public static function atribuir(int $idEstabelecimento, ?int $idPlano, string $observacao = ''): bool
    {
        self::exigirTabelas();

        // O vinculo acabou de mudar: o que foi lido antes nesta requisicao nao
        // vale mais, e uma conferencia de teto logo em seguida usaria o plano
        // antigo.
        unset(self::$vinculos[$idEstabelecimento]);

        if ($idPlano === null) {
            $q = bd()->prepare('DELETE FROM estabelecimento_plano WHERE id_estabelecimento = ?');
            return $q->execute([$idEstabelecimento]);
        }

        // Um estabelecimento tem um plano de cada vez: a troca substitui o
        // vinculo em vez de empilhar linhas. As duas gravacoes andam juntas,
        // senao uma falha no meio deixaria a empresa sem plano nenhum — ou
        // seja, sem limite, que e o oposto do que a troca pretendia.
        $db = bd();
        $db->beginTransaction();

        try {
            $db->prepare('DELETE FROM estabelecimento_plano WHERE id_estabelecimento = ?')
               ->execute([$idEstabelecimento]);

            $q = $db->prepare(
                'INSERT INTO estabelecimento_plano (id_estabelecimento, id_plano, data_inicio, observacao)
                 VALUES (?, ?, ?, ?)'
            );
            $resultado = $q->execute([$idEstabelecimento, $idPlano, date('Y-m-d H:i:s'), self::texto($observacao)]);

            $db->commit();
            return $resultado;
        } catch (Throwable $erro) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $erro;
        }
    }

    // -----------------------------------------------------------------
    // Consumo e limites
    // -----------------------------------------------------------------

    /**
     * Quanto a empresa ja usa de cada recurso.
     *
     * Conta o que esta em pe: profissional e servico desativados nao ocupam
     * vaga, e agendamento cancelado nao pesa no mes. Quem desfez nao deve
     * continuar pagando o teto por aquilo.
     */
    public static function uso(int $idEstabelecimento): array
    {
        $profissionais = bd()->prepare(
            'SELECT COUNT(*) FROM profissionais p
             JOIN vinculos v ON v.id_vinculo = p.id_vinculo
             WHERE p.id_estabelecimento = ? AND v.status = \'ativo\''
        );
        $profissionais->execute([$idEstabelecimento]);

        $servicos = bd()->prepare(
            'SELECT COUNT(*) FROM servicos WHERE id_estabelecimento = ? AND status = \'ativo\''
        );
        $servicos->execute([$idEstabelecimento]);

        $agendamentos = bd()->prepare(
            'SELECT COUNT(*) FROM agendamentos
             WHERE id_estabelecimento = ? AND data_criacao >= ? AND status <> \'cancelado\''
        );
        $agendamentos->execute([$idEstabelecimento, date('Y-m-01 00:00:00')]);

        return [
            'profissionais'    => (int) $profissionais->fetchColumn(),
            'servicos'         => (int) $servicos->fetchColumn(),
            'agendamentos_mes' => (int) $agendamentos->fetchColumn(),
        ];
    }

    /** Teto do recurso no plano da empresa, ou null quando nao ha teto. */
    public static function limiteDe(string $recurso, int $idEstabelecimento): ?int
    {
        if (!isset(self::RECURSOS[$recurso])) {
            return null;
        }

        $plano = self::doEstabelecimento($idEstabelecimento);
        if ($plano === null) {
            return null;
        }

        $valor = $plano['limite_' . $recurso] ?? null;
        return $valor === null || $valor === '' ? null : (int) $valor;
    }

    /**
     * Mensagem a exibir quando o recurso nao pode mais crescer, ou null quando
     * ainda cabe. E o unico ponto que decide "pode criar?", para que as telas
     * e o model de agendamento respondam a mesma regra.
     */
    public static function bloqueio(string $recurso, int $idEstabelecimento): ?string
    {
        $limite = self::limiteDe($recurso, $idEstabelecimento);
        if ($limite === null) {
            return null;
        }

        $usado = self::uso($idEstabelecimento)[$recurso] ?? 0;
        if ($usado < $limite) {
            return null;
        }

        $plano = self::doEstabelecimento($idEstabelecimento);

        return match ($recurso) {
            'profissionais'    => 'O plano ' . $plano['nome'] . ' permite até ' . $limite . ' profissional(is) ativo(s). Desative um ou fale com a administração para mudar de plano.',
            'servicos'         => 'O plano ' . $plano['nome'] . ' permite até ' . $limite . ' servico(s) ativo(s). Desative um ou fale com a administração para mudar de plano.',
            default            => 'O plano ' . $plano['nome'] . ' permite até ' . $limite . ' agendamento(s) por mês, e o mês atual já chegou a esse total.',
        };
    }

    /** Situacao de cada recurso para a tela do master: usado, teto e excedente. */
    public static function situacao(int $idEstabelecimento): array
    {
        $uso = self::uso($idEstabelecimento);
        $situacao = [];

        foreach (self::RECURSOS as $recurso => $rotulo) {
            $limite = self::limiteDe($recurso, $idEstabelecimento);
            $situacao[$recurso] = [
                'rotulo'    => $rotulo,
                'usado'     => $uso[$recurso] ?? 0,
                'limite'    => $limite,
                'excedido'  => $limite !== null && ($uso[$recurso] ?? 0) >= $limite,
            ];
        }

        return $situacao;
    }

    // -----------------------------------------------------------------
    // Beneficios e preco
    // -----------------------------------------------------------------

    /** Beneficios ligados num plano (linha da tabela planos). */
    public static function recursosDe(?array $plano): array
    {
        $lista = array_filter(array_map('trim', explode(',', (string) ($plano['recursos'] ?? ''))));
        return array_values(array_intersect(array_keys(self::BENEFICIOS), $lista));
    }

    /**
     * O plano da empresa inclui o beneficio? Empresa sem plano tem tudo o que
     * ja existia antes dos planos, menos o que e SO_COM_PLANO.
     */
    public static function temRecurso(string $recurso, ?int $idEstabelecimento = null): bool
    {
        $plano = self::doEstabelecimento($idEstabelecimento ?? Contexto::id());
        if ($plano === null) {
            return !in_array($recurso, self::SO_COM_PLANO, true);
        }
        return in_array($recurso, self::recursosDe($plano), true);
    }

    /** Mensalidade da empresa: o preco do plano dela, ou o valor padrao sem plano. */
    public static function precoDe(int $idEstabelecimento): float
    {
        $preco = self::doEstabelecimento($idEstabelecimento)['preco_mensal'] ?? null;
        return $preco === null || $preco === '' ? Assinatura::VALOR_MENSAL : (float) $preco;
    }

    /** Planos ativos que a empresa pode escolher, do mais barato ao mais caro. */
    public static function contrataveis(): array
    {
        $planos = array_values(array_filter(
            self::listar(),
            fn($p) => $p['status'] === 'ativo' && $p['preco_mensal'] !== null && $p['preco_mensal'] !== ''
        ));
        usort($planos, fn($a, $b) => (float) $a['preco_mensal'] <=> (float) $b['preco_mensal']);
        return $planos;
    }

    /**
     * O que o plano inclui, na ordem das telas: limites e depois cada beneficio,
     * marcado como incluso ou nao. Usado na pagina inicial e em Assinatura.
     * @return array<int, array{texto: string, incluso: bool}>
     */
    public static function itensDe(array $plano): array
    {
        $limite = fn($valor, string $unidade) => $valor === null || $valor === ''
            ? ['texto' => ucfirst($unidade) . ' ilimitados', 'incluso' => true]
            : ['texto' => 'Até ' . (int) $valor . ' ' . $unidade, 'incluso' => true];

        $itens = [
            $limite($plano['limite_profissionais'] ?? null, 'profissionais'),
            $limite($plano['limite_servicos'] ?? null, 'serviços'),
            $limite($plano['limite_agendamentos_mes'] ?? null, 'agendamentos por mês'),
        ];
        $recursos = self::recursosDe($plano);
        foreach (self::BENEFICIOS as $chave => $rotulo) {
            $itens[] = ['texto' => $rotulo, 'incluso' => in_array($chave, $recursos, true)];
        }
        return $itens;
    }

    /** Menor mensalidade entre os planos contrataveis, para o "a partir de" das paginas publicas. */
    public static function menorPreco(): float
    {
        $planos = self::contrataveis();
        return $planos === [] ? Assinatura::VALOR_MENSAL : (float) $planos[0]['preco_mensal'];
    }

    /** Plano mais barato que tem o beneficio, para o aviso "disponivel no plano X". */
    public static function menorCom(string $recurso): ?array
    {
        foreach (self::contrataveis() as $plano) {
            if (in_array($recurso, self::recursosDe($plano), true)) return $plano;
        }
        return null;
    }

    /** Texto do aviso de beneficio fora do plano. */
    public static function avisoSemRecurso(string $recurso): string
    {
        $plano = self::menorCom($recurso);
        return self::BENEFICIOS[$recurso] . ' não faz parte do seu plano'
            . ($plano ? '. Está disponível a partir do plano ' . $plano['nome'] . ', em Assinatura.' : '.');
    }

    /**
     * Colunas de preco e beneficios em bancos criados antes delas, e os planos
     * padrao que ainda nao existirem. Roda na subida do container e em migrar.php.
     */
    public static function garantirEstrutura(): void
    {
        foreach (self::ddl() as $comando) {
            bd()->exec($comando);
        }
        Sql::garantirColuna('planos', 'preco_mensal', 'DECIMAL(10,2) NULL');
        Sql::garantirColuna('planos', 'recursos', 'VARCHAR(255) NULL');
        self::$tabelasProntas = true;

        foreach (self::PADRAO as $plano) {
            if (self::porNome($plano['nome']) === null) {
                self::criar($plano);
            }
        }
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /** Preco do formulario ("1.249,90", "149,90" ou 149.9); vazio ou zero vira null. */
    private static function preco($valor): ?string
    {
        if ($valor === null || $valor === '') return null;
        $numero = is_float($valor) || is_int($valor) || preg_match('/^\d+(\.\d+)?$/', (string) $valor)
            ? (float) $valor
            : (float) str_replace(',', '.', str_replace('.', '', (string) $valor));
        return $numero > 0 ? number_format($numero, 2, '.', '') : null;
    }

    /** Lista de beneficios validos, gravada separada por virgula. */
    private static function listaRecursos($lista): ?string
    {
        $validos = array_values(array_intersect(array_keys(self::BENEFICIOS), (array) $lista));
        return $validos === [] ? null : implode(',', $validos);
    }

    /** Converte o campo do formulario em teto: vazio ou zero viram "sem teto". */
    private static function limite($valor): ?int
    {
        if ($valor === null || $valor === '' || (int) $valor <= 0) {
            return null;
        }
        return (int) $valor;
    }

    private static function texto(?string $valor): ?string
    {
        $valor = trim((string) $valor);
        return $valor === '' ? null : mb_substr($valor, 0, 255);
    }

    // -----------------------------------------------------------------
    // Estrutura
    // -----------------------------------------------------------------

    /** Indica se as tabelas de plano respondem; cria-as na primeira chamada. */
    public static function disponivel(): bool
    {
        if (PHP_SAPI !== 'cli' && getenv('AGENDEI_ESTRUTURA_PRONTA') === '1') return true;
        if (self::$tabelasProntas !== null) {
            return self::$tabelasProntas;
        }

        try {
            foreach (self::ddl() as $comando) {
                bd()->exec($comando);
            }
            self::$tabelasProntas = true;
        } catch (Throwable $erro) {
            error_log('Tabelas de planos indisponíveis: ' . $erro->getMessage());
            self::$tabelasProntas = false;
        }

        return self::$tabelasProntas;
    }

    /** Interrompe a gravacao quando as tabelas nao existem. */
    private static function exigirTabelas(): void
    {
        if (!self::disponivel()) {
            throw new RuntimeException('As tabelas de planos não estão disponíveis neste banco.');
        }
    }

    /** Comandos de criacao das tabelas em cada dialeto. */
    public static function ddl(): array
    {
        if (Database::ehPostgres()) {
            return [
                "CREATE TABLE IF NOT EXISTS planos (
                    id_plano                INTEGER GENERATED BY DEFAULT AS IDENTITY,
                    nome                    VARCHAR(60) NOT NULL,
                    descricao               VARCHAR(255) DEFAULT NULL,
                    limite_profissionais    INTEGER DEFAULT NULL,
                    limite_servicos         INTEGER DEFAULT NULL,
                    limite_agendamentos_mes INTEGER DEFAULT NULL,
                    preco_mensal            NUMERIC(10,2) DEFAULT NULL,
                    recursos                VARCHAR(255) DEFAULT NULL,
                    status                  VARCHAR(10) NOT NULL DEFAULT 'ativo',
                    data_criacao            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT pk_planos PRIMARY KEY (id_plano),
                    CONSTRAINT uk_planos_nome UNIQUE (nome),
                    CONSTRAINT ck_planos_status CHECK (status IN ('ativo','inativo'))
                )",
                "CREATE TABLE IF NOT EXISTS estabelecimento_plano (
                    id_estabelecimento INTEGER NOT NULL,
                    id_plano           INTEGER NOT NULL,
                    data_inicio        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    observacao         VARCHAR(255) DEFAULT NULL,
                    CONSTRAINT pk_estabelecimento_plano PRIMARY KEY (id_estabelecimento),
                    CONSTRAINT fk_ep_estabelecimento FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento (id_estabelecimento),
                    CONSTRAINT fk_ep_plano FOREIGN KEY (id_plano) REFERENCES planos (id_plano)
                )",
                'CREATE INDEX IF NOT EXISTS idx_ep_plano ON estabelecimento_plano (id_plano)',
            ];
        }

        return [
            "CREATE TABLE IF NOT EXISTS planos (
                id_plano                INT UNSIGNED NOT NULL AUTO_INCREMENT,
                nome                    VARCHAR(60) NOT NULL,
                descricao               VARCHAR(255) DEFAULT NULL,
                limite_profissionais    INT UNSIGNED DEFAULT NULL,
                limite_servicos         INT UNSIGNED DEFAULT NULL,
                limite_agendamentos_mes INT UNSIGNED DEFAULT NULL,
                preco_mensal            DECIMAL(10,2) DEFAULT NULL,
                recursos                VARCHAR(255) DEFAULT NULL,
                status                  ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
                data_criacao            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id_plano),
                UNIQUE KEY uk_planos_nome (nome)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS estabelecimento_plano (
                id_estabelecimento INT UNSIGNED NOT NULL,
                id_plano           INT UNSIGNED NOT NULL,
                data_inicio        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                observacao         VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (id_estabelecimento),
                KEY idx_ep_plano (id_plano),
                CONSTRAINT fk_ep_estabelecimento FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento (id_estabelecimento),
                CONSTRAINT fk_ep_plano FOREIGN KEY (id_plano) REFERENCES planos (id_plano)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
}
