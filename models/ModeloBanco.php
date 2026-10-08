<?php
/** Documentação do esquema versionado; nunca executa SQL nem lê dados de clientes. */
class ModeloBanco
{
    public static function carregar(string $dialeto = 'mysql'): array
    {
        $arquivo = $dialeto === 'pgsql' ? 'banco_postgres.sql' : 'banco.sql';
        $sql = file_get_contents(dirname(__DIR__) . '/' . $arquivo);
        if ($sql === false) {
            throw new RuntimeException('Não foi possível ler o esquema do banco.');
        }
        // Os esquemas acrescentam filiais por ALTER TABLE depois das tabelas
        // principais. Incorpora essas colunas e FKs ao dicionario tambem.
        preg_match_all('/^ALTER TABLE `?(\w+)`?\s+ADD\s+(?:COLUMN\s+)?([^;]+);/mi', $sql, $alteracoes, PREG_SET_ORDER);
        foreach ($alteracoes as $alteracao) {
            $definicao = preg_replace('/^\s*ADD\s+(?:COLUMN\s+)?/mi', '  ', trim($alteracao[2]));
            $padrao = '/(^CREATE TABLE `?' . preg_quote($alteracao[1], '/') . '`? \\(\\R)(.*?)(^[\\t ]*\\)[^;]*;)/ms';
            $sql = preg_replace_callback($padrao, static fn(array $m): string =>
                $m[1] . rtrim($m[2]) . ",\n  " . $definicao . "\n" . $m[3], $sql);
        }
        // Leitor limitado ao formato dos scripts do projeto: uma definição por linha.
        preg_match_all('/^CREATE TABLE `?(\w+)`? \(\R(.*?)^[\t ]*\)[^;]*;/ms', $sql, $blocos, PREG_SET_ORDER);
        $tabelas = [];
        foreach ($blocos as $bloco) {
            $tabela = ['nome' => $bloco[1], 'colunas' => [], 'pk' => [], 'uk' => [], 'fks' => [], 'restricoes' => [], 'ddl' => $bloco[0]];
            foreach (preg_split('/\R/', $bloco[2]) as $linha) {
                $linha = rtrim(trim($linha), ',');
                if ($linha === '' || str_starts_with($linha, '--')) continue;
                if (preg_match('/^(?:CONSTRAINT `?\w+`? )?PRIMARY KEY\s*\(([^)]+)\)/i', $linha, $m)) {
                    $tabela['pk'] = self::campos($m[1]);
                } elseif (preg_match('/^(?:CONSTRAINT `?\w+`? )?UNIQUE(?: KEY `?\w+`?)?\s*\(([^)]+)\)/i', $linha, $m)) {
                    $tabela['uk'][] = self::campos($m[1]);
                } elseif (preg_match('/^CONSTRAINT `?(\w+)`? FOREIGN KEY\s*\(([^)]+)\) REFERENCES `?(\w+)`?\s*\(([^)]+)\)(.*)$/i', $linha, $m)) {
                    $tabela['fks'][] = ['nome' => $m[1], 'filha' => $bloco[1], 'campos' => self::campos($m[2]), 'pai' => $m[3], 'referencias' => self::campos($m[4]), 'acoes' => trim($m[5])];
                } elseif (preg_match('/^(?:CONSTRAINT|KEY)\b/i', $linha)) {
                    // CHECK e índices continuam disponíveis na definição literal abaixo.
                } elseif (preg_match('/^`?(\w+)`?\s+((?:[a-z]+)(?:\([^)]*\))?(?: unsigned)?)(.*)$/i', $linha, $m)) {
                    $tabela['colunas'][$m[1]] = ['nome' => $m[1], 'tipo' => $m[2], 'nulo' => !preg_match('/NOT NULL|PRIMARY KEY/i', $m[3]), 'definicao' => $linha, 'chaves' => []];
                } else {
                    throw new RuntimeException('Definição não reconhecida em ' . $bloco[1] . ': ' . $linha);
                }
                if (preg_match('/^(?:CONSTRAINT|PRIMARY KEY|UNIQUE|KEY)\b/i', $linha)) $tabela['restricoes'][] = $linha;
            }
            foreach ($tabela['colunas'] as $nome => &$coluna) {
                if (in_array($nome, $tabela['pk'], true)) {
                    $coluna['chaves'][] = 'PK';
                    $coluna['nulo'] = false;
                }
                foreach ($tabela['fks'] as $fk) {
                    if (in_array($nome, $fk['campos'], true)) { $coluna['chaves'][] = 'FK'; break; }
                }
                foreach ($tabela['uk'] as $uk) {
                    if (in_array($nome, $uk, true)) { $coluna['chaves'][] = 'UK'; break; }
                }
            }
            unset($coluna);
            $tabelas[$bloco[1]] = $tabela;
        }
        if (!$tabelas) throw new RuntimeException('Nenhuma tabela encontrada no esquema.');
        return ['arquivo' => $arquivo, 'tabelas' => $tabelas];
    }

    private static function campos(string $lista): array
    {
        return array_map(static fn(string $campo): string => trim($campo, " `\t"), explode(',', $lista));
    }

    /** Uma linha por FK, inclusive as simples que coexistem com FKs compostas. */
    public static function relacoes(array $modelo, bool $agrupar = false): array
    {
        $relacoes = [];
        foreach ($modelo['tabelas'] as $tabela) {
            foreach ($tabela['fks'] as $fk) {
                $opcional = false;
                foreach ($fk['campos'] as $campo) $opcional = $opcional || $tabela['colunas'][$campo]['nulo'];
                $unico = false;
                foreach (array_merge([$tabela['pk']], $tabela['uk']) as $chave) {
                    if ($chave && !array_diff($chave, $fk['campos'])) $unico = true;
                }
                $fk['por_filha'] = $opcional ? '0..1' : '1';
                $fk['por_pai'] = $unico ? '0..1' : '0..N';
                $fk['identificadora'] = !array_diff($fk['campos'], $tabela['pk']);
                $relacoes[] = $fk;
            }
        }
        if (!$agrupar) return $relacoes;
        // O desenho mostra uma ligação quando a FK composta reforça a mesma FK simples.
        return array_values(array_filter($relacoes, static function (array $fk) use ($relacoes): bool {
            foreach ($relacoes as $outra) {
                if ($fk['filha'] === $outra['filha'] && $fk['pai'] === $outra['pai']
                    && count($fk['campos']) < count($outra['campos'])
                    && !array_diff($fk['campos'], $outra['campos'])
                    && !array_diff($fk['referencias'], $outra['referencias'])) return false;
            }
            return true;
        }));
    }

    public static function mermaid(array $modelo): string
    {
        $linhas = ['erDiagram'];
        foreach ($modelo['tabelas'] as $tabela) {
            $linhas[] = '    ' . $tabela['nome'] . ' {';
            foreach ($tabela['colunas'] as $coluna) {
                // Mermaid não admite a lista de valores de ENUM nem espaços no tipo.
                $tipo = preg_replace('/\(.*\)/', '', $coluna['tipo']);
                $tipo = str_replace(' ', '_', strtolower($tipo));
                $chaves = $coluna['chaves'] ? ' ' . implode(',', $coluna['chaves']) : '';
                $linhas[] = '        ' . $tipo . ' ' . $coluna['nome'] . $chaves . ' "' . ($coluna['nulo'] ? 'NULL' : 'NOT NULL') . '"';
            }
            $linhas[] = '    }';
        }
        foreach (self::relacoes($modelo, true) as $fk) {
            $pai = $fk['por_filha'] === '1' ? '||' : '|o';
            $filha = $fk['por_pai'] === '0..1' ? 'o|' : 'o{';
            $linha = $fk['identificadora'] ? '--' : '..';
            $linhas[] = '    ' . $fk['pai'] . ' ' . $pai . $linha . $filha . ' ' . $fk['filha'] . ' : "' . implode(' + ', $fk['campos']) . '"';
        }
        return implode("\n", $linhas) . "\n";
    }

    public static function regras(): array
    {
        return [
            'Perfis e master' => 'usuarios identifica a pessoa, com e-mail unico na plataforma. vinculos.tipo distingue cliente, profissional e admin por empresa. Uma pessoa pode ter varios vinculos; clientes, profissionais e administradores possuem id_vinculo unico e id_usuario nao exclusivo. As FKs compostas associam cada perfil ao vinculo da mesma empresa. administradores_master contem as contas globais e nao pertence a estabelecimento.',
            'Isolamento das empresas' => 'As FKs compostas incluem id_estabelecimento e o identificador do registro. Chaves únicas compostas não tornam cada coluna única isoladamente. Os vínculos id_usuario_criou e id_usuario_cancelou usam FKs simples; o DER não deve atribuir a eles uma restrição composta inexistente.',
            'Agenda' => 'agendamentos relaciona exatamente um cliente, um profissional e um serviço. profissional_servico resolve quais profissionais executam quais serviços (N:N). Disponibilidade.php verifica expediente semanal, bloqueios, antecedência e conflitos. Agendamento.php também verifica conflito do cliente. Essas verificações são feitas pela aplicação, não por uma restrição SQL de sobreposição.',
            'Histórico da reserva' => 'Agendamento.php copia o preço para valor e calcula hora_fim com a duração do serviço ao criar a reserva. Alterações posteriores no catálogo não recalculam essas colunas. Cancelar mantém o registro, altera o status e libera o intervalo. grupo_recorrencia agrupa reservas; não existe tabela de recorrências.',
            'Pacotes: vínculo sem FK' => 'agendamentos.id_cliente_pacote é anulável e não tem FOREIGN KEY em nenhum dos dois scripts. Diferencial::usarCreditoPacote seleciona uma compra paga, válida, com saldo, do cliente, serviço e estabelecimento da reserva. A aplicação consome o crédito e grava o identificador; o cancelamento devolve o crédito e limpa o vínculo. Esta relação deve aparecer separadamente como lógica, sem marcação FK.',
            'Benefícios e pagamentos' => 'cliente_pacotes registra compras de pacotes de serviços. pagamentos registra sinal ou integral por agendamento, com unicidade por estabelecimento, agendamento e tipo. fidelidade_movimentos admite no máximo um movimento por agendamento quando informado. avaliacoes admite no máximo uma avaliação por agendamento; a aplicação verifica cliente, conclusão e nota. Saldo de fidelidade fica em clientes.pontos_fidelidade.',
            'Espera e notificações' => 'lista_espera exige cliente e serviço, mas permite profissional nulo (qualquer profissional). notificacoes permite usuário e agendamento nulos. As colunas canal e status representam registros e acompanhamento; não significam, por si, integração automática com provedores externos.',
            'Autenticação e auditoria' => 'usuarios e administradores_master armazenam os três campos TOTP. logs_autenticacao tem FK somente para estabelecimento: id_usuario é referência histórica sem FK. logs_master não possui FKs, inclusive em id_master e id_estabelecimento; os nomes são copiados. tentativas_acesso é global e guarda escopo, chave em hash e data, sem relação por FK com contas.',
            'Planos e assinaturas' => 'planos define limites de uso; estabelecimento_plano tem id_estabelecimento como PK/FK e permite zero ou um plano atual por empresa. assinaturas também tem id_estabelecimento como PK/FK e registra demonstração, solicitação e confirmação de pagamento da plataforma. Não existe FK entre assinaturas e planos nem entre assinaturas e pagamentos. Assinatura.php inicia sete dias de demonstração; empresas sem assinatura são tratadas como legadas ativas. O valor mensal é uma constante PHP, não uma coluna.',
            'Limites da documentação' => 'O DER descreve os scripts SQL versionados e as regras identificadas no código. Não é uma inspeção do banco instalado: bases antigas podem exigir as migrações do projeto. MySQL/MariaDB e PostgreSQL têm tipos, índices e CHECKs próprios; o dicionário preserva as definições de cada dialeto.',
        ];
    }
}
