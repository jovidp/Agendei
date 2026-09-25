<?php
/**
 * Pessoas (tabela usuarios) e a leitura delas dentro de um estabelecimento.
 *
 * Uma pessoa tem um e-mail, uma senha e um segundo fator, e pode ter varios
 * vinculos (models/Vinculo.php): cliente aqui, profissional ali, administradora
 * do proprio negocio. As consultas por Contexto devolvem a pessoa JA JUNTADA ao
 * vinculo dela na empresa da sessao: e o array "usuario" que as telas conhecem,
 * com tipo, status, login e id_vinculo, e ele continua nulo para quem nao tem
 * vinculo na empresa. Quando a pessoa tem mais de um vinculo na mesma empresa,
 * o chamador diz qual tipo quer; sem isso vale a ordem admin, profissional,
 * cliente.
 *
 * O 'status' do array e o efetivo: pessoa e vinculo ativos. 'status_pessoa' e
 * o bloqueio global (so o master mexe) e 'status_vinculo' e o que o
 * administrador da empresa liga e desliga.
 *
 * As operacoes sobre a pessoa (senha, dados, 2FA, token) exigem que ela tenha
 * vinculo na empresa da sessao: e o isolamento de sempre, declarado no vinculo.
 */
class Usuario
{
    /** Tipos de vinculo, na ordem em que aparecem nos filtros. */
    public const TIPOS = ['admin' => 'Administrador', 'profissional' => 'Profissional', 'cliente' => 'Cliente'];

    /** Colunas do vinculo que acompanham a pessoa. 'status' por ultimo, para sobrepor o u.status. */
    private const CAMPOS_VINCULO = "v.id_vinculo, v.id_estabelecimento, v.tipo, v.login, v.ultimo_acesso,
        v.status AS status_vinculo, u.status AS status_pessoa,
        CASE WHEN u.status = 'ativo' AND v.status = 'ativo' THEN 'ativo' ELSE 'inativo' END AS status";

    /** A pessoa precisa ter vinculo na empresa da sessao para ser alterada por ela. */
    private static function comVinculoAqui(): string
    {
        return ' AND EXISTS (SELECT 1 FROM vinculos v WHERE v.id_usuario = usuarios.id_usuario AND v.id_estabelecimento = ' . Contexto::id() . ')';
    }

    /** Pessoa + vinculo na empresa da sessao que atende a condicao, ou null. */
    private static function selecionar(string $condicao, array $parametros, ?string $tipo = null): ?array
    {
        $sql = 'SELECT u.*, ' . self::CAMPOS_VINCULO . '
                FROM usuarios u
                JOIN vinculos v ON v.id_usuario = u.id_usuario AND v.id_estabelecimento = ' . Contexto::id() . '
                WHERE ' . $condicao;
        if ($tipo !== null) {
            $sql .= ' AND v.tipo = :tipo';
            $parametros[':tipo'] = $tipo;
        }
        $sql .= ' ORDER BY ' . Vinculo::ORDEM_TIPO . ', v.id_vinculo LIMIT 1';

        $consulta = bd()->prepare($sql);
        $consulta->execute($parametros);
        return $consulta->fetch() ?: null;
    }

    // -----------------------------------------------------------------
    // Leitura dentro da empresa da sessao
    // -----------------------------------------------------------------

    /** Pessoa pelo id, com o vinculo dela nesta empresa; null quando nao tem vinculo aqui. */
    public static function porId(int $idUsuario, ?string $tipo = null): ?array
    {
        return self::selecionar('u.id_usuario = :id', [':id' => $idUsuario], $tipo);
    }

    /** Pessoa + vinculo pelo id do vinculo, desde que ele seja desta empresa. */
    public static function porVinculo(int $idVinculo): ?array
    {
        return self::selecionar('v.id_vinculo = :id', [':id' => $idVinculo]);
    }

    /** A conta da sessao aberta: o vinculo escolhido no login, nunca outro da mesma pessoa. */
    public static function daSessao(): ?array
    {
        $idVinculo = vinculoId();
        if ($idVinculo !== null) {
            return self::porVinculo($idVinculo);
        }
        $idUsuario = usuarioId();
        return $idUsuario === null ? null : self::porId($idUsuario, perfil());
    }

    /** Busca a conta pelo e-mail para autenticação e recuperação de acesso. */
    public static function porEmail(string $email, ?string $tipo = null): ?array
    {
        return self::selecionar('u.email = :email', [':email' => mb_strtolower(trim($email))], $tipo);
    }

    /** Busca a conta pelo login de acesso (exatamente 6 letras), que e do vinculo. */
    public static function porLogin(string $login): ?array
    {
        return self::selecionar('v.login = :login', [':login' => mb_strtolower(trim($login))]);
    }

    /**
     * Ponto de entrada da autenticacao: aceita o login de 6 letras ou o e-mail.
     * As contas criadas antes do campo login continuam entrando pelo e-mail.
     */
    public static function porLoginOuEmail(string $identificador): ?array
    {
        $identificador = trim($identificador);

        if (validarLogin($identificador)) {
            $usuario = self::porLogin($identificador);
            if ($usuario !== null) {
                return $usuario;
            }
        }

        return self::porEmail($identificador);
    }

    /** Verifica login duplicado nesta empresa, ignorando os vinculos da propria pessoa quando o ID é informado. */
    public static function loginEmUso(string $login, ?int $ignorarIdUsuario = null): bool
    {
        $login = trim($login);
        if ($login === '') {
            return false;
        }

        $sql = 'SELECT id_vinculo FROM vinculos WHERE id_estabelecimento = ' . Contexto::id() . ' AND login = :login';
        $parametros = [':login' => mb_strtolower($login)];

        if ($ignorarIdUsuario !== null) {
            $sql .= ' AND id_usuario <> :id';
            $parametros[':id'] = $ignorarIdUsuario;
        }

        $consulta = bd()->prepare($sql . ' LIMIT 1');
        $consulta->execute($parametros);
        return (bool) $consulta->fetch();
    }

    /** Verifica e-mail duplicado em toda a plataforma, ignorando a própria pessoa quando o ID é informado. */
    public static function emailEmUso(string $email, ?int $ignorarIdUsuario = null): bool
    {
        $sql = 'SELECT id_usuario FROM usuarios WHERE email = :email';
        $parametros = [':email' => mb_strtolower(trim($email))];

        if ($ignorarIdUsuario !== null) {
            $sql .= ' AND id_usuario <> :id';
            $parametros[':id'] = $ignorarIdUsuario;
        }

        $consulta = bd()->prepare($sql . ' LIMIT 1');
        $consulta->execute($parametros);
        return (bool) $consulta->fetch();
    }

    // -----------------------------------------------------------------
    // A pessoa, sem empresa (identidade global)
    // -----------------------------------------------------------------

    /** Linha da pessoa pelo e-mail, em qualquer empresa. Traz o hash: uso restrito a autenticacao. */
    public static function pessoaPorEmail(string $email): ?array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return null;
        }
        $consulta = bd()->prepare('SELECT * FROM usuarios WHERE email = :email LIMIT 1');
        $consulta->execute([':email' => $email]);
        return $consulta->fetch() ?: null;
    }

    /** Linha da pessoa pelo id, sem hash nem segredos. Para o master e as telas de vinculo. */
    public static function pessoaPorId(int $idUsuario): ?array
    {
        $consulta = bd()->prepare('SELECT * FROM usuarios WHERE id_usuario = :id LIMIT 1');
        $consulta->execute([':id' => $idUsuario]);
        $pessoa = $consulta->fetch();
        if (!$pessoa) {
            return null;
        }
        unset($pessoa['senha_hash'], $pessoa['totp_segredo'], $pessoa['token_recuperacao']);
        return $pessoa;
    }

    /**
     * Cria a pessoa: identidade, senha e dados do cadastro completo. Nao cria
     * vinculo nenhum; Cliente, Profissional e Estabelecimento criam o deles.
     */
    public static function criar(array $dados): int
    {
        $email = mb_strtolower(trim((string) ($dados['email'] ?? '')));
        if (self::emailEmUso($email)) {
            throw new DomainException('Ja existe uma conta cadastrada com este e-mail.');
        }

        $sql = 'INSERT INTO usuarios (nome, email, senha_hash, telefone, status,
                                      sexo, nome_materno, data_nascimento, telefone_fixo,
                                      cep, logradouro, numero, complemento, bairro, cidade, uf)
                VALUES (:nome, :email, :senha_hash, :telefone, :status,
                        :sexo, :nome_materno, :data_nascimento, :telefone_fixo,
                        :cep, :logradouro, :numero, :complemento, :bairro, :cidade, :uf)';

        $consulta = bd()->prepare($sql);
        $consulta->execute([
            ':nome'       => $dados['nome'],
            ':email'      => $email,
            ':senha_hash' => password_hash($dados['senha'], PASSWORD_DEFAULT),
            ':telefone'   => apenasNumeros($dados['telefone'] ?? '') ?: null,
            ':status'     => 'ativo',
        ] + self::parametrosPessoais($dados));

        return (int) bd()->lastInsertId();
    }

    /**
     * Campos do cadastro completo exigido pela especificacao.
     * Ficam em usuarios para que o master tambem responda as perguntas do 2FA.
     */
    private static function parametrosPessoais(array $dados): array
    {
        $cep = apenasNumeros($dados['cep'] ?? '');

        return [
            ':sexo'            => in_array($dados['sexo'] ?? '', ['F', 'M', 'O'], true) ? $dados['sexo'] : null,
            ':nome_materno'    => trim((string) ($dados['nome_materno'] ?? '')) ?: null,
            ':data_nascimento' => $dados['data_nascimento'] ?? null,
            ':telefone_fixo'   => apenasNumeros($dados['telefone_fixo'] ?? '') ?: null,
            ':cep'             => $cep !== '' ? $cep : null,
            ':logradouro'      => trim((string) ($dados['logradouro'] ?? '')) ?: null,
            ':numero'          => trim((string) ($dados['numero'] ?? '')) ?: null,
            ':complemento'     => trim((string) ($dados['complemento'] ?? '')) ?: null,
            ':bairro'          => trim((string) ($dados['bairro'] ?? '')) ?: null,
            ':cidade'          => trim((string) ($dados['cidade'] ?? '')) ?: null,
            ':uf'              => mb_strtoupper(trim((string) ($dados['uf'] ?? ''))) ?: null,
        ];
    }

    /** Atualiza somente os dados pessoais do cadastro completo (o login e do vinculo: Vinculo::definirLogin). */
    public static function atualizarDadosPessoais(int $idUsuario, array $dados): bool
    {
        $sql = 'UPDATE usuarios SET
                    sexo = :sexo, nome_materno = :nome_materno,
                    data_nascimento = :data_nascimento, telefone_fixo = :telefone_fixo,
                    cep = :cep, logradouro = :logradouro, numero = :numero,
                    complemento = :complemento, bairro = :bairro, cidade = :cidade, uf = :uf
                WHERE id_usuario = :id' . self::comVinculoAqui();

        $consulta = bd()->prepare($sql);
        return $consulta->execute(self::parametrosPessoais($dados) + [':id' => $idUsuario]);
    }

    /** Persiste nome, e-mail e telefone da pessoa. */
    public static function atualizar(int $idUsuario, array $dados): bool
    {
        $email = mb_strtolower(trim((string) ($dados['email'] ?? '')));
        if (self::emailEmUso($email, $idUsuario)) {
            throw new DomainException('Ja existe uma conta cadastrada com este e-mail.');
        }

        $sql = 'UPDATE usuarios SET nome = :nome, email = :email, telefone = :telefone
                WHERE id_usuario = :id' . self::comVinculoAqui();

        $consulta = bd()->prepare($sql);
        return $consulta->execute([
            ':nome'     => $dados['nome'],
            ':email'    => $email,
            ':telefone' => apenasNumeros($dados['telefone'] ?? '') ?: null,
            ':id'       => $idUsuario,
        ]);
    }

    /**
     * A pessoa so tem vinculo nesta empresa? Quando sim, a empresa e a unica
     * dona da conta e o administrador dela pode mexer em nome, e-mail e senha;
     * quando nao, esses dados valem em outras empresas e so a propria pessoa
     * (ou o master) os altera.
     */
    public static function pertenceSoAqui(int $idUsuario): bool
    {
        $q = bd()->prepare('SELECT COUNT(*) FROM vinculos WHERE id_usuario = ? AND id_estabelecimento <> ?');
        $q->execute([$idUsuario, Contexto::id()]);
        return (int) $q->fetchColumn() === 0;
    }

    /** Gera um novo hash antes de substituir a senha armazenada. */
    public static function atualizarSenha(int $idUsuario, string $senhaPura): bool
    {
        $consulta = bd()->prepare('UPDATE usuarios SET senha_hash = :hash WHERE id_usuario = :id' . self::comVinculoAqui());
        return $consulta->execute([
            ':hash' => password_hash($senhaPura, PASSWORD_DEFAULT),
            ':id'   => $idUsuario,
        ]);
    }

    /** Compara a senha fornecida com o hash da conta usando password_verify. */
    public static function senhaConfere(int $idUsuario, string $senha): bool
    {
        $consulta = bd()->prepare('SELECT senha_hash FROM usuarios WHERE id_usuario = :id' . self::comVinculoAqui() . ' LIMIT 1');
        $consulta->execute([':id' => $idUsuario]);
        $registro = $consulta->fetch();

        return $registro && password_verify($senha, $registro['senha_hash']);
    }

    /**
     * Liga ou desliga os vinculos da pessoa nesta empresa (um tipo, ou todos).
     * A pessoa em si nao muda: continua entrando nas outras empresas dela.
     */
    public static function alterarStatus(int $idUsuario, string $status, ?string $tipo = null): bool
    {
        $status = $status === 'ativo' ? 'ativo' : 'inativo';
        $sql = 'UPDATE vinculos SET status = :status WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_usuario = :id';
        $parametros = [':status' => $status, ':id' => $idUsuario];
        if ($tipo !== null) {
            $sql .= ' AND tipo = :tipo';
            $parametros[':tipo'] = $tipo;
        }
        $consulta = bd()->prepare($sql);
        return $consulta->execute($parametros);
    }

    /** Bloqueio global da pessoa: so o master. Desligada, ela nao entra em empresa nenhuma. */
    public static function alterarStatusPessoa(int $idUsuario, string $status): bool
    {
        $status = $status === 'ativo' ? 'ativo' : 'inativo';
        $consulta = bd()->prepare('UPDATE usuarios SET status = :status WHERE id_usuario = :id');
        return $consulta->execute([':status' => $status, ':id' => $idUsuario]);
    }

    /** Atualiza a data do último acesso dos vinculos da pessoa na empresa indicada. */
    public static function registrarAcessoEm(int $idEstabelecimento, int $idUsuario): void
    {
        $consulta = bd()->prepare('UPDATE vinculos SET ultimo_acesso = NOW() WHERE id_estabelecimento = :empresa AND id_usuario = :id');
        $consulta->execute([':empresa' => $idEstabelecimento, ':id' => $idUsuario]);
    }

    /** Retorna o id da tabela especifica do perfil (clientes/profissionais/administradores). */
    public static function idDoPerfil(int $idUsuario, string $tipo): ?int
    {
        return self::idDoPerfilEm(Contexto::id(), $idUsuario, $tipo);
    }

    /** Variante com a empresa explicita: a sessao lembrada abre antes de o Contexto existir. */
    public static function idDoPerfilEm(int $idEstabelecimento, int $idUsuario, string $tipo): ?int
    {
        $mapa = [
            'cliente'      => ['clientes', 'id_cliente'],
            'profissional' => ['profissionais', 'id_profissional'],
            'admin'        => ['administradores', 'id_administrador'],
        ];

        if (!isset($mapa[$tipo])) {
            return null;
        }

        [$tabela, $coluna] = $mapa[$tipo];

        $consulta = bd()->prepare("SELECT {$coluna} FROM {$tabela} WHERE id_estabelecimento = :empresa AND id_usuario = :id LIMIT 1");
        $consulta->execute([':empresa' => $idEstabelecimento, ':id' => $idUsuario]);
        $registro = $consulta->fetch();

        return $registro ? (int) $registro[$coluna] : null;
    }

    // -----------------------------------------------------------------
    // Recuperacao de senha
    // -----------------------------------------------------------------

    /** Gera e grava um token de recuperacao valido por algumas horas. */
    public static function gerarTokenRecuperacao(int $idUsuario, int $validadeHoras = 2): string
    {
        $token = bin2hex(random_bytes(32));

        $consulta = bd()->prepare(
            'UPDATE usuarios
             SET token_recuperacao = :token, token_expiracao = ' . Sql::somarHoras('NOW()', ':horas') . '
             WHERE id_usuario = :id' . self::comVinculoAqui()
        );
        $consulta->execute([':token' => $token, ':horas' => $validadeHoras, ':id' => $idUsuario]);

        return $token;
    }

    /** Busca a conta associada ao token de recuperação ainda válido, com vinculo nesta empresa. */
    public static function porTokenRecuperacao(string $token): ?array
    {
        $usuario = self::selecionar(
            'u.token_recuperacao = :token AND u.token_expiracao > NOW() AND u.status = \'ativo\'',
            [':token' => $token]
        );
        return $usuario !== null && $usuario['status'] === 'ativo' ? $usuario : null;
    }

    /** Invalida o token após a recuperação para impedir sua reutilização. */
    public static function limparTokenRecuperacao(int $idUsuario): void
    {
        $consulta = bd()->prepare(
            'UPDATE usuarios SET token_recuperacao = NULL, token_expiracao = NULL WHERE id_usuario = :id' . self::comVinculoAqui()
        );
        $consulta->execute([':id' => $idUsuario]);
    }

    // -----------------------------------------------------------------
    // Exclusao
    // -----------------------------------------------------------------

    /**
     * Apaga os vinculos da pessoa nesta empresa (perfis e dados dependentes
     * caem em cascata). A pessoa so some se nao restar vinculo em nenhuma
     * outra empresa. Devolve true quando havia o que apagar.
     */
    public static function excluir(int $idUsuario): bool
    {
        $consulta = bd()->prepare('DELETE FROM vinculos WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_usuario = :id');
        $consulta->execute([':id' => $idUsuario]);
        $apagou = $consulta->rowCount() > 0;
        self::apagarSeSemVinculo($idUsuario);
        return $apagou;
    }

    /** Apaga a pessoa que ficou sem nenhum vinculo. Chamado depois de remover vinculos. */
    public static function apagarSeSemVinculo(int $idUsuario): void
    {
        $consulta = bd()->prepare(
            'DELETE FROM usuarios WHERE id_usuario = :id
               AND NOT EXISTS (SELECT 1 FROM vinculos v WHERE v.id_usuario = :id_vinculo)'
        );
        $consulta->execute([':id' => $idUsuario, ':id_vinculo' => $idUsuario]);
    }

    // -----------------------------------------------------------------
    // Segundo fator por codigo (TOTP) — e da pessoa, vale em todas as empresas
    // -----------------------------------------------------------------

    /** Indica se a conta ja concluiu o cadastro do aplicativo autenticador. */
    public static function totpAtivo(?array $usuario): bool
    {
        return !empty($usuario['totp_ativado_em']) && Totp::decifrar($usuario['totp_segredo'] ?? null) !== '';
    }

    /** Segredo aberto da conta, ou string vazia quando nao ha nenhum guardado. */
    public static function totpSegredo(?array $usuario): string
    {
        return Totp::decifrar($usuario['totp_segredo'] ?? null);
    }

    /**
     * Guarda um segredo novo ainda nao confirmado.
     *
     * O cadastro so vale depois que o usuario digita um codigo gerado por ele
     * (totpAtivar). Ate la o segredo fica no banco sem data de ativacao, entao
     * o login continua pedindo a pergunta cadastral: ninguem se tranca fora da
     * conta por ter aberto a tela de cadastro e desistido no meio.
     */
    public static function totpPreparar(int $idUsuario, string $segredo): void
    {
        $consulta = bd()->prepare(
            'UPDATE usuarios
             SET totp_segredo = :segredo, totp_ativado_em = NULL, totp_ultimo_contador = NULL
             WHERE id_usuario = :id' . self::comVinculoAqui()
        );
        $consulta->execute([':segredo' => Totp::cifrar($segredo), ':id' => $idUsuario]);
    }

    /** Confirma o cadastro do aplicativo: a partir daqui o login pede o codigo. */
    public static function totpAtivar(int $idUsuario, int $contadorUsado): void
    {
        $consulta = bd()->prepare(
            'UPDATE usuarios
             SET totp_ativado_em = NOW(), totp_ultimo_contador = :contador
             WHERE id_usuario = :id' . self::comVinculoAqui()
        );
        $consulta->execute([':contador' => $contadorUsado, ':id' => $idUsuario]);
    }

    /** Desliga o codigo e apaga o segredo; a conta volta a pergunta cadastral. */
    public static function totpDesativar(int $idUsuario): void
    {
        $consulta = bd()->prepare(
            'UPDATE usuarios
             SET totp_segredo = NULL, totp_ativado_em = NULL, totp_ultimo_contador = NULL
             WHERE id_usuario = :id' . self::comVinculoAqui()
        );
        $consulta->execute([':id' => $idUsuario]);
    }

    /**
     * Aceita a janela usada apenas se ela for posterior a ultima aproveitada.
     *
     * Sem esta trava, um codigo visto por cima do ombro — ou capturado num
     * computador comprometido — continuaria valendo pelo resto dos 30 segundos
     * e ainda pela janela de tolerancia. Devolve false quando o codigo ja foi
     * usado, e a tela trata como codigo invalido.
     */
    public static function totpContadorUsado(int $idUsuario, array $usuario, int $contador): bool
    {
        $ultimo = $usuario['totp_ultimo_contador'] ?? null;

        if ($ultimo !== null && $contador <= (int) $ultimo) {
            return false;
        }

        $consulta = bd()->prepare(
            'UPDATE usuarios SET totp_ultimo_contador = :contador
             WHERE id_usuario = :id' . self::comVinculoAqui()
        );
        $consulta->execute([':contador' => $contador, ':id' => $idUsuario]);

        return true;
    }

    // ---------------------------------------------------------------------
    // Consulta global (painel master)
    //
    // As buscas acima passam pelo Contexto e so enxergam a empresa da sessao;
    // o master nao tem empresa, e precisa responder "este e-mail e de quem?".
    // A resposta e a lista de vinculos: uma linha por (pessoa, empresa, tipo).
    // ---------------------------------------------------------------------

    /** Monta o WHERE e os parametros da consulta global a partir dos filtros. */
    private static function filtrosGlobais(array $filtros): array
    {
        $condicoes = [];
        $parametros = [];

        $busca = trim((string) ($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $como = Sql::como();
            $condicoes[] = '(u.email ' . $como . ' :busca_email OR u.nome ' . $como . ' :busca_nome OR v.login = :busca_login)';
            $parametros[':busca_email'] = '%' . $busca . '%';
            $parametros[':busca_nome'] = '%' . $busca . '%';
            $parametros[':busca_login'] = mb_strtolower($busca);
        }

        $tipo = (string) ($filtros['tipo'] ?? '');
        if (isset(self::TIPOS[$tipo])) {
            $condicoes[] = 'v.tipo = :tipo';
            $parametros[':tipo'] = $tipo;
        }

        $empresa = (int) ($filtros['estabelecimento'] ?? 0);
        if ($empresa > 0) {
            $condicoes[] = 'v.id_estabelecimento = :estabelecimento';
            $parametros[':estabelecimento'] = $empresa;
        }

        return [$condicoes ? ' WHERE ' . implode(' AND ', $condicoes) : '', $parametros];
    }

    /** Conta os vinculos de todas as empresas que atendem aos filtros. */
    public static function contarGlobal(array $filtros = []): int
    {
        [$where, $parametros] = self::filtrosGlobais($filtros);
        $consulta = bd()->prepare('SELECT COUNT(*) FROM vinculos v JOIN usuarios u ON u.id_usuario = v.id_usuario' . $where);
        $consulta->execute($parametros);
        return (int) $consulta->fetchColumn();
    }

    /**
     * Lista vinculos de qualquer empresa com a pessoa e o estabelecimento ao lado.
     * Nunca devolve hash de senha nem segredos de 2FA.
     */
    public static function buscarGlobal(array $filtros = []): array
    {
        [$where, $parametros] = self::filtrosGlobais($filtros);
        $sql = 'SELECT u.id_usuario, u.nome, u.email, u.telefone, u.data_criacao, u.status AS status_pessoa,
                       v.id_vinculo, v.login, v.tipo, v.status, v.ultimo_acesso, v.id_estabelecimento,
                       e.nome AS estabelecimento_nome, e.slug AS estabelecimento_slug, e.status AS estabelecimento_status
                FROM vinculos v
                JOIN usuarios u ON u.id_usuario = v.id_usuario
                JOIN estabelecimento e ON e.id_estabelecimento = v.id_estabelecimento'
            . $where
            . ' ORDER BY u.email, e.nome, ' . Vinculo::ORDEM_TIPO . ', v.id_vinculo';

        $limite = (int) ($filtros['limite'] ?? 0);
        if ($limite > 0) {
            $sql .= ' LIMIT ' . $limite . ' OFFSET ' . max(0, (int) ($filtros['deslocamento'] ?? 0));
        }

        $consulta = bd()->prepare($sql);
        $consulta->execute($parametros);
        return $consulta->fetchAll();
    }

    /** Todos os vinculos de um e-mail, em qualquer empresa, sem segredos. Resposta direta para "esta conta e de qual estabelecimento?". */
    public static function vinculosPorEmail(string $email): array
    {
        $pessoa = self::pessoaPorEmail($email);
        return $pessoa === null ? [] : Vinculo::daPessoa((int) $pessoa['id_usuario']);
    }
}
