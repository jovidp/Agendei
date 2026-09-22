<?php
/**
 * Dados institucionais da empresa selecionada na requisição.
 */
class Estabelecimento
{
    /** Lê o cadastro institucional e reutiliza o resultado durante a requisição. */
    public static function dados(): array
    {
        return Contexto::dados();
    }

    /** Obtém um campo do estabelecimento com valor padrão quando necessário. */
    public static function campo(string $campo, string $padrao = ''): string
    {
        $dados = self::dados();
        $valor = $dados[$campo] ?? null;
        return ($valor === null || $valor === '') ? $padrao : (string) $valor;
    }

    /** Persiste os campos editáveis do cadastro identificado pelo ID. */
    public static function atualizar(array $dados): bool
    {
        $atual = self::dados();

        $campos = [
            'nome', 'slogan', 'descricao', 'telefone', 'whatsapp', 'email',
            'endereco', 'bairro', 'cidade', 'uf', 'cep', 'horario_funcionamento',
            'instagram', 'facebook',
        ];

        $valores = [];
        foreach ($campos as $campo) {
            $valores[':' . $campo] = $dados[$campo] ?? null;
        }

        if (empty($atual['id_estabelecimento'])) {
            $sql = 'INSERT INTO estabelecimento (' . implode(', ', $campos) . ')
                    VALUES (' . implode(', ', array_map(fn ($c) => ':' . $c, $campos)) . ')';
            $consulta = bd()->prepare($sql);
            $resultado = $consulta->execute($valores);
        } else {
            $atribuicoes = implode(', ', array_map(fn ($c) => "{$c} = :{$c}", $campos));
            $consulta = bd()->prepare("UPDATE estabelecimento SET {$atribuicoes} WHERE id_estabelecimento = :id");
            $valores[':id'] = $atual['id_estabelecimento'];
            $resultado = $consulta->execute($valores);
        }

        Contexto::recarregar();
        return $resultado;
    }

    /** Salva apenas a identidade da empresa da sessão; o formulário não escolhe outro ID. */
    public static function personalizar(string $nome, array $tema, ?string $logo): void
    {
        $q = bd()->prepare('UPDATE estabelecimento SET nome = ?, cor_primaria = ?, cor_secundaria = ?, cor_fundo = ?, fonte = ?, logo = ? WHERE id_estabelecimento = ?');
        $q->execute([$nome, $tema['cor_primaria'], $tema['cor_secundaria'], $tema['cor_fundo'], $tema['fonte'], $logo, Contexto::id()]);
        Contexto::recarregar();
    }

    /** Cria uma empresa e sua primeira conta administrativa de forma atômica. */
    public static function contratar(array $dados): int
    {
        $db = bd();
        $db->beginTransaction();
        try {
            $q = $db->prepare('INSERT INTO estabelecimento (nome, slug) VALUES (?, ?)');
            $q->execute([$dados['estabelecimento'], $dados['slug']]);
            $id = (int) $db->lastInsertId();
            $q = $db->prepare('INSERT INTO usuarios (id_estabelecimento, nome, email, senha_hash, tipo) VALUES (?, ?, ?, ?, \'admin\')');
            $q->execute([$id, $dados['nome'], $dados['email'], password_hash($dados['senha'], PASSWORD_DEFAULT)]);
            $usuario = (int) $db->lastInsertId();
            $q = $db->prepare('INSERT INTO administradores (id_estabelecimento, id_usuario, nivel) VALUES (?, ?, \'super\')');
            $q->execute([$id, $usuario]);
            $db->commit();
            return $id;
        } catch (Throwable $erro) {
            if ($db->inTransaction()) $db->rollBack();
            throw $erro;
        }
    }

    /** Lista as empresas para a administração global, com totais isolados por vínculo. */
    public static function listarTodos(): array
    {
        $sql = 'SELECT e.*,
                       (SELECT COUNT(*) FROM usuarios u WHERE u.id_estabelecimento = e.id_estabelecimento AND u.tipo = \'admin\' AND u.status = \'ativo\') AS admins_ativos,
                       (SELECT COUNT(*) FROM clientes c WHERE c.id_estabelecimento = e.id_estabelecimento) AS total_clientes,
                       (SELECT COUNT(*) FROM profissionais p WHERE p.id_estabelecimento = e.id_estabelecimento) AS total_profissionais,
                       (SELECT COUNT(*) FROM servicos s WHERE s.id_estabelecimento = e.id_estabelecimento) AS total_servicos
                FROM estabelecimento e ORDER BY e.nome';
        return bd()->query($sql)->fetchAll();
    }

    /**
     * Uso real de cada empresa, para a administracao global.
     *
     * A listagem comum conta cadastro: quantos clientes, quantos servicos. Isso
     * nao diz se a empresa esta viva — uma base cadastrada em janeiro e
     * abandonada em fevereiro continua "grande". Aqui o que conta e movimento:
     * agendamentos criados na janela, clientes novos e a ultima vez que alguem
     * da equipe entrou no sistema.
     */
    public static function uso(int $dias = 30): array
    {
        $corte = date('Y-m-d H:i:s', strtotime('-' . max(1, $dias) . ' days'));

        $sql = 'SELECT e.id_estabelecimento, e.nome, e.slug, e.status,
                       (SELECT COUNT(*) FROM agendamentos a
                         WHERE a.id_estabelecimento = e.id_estabelecimento) AS agendamentos_total,
                       (SELECT COUNT(*) FROM agendamentos a
                         WHERE a.id_estabelecimento = e.id_estabelecimento
                           AND a.data_criacao > :corte) AS agendamentos_periodo,
                       (SELECT COUNT(*) FROM agendamentos a
                         WHERE a.id_estabelecimento = e.id_estabelecimento
                           AND a.data_criacao > :corteCancelados
                           AND a.status = \'cancelado\') AS cancelados_periodo,
                       (SELECT COUNT(*) FROM clientes c
                         WHERE c.id_estabelecimento = e.id_estabelecimento
                           AND c.data_cadastro > :corteClientes) AS clientes_novos,
                       (SELECT COUNT(*) FROM clientes c
                         WHERE c.id_estabelecimento = e.id_estabelecimento) AS clientes_total,
                       (SELECT MAX(u.ultimo_acesso) FROM usuarios u
                         WHERE u.id_estabelecimento = e.id_estabelecimento) AS ultimo_acesso
                FROM estabelecimento e
                ORDER BY e.nome';

        $consulta = bd()->prepare($sql);
        $consulta->execute([
            ':corte'           => $corte,
            ':corteCancelados' => $corte,
            ':corteClientes'   => $corte,
        ]);

        return $consulta->fetchAll();
    }

    public static function porIdGlobal(int $id): ?array
    {
        $q = bd()->prepare('SELECT * FROM estabelecimento WHERE id_estabelecimento = ? LIMIT 1');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    public static function alterarStatusGlobal(int $id, string $status): bool
    {
        $q = bd()->prepare('UPDATE estabelecimento SET status = ? WHERE id_estabelecimento = ?');
        return $q->execute([$status === 'ativo' ? 'ativo' : 'inativo', $id]);
    }

    /** Exclui a empresa e seus dados em uma unica transacao, preservando a auditoria master. */
    public static function excluirGlobal(int $id): bool
    {
        $db = bd();
        $db->beginTransaction();
        try {
            // Serializa exclusoes da mesma empresa ate o commit.
            $q = $db->prepare('UPDATE estabelecimento SET nome = nome WHERE id_estabelecimento = ?');
            $q->execute([$id]);
            if (!self::porIdGlobal($id)) {
                $db->rollBack();
                return false;
            }

            // Filhos antes dos pais: as FKs de empresa nao usam ON DELETE CASCADE.
            foreach ([
                'notificacoes', 'pagamentos', 'fidelidade_movimentos', 'avaliacoes',
                'cliente_pacotes', 'pacotes', 'lista_espera', 'agendamentos',
                'bloqueios_agenda', 'horarios_profissionais', 'profissional_servico',
                'administradores', 'clientes', 'profissionais', 'servicos',
                // filiais so depois de profissionais e agendamentos, que a referenciam.
                'filiais',
                'logs_autenticacao', 'usuarios', 'configuracoes', 'estabelecimento_plano',
            ] as $tabela) {
                $q = $db->prepare('DELETE FROM ' . $tabela . ' WHERE id_estabelecimento = ?');
                $q->execute([$id]);
            }
            $q = $db->prepare('DELETE FROM estabelecimento WHERE id_estabelecimento = ?');
            $q->execute([$id]);
            $db->commit();
            return true;
        } catch (Throwable $erro) {
            if ($db->inTransaction()) $db->rollBack();
            throw $erro;
        }
    }

    /** Retorna os administradores da empresa sem expor hashes de senha. */
    public static function administradores(int $id): array
    {
        $q = bd()->prepare('SELECT u.id_usuario, u.nome, u.email, u.status, u.ultimo_acesso
                            FROM usuarios u WHERE u.id_estabelecimento = ? AND u.tipo = \'admin\' ORDER BY u.nome');
        $q->execute([$id]);
        return $q->fetchAll();
    }

    /** Cria uma conta administrativa adicional dentro da empresa indicada pelo master. */
    public static function criarAdministrador(int $id, array $dados): int
    {
        if (!self::porIdGlobal($id)) throw new InvalidArgumentException('Estabelecimento não encontrado.');
        $db = bd();
        $db->beginTransaction();
        try {
            $q = $db->prepare('INSERT INTO usuarios (id_estabelecimento, nome, email, senha_hash, tipo) VALUES (?, ?, ?, ?, \'admin\')');
            $q->execute([$id, $dados['nome'], mb_strtolower($dados['email']), password_hash($dados['senha'], PASSWORD_DEFAULT)]);
            $usuario = (int) $db->lastInsertId();
            $q = $db->prepare('INSERT INTO administradores (id_estabelecimento, id_usuario, nivel) VALUES (?, ?, \'super\')');
            $q->execute([$id, $usuario]);
            $db->commit();
            return $usuario;
        } catch (Throwable $erro) {
            if ($db->inTransaction()) $db->rollBack();
            throw $erro;
        }
    }

    // -----------------------------------------------------------------
    // Manutencao das contas administrativas pelo master
    //
    // Os metodos de Usuario:: filtram por Contexto::id(), que na area master
    // vale zero — nenhum deles serve aqui. Por isso estas consultas recebem o
    // id da empresa explicitamente e o repetem no WHERE: e o mesmo isolamento
    // do resto do sistema, so que declarado no lugar de herdado da sessao.
    // -----------------------------------------------------------------

    /** Le uma conta administrativa garantindo que ela pertence a empresa informada. */
    public static function administrador(int $id, int $idUsuario): ?array
    {
        $q = bd()->prepare('SELECT id_usuario, id_estabelecimento, nome, email, login, tipo, status, ultimo_acesso
                            FROM usuarios
                            WHERE id_estabelecimento = ? AND id_usuario = ? AND tipo = \'admin\' LIMIT 1');
        $q->execute([$id, $idUsuario]);
        return $q->fetch() ?: null;
    }

    /**
     * ID do registro em administradores, consultado pela empresa informada.
     * Usuario::idDoPerfil() nao serve aqui: ele filtra pelo Contexto, que na
     * area master nao aponta para empresa nenhuma.
     */
    public static function idAdministrador(int $id, int $idUsuario): ?int
    {
        $q = bd()->prepare('SELECT id_administrador FROM administradores
                            WHERE id_estabelecimento = ? AND id_usuario = ? LIMIT 1');
        $q->execute([$id, $idUsuario]);
        $registro = $q->fetch();
        return $registro ? (int) $registro['id_administrador'] : null;
    }

    /** Quantas contas administrativas da empresa continuam podendo entrar. */
    public static function administradoresAtivos(int $id, ?int $ignorarIdUsuario = null): int
    {
        $sql = 'SELECT COUNT(*) FROM usuarios
                WHERE id_estabelecimento = ? AND tipo = \'admin\' AND status = \'ativo\'';
        $parametros = [$id];

        if ($ignorarIdUsuario !== null) {
            $sql .= ' AND id_usuario <> ?';
            $parametros[] = $ignorarIdUsuario;
        }

        $q = bd()->prepare($sql);
        $q->execute($parametros);
        return (int) $q->fetchColumn();
    }

    /**
     * Redefine a senha de um administrador local.
     *
     * O token de recuperacao pendente e descartado junto: se alguem pediu
     * "esqueci minha senha" e o master atendeu por outro caminho, o link
     * antigo nao pode continuar valendo.
     */
    public static function definirSenhaAdministrador(int $id, int $idUsuario, string $senha): bool
    {
        $q = bd()->prepare('UPDATE usuarios
                            SET senha_hash = ?, token_recuperacao = NULL, token_expiracao = NULL
                            WHERE id_estabelecimento = ? AND id_usuario = ? AND tipo = \'admin\'');
        return $q->execute([password_hash($senha, PASSWORD_DEFAULT), $id, $idUsuario]);
    }

    /**
     * Liga ou desliga o acesso de um administrador local.
     *
     * Desligar o ultimo administrador ativo deixaria a empresa sem ninguem
     * capaz de abrir o proprio painel, entao a operacao e recusada.
     */
    public static function alterarStatusAdministrador(int $id, int $idUsuario, string $status): bool
    {
        $status = $status === 'ativo' ? 'ativo' : 'inativo';

        if ($status === 'inativo' && self::administradoresAtivos($id, $idUsuario) === 0) {
            return false;
        }

        $q = bd()->prepare('UPDATE usuarios SET status = ?
                            WHERE id_estabelecimento = ? AND id_usuario = ? AND tipo = \'admin\'');
        return $q->execute([$status, $id, $idUsuario]);
    }

    /** Endereco em linha unica para exibicao. */
    public static function enderecoCompleto(): string
    {
        $dados = self::dados();
        $partes = array_filter([
            $dados['endereco'] ?? '',
            $dados['bairro'] ?? '',
            trim(($dados['cidade'] ?? '') . (!empty($dados['uf']) ? ' - ' . $dados['uf'] : '')),
            $dados['cep'] ?? '',
        ]);

        return implode(', ', $partes);
    }
}
