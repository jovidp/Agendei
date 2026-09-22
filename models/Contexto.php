<?php
/** Estabelecimento da requisição. A sessão autenticada nunca muda de empresa pela URL. */
class Contexto
{
    private static ?array $atual = null;

    public static function iniciar(): void
    {
        $slug = get('estabelecimento');

        // A sessao master impoe o contexto global na propria area master e quando a
        // URL nao aponta para um estabelecimento. Com o slug presente, a pagina
        // publica daquele estabelecimento (login, cadastro) resolve o proprio
        // contexto: e por ela que o master entra no estabelecimento que acabou de
        // criar, e o login local descarta a identidade master em seguida.
        if (defined('AREA_MASTER') || (($_SESSION['usuario_tipo'] ?? null) === 'master' && $slug === '')) {
            $master = Master::aparencia();
            self::$atual = [
                'id_estabelecimento' => 0,
                'slug' => '',
                'nome' => $master['nome'] ?? 'Agendei Master',
                'cor_primaria' => $master['cor_primaria'] ?? '#252B36',
                'cor_secundaria' => $master['cor_secundaria'] ?? '#6E8BFF',
                'cor_fundo' => $master['cor_fundo'] ?? '#F3F5F8',
                'fonte' => $master['fonte'] ?? 'padrao',
                'logo' => $master['logo'] ?? null,
                'slogan' => 'Administração geral da plataforma',
            ];
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
