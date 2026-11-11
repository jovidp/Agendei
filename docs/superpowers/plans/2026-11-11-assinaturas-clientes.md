# Assinaturas de clientes — plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** cada estabelecimento cria planos de assinatura (serviços incluídos por mês + desconto no restante) e os vende aos seus clientes, com mensalidade por Pix confirmada pelo admin.

**Architecture:** um model novo `AssinaturaCliente` concentra esquema, regras e consultas; `Agendamento::criar` pergunta a ele o valor e a cobertura antes do INSERT; uma tela de admin nova e uma seção em `cliente/beneficios.php` usam o model. Situação (suspensa/encerrada) é derivada de datas, sem tarefa de suspensão; a tarefa diária só gera renovações.

**Tech Stack:** PHP 8.2 sem framework, PDO (MariaDB/MySQL e PostgreSQL), páginas PHP com `includes/painel_header.php`, testes em scripts CLI `tests/*.php` com banco temporário.

**Spec:** `docs/superpowers/specs/2026-11-11-assinaturas-clientes-design.md` (ler junto; este plano argumenta a partir dela).

## Global Constraints

- Nunca rodar testes contra a conexão padrão (`config/database.local.php` aponta para o Supabase de produção). Sempre com `AGENDEI_DB_DRIVER=mysql AGENDEI_DB_HOST=127.0.0.1 AGENDEI_DB_PORT=3306 AGENDEI_DB_USER=root AGENDEI_DB_PASS=`; PHP em `D:\xampp\php\php.exe` com `PHPRC` (formato Windows) apontando para um `php.ini` com pdo_mysql, pdo_pgsql, pdo_sqlite, mbstring, openssl, curl, fileinfo.
- O dialeto PostgreSQL também é testado: cluster descartável na porta 5433 (ver memória do projeto), `AGENDEI_DB_DRIVER=pgsql AGENDEI_DB_PORT=5433 AGENDEI_DB_USER=postgres`.
- Toda consulta filtra por `id_estabelecimento = Contexto::id()`; FKs compostas com o tenant, como em `pacotes`/`cliente_pacotes`.
- Recurso visível só com `Plano::temRecurso('diferenciais')` (vitrine, assinar, tela do admin). `beneficio()` **não** checa o plano do Agendei: quem já pagou mantém o benefício até o fim do período.
- `desconto_percentual` inteiro 0–100; `quantidade_mes` NULL = ilimitado; dinheiro em `DECIMAL(10,2)` e `round(..., 2)`.
- Status guardados: assinatura `pendente|ativa|cancelada`; cobrança `pendente|pago|cancelado`. Situações derivadas: `pendente|ativa|suspensa|cancelada|encerrada`.
- Renovação: cobrança gerada quando `periodo_fim <= hoje + 3 dias`; txid/referência `AC{id_cobranca}`.
- Textos de interface em português com acentos; comentários seguem o estilo do arquivo (sem acento nos models novos, como `Cobranca.php`).
- O repositório tem alterações do usuário não commitadas (inclusive em `banco_postgres.sql` e `models/Agendamento.php`): editar por cima delas, nunca reverter; commitar só os arquivos da tarefa, e só se o usuário autorizar commits.
- Arquivos podem estar em CRLF: normalizar antes de substituição por script; preferir a ferramenta Edit.

## Review Focus

- Período que começa em 29, 30 ou 31: o fim deve cair no último dia do mês seguinte quando o dia não existe (31/01 → 28/02), nunca pular para março. → Task 2, `periodoFim`.
- Duplo clique em "Confirmar pagamento" (mesma cobrança duas vezes): o período avança uma vez só. → Task 2.
- Cliente manda no POST o id da assinatura de outro cliente para cancelar: nada muda. → Task 2 (`cancelar` com `$idCliente`).
- Admin reduz a quantidade incluída para menos do que o cliente já usou no mês: os agendamentos já feitos ficam como estão, o próximo recebe desconto, nada dá erro. → Task 4.
- Empresa sem chave Pix: assinar é recusado com mensagem clara; se a chave for apagada depois, a cobrança pendente mostra aviso em vez de QR quebrado. → Task 2 (recusa) e Task 6 (aviso).

---

### Task 1: Esquema das quatro tabelas e da coluna nova

**Files:**
- Create: `models/AssinaturaCliente.php` (só `garantirEstrutura` e o DDL por dialeto nesta tarefa)
- Modify: `banco.sql`, `banco_hospedagem.sql`, `banco_postgres.sql` (tabelas depois de `cliente_pacotes`; coluna `id_cliente_assinatura INT UNSIGNED NULL` em `agendamentos`, ao lado de `id_cliente_pacote`, sem FK — no Postgres `INTEGER NULL`)
- Modify: `scripts/migrar.php` (chamar `AssinaturaCliente::garantirEstrutura()` depois de `VerificacaoEmail::garantirEstrutura()`, com `echo` no padrão)
- Modify: `models/ModeloBanco.php` e `docs/MER_DER.md`/`docs/der_*.mmd` se `tests/modelo_bd.php` exigir as tabelas novas
- Test: `tests/assinaturas_clientes.php` (novo, estrutura de `tests/legal.php`: processo pai cria `agendei_test_<hex>`, worker roda e o pai apaga)

**Interfaces:**
- Produces: `AssinaturaCliente::garantirEstrutura(): void` — idempotente, cria as tabelas com `CREATE TABLE IF NOT EXISTS` no dialeto de `Database::ehPostgres()` e a coluna via `Sql::garantirColuna('agendamentos', 'id_cliente_assinatura', ...)`. Tabelas e colunas exatamente como na seção "Dados" da spec.

- [ ] **Step 1: Teste de estrutura** — em `tests/assinaturas_clientes.php`, depois de carregar o esquema, `ALTER TABLE agendamentos DROP COLUMN id_cliente_assinatura` e `DROP TABLE` das quatro tabelas (ordem inversa das FKs), chamar `garantirEstrutura()` duas vezes e `verificar()` que as quatro tabelas existem e a coluna existe (consulta `SELECT id_cliente_assinatura FROM agendamentos WHERE 1=0` não lança).
- [ ] **Step 2: Rodar e ver falhar** — `php tests/assinaturas_clientes.php` → falha com classe `AssinaturaCliente` inexistente.
- [ ] **Step 3: Implementar** o DDL nos três `.sql` e em `garantirEstrutura()` (seguir `Plano::ddl()`/`Plano::garantirEstrutura()`), e a chamada em `scripts/migrar.php`.
- [ ] **Step 4: Rodar** `php tests/assinaturas_clientes.php` em MariaDB e em PostgreSQL 5433 → OK; `php tests/modelo_bd.php` → OK (atualizar `ModeloBanco`/DER até passar).
- [ ] **Step 5: Commit** (se autorizado) — `feat(assinaturas-clientes): esquema das tabelas`.

### Task 2: Regras do model (planos, assinar, pagar, cancelar, renovar)

**Files:**
- Modify: `models/AssinaturaCliente.php`
- Test: `tests/assinaturas_clientes.php`

**Interfaces:**
- Consumes: Task 1.
- Produces (todas no contexto da empresa atual; `$hoje` em `Y-m-d`, padrão `date('Y-m-d')`, existe para teste):
  - `criarPlano(array $dados): int` — chaves `nome, descricao, preco_mensal, desconto_percentual, servicos` onde `servicos = [id_servico => ?int quantidade_mes]`. Valida: nome não vazio, preço > 0, desconto 0–100, pelo menos um serviço, todos os serviços da empresa. Lança `InvalidArgumentException` com mensagem para o usuário.
  - `atualizarPlano(int $id, array $dados): void` — mesmas chaves e validação; substitui a lista de serviços.
  - `alterarStatusPlano(int $id, string $status): void` — `ativo|inativo`.
  - `planos(bool $somenteAtivos = false): array` — cada item com as colunas do plano + `servicos` (lista de `id_servico, nome, quantidade_mes`).
  - `planoPorId(int $id): ?array` — mesmo formato.
  - `assinar(int $idCliente, int $idPlano, ?string $hoje = null): int` — devolve `id_cliente_assinatura`; cria assinatura `pendente` e a primeira cobrança `pendente` com `valor = preco_mensal`. Lança `DomainException` se: cliente já tem assinatura cuja situação não é `encerrada`; plano inativo/inexistente; `Configuracao::obter('pix_chave') === ''` (mensagem: "A empresa ainda não configurou a chave Pix para receber assinaturas.").
  - `situacao(array $assinatura, ?string $hoje = null): string` — tabela da spec.
  - `vigenteEm(array $assinatura, string $data): bool` — situação com benefícios (`ativa` ou `cancelada` com período) **e** `periodo_inicio <= $data <= periodo_fim`.
  - `periodoFim(string $inicio): string` — algoritmo abaixo.
  - `confirmarPagamento(int $idCobranca, ?string $hoje = null): bool` — em transação, `FOR UPDATE` na cobrança e na assinatura; só age se a cobrança está `pendente` (devolve `false` caso contrário). Regras de período da spec ("Confirmar pagamento").
  - `cancelarCobranca(int $idCobranca): void`.
  - `cancelar(int $idAssinatura, ?int $idCliente = null): bool` — com `$idCliente`, só cancela se a assinatura for dele; cancela cobranças pendentes.
  - `gerarRenovacoes(?string $hoje = null): int` — empresa atual; quantas cobranças criou.
  - `renovarTodas(): array` — exige `TAREFA_AGENDADA`, percorre empresas como `Lembrete::processarTodas()` (com `Contexto::assumirTarefa`), resumo por empresa.
  - `daCliente(int $idCliente, ?string $hoje = null): ?array` — a assinatura mais recente do cliente com chaves extras `situacao`, `plano` (formato de `planoPorId`), `cobranca_pendente` (?array), `uso` (`[id_servico => int]` no período atual; vazio sem período).
  - `assinantes(?string $hoje = null): array` — para o admin: assinatura + `nome_cliente`, `nome_plano`, `situacao`, `cobranca_pendente`.

`periodoFim`: próximo = mesmo dia no mês seguinte; se esse dia não existe, use o 1º dia do mês depois do seguinte; fim = próximo − 1 dia. (15/01→14/02; 31/01→28/02; 31/01/2028→29/02/2028; 28/02→27/03; 31/12→30/01.)

- [ ] **Step 1: Testes** (cenário: empresa via `Estabelecimento::contratar(... 'iniciar_demo' => true)`, `Contexto::iniciar()` com `$_GET['estabelecimento']`, `Configuracao::definir('pix_chave', 'chave@empresa.local', '')`, serviços Corte R$ 45 e Barba R$ 35 e Escova R$ 50, um cliente):
  - `periodoFim`: os cinco pares acima.
  - `criarPlano` com desconto 101 ou sem serviços → `InvalidArgumentException`.
  - plano "Clube" R$ 99, desconto 15, `[corte => 4, barba => null]`; `assinar` → situação `pendente`, uma cobrança pendente de 99.00; `assinar` de novo → `DomainException`.
  - `confirmarPagamento($cobranca, '2026-01-10')` → `true`, `periodo_inicio = 2026-01-10`, `periodo_fim = 2026-02-09`, `situacao(..., '2026-01-10') = ativa`; segunda chamada com o mesmo id → `false` e período inalterado.
  - `situacao(..., '2026-02-10') = suspensa`; `vigenteEm(..., '2026-02-10') = false`.
  - renovação em dia: `gerarRenovacoes('2026-02-06')` → 1; de novo → 0; `gerarRenovacoes('2026-02-05')` em outra assinatura recém-paga → 0 (fora da janela). Confirmar em `2026-02-08` → início `2026-02-10`, fim `2026-03-09`.
  - renovação após suspensão: confirmar em `2026-03-20` → início `2026-03-20`.
  - `cancelar($id, $outroCliente)` → `false`, nada muda; `cancelar($id, $cliente)` → `true`, `situacao(..., dentro do período) = cancelada`, depois do fim `encerrada`; cobranças pendentes viram `cancelado`; agora `assinar` volta a ser aceito só depois de encerrada.
  - sem chave Pix (`Configuracao::definir('pix_chave', '', '')`) → `assinar` lança a mensagem exata acima.
  - isolamento: segunda empresa não enxerga o plano em `planos()` e `planoPorId()` devolve `null`.
- [ ] **Step 2: Rodar e ver falhar** — `php tests/assinaturas_clientes.php` → métodos inexistentes.
- [ ] **Step 3: Implementar** os métodos acima em `models/AssinaturaCliente.php`.
- [ ] **Step 4: Rodar** nos dois dialetos → OK.
- [ ] **Step 5: Commit** (se autorizado) — `feat(assinaturas-clientes): regras de plano, pagamento e renovação`.

### Task 3: Pix com a chave da empresa

**Files:**
- Modify: `models/Cobranca.php:19` (`pix`)
- Test: `tests/cobranca.php`

**Interfaces:**
- Produces: `Cobranca::pix(float $valor, string $identificador, ?string $chave = null, ?string $nome = null): string` — `null` mantém o comportamento atual (env do Agendei); `$nome` passa pelo mesmo `ascii(..., 25)`.

- [ ] **Step 1: Teste** em `tests/cobranca.php`: `Cobranca::pix(99.0, 'AC7', 'chave@empresa.local', 'Barbearia Ção')` contém `chave@empresa.local`, contém `BARBEARIA CAO`, contém `AC7`, e os 4 últimos caracteres batem com `Cobranca::crc16(substr($codigo, 0, -4))`; chamada sem os dois argumentos continua igual à atual (assert existente).
- [ ] **Step 2: Rodar e ver falhar** — `php tests/cobranca.php`.
- [ ] **Step 3: Implementar** os parâmetros opcionais.
- [ ] **Step 4: Rodar** `php tests/cobranca.php` e `php tests/qrcode.php` → OK.
- [ ] **Step 5: Commit** (se autorizado) — `feat(cobranca): Pix com chave e nome do recebedor`.

### Task 4: Benefício no agendamento

**Files:**
- Modify: `models/AssinaturaCliente.php`
- Modify: `models/Agendamento.php:363-416` (`criar`)
- Test: `tests/assinaturas_clientes.php`

**Interfaces:**
- Consumes: Task 2 (`vigenteEm`, plano com `servicos`).
- Produces: `AssinaturaCliente::beneficio(int $idCliente, int $idServico, string $data, float $preco): array` → `['id_cliente_assinatura' => ?int, 'valor' => float]`. Precisa ser chamada **dentro** da transação de `criar`: trava a assinatura com `FOR UPDATE` antes de contar o uso. Uso = agendamentos com aquele `id_cliente_assinatura` e `id_servico`, `data_agendamento` no período e `status <> 'cancelado'`. Regras 1–3 da spec.

Em `Agendamento::criar`: depois de `$servico = Servico::porId(...)`, chamar `beneficio(...)`; gravar `valor` e `id_cliente_assinatura` no INSERT. Depois do commit, se `id_cliente_assinatura` não for nulo, **pular** `usarCreditoPacote`/`criarPagamentoSinal`; senão, fluxo atual (o sinal já sai sobre o valor com desconto porque lê `agendamento['valor']`).

- [ ] **Step 1: Testes** (profissional com os três serviços e horário 08:00–18:00 todos os dias; assinatura do Clube paga com `hoje` real; agendamentos em datas futuras dentro do período):
  - 4 cortes → `valor = 0.00` e `id_cliente_assinatura` preenchido; 5º corte → `valor = 38.25`, coluna nula.
  - 6 barbas → todas `0.00` (ilimitado).
  - escova → `42.50` (desconto), coluna nula.
  - cancelar um corte coberto (`Agendamento::cancelar`) → o próximo corte volta a sair `0.00`.
  - corte em data depois de `periodo_fim` → `45.00` (sem vigência na data).
  - com `pix_chave` e `sinal_percentual = 50`: corte coberto não cria linha em `pagamentos`; escova cria sinal de `21.25`.
  - pacote pago de corte + corte coberto: `creditos_restantes` do pacote não muda.
  - `atualizarPlano` reduzindo corte para 1 depois de 3 usados → próximo corte `38.25`, os 3 anteriores continuam `0.00`.
  - cliente sem assinatura → `45.00` (regressão).
- [ ] **Step 2: Rodar e ver falhar**.
- [ ] **Step 3: Implementar** `beneficio` e a chamada em `criar`.
- [ ] **Step 4: Rodar** `tests/assinaturas_clientes.php`, `tests/sinal_horarios.php`, `tests/fluxo_projeto.php`, `tests/multitenancy.php`, `tests/filiais.php` nos dois dialetos → OK.
- [ ] **Step 5: Commit** (se autorizado) — `feat(assinaturas-clientes): cobertura e desconto no agendamento`.

### Task 5: Tela do admin

**Files:**
- Create: `admin/assinaturas_clientes.php`
- Modify: `includes/sidebar.php:44` (item `['rotulo' => 'Assinaturas de clientes', 'arquivo' => 'admin/assinaturas_clientes.php', 'icone' => 'novo']` logo depois de "Diferenciais")
- Test: `tests/acesso.php` (se ele lista as páginas do admin, incluir a nova) + verificação manual

**Interfaces:**
- Consumes: Task 2 (todos os métodos de plano, `assinantes`, `assinar`, `confirmarPagamento`, `cancelarCobranca`, `cancelar`).

Página no padrão de `admin/servicos.php`: `exigirLogin('admin')`; sem `Plano::temRecurso('diferenciais')` → `includes/fora_do_plano.php` como em `cliente/beneficios.php`; POST com `exigirCsrf()` e `acao` ∈ `criar_plano|atualizar_plano|status_plano|inscrever|confirmar|cancelar_cobranca|cancelar`; `definirFlash` com a mensagem da exceção; PRG (redirect após POST). Seções: formulário de plano (serviços com checkbox + campo quantidade, vazio = ilimitado), tabela de planos, tabela de assinantes (situação, período, cobrança pendente com botões), formulário "inscrever cliente" (select de clientes ativos + select de planos ativos). Tabelas dentro de `.tabela-area`.

- [ ] **Step 1:** `php tests/acesso.php` (com `MSYS_NO_PATHCONV=1` no Git Bash) depois de registrar a página, se aplicável → falha antes de criar o arquivo.
- [ ] **Step 2: Implementar** a página e o item do menu.
- [ ] **Step 3: Verificar** com o servidor local (`php -S` + roteador `/__entrar` do scratchpad) e o Playwright MCP em 1280 px e 390 px: criar plano, inscrever cliente, confirmar pagamento, cancelar; página sem rolagem horizontal (`scrollWidth == clientWidth`); profissional e cliente recebem acesso negado.
- [ ] **Step 4: Commit** (se autorizado) — `feat(assinaturas-clientes): tela do admin`.

### Task 6: Seção do cliente e tarefa de renovação

**Files:**
- Modify: `cliente/beneficios.php` (novas ações POST `assinar` e `cancelar_assinatura`; seção "Assinaturas" antes de "Pacotes")
- Modify: `tarefas.php:42-45` (chamar `AssinaturaCliente::renovarTodas()` dentro da mesma condição `date('G') === 3` da retenção; incluir no JSON como `renovacoes`)
- Test: `tests/assinaturas_clientes.php` (`renovarTodas` com `TAREFA_AGENDADA` definido no worker) + verificação manual

**Interfaces:**
- Consumes: Task 2 (`planos(true)`, `daCliente`, `assinar`, `cancelar`, `gerarRenovacoes`, `renovarTodas`), Task 3 (`Cobranca::pix`), `QrCode::svg`.

Ao abrir a página, chamar `AssinaturaCliente::gerarRenovacoes()` antes de montar a tela. Cancelar passa `perfilId()` como `$idCliente`. Cobrança pendente: se `pix_chave` vazia, mostrar "A empresa ainda não configurou o Pix. Fale com o estabelecimento para pagar." no lugar do QR; senão `QrCode::svg(Cobranca::pix($valor, 'AC' . $id, $chave, $nomeEmpresa))` e o copia e cola em `<code>`, como em `assinatura.php:97`. Uso no formato "2 de 4" ou "ilimitado".

- [ ] **Step 1: Teste** `renovarTodas()` com duas empresas, cada uma com uma assinatura vencendo em 2 dias → resumo com 1 renovação em cada; sem `TAREFA_AGENDADA` → `LogicException`.
- [ ] **Step 2: Rodar e ver falhar**.
- [ ] **Step 3: Implementar** `cliente/beneficios.php` e `tarefas.php`.
- [ ] **Step 4: Verificar** com Playwright MCP em 390 px e 1280 px: vitrine, assinar, QR aparece, admin confirma, uso "0 de 4", agendar um corte pelo fluxo do cliente sai R$ 0,00, cancelar assinatura mostra "vale até …"; sem rolagem horizontal; com `pix_chave` apagada aparece o aviso.
- [ ] **Step 5: Rodar todas as suítes** `for t in tests/*.php` (exceto `api_falsa.php`/`smtp_falso.php`, que são servidores auxiliares) em MariaDB e no PostgreSQL 5433 → todas OK.
- [ ] **Step 6: Commit** (se autorizado) — `feat(assinaturas-clientes): área do cliente e renovação diária`.
