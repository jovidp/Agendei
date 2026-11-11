<?php

/**
 * Menu do bot de WhatsApp que cada estabelecimento personaliza: textos
 * principais, opcoes embutidas (ligar, renomear, ordem) e opcoes proprias
 * com resposta pronta. Sem personalizacao, o menu sai igual ao de antes.
 *
 * O numero de cada opcao e a posicao dela entre as ligadas. Quem nao tem
 * cadastro nao ve as opcoes que pedem conta, mas as outras mantem o mesmo
 * numero (como "4. Servicos / 5. Equipe" no menu de antes).
 */
class BotMenu
{
    public const TIPOS_EMBUTIDOS = ['marcar', 'meus', 'cancelar', 'servicos', 'equipe'];
    /** Opcoes que so fazem sentido para quem tem cadastro. */
    private const PEDEM_CADASTRO = ['marcar', 'meus', 'cancelar'];
    public const MAX_OPCOES = 12;

    private const PADRAO = [
        'marcar'   => 'Marcar horário',
        'meus'     => 'Meus agendamentos',
        'cancelar' => 'Cancelar um agendamento',
        'servicos' => 'Serviços e preços',
        'equipe'   => 'Falar com a equipe',
    ];

    /** Textos editaveis e o padrao de cada um (vazio na configuracao = padrao). */
    public const TEXTOS = [
        'boas_vindas'  => 'Olá, {nome}! Sou o assistente virtual de *{empresa}*. Responda com o número:',
        'sem_cadastro' => "Olá! Sou o assistente virtual de *{empresa}*.\n\nNão encontrei um cadastro com este número. Para marcar horário por aqui, crie sua conta usando este mesmo celular:\n{link_cadastro}\n\nEnquanto isso, responda com o número:",
        'equipe'       => 'Certo! Avisei a equipe, e alguém vai te responder por aqui. Quando quiser falar com o assistente de novo, mande *menu*.',
        'nao_entendi'  => 'Não entendi. Responda com um dos números do menu.',
    ];

    public const ROTULOS_TEXTOS = [
        'boas_vindas'  => 'Boas-vindas (cliente com cadastro)',
        'sem_cadastro' => 'Boas-vindas (número sem cadastro)',
        'equipe'       => 'Resposta de "Falar com a equipe"',
        'nao_entendi'  => 'Quando o cliente manda algo que não está no menu',
    ];

    // -----------------------------------------------------------------
    // Textos
    // -----------------------------------------------------------------

    /** Texto da empresa (ou o padrao) com {nome}, {empresa} e {link_cadastro} trocados. */
    public static function texto(string $chave, array $variaveis): string
    {
        $valor = Configuracao::obter('bot_texto_' . $chave);
        if (trim($valor) === '') $valor = self::TEXTOS[$chave] ?? '';
        $trocas = [];
        foreach ($variaveis as $nome => $conteudo) $trocas['{' . $nome . '}'] = (string) $conteudo;
        return strtr($valor, $trocas);
    }

    /** Grava o texto; vazio volta ao padrao. */
    public static function definirTexto(string $chave, string $valor): void
    {
        if (!isset(self::TEXTOS[$chave])) {
            throw new InvalidArgumentException('Texto do bot desconhecido.');
        }
        $valor = trim(str_replace("\r\n", "\n", $valor));
        if (mb_strlen($valor) > 1000) {
            throw new InvalidArgumentException('Cada texto do bot pode ter até 1.000 caracteres.');
        }
        Configuracao::definir('bot_texto_' . $chave, $valor, 'Texto do bot de WhatsApp');
    }

    // -----------------------------------------------------------------
    // Opcoes
    // -----------------------------------------------------------------

    /** Cria as cinco opcoes embutidas na primeira vez que a empresa usa o bot. */
    public static function garantirPadrao(): void
    {
        if (self::contar() > 0) return;
        $db = bd();
        $db->beginTransaction();
        try {
            // Trava a empresa: duas mensagens chegando juntas nao criam o padrao duas vezes.
            $db->prepare('SELECT id_estabelecimento FROM estabelecimento WHERE id_estabelecimento = ? FOR UPDATE')->execute([Contexto::id()]);
            if (self::contar() === 0) {
                $q = $db->prepare('INSERT INTO bot_opcoes (id_estabelecimento, tipo, rotulo, ordem) VALUES (?, ?, ?, ?)');
                $ordem = 0;
                foreach (self::PADRAO as $tipo => $rotulo) {
                    $q->execute([Contexto::id(), $tipo, $rotulo, ++$ordem]);
                }
            }
            $db->commit();
        } catch (Throwable $erro) {
            if ($db->inTransaction()) $db->rollBack();
            throw $erro;
        }
    }

    /** Todas as opcoes da empresa, na ordem do menu. */
    public static function opcoes(): array
    {
        self::garantirPadrao();
        $lista = bd()->query('SELECT * FROM bot_opcoes WHERE id_estabelecimento = ' . Contexto::id() . ' ORDER BY ordem, id_bot_opcao')->fetchAll();
        foreach ($lista as &$opcao) $opcao['ativo'] = (int) $opcao['ativo'];
        return $lista;
    }

    /** Opcoes que o cliente ve, cada uma com o 'numero' que ele responde. */
    public static function visiveis(bool $comCadastro): array
    {
        $lista = [];
        $numero = 0;
        foreach (self::opcoes() as $opcao) {
            if (!$opcao['ativo']) continue;
            $numero++;
            if (!$comCadastro && in_array($opcao['tipo'], self::PEDEM_CADASTRO, true)) continue;
            $lista[] = $opcao + ['numero' => $numero];
        }
        return $lista;
    }

    /** Numero que o cliente responde para a opcao embutida $tipo, ou null se ele nao a ve. */
    public static function numeroDe(string $tipo, bool $comCadastro): ?int
    {
        foreach (self::visiveis($comCadastro) as $opcao) {
            if ($opcao['tipo'] === $tipo) return $opcao['numero'];
        }
        return null;
    }

    /** A opcao que o numero respondido escolhe, ou null. */
    public static function escolha(int $numero, bool $comCadastro): ?array
    {
        foreach (self::visiveis($comCadastro) as $opcao) {
            if ($opcao['numero'] === $numero) return $opcao;
        }
        return null;
    }

    /** Menu pronto para enviar. */
    public static function menu(bool $comCadastro, array $variaveis): string
    {
        $linhas = array_map(fn($o) => $o['numero'] . '. ' . $o['rotulo'], self::visiveis($comCadastro));
        if ($comCadastro) {
            return self::texto('boas_vindas', $variaveis) . "\n\n"
                . ($linhas === [] ? 'Mande *menu* a qualquer momento.' : implode("\n", $linhas) . "\n\nA qualquer momento, mande *menu* para voltar aqui.");
        }
        return self::texto('sem_cadastro', $variaveis)
            . ($linhas === [] ? "\n\nMande *menu* a qualquer momento." : "\n" . implode("\n", $linhas));
    }

    /** Nova opcao propria com resposta pronta, no fim do menu. */
    public static function criar(string $rotulo, string $resposta): int
    {
        $rotulo = self::validarRotulo($rotulo);
        $resposta = self::validarResposta($resposta);
        if (count(self::opcoes()) >= self::MAX_OPCOES) {
            throw new InvalidArgumentException('O menu pode ter até ' . self::MAX_OPCOES . ' opções.');
        }
        $ordem = (int) bd()->query('SELECT COALESCE(MAX(ordem), 0) FROM bot_opcoes WHERE id_estabelecimento = ' . Contexto::id())->fetchColumn() + 1;
        bd()->prepare("INSERT INTO bot_opcoes (id_estabelecimento, tipo, rotulo, resposta, ordem) VALUES (?, 'resposta', ?, ?, ?)")
            ->execute([Contexto::id(), $rotulo, $resposta, $ordem]);
        return (int) bd()->lastInsertId();
    }

    /** Renomeia; a resposta so vale para opcao propria (null mantem a atual). */
    public static function atualizar(int $id, string $rotulo, ?string $resposta): void
    {
        $opcao = self::porId($id);
        $rotulo = self::validarRotulo($rotulo);
        $resposta = $opcao['tipo'] === 'resposta' && $resposta !== null ? self::validarResposta($resposta) : $opcao['resposta'];
        bd()->prepare('UPDATE bot_opcoes SET rotulo = ?, resposta = ? WHERE id_estabelecimento = ? AND id_bot_opcao = ?')
            ->execute([$rotulo, $resposta, Contexto::id(), $id]);
    }

    public static function alternar(int $id): void
    {
        $opcao = self::porId($id);
        bd()->prepare('UPDATE bot_opcoes SET ativo = ? WHERE id_estabelecimento = ? AND id_bot_opcao = ?')
            ->execute([$opcao['ativo'] ? 0 : 1, Contexto::id(), $id]);
    }

    /** Sobe (-1) ou desce (+1) uma posicao; na ponta, nao muda. */
    public static function mover(int $id, int $direcao): void
    {
        self::porId($id);
        $ids = array_map(fn($o) => (int) $o['id_bot_opcao'], self::opcoes());
        $i = array_search($id, $ids, true);
        $j = $i + ($direcao < 0 ? -1 : 1);
        if (!isset($ids[$j])) return;
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        // Regrava a ordem inteira: corrige de passagem ordens repetidas.
        $q = bd()->prepare('UPDATE bot_opcoes SET ordem = ? WHERE id_estabelecimento = ? AND id_bot_opcao = ?');
        foreach ($ids as $posicao => $idOpcao) $q->execute([$posicao + 1, Contexto::id(), $idOpcao]);
    }

    /** Apaga opcao propria; as embutidas so se desligam. */
    public static function apagar(int $id): void
    {
        if (self::porId($id)['tipo'] !== 'resposta') {
            throw new DomainException('As opções que marcam, consultam ou cancelam não podem ser apagadas, só desligadas.');
        }
        bd()->prepare('DELETE FROM bot_opcoes WHERE id_estabelecimento = ? AND id_bot_opcao = ?')->execute([Contexto::id(), $id]);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private static function contar(): int
    {
        return (int) bd()->query('SELECT COUNT(*) FROM bot_opcoes WHERE id_estabelecimento = ' . Contexto::id())->fetchColumn();
    }

    private static function porId(int $id): array
    {
        $q = bd()->prepare('SELECT * FROM bot_opcoes WHERE id_estabelecimento = ? AND id_bot_opcao = ?');
        $q->execute([Contexto::id(), $id]);
        $opcao = $q->fetch();
        if (!$opcao) {
            throw new InvalidArgumentException('Opção do menu não encontrada.');
        }
        $opcao['ativo'] = (int) $opcao['ativo'];
        return $opcao;
    }

    private static function validarRotulo(string $rotulo): string
    {
        $rotulo = trim(preg_replace('/\s+/', ' ', $rotulo));
        if ($rotulo === '' || mb_strlen($rotulo) > 60) {
            throw new InvalidArgumentException('O nome da opção é obrigatório e pode ter até 60 caracteres.');
        }
        return $rotulo;
    }

    private static function validarResposta(string $resposta): string
    {
        $resposta = trim(str_replace("\r\n", "\n", $resposta));
        if ($resposta === '' || mb_strlen($resposta) > 1000) {
            throw new InvalidArgumentException('A resposta da opção é obrigatória e pode ter até 1.000 caracteres.');
        }
        return $resposta;
    }
}
