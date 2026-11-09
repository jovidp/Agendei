<?php

/**
 * Confirmacao do e-mail da conta por um codigo de 6 digitos.
 *
 * A conta nasce na hora, como sempre; o codigo vai para o e-mail logo depois
 * do cadastro e a pessoa confirma quando quiser, pelo aviso do painel
 * (verificar_email.php). Confirmar grava usuarios.email_confirmado_em. Quem
 * se cadastra pelo Google ja nasce confirmado.
 *
 * O codigo fica na sessao (so o hash), por conta: quem entra em outro
 * aparelho pede outro. Por isso nao ha tabela de codigos.
 */
class VerificacaoEmail
{
    public const VALIDADE_MINUTOS = 15;
    public const MAX_TENTATIVAS = 5;
    public const INTERVALO_REENVIO = 60;

    private static ?bool $estruturaPronta = null;
    private static array $confirmados = [];

    /** Coluna nova em bancos criados antes dela. Roda na subida do container e em migrar.php. */
    public static function garantirEstrutura(): void
    {
        Sql::garantirColuna('usuarios', 'email_confirmado_em', 'TIMESTAMP NULL DEFAULT NULL');
        self::$estruturaPronta = true;
    }

    private static function disponivel(): bool
    {
        if (self::$estruturaPronta === null) {
            try {
                if (PHP_SAPI === 'cli' || getenv('AGENDEI_ESTRUTURA_PRONTA') !== '1') self::garantirEstrutura();
                self::$estruturaPronta = true;
            } catch (Throwable $erro) {
                error_log('Confirmação de e-mail indisponível: ' . $erro->getMessage());
                self::$estruturaPronta = false;
            }
        }
        return self::$estruturaPronta;
    }

    public static function confirmado(int $idUsuario): bool
    {
        if (!self::disponivel()) return true;
        if (!array_key_exists($idUsuario, self::$confirmados)) {
            $q = bd()->prepare('SELECT email_confirmado_em FROM usuarios WHERE id_usuario = ?');
            $q->execute([$idUsuario]);
            self::$confirmados[$idUsuario] = !empty($q->fetchColumn());
        }
        return self::$confirmados[$idUsuario];
    }

    public static function marcarConfirmado(int $idUsuario): void
    {
        if (!self::disponivel()) return;
        bd()->prepare('UPDATE usuarios SET email_confirmado_em = CURRENT_TIMESTAMP WHERE id_usuario = ?')->execute([$idUsuario]);
        self::$confirmados[$idUsuario] = true;
        unset($_SESSION['codigos_email']['conta' . $idUsuario]);
    }

    /** O painel deve lembrar a pessoa logada de confirmar o e-mail? */
    public static function precisaConfirmar(): bool
    {
        $id = (int) ($_SESSION['usuario_id'] ?? 0);
        return $id > 0 && empty($_SESSION['master_id']) && Email::configurado() && !self::confirmado($id);
    }

    /** Depois do cadastro: Google ja confirmou; senao manda o codigo. Falha no envio nao desfaz a conta. */
    public static function aposCadastro(string $email, bool $confirmadoPeloGoogle): void
    {
        try {
            $pessoa = Usuario::pessoaPorEmail($email);
            if ($pessoa === null) return;
            if ($confirmadoPeloGoogle) {
                self::marcarConfirmado((int) $pessoa['id_usuario']);
            } elseif (Email::configurado() && !self::confirmado((int) $pessoa['id_usuario'])) {
                self::enviar((int) $pessoa['id_usuario'], $pessoa['email'], $pessoa['nome']);
            }
        } catch (Throwable $erro) {
            error_log('Código de confirmação não enviado: ' . $erro->getMessage());
        }
    }

    /** Gera um codigo novo e manda para o e-mail. */
    public static function enviar(int $idUsuario, string $email, string $nome): bool
    {
        $codigo = self::novoCodigo('conta' . $idUsuario, $idUsuario, $email);
        [$assunto, $texto, $html] = emailCodigoConfirmacao($nome, $codigo);
        return Email::enviar($email, $assunto, $texto, $html, $nome);
    }

    /** Novo codigo, respeitando o intervalo entre envios. Devolve a mensagem de erro, ou '' se enviou. */
    public static function reenviar(int $idUsuario, string $email, string $nome): string
    {
        $espera = self::espera('conta' . $idUsuario);
        if ($espera > 0) return 'Aguarde ' . $espera . ' segundos para pedir outro código.';
        return self::enviar($idUsuario, $email, $nome) ? '' : 'Não foi possível enviar o e-mail agora. Tente de novo em instantes.';
    }

    /** Confere o codigo e, certo, confirma o e-mail. Devolve a mensagem de erro, ou '' se confirmou. */
    public static function conferir(int $idUsuario, string $email, string $codigo): string
    {
        $erro = self::conferirCodigo('conta' . $idUsuario, $codigo);
        if ($erro === '') self::marcarConfirmado($idUsuario);
        return $erro;
    }

    /** Ha codigo valido aguardando nesta sessao? */
    public static function codigoAtivo(int $idUsuario): bool
    {
        $pendente = $_SESSION['codigos_email']['conta' . $idUsuario] ?? null;
        return is_array($pendente) && time() <= (int) $pendente['expira'] && (int) $pendente['tentativas'] < self::MAX_TENTATIVAS;
    }

    // -----------------------------------------------------------------
    // Recuperacao de senha: o mesmo codigo leva ao token do link
    // -----------------------------------------------------------------

    /**
     * Codigo para a recuperacao de senha, guardado junto do token do link. Sem
     * conta ($token null) guarda um codigo que nunca confere: a tela se
     * comporta igual e nao revela quais e-mails existem.
     */
    public static function codigoRecuperacao(?int $idUsuario, string $email, ?string $token): string
    {
        $codigo = self::novoCodigo('senha', (int) $idUsuario, $email);
        if ($token === null) $_SESSION['codigos_email']['senha']['hash'] = '';
        $_SESSION['codigos_email']['senha']['token'] = $token;
        $_SESSION['codigos_email']['senha']['email'] = $email;
        return $codigo;
    }

    /** E-mail da recuperacao em andamento nesta sessao, ou null. */
    public static function recuperacaoPendente(): ?string
    {
        $pendente = $_SESSION['codigos_email']['senha'] ?? null;
        return is_array($pendente) && time() <= (int) $pendente['expira'] ? (string) $pendente['email'] : null;
    }

    public static function esperaRecuperacao(): int
    {
        return self::espera('senha');
    }

    /** Codigo certo: devolve o token para redefinir_senha.php. Errado: ['erro' => mensagem]. */
    public static function conferirRecuperacao(string $codigo): array
    {
        $token = $_SESSION['codigos_email']['senha']['token'] ?? null;
        $erro = self::conferirCodigo('senha', $codigo);
        if ($erro !== '') return ['erro' => $erro];
        self::cancelarRecuperacao();
        return ['token' => (string) $token];
    }

    public static function cancelarRecuperacao(): void
    {
        unset($_SESSION['codigos_email']['senha']);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private static function novoCodigo(string $chave, int $idUsuario, string $email): string
    {
        $codigo = sprintf('%06d', random_int(0, 999999));
        $_SESSION['codigos_email'][$chave] = [
            'hash'       => self::hash($codigo, $idUsuario, $email),
            'id'         => $idUsuario,
            'contato'    => $email,
            'expira'     => time() + self::VALIDADE_MINUTOS * 60,
            'tentativas' => 0,
            'enviado_em' => time(),
        ];
        return $codigo;
    }

    /** Segundos ate poder mandar outro codigo. */
    private static function espera(string $chave): int
    {
        return max(0, (int) ($_SESSION['codigos_email'][$chave]['enviado_em'] ?? 0) + self::INTERVALO_REENVIO - time());
    }

    /** '' quando confere; senao a mensagem. Cada erro conta uma tentativa. */
    private static function conferirCodigo(string $chave, string $codigo): string
    {
        $pendente = $_SESSION['codigos_email'][$chave] ?? null;
        if (!is_array($pendente)) return 'Peça um código novo.';
        if (time() > (int) $pendente['expira']) return 'O código expirou. Peça um novo.';
        if ((int) $pendente['tentativas'] >= self::MAX_TENTATIVAS) return 'Muitas tentativas erradas. Peça um novo código.';

        $digitado = self::hash(preg_replace('/\D/', '', $codigo), (int) $pendente['id'], (string) $pendente['contato']);
        if ($pendente['hash'] === '' || !hash_equals($pendente['hash'], $digitado)) {
            $restam = self::MAX_TENTATIVAS - (++$_SESSION['codigos_email'][$chave]['tentativas']);
            return $restam > 0 ? 'Código incorreto. Restam ' . $restam . ' tentativa(s).' : 'Muitas tentativas erradas. Peça um novo código.';
        }
        unset($_SESSION['codigos_email'][$chave]);
        return '';
    }

    /** Mostra parte do e-mail na tela ("ma***@gmail.com"). */
    public static function mascarar(string $email): string
    {
        [$usuario, $dominio] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($usuario, 0, 2) . str_repeat('*', max(1, mb_strlen($usuario) - 2)) . '@' . $dominio;
    }

    /** Na sessao fica so o hash; a chave tem a mesma origem da de Confirmacao, fora do banco. */
    private static function hash(string $codigo, int $idUsuario, string $email): string
    {
        $segredo = (string) (getenv('AGENDEI_CHAVE_TOTP') ?: getenv('AGENDEI_DB_PASS') ?: '');
        return hash_hmac('sha256', $codigo . '|' . $idUsuario . '|' . mb_strtolower($email), 'agendei-verificacao|' . $segredo . '|' . RAIZ);
    }
}
