<?php
/** Atualiza a documentação sem conectar ao banco. Execute: php scripts/gerar_modelo_bd.php */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/models/ModeloBanco.php';
$raiz = dirname(__DIR__);
$mysql = ModeloBanco::carregar();
$postgres = ModeloBanco::carregar('pgsql');
$texto = <<<'MD'
# MER e DER do Agendei

Documento gerado por `php scripts/gerar_modelo_bd.php`. A estrutura é extraída de
`banco.sql` e `banco_postgres.sql`; as regras de funcionamento foram conferidas nos
models PHP. Não é uma inspeção da base instalada. Para atualizar uma base antiga,
consulte as migrações do projeto; não execute os scripts de instalação sobre seus dados.

## Como ler

O DER completo abaixo inclui todas as tabelas. PK identifica a chave primária;
FK identifica colunas que participam de uma chave estrangeira declarada;
UK indica participação em uma restrição de unicidade, possivelmente composta.
Uma coluna marcada UK não é necessariamente única sozinha: consulte os conjuntos
no dicionário. Tipos no desenho são abreviados; tipos exatos, tamanhos, enumerações,
valores padrão, ações de exclusão e CHECKs constam nas definições SQL literais.

Na notação Mermaid, `||` = exatamente um, `o|`/`|o` = zero ou um e `o{` = zero ou
vários. A extremidade junto ao pai indica quantos pais um filho pode referenciar;
a extremidade junto ao filho indica quantos filhos um pai pode ter. Linha contínua
é relação identificadora (FK contida na PK); linha tracejada é não identificadora.
**Ambas representam FKs reais**. Vínculos sem FK aparecem apenas no diagrama lógico
separado. FKs simples reforçadas por compostas aparecem uma vez no desenho;
a listagem de restrições preserva todas elas.

## MER: entidades e funcionamento

O estabelecimento organiza usuários, catálogo, agenda e configurações. Usuários
possuem perfis de cliente, profissional ou administrador local; a conta master é
global e independente dessa especialização. O fluxo de cadastro cria o perfil
correspondente, mas o SQL permite zero ou um registro em cada subtipo e não impõe
exclusividade entre as três tabelas.

`profissional_servico` é a associação N:N entre profissionais e serviços, com PK
composta pelos dois identificadores. `cliente_pacotes` representa a aquisição de
um pacote por um cliente, com saldo e validade próprios; admite compras repetidas.
`estabelecimento_plano` guarda um único vínculo atual por empresa, não um histórico.
`assinaturas` guarda o estado comercial da empresa. Estas duas últimas tabelas
dependem da chave do estabelecimento para sua identificação. As demais tabelas
com PK própria não devem ser classificadas como fracas apenas porque possuem FK.

Horários e bloqueios sustentam o cálculo de disponibilidade. Agendamentos ligam
cliente, profissional e serviço e preservam preço e intervalo da reserva.
Espera, notificações, pagamentos, fidelidade, pacotes e avaliações complementam
esse fluxo. Logs preservam informações históricas; tentativas de acesso protegem
a autenticação. Relatórios e comissões são calculados pelo código, sem tabelas
próprias de relatório ou comissão. Os atributos de cada entidade estão no dicionário.

MD;
foreach (ModeloBanco::regras() as $titulo => $regra) $texto .= "\n### $titulo\n\n$regra\n";
$texto .= "\n## DER físico completo — MySQL/MariaDB\n\n```mermaid\n" . ModeloBanco::mermaid($mysql) . "```\n";
$texto .= <<<'MD'

## Referências lógicas sem FOREIGN KEY

Este desenho não adiciona restrições ao banco. Os identificadores históricos podem
permanecer após a exclusão do registro original; não há garantia de integridade
referencial nem de existência atual do pai. A cardinalidade mostra o uso esperado
do identificador pela aplicação.

```mermaid
flowchart LR
    CP[cliente_pacotes] -. "0..1 pacote por reserva; 0..N reservas por compra; sem FK" .-> A[agendamentos.id_cliente_pacote]
    U[usuarios] -. "0..1 usuário por log; 0..N logs por usuário; sem FK" .-> LA[logs_autenticacao.id_usuario]
    M[administradores_master] -. "0..1 master por log; 0..N logs por master; sem FK" .-> LM[logs_master.id_master]
    E[estabelecimento] -. "0..1 empresa por log; 0..N logs por empresa; sem FK" .-> LE[logs_master.id_estabelecimento]
```

MD;
foreach (['MySQL/MariaDB' => $mysql, 'PostgreSQL' => $postgres] as $dialeto => $modelo) {
    $texto .= "\n## Dicionário e restrições — $dialeto\n\n";
    $texto .= count($modelo['tabelas']) . ' tabelas; ' . count(ModeloBanco::relacoes($modelo)) . " chaves estrangeiras declaradas.\n";
    foreach ($modelo['tabelas'] as $tabela) {
        $texto .= "\n### `{$tabela['nome']}`\n\n| Coluna | Tipo SQL | Nulo | Chaves |\n|---|---|---|---|\n";
        foreach ($tabela['colunas'] as $coluna) {
            $texto .= '| `' . $coluna['nome'] . '` | `' . $coluna['tipo'] . '` | ' . ($coluna['nulo'] ? 'Sim' : 'Não') . ' | ' . (implode(', ', $coluna['chaves']) ?: '—') . " |\n";
        }
        $texto .= "\nDefinição literal (inclui defaults, chaves compostas e CHECKs):\n\n```sql\n" . $tabela['ddl'] . "\n```\n";
    }
    $texto .= "\n### Cardinalidades das FKs — $dialeto\n\n| Restrição | Pai | Filha | Colunas da FK | Pais por filha | Filhas por pai |\n|---|---|---|---|---|---|\n";
    foreach (ModeloBanco::relacoes($modelo) as $fk) {
        $texto .= '| `' . $fk['nome'] . '` | `' . $fk['pai'] . '` | `' . $fk['filha'] . '` | `' . implode(', ', $fk['campos']) . '` | ' . $fk['por_filha'] . ' | ' . $fk['por_pai'] . " |\n";
    }
}
if (!is_dir($raiz . '/docs')) mkdir($raiz . '/docs', 0775, true);
file_put_contents($raiz . '/docs/MER_DER.md', $texto);
file_put_contents($raiz . '/docs/der_mysql.mmd', ModeloBanco::mermaid($mysql));
file_put_contents($raiz . '/docs/der_postgres.mmd', ModeloBanco::mermaid($postgres));
$prompt = <<<'MD'
# Prompt para apresentar o MER e o DER do Agendei

Use este prompt junto com o documento completo [docs/MER_DER.md](docs/MER_DER.md).
Os diagramas prontos estão em [der_mysql.mmd](docs/der_mysql.mmd) e
[der_postgres.mmd](docs/der_postgres.mmd). A tela `modelo_bd.php` consulta os mesmos
scripts SQL para mostrar campos, chaves e relações.

Para atualizar estes arquivos: `php scripts/gerar_modelo_bd.php`.

--- INÍCIO DO PROMPT ---

Apresente o MER e o DER do sistema Agendei estritamente conforme o documento
`docs/MER_DER.md` fornecido junto com este prompt. Não invente nem omita tabelas,
colunas, restrições ou relacionamentos. Use as 25 tabelas documentadas, incluindo
assinaturas, planos, administração master, agenda, benefícios e segurança.

1. Explique o MER conceitual e seus relacionamentos com cardinalidade mínima e
   máxima, distinguindo regras de negócio de restrições impostas pelo banco.
2. Reproduza o DER completo em Mermaid. Escolha explicitamente MySQL/MariaDB ou
   PostgreSQL e respeite as definições daquele dialeto. Os arquivos `.mmd`
   fornecidos são os diagramas físicos de referência.
3. Inclua o dicionário completo, preservando tipos, nulabilidade, defaults,
   PK, conjuntos UK, FKs simples/compostas e ações de exclusão/atualização.
4. Mostre separadamente as referências lógicas sem FOREIGN KEY. Não marque
   agendamentos.id_cliente_pacote, logs_autenticacao.id_usuario ou os
   identificadores históricos de logs_master como FKs.
5. Explique por que o admin de empresa é diferente do master global; por que
   cada subtipo de usuário é opcional no modelo físico; como o código verifica
   disponibilidade, crédito de pacotes e avaliações; e por que valor e hora_fim
   preservam o histórico da reserva.
6. Se criar vistas menores para impressão, identifique-as como recortes do DER
   completo. Não apresente um recorte como representação de todo o sistema.

Use português do Brasil. Não afirme ter inspecionado o banco instalado; a fonte
é o esquema versionado e o funcionamento descrito nos models PHP. Não confunda
linha não identificadora do Mermaid com ausência de FK. Não trate uma coluna
integrante de UK composta como única isoladamente. Não crie vínculos de FK entre
assinaturas, planos e pagamentos, pois eles não existem nos scripts fornecidos.

--- FIM DO PROMPT ---

MD;
file_put_contents($raiz . '/MER_DER_PROMPT.md', $prompt);
echo "Documentação e diagramas gerados para " . count($mysql['tabelas']) . " tabelas em cada dialeto.\n";
