<?php
/** Estabelecimento da requisição. A sessão autenticada nunca muda de empresa pela URL. */
class Contexto
{
    private static ?array $atual = null;

    public static function iniciar(): void
    {
        $slug = get('estabelecimento');

        // A tarefa periodica (scripts/enviar_lembretes.php e tarefas.php) nao
        // pertence a empresa nenhuma: ela percorre todas por assumirTarefa().
        if (defined('TAREFA_AGENDADA')) {
            self::$atual = self::produto();
            return;
        }

        // A conta master e global e nunca assume um estabelecimento pela URL. As
        // paginas de entrada local (login, cadastro, 2FA) descartam a identidade
        // master antes de chegar aqui (ENTRADA_LOCAL, em config.php), e por isso
        // resolvem o estabelecimento normalmente.
        if (($_SESSION['usuario_tipo'] ?? null) === 'master' || defined('AREA_MASTER')) {
            self::$atual = self::neutro(null, 'Administração geral da plataforma');
            return;
        }
        $idSessao = (int) ($_SESSION['estabelecimento_id'] ?? 0);
        // A sessao nasce de um vinculo (registrarSessao) e sempre sabe a empresa.
        // Uma sessao antiga sem esse dado nao tem como ser reconstruida: a pessoa
        // pode ter vinculos em varias empresas, e a escolha e dela, no login.
        if (!empty($_SESSION['usuario_id']) && $idSessao < 1) {
            encerrarSessao();
            self::falhar(401, 'Faça login novamente.');
        }
        // A entrada geral (entrar.php) e a pagina inicial sem link de empresa
        // (index.php) nao pertencem a empresa nenhuma: sem sessao local abrem com
        // a marca do produto e ignoram o slug da URL. Na entrada geral a empresa
        // so e definida por assumir(), depois que a senha foi conferida.
        if ((defined('ENTRADA_GLOBAL') || defined('PAGINA_PRODUTO')) && $idSessao < 1) {
            self::$atual = self::produto();
            return;
        }
        if ($idSessao > 0) {
            $q = bd()->prepare('SELECT * FROM estabelecimento WHERE id_estabelecimento = ? AND status = \'ativo\'');
            $q->execute([$idSessao]);
        } elseif ($slug !== '') {
            $q = bd()->prepare('SELECT * FROM estabelecimento WHERE slug = ? AND status = \'ativo\'');
            $q->execute([$slug]);
        } else {
            $q = bd()->query('SELECT * FROM estabelecimento WHERE status = \'ativo\' ORDER BY id_estabelecimento LIMIT 1');
        }
        $registro = $q->fetch();
        if (!$registro && $slug !== '' && Solicitacao::pendentePorSlug($slug)) {
            self::falhar(403, 'Este estabelecimento ainda aguarda a aprovação do cadastro. O responsável será avisado quando o acesso for liberado.');
        }
        if (!$registro) self::falhar(404, 'Estabelecimento não encontrado.');
        if ($idSessao > 0 && $slug !== '' && $slug !== $registro['slug']) {
            self::falhar(403, 'Sua sessão pertence a outro estabelecimento. Saia da conta antes de acessar outro link.');
        }
        self::$atual = $registro;
    }

    /**
     * Assume a empresa de uma conta que acabou de ser autenticada pela entrada
     * geral (ou escolhida em trocar.php). E o unico caminho em que a requisicao
     * troca de empresa depois de iniciada, e por isso so existe sob
     * ENTRADA_GLOBAL ou TROCA_VINCULO: nas demais paginas a sessao nunca muda
     * de estabelecimento pela URL nem por chamada de codigo.
     */
    public static function assumir(int $idEstabelecimento): void
    {
        // TROCA_VINCULO e a pagina trocar.php: a pessoa logada escolhe outro
        // vinculo seu por um POST com CSRF, e a sessao e refeita do zero.
        if (!defined('ENTRADA_GLOBAL') && !defined('TROCA_VINCULO')) {
            throw new LogicException('Somente a entrada geral pode assumir um estabelecimento.');
        }
        $q = bd()->prepare('SELECT * FROM estabelecimento WHERE id_estabelecimento = ? AND status = \'ativo\'');
        $q->execute([$idEstabelecimento]);
        $registro = $q->fetch();
        if (!$registro) {
            throw new InvalidArgumentException('Estabelecimento inativo ou inexistente.');
        }
        self::$atual = $registro;
    }

    /**
     * Passa a tarefa periodica para a proxima empresa da lista.
     *
     * So existe sob TAREFA_AGENDADA, definida antes do bootstrap pelos dois
     * gatilhos da tarefa. Nenhuma pagina servida a um usuario define essa
     * constante, e por isso nenhuma delas consegue trocar de empresa por aqui.
     */
    public static function assumirTarefa(int $idEstabelecimento): void
    {
        if (!defined('TAREFA_AGENDADA')) {
            throw new LogicException('Somente a tarefa agendada percorre os estabelecimentos.');
        }
        $q = bd()->prepare('SELECT * FROM estabelecimento WHERE id_estabelecimento = ? AND status = \'ativo\'');
        $q->execute([$idEstabelecimento]);
        $registro = $q->fetch();
        if (!$registro) {
            throw new InvalidArgumentException('Estabelecimento inativo ou inexistente.');
        }
        self::$atual = $registro;
    }

    /**
     * Contexto das paginas do produto (entrada geral e pagina inicial). Elas nao
     * sao do administrador master: vestem a marca e o tema de fabrica,
     * independentemente da aparencia que o master escolheu para a sua area.
     */
    private static function produto(): array
    {
        return [
            'id_estabelecimento' => 0,
            'slug' => '',
            'nome' => NOME_SISTEMA,
            'logo' => null,
            'slogan' => MARCA_SLOGAN,
        ] + Tema::PADRAO;
    }

    /** Contexto sem empresa (area master), com a aparencia escolhida pelo master. */
    private static function neutro(?string $nome, string $slogan): array
    {
        $master = Master::aparencia();
        return [
            'id_estabelecimento' => 0,
            'slug' => '',
            'nome' => $nome ?? ($master['nome'] ?? 'Agendei Master'),
            'cor_primaria' => $master['cor_primaria'] ?? '#252B36',
            'cor_secundaria' => $master['cor_secundaria'] ?? '#6E8BFF',
            'cor_fundo' => $master['cor_fundo'] ?? '#F3F5F8',
            'fonte' => $master['fonte'] ?? 'padrao',
            'logo' => $master['logo'] ?? null,
            'slogan' => $slogan,
        ];
    }

    public static function id(): int
    {
        if (self::$atual === null) throw new LogicException('Estabelecimento não selecionado.');
        return (int) self::$atual['id_estabelecimento'];
    }

    public static function slug(): string { return (string) (self::$atual['slug'] ?? ''); }
    public static function dados(): array { self::id(); return self::$atual; }

    /** Atualiza a aparência durante a requisição sem permitir trocar o vínculo da sessão. */
    public static function recarregar(): void
    {
        $q = bd()->prepare('SELECT * FROM estabelecimento WHERE id_estabelecimento = ?');
        $q->execute([self::id()]);
        self::$atual = $q->fetch();
    }

    private static function falhar(int $status, string $mensagem): void
    {
        http_response_code($status);
        if (ehRequisicaoAjax()) jsonResposta(['sucesso' => false, 'mensagem' => $mensagem], $status);
        exit(e($mensagem));
    }
}
