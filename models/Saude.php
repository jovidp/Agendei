<?php

/**
 * Diagnostico do sistema para a administracao master.
 *
 * Reune num lugar so as perguntas que hoje so se responde abrindo o servidor:
 * o banco esta respondendo, as tabelas de apoio existem, o instalador ainda
 * esta la, a hospedagem esta atras de um proxy sem avisar o sistema.
 *
 * Cada verificacao devolve um estado — ok, aviso ou erro — e uma frase que
 * explica o que fazer. Nenhuma delas escreve nada: a tela e de leitura, e um
 * diagnostico que altera o sistema nao serve para diagnosticar.
 */
class Saude
{
    public const OK    = 'ok';
    public const AVISO = 'aviso';
    public const ERRO  = 'erro';

    /** Executa todas as verificacoes, agrupadas por assunto. */
    public static function verificacoes(): array
    {
        return array_merge(
            self::banco(),
            self::tabelasDeApoio(),
            self::ambiente(),
            self::email(),
            self::operacao()
        );
    }

    /** Resumo por estado, para os indicadores do topo da tela. */
    public static function resumo(array $verificacoes): array
    {
        $resumo = [self::OK => 0, self::AVISO => 0, self::ERRO => 0];

        foreach ($verificacoes as $item) {
            $estado = $item['estado'] ?? self::OK;
            $resumo[$estado] = ($resumo[$estado] ?? 0) + 1;
        }

        return $resumo;
    }

    // -----------------------------------------------------------------
    // Banco
    // -----------------------------------------------------------------

    private static function banco(): array
    {
        $itens = [];

        try {
            $inicio = microtime(true);
            bd()->query('SELECT 1')->fetchColumn();
            $ms = (int) round((microtime(true) - $inicio) * 1000);

            $versao = (string) bd()->getAttribute(PDO::ATTR_SERVER_VERSION);
            $dialeto = Database::ehPostgres() ? 'PostgreSQL' : 'MySQL/MariaDB';

            $itens[] = self::item(
                'Conexao com o banco',
                // Acima de meio segundo para responder "SELECT 1" o problema nao
                // e a consulta: e a rede ou o servidor do banco.
                $ms > 500 ? self::AVISO : self::OK,
                $dialeto . ' ' . $versao,
                $ms > 500
                    ? 'A resposta levou ' . $ms . ' ms. Verifique a latencia ate o servidor do banco.'
                    : 'Respondeu em ' . $ms . ' ms.'
            );
        } catch (Throwable $erro) {
            $itens[] = self::item('Conexao com o banco', self::ERRO, 'sem resposta', 'O banco nao respondeu a uma consulta simples.');
            return $itens;
        }

        return $itens;
    }

    /**
     * Tabelas que a aplicacao cria sozinha quando falta.
     * Se alguma nao responde, a protecao que depende dela esta desligada.
     */
    private static function tabelasDeApoio(): array
    {
        $tabelas = [
            'logs_master'       => ['Auditoria da administracao', 'As acoes do master nao estao sendo registradas. Rode scripts/migrar_seguranca.php.'],
            'tentativas_acesso' => ['Controle de forca bruta', 'A contagem caiu para arquivos no diretorio temporario, que nao serve quando ha mais de uma instancia.'],
            'logs_autenticacao' => ['Log de autenticacao', 'O historico de acessos nao esta sendo gravado. Importe o esquema atualizado.'],
        ];

        $itens = [];

        foreach ($tabelas as $tabela => [$rotulo, $mensagemFalha]) {
            try {
                $total = (int) bd()->query('SELECT COUNT(*) FROM ' . $tabela)->fetchColumn();
                $itens[] = self::item($rotulo, self::OK, number_format($total, 0, ',', '.') . ' registro(s)', 'Tabela ' . $tabela . ' respondendo.');
            } catch (Throwable $erro) {
                $itens[] = self::item($rotulo, self::ERRO, 'indisponivel', $mensagemFalha);
            }
        }

        return $itens;
    }

    // -----------------------------------------------------------------
    // Ambiente e hospedagem
    // -----------------------------------------------------------------

    private static function ambiente(): array
    {
        $itens = [];

        $itens[] = self::item(
            'Modo de execucao',
            AMBIENTE === 'producao' ? self::OK : self::AVISO,
            AMBIENTE,
            AMBIENTE === 'producao'
                ? 'Erros vao para o log e nao aparecem na tela.'
                : 'Em desenvolvimento o rastro do erro aparece na tela. Nao publique assim.'
        );

        $itens[] = self::item(
            'Versao do PHP',
            version_compare(PHP_VERSION, '8.1', '>=') ? self::OK : self::ERRO,
            PHP_VERSION,
            version_compare(PHP_VERSION, '8.1', '>=')
                ? 'Compativel com o codigo do projeto.'
                : 'O projeto usa recursos do PHP 8.1 em diante.'
        );

        $extensaoBanco = Database::ehPostgres() ? 'pdo_pgsql' : 'pdo_mysql';
        $faltando = array_values(array_filter(
            [$extensaoBanco, 'mbstring', 'openssl'],
            static fn (string $extensao): bool => !extension_loaded($extensao)
        ));

        $itens[] = self::item(
            'Extensoes do PHP',
            $faltando === [] ? self::OK : self::ERRO,
            $faltando === [] ? 'completas' : 'faltam ' . implode(', ', $faltando),
            $faltando === []
                ? 'PDO do banco em uso, mbstring e openssl carregados.'
                : 'Sem essas extensoes partes do sistema deixam de funcionar.'
        );

        // Sob HTTPS o cookie de sessao vai como Secure e o HSTS e anunciado.
        // Em CLI nao ha requisicao: a verificacao se abstem.
        if (PHP_SAPI !== 'cli') {
            $seguro = requisicaoSegura();
            $itens[] = self::item(
                'HTTPS',
                $seguro ? self::OK : self::AVISO,
                $seguro ? 'ativo' : 'inativo',
                $seguro
                    ? 'Cookie de sessao marcado como Secure.'
                    : 'Sem HTTPS a sessao viaja sem protecao de transporte. Vale so para ambiente local.'
            );

            // Atras de um proxy, REMOTE_ADDR e sempre o mesmo endereco interno:
            // sem a variavel, um unico visitante mal-intencionado bloqueia todos.
            $atrasDeProxy = !empty($_SERVER['HTTP_X_FORWARDED_FOR']);
            $confia = filter_var(getenv('AGENDEI_PROXY_CONFIAVEL') ?: '', FILTER_VALIDATE_BOOLEAN);

            if ($atrasDeProxy || $confia) {
                $itens[] = self::item(
                    'Identificacao da origem',
                    $atrasDeProxy && !$confia ? self::AVISO : self::OK,
                    $confia ? 'proxy confiavel ligado' : 'proxy detectado',
                    $atrasDeProxy && !$confia
                        ? 'A requisicao chega por proxy, mas AGENDEI_PROXY_CONFIAVEL esta desligada: o bloqueio por origem vai contar todo mundo como o mesmo IP.'
                        : 'O endereco do visitante e lido do cabecalho escrito pelo proxy.'
                );
            }
        }

        // O contador de tentativas cai para arquivo quando o banco recusa DDL.
        $temp = sys_get_temp_dir();
        $itens[] = self::item(
            'Diretorio temporario',
            is_writable($temp) ? self::OK : self::AVISO,
            $temp,
            is_writable($temp)
                ? 'Serve de reserva para o controle de forca bruta.'
                : 'Sem escrita aqui, a reserva do controle de forca bruta nao funciona.'
        );

        return $itens;
    }

    // -----------------------------------------------------------------
    // E-mail
    // -----------------------------------------------------------------

    private static function email(): array
    {
        $config = Email::configuracao();
        $meio = Email::meio();

        return [self::item(
            'Envio de e-mail',
            $meio !== '' ? self::OK : self::AVISO,
            match ($meio) {
                'api'   => 'API Brevo (HTTPS)',
                'smtp'  => $config['host'] . ':' . $config['porta'] . ' (' . $config['seguranca'] . ')',
                default => 'nao configurado',
            },
            $meio !== ''
                ? 'Cadastro de empresa, aprovacao e recuperacao de senha avisam por e-mail a partir de ' . $config['remetente'] . '. Use o teste abaixo para confirmar a entrega.'
                : 'Defina AGENDEI_EMAIL_API_CHAVE e AGENDEI_EMAIL_REMETENTE (Brevo, funciona no Render) ou AGENDEI_EMAIL_HOST, _USUARIO e _SENHA (SMTP). Sem isso, os avisos ficam por sua conta e a recuperacao de senha so funciona em desenvolvimento.'
        )];
    }

    // -----------------------------------------------------------------
    // Operacao
    // -----------------------------------------------------------------

    private static function operacao(): array
    {
        $itens = [];

        $masters = Master::contarAtivos();
        $itens[] = self::item(
            'Contas master ativas',
            $masters >= 2 ? self::OK : ($masters === 1 ? self::AVISO : self::ERRO),
            (string) $masters,
            match (true) {
                $masters >= 2 => 'Ha mais de uma pessoa capaz de administrar a plataforma.',
                $masters === 1 => 'Com uma conta so, perder a senha deixa a plataforma sem administracao.',
                default => 'Nenhuma conta master ativa: ninguem consegue administrar a plataforma.',
            }
        );

        // O instalador cria contas e carga inicial. Ele ja se esconde em
        // producao depois da instalacao, mas o arquivo presente continua sendo
        // superficie que nao precisa existir num servidor publicado.
        if (is_file(RAIZ . '/instalar.php')) {
            $itens[] = self::item(
                'Instalador',
                AMBIENTE === 'producao' ? self::AVISO : self::OK,
                'presente',
                AMBIENTE === 'producao'
                    ? 'Em producao ele responde 404 depois da instalacao, mas o ideal e apagar instalar.php do servidor.'
                    : 'Util enquanto o ambiente e de desenvolvimento.'
            );
        }

        try {
            $sql = 'SELECT COUNT(*) FROM estabelecimento e
                    WHERE e.status = \'ativo\'
                      AND NOT EXISTS (SELECT 1 FROM usuarios u
                                       WHERE u.id_estabelecimento = e.id_estabelecimento
                                         AND u.tipo = \'admin\' AND u.status = \'ativo\')';
            $semAdmin = (int) bd()->query($sql)->fetchColumn();

            $itens[] = self::item(
                'Empresas sem administrador',
                $semAdmin === 0 ? self::OK : self::ERRO,
                (string) $semAdmin,
                $semAdmin === 0
                    ? 'Toda empresa ativa tem quem abra o painel dela.'
                    : 'Ha empresa ativa sem nenhuma conta administrativa ativa: ninguem consegue entrar no painel.'
            );
        } catch (Throwable $erro) {
            $itens[] = self::item('Empresas sem administrador', self::AVISO, 'nao verificado', 'A consulta falhou: ' . $erro->getMessage());
        }

        return $itens;
    }

    // -----------------------------------------------------------------

    /** Monta uma linha do diagnostico. */
    private static function item(string $nome, string $estado, string $valor, string $detalhe): array
    {
        return ['nome' => $nome, 'estado' => $estado, 'valor' => $valor, 'detalhe' => $detalhe];
    }

    /** Rotulo legivel do estado, usado na tela. */
    public static function estadoTexto(string $estado): string
    {
        return match ($estado) {
            self::OK    => 'Tudo certo',
            self::AVISO => 'Atencao',
            default     => 'Problema',
        };
    }
}
