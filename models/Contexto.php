<?php
/** Estabelecimento da requisição. A sessão autenticada nunca muda de empresa pela URL. */
class Contexto
{
    private static ?array $atual = null;

    public static function iniciar(): void
    {
        $slug = get('estabelecimento');

        // A conta master e global e nunca assume um estabelecimento pela URL. As
        // paginas de entrada local (login, cadastro, 2FA) descartam a identidade
        // master antes de chegar aqui (ENTRADA_LOCAL, em config.php), e por isso
        // resolvem o estabelecimento normalmente.
        if (($_SESSION['usuario_tipo'] ?? null) === 'master' || defined('AREA_MASTER')) {
            self::$atual = self::neutro(null, 'Administração geral da plataforma');
            return;
        }
        $idSessao = (int) ($_SESSION['estabelecimento_id'] ?? 0);
        // Sessões anteriores à atualização recuperam o vínculo diretamente da conta.
        if (!empty($_SESSION['usuario_id']) && $idSessao < 1) {
            $q = bd()->prepare('SELECT id_estabelecimento FROM usuarios WHERE id_usuario = ?');
            $q->execute([(int) $_SESSION['usuario_id']]);
            $idSessao = (int) $q->fetchColumn();
            if ($idSessao < 1) { encerrarSessao(); self::falhar(401, 'Faça login novamente.'); }
            $_SESSION['estabelecimento_id'] = $idSessao;
        }
        // A entrada geral (entrar.php) nao pertence a empresa nenhuma: sem sessao
        // local ela abre com a aparencia da plataforma e ignora o slug da URL. A
        // empresa so e definida por assumir(), depois que a senha foi conferida.
        if (defined('ENTRADA_GLOBAL') && $idSessao < 1) {
            self::$atual = self::neutro('Agendei', 'Entrada única da plataforma');
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
        if (!$registro) self::falhar(404, 'Estabelecimento não encontrado.');
        if ($idSessao > 0 && $slug !== '' && $slug !== $registro['slug']) {
            self::falhar(403, 'Sua sessão pertence a outro estabelecimento. Saia da conta antes de acessar outro link.');
        }
        self::$atual = $registro;
    }

    /**
     * Assume a empresa de uma conta que acabou de ser autenticada pela entrada
     * geral. E o unico caminho em que a requisicao troca de empresa depois de
     * iniciada, e por isso so existe sob ENTRADA_GLOBAL: nas demais paginas a
     * sessao nunca muda de estabelecimento pela URL nem por chamada de codigo.
     */
    public static function assumir(int $idEstabelecimento): void
    {
        if (!defined('ENTRADA_GLOBAL')) {
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

    /** Contexto sem empresa (master e entrada geral), com a aparencia da plataforma. */
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
