<?php
/**
 * Conformidade: dados do fornecedor, aceite dos termos e retenção de dados.
 *
 * Os dados de quem fornece o serviço (razão social, CNPJ, encarregado de
 * dados) vêm de variáveis de ambiente, para serem preenchidos na hospedagem
 * sem mexer no código. Enquanto não forem preenchidos, as páginas legais
 * mostram "a informar" no lugar.
 */
class Legal
{
    /** Versão dos Termos e da Política. Mude a data quando o texto mudar: o aceite guarda qual versão foi aceita. */
    public const VERSAO_TERMOS = '2026-11-09';

    /** Retenção: registros mais antigos que isto são apagados pela tarefa periódica. */
    public const MESES_LOG_ACESSO = 12;
    public const MESES_NOTIFICACOES = 12;
    public const ANOS_AUDITORIA = 5;

    /** LGPD art. 14: abaixo disto e crianca, e o cadastro cabe ao responsavel. */
    public const IDADE_MINIMA_CADASTRO = 12;

    private const AMBIENTE = [
        'nome'      => 'AGENDEI_FORNECEDOR_NOME',
        'documento' => 'AGENDEI_FORNECEDOR_DOCUMENTO',
        'endereco'  => 'AGENDEI_FORNECEDOR_ENDERECO',
        'email'     => 'AGENDEI_FORNECEDOR_EMAIL',
        'dpo_nome'  => 'AGENDEI_DPO_NOME',
        'dpo_email' => 'AGENDEI_DPO_EMAIL',
    ];

    /** Dados do fornecedor; campo não configurado volta vazio. */
    public static function fornecedor(): array
    {
        $dados = [];
        foreach (self::AMBIENTE as $campo => $variavel) {
            $dados[$campo] = trim((string) (getenv($variavel) ?: ''));
        }
        // Sem encarregado nomeado, o contato de privacidade é o do próprio fornecedor.
        if ($dados['dpo_email'] === '') {
            $dados['dpo_email'] = $dados['email'];
        }
        return $dados;
    }

    /** Valor para exibir: o configurado ou "a informar". */
    public static function campo(string $campo): string
    {
        $valor = self::fornecedor()[$campo] ?? '';
        return $valor !== '' ? $valor : 'a informar';
    }

    /**
     * Guarda na pessoa a versão dos termos aceita e quando. Roda depois de a
     * conta existir: uma falha aqui (coluna ainda não criada) vai para o log
     * e não desfaz o cadastro.
     */
    public static function registrarAceite(string $email): void
    {
        try {
            $q = bd()->prepare('UPDATE usuarios SET termos_versao = ?, termos_aceitos_em = CURRENT_TIMESTAMP WHERE email = ?');
            $q->execute([self::VERSAO_TERMOS, mb_strtolower(trim($email))]);
        } catch (PDOException $erro) {
            error_log('Aceite dos termos não gravado para ' . $email . ': ' . $erro->getMessage());
        }
    }

    /**
     * Apaga o que passou do prazo de retenção, em todas as empresas.
     * As tentativas de acesso e as sessões lembradas já têm limpeza própria
     * (Tentativa e SessaoLembrada). Devolve quantas linhas saíram de cada tabela.
     */
    public static function limparDadosAntigos(): array
    {
        $limites = [
            'logs_autenticacao' => ['data_hora', self::MESES_LOG_ACESSO . ' months', ''],
            'notificacoes'      => ['data_criacao', self::MESES_NOTIFICACOES . ' months', " AND status IN ('enviada','lida','cancelada')"],
            'logs_master'       => ['data_hora', self::ANOS_AUDITORIA . ' years', ''],
        ];

        $apagadas = [];
        foreach ($limites as $tabela => [$coluna, $prazo, $filtro]) {
            $corte = date('Y-m-d H:i:s', strtotime('-' . $prazo));
            try {
                $q = bd()->prepare("DELETE FROM $tabela WHERE $coluna < ?$filtro");
                $q->execute([$corte]);
                $apagadas[$tabela] = $q->rowCount();
            } catch (PDOException $erro) {
                // Tabela ainda inexistente numa instalação antiga: segue para a próxima.
                error_log("Retenção: $tabela não foi limpa: " . $erro->getMessage());
                $apagadas[$tabela] = 0;
            }
        }
        return $apagadas;
    }

    /**
     * Acrescenta as colunas do aceite (usuarios) e do cancelamento (assinaturas).
     * Roda na subida do container (scripts/preparar_runtime.php) e em
     * scripts/migrar.php; não faz nada quando as colunas já existem.
     */
    public static function garantirEstrutura(): void
    {
        Sql::garantirColuna('usuarios', 'termos_versao', 'VARCHAR(20) NULL');
        Sql::garantirColuna('usuarios', 'termos_aceitos_em', Sql::tipoDataHora());
        Sql::garantirColuna('assinaturas', 'data_cancelamento', Sql::tipoDataHora());
        // Consentimento opcional (LGPD art. 8): vale para a relacao com uma empresa, por isso fica no vinculo.
        Sql::garantirColuna('vinculos', 'comunicacoes_aceitas_em', Sql::tipoDataHora());
        // CNPJ da empresa: anulavel para as empresas antigas; o indice unico aceita varios NULL nos dois bancos.
        Sql::garantirColuna('estabelecimento', 'cnpj', 'VARCHAR(14) NULL');
        bd()->exec('CREATE UNIQUE INDEX IF NOT EXISTS uk_estabelecimento_cnpj ON estabelecimento (cnpj)');
    }

    /**
     * Liga ou desliga o consentimento opcional de novidades por e-mail e WhatsApp
     * no vinculo da pessoa com a empresa. Falha (coluna ainda nao criada) vai ao
     * log e nao desfaz o cadastro.
     */
    public static function registrarComunicacoes(int $idEstabelecimento, string $email, string $tipo, bool $aceita): void
    {
        try {
            $q = bd()->prepare('UPDATE vinculos SET comunicacoes_aceitas_em = ' . ($aceita ? 'CURRENT_TIMESTAMP' : 'NULL') . '
                WHERE id_estabelecimento = ? AND tipo = ? AND id_usuario = (SELECT id_usuario FROM usuarios WHERE email = ?)');
            $q->execute([$idEstabelecimento, $tipo, mb_strtolower(trim($email))]);
        } catch (PDOException $erro) {
            error_log('Consentimento de comunicações não gravado para ' . $email . ': ' . $erro->getMessage());
        }
    }

    /** Se a pessoa aceitou receber novidades da empresa (vinculo da sessao). */
    public static function aceitaComunicacoes(int $idVinculo): bool
    {
        try {
            $q = bd()->prepare('SELECT comunicacoes_aceitas_em FROM vinculos WHERE id_vinculo = ?');
            $q->execute([$idVinculo]);
            $aceitoEm = $q->fetchColumn();
            return $aceitoEm !== null && $aceitoEm !== false;
        } catch (PDOException $erro) {
            return false;
        }
    }
}
