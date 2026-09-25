<?php
/**
 * Vinculo: a pessoa dentro de um estabelecimento.
 *
 * A pessoa (tabela usuarios) e uma so em toda a plataforma: um e-mail, uma
 * senha, um segundo fator. O vinculo e o que ela e em cada empresa: cliente,
 * profissional ou administradora, com status, login de 6 letras e ultimo
 * acesso proprios. A mesma pessoa pode ter varios vinculos, inclusive mais de
 * um no mesmo estabelecimento (a autonoma que administra e atende no proprio
 * negocio), mas no maximo um por tipo em cada empresa.
 *
 * Os perfis (clientes, profissionais, administradores) apontam para o vinculo
 * pela chave composta (id_estabelecimento, id_vinculo): apagar o vinculo leva
 * o perfil junto, e o banco continua recusando misturar empresas.
 */
class Vinculo
{
    public const TIPOS = ['admin', 'profissional', 'cliente'];

    /** Tabela e chave do perfil de cada tipo. */
    private const PERFIS = [
        'cliente'      => ['clientes', 'id_cliente'],
        'profissional' => ['profissionais', 'id_profissional'],
        'admin'        => ['administradores', 'id_administrador'],
    ];

    /** Ordem de preferencia quando a pessoa tem mais de um vinculo na mesma empresa. */
    public const ORDEM_TIPO = "CASE v.tipo WHEN 'admin' THEN 0 WHEN 'profissional' THEN 1 ELSE 2 END";

    /**
     * Cria o vinculo e devolve o id. Recusa um segundo vinculo do mesmo tipo
     * da pessoa na mesma empresa. $dados aceita status e login.
     */
    public static function criar(int $idEstabelecimento, int $idUsuario, string $tipo, array $dados = []): int
    {
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new InvalidArgumentException('Tipo de vinculo invalido.');
        }
        if (self::porPessoaEmpresaTipo($idEstabelecimento, $idUsuario, $tipo) !== null) {
            throw new DomainException('Esta pessoa ja tem este vinculo com o estabelecimento.');
        }

        $login = mb_strtolower(trim((string) ($dados['login'] ?? '')));
        $q = bd()->prepare(
            'INSERT INTO vinculos (id_estabelecimento, id_usuario, tipo, status, login)
             VALUES (:empresa, :usuario, :tipo, :status, :login)'
        );
        $q->execute([
            ':empresa' => $idEstabelecimento,
            ':usuario' => $idUsuario,
            ':tipo'    => $tipo,
            ':status'  => ($dados['status'] ?? 'ativo') === 'inativo' ? 'inativo' : 'ativo',
            ':login'   => $login !== '' ? $login : null,
        ]);

        return (int) bd()->lastInsertId();
    }

    /** Linha crua do vinculo, sem depender do Contexto. */
    public static function porId(int $idVinculo): ?array
    {
        $q = bd()->prepare('SELECT * FROM vinculos WHERE id_vinculo = ? LIMIT 1');
        $q->execute([$idVinculo]);
        return $q->fetch() ?: null;
    }

    public static function porPessoaEmpresaTipo(int $idEstabelecimento, int $idUsuario, string $tipo): ?array
    {
        $q = bd()->prepare('SELECT * FROM vinculos WHERE id_estabelecimento = ? AND id_usuario = ? AND tipo = ? LIMIT 1');
        $q->execute([$idEstabelecimento, $idUsuario, $tipo]);
        return $q->fetch() ?: null;
    }

    /**
     * Todos os vinculos da pessoa, com a empresa ao lado, na ordem das telas de
     * escolha. Com $apenasAtivos, so os que podem entrar: vinculo, pessoa e
     * empresa ativos. Nunca devolve hash de senha.
     */
    public static function daPessoa(int $idUsuario, bool $apenasAtivos = false): array
    {
        $sql = 'SELECT v.id_vinculo, v.id_estabelecimento, v.id_usuario, v.tipo, v.login, v.ultimo_acesso,
                       v.status AS status_vinculo, u.status AS status_pessoa, u.nome, u.email,
                       e.nome AS estabelecimento_nome, e.slug AS estabelecimento_slug, e.status AS estabelecimento_status,
                       CASE WHEN u.status = \'ativo\' AND v.status = \'ativo\' THEN \'ativo\' ELSE \'inativo\' END AS status
                FROM vinculos v
                JOIN usuarios u ON u.id_usuario = v.id_usuario
                JOIN estabelecimento e ON e.id_estabelecimento = v.id_estabelecimento
                WHERE v.id_usuario = :usuario';
        if ($apenasAtivos) {
            $sql .= ' AND v.status = \'ativo\' AND u.status = \'ativo\' AND e.status = \'ativo\'';
        }
        $sql .= ' ORDER BY e.nome, ' . self::ORDEM_TIPO . ', v.id_vinculo';

        $q = bd()->prepare($sql);
        $q->execute([':usuario' => $idUsuario]);
        return $q->fetchAll();
    }

    /** Quantos vinculos a pessoa tem, em qualquer empresa. */
    public static function contarDaPessoa(int $idUsuario): int
    {
        $q = bd()->prepare('SELECT COUNT(*) FROM vinculos WHERE id_usuario = ?');
        $q->execute([$idUsuario]);
        return (int) $q->fetchColumn();
    }

    /** Vinculos de um tipo na empresa; com $status, so os que estao nele. */
    public static function contarNaEmpresa(int $idEstabelecimento, string $tipo, ?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) FROM vinculos WHERE id_estabelecimento = ? AND tipo = ?';
        $parametros = [$idEstabelecimento, $tipo];
        if ($status !== null) {
            $sql .= ' AND status = ?';
            $parametros[] = $status;
        }
        $q = bd()->prepare($sql);
        $q->execute($parametros);
        return (int) $q->fetchColumn();
    }

    /**
     * Liga ou desliga o vinculo. E o status que o administrador da empresa
     * controla: desligar aqui nao mexe na pessoa nem nos outros vinculos dela.
     * O filtro por empresa impede que um id de outra empresa surta efeito.
     */
    public static function alterarStatus(int $idVinculo, string $status, ?int $idEstabelecimento = null): bool
    {
        $status = $status === 'ativo' ? 'ativo' : 'inativo';
        $q = bd()->prepare('UPDATE vinculos SET status = ? WHERE id_vinculo = ? AND id_estabelecimento = ?');
        return $q->execute([$status, $idVinculo, $idEstabelecimento ?? Contexto::id()]);
    }

    /** Troca o login de 6 letras do vinculo (null apaga). */
    public static function definirLogin(int $idVinculo, ?string $login, ?int $idEstabelecimento = null): bool
    {
        $login = mb_strtolower(trim((string) $login));
        $q = bd()->prepare('UPDATE vinculos SET login = ? WHERE id_vinculo = ? AND id_estabelecimento = ?');
        return $q->execute([$login !== '' ? $login : null, $idVinculo, $idEstabelecimento ?? Contexto::id()]);
    }

    /** Marca o ultimo acesso do vinculo. Sem filtro de Contexto: a sessao lembrada abre antes dele. */
    public static function registrarAcesso(int $idVinculo): void
    {
        $q = bd()->prepare('UPDATE vinculos SET ultimo_acesso = NOW() WHERE id_vinculo = ?');
        $q->execute([$idVinculo]);
    }

    /** Id na tabela do perfil (id_cliente, id_profissional ou id_administrador) do vinculo. */
    public static function idDoPerfil(int $idVinculo, string $tipo): ?int
    {
        if (!isset(self::PERFIS[$tipo])) {
            return null;
        }
        [$tabela, $coluna] = self::PERFIS[$tipo];
        $q = bd()->prepare("SELECT {$coluna} FROM {$tabela} WHERE id_vinculo = ? LIMIT 1");
        $q->execute([$idVinculo]);
        $registro = $q->fetch();
        return $registro ? (int) $registro[$coluna] : null;
    }

    /**
     * Apaga o vinculo (o perfil e tudo o que depende dele caem em cascata) e,
     * se a pessoa ficou sem nenhum vinculo, apaga a pessoa tambem: era uma
     * conta que so existia por causa desta empresa.
     */
    public static function excluir(int $idVinculo, ?int $idEstabelecimento = null): bool
    {
        $vinculo = self::porId($idVinculo);
        if ($vinculo === null || (int) $vinculo['id_estabelecimento'] !== ($idEstabelecimento ?? Contexto::id())) {
            return false;
        }
        $q = bd()->prepare('DELETE FROM vinculos WHERE id_vinculo = ?');
        $q->execute([$idVinculo]);
        Usuario::apagarSeSemVinculo((int) $vinculo['id_usuario']);
        return true;
    }
}
