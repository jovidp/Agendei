<?php

/**
 * "Manter conectado": o dispositivo lembrado.
 *
 * Ao marcar a opcao no login, o navegador recebe um cookie proprio, separado
 * do cookie de sessao, com dois pedacos: um seletor (o indice) e um validador
 * (o segredo). So o hash do validador fica no banco, entao um vazamento da
 * tabela nao entrega cookies prontos. A cada uso o validador e trocado, o que
 * invalida qualquer copia antiga; ao sair da conta (logout.php) ou trocar a
 * senha o registro e apagado.
 *
 * A tabela nao e escopada pelo Contexto de proposito: o cookie chega antes de
 * a empresa ser conhecida, e e ele que diz em qual empresa a sessao renasce
 * (includes/auth.php, restaurarSessaoLembrada()).
 */
class SessaoLembrada
{
    /** Validade do cookie, renovada a cada uso. */
    public const DIAS = 30;

    /**
     * Cria o registro e devolve o valor do cookie ("seletor:validador").
     * O dispositivo lembra um vinculo: a pessoa numa empresa, com um tipo.
     */
    public static function criar(int $idEstabelecimento, int $idUsuario, int $idVinculo): string
    {
        $seletor   = bin2hex(random_bytes(12));
        $validador = bin2hex(random_bytes(32));
        $agora     = date('Y-m-d H:i:s');

        $q = bd()->prepare(
            'INSERT INTO sessoes_lembradas (id_estabelecimento, id_usuario, id_vinculo, seletor, validador_hash, expira_em, criado_em, ultimo_uso)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $q->execute([$idEstabelecimento, $idUsuario, $idVinculo, $seletor, self::hash($validador), self::vencimento(), $agora, $agora]);

        return $seletor . ':' . $validador;
    }

    /**
     * Registro correspondente ao cookie, ou null quando ele nao vale mais.
     * Seletor conhecido com segredo errado e sinal de copia antiga do cookie:
     * o registro e apagado para que a copia legitima tambem pare de valer.
     */
    public static function porCookie(string $valor): ?array
    {
        if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/D', $valor, $partes)) {
            return null;
        }

        $q = bd()->prepare('SELECT * FROM sessoes_lembradas WHERE seletor = ? LIMIT 1');
        $q->execute([$partes[1]]);
        $registro = $q->fetch();
        if (!$registro) {
            return null;
        }

        if (!hash_equals((string) $registro['validador_hash'], self::hash($partes[2]))) {
            self::apagar((int) $registro['id_sessao']);
            return null;
        }
        if (strtotime((string) $registro['expira_em']) < time()) {
            self::apagar((int) $registro['id_sessao']);
            return null;
        }

        return $registro;
    }

    /** Troca o segredo e estende a validade; devolve o novo valor do cookie. */
    public static function renovar(array $registro): string
    {
        $validador = bin2hex(random_bytes(32));
        $q = bd()->prepare('UPDATE sessoes_lembradas SET validador_hash = ?, expira_em = ?, ultimo_uso = ? WHERE id_sessao = ?');
        $q->execute([self::hash($validador), self::vencimento(), date('Y-m-d H:i:s'), (int) $registro['id_sessao']]);

        return $registro['seletor'] . ':' . $validador;
    }

    public static function apagar(int $idSessao): void
    {
        $q = bd()->prepare('DELETE FROM sessoes_lembradas WHERE id_sessao = ?');
        $q->execute([$idSessao]);
    }

    /** Todos os dispositivos lembrados de uma conta (troca de senha, exclusao). */
    public static function apagarDoUsuario(int $idUsuario): void
    {
        $q = bd()->prepare('DELETE FROM sessoes_lembradas WHERE id_usuario = ?');
        $q->execute([$idUsuario]);
    }

    /** Limpeza dos registros vencidos; devolve quantos saíram. */
    public static function apagarVencidas(): int
    {
        $q = bd()->prepare('DELETE FROM sessoes_lembradas WHERE expira_em < ?');
        $q->execute([date('Y-m-d H:i:s')]);

        return $q->rowCount();
    }

    private static function hash(string $validador): string
    {
        return hash('sha256', $validador);
    }

    private static function vencimento(): string
    {
        return date('Y-m-d H:i:s', time() + self::DIAS * 86400);
    }
}
