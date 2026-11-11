# Cartão, boleto e avulso — plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans (nativo). Steps com `- [ ]`.

**Goal:** mensalidade com Pix/boleto/cartão e pagamento avulso online por atendimento.

**Architecture:** `Cobranca` ganha a migração e os rótulos das formas; `AssinaturaCliente::escolherMetodo` e o model novo `PagamentoAvulso` gravam a escolha; uma página `cliente/pagar.php` atende os dois casos; admin vê forma e tipo.

**Spec:** `docs/superpowers/specs/2026-11-11-pagamento-cartao-boleto-avulso-design.md`

## Global Constraints

- Testes só no MariaDB local e no PostgreSQL 5433; nunca na conexão padrão.
- Todo SQL filtra `id_estabelecimento = Contexto::id()`; ação do cliente confere o dono.
- Cartão: só 4 dígitos chegam ao servidor (`/^\d{4}$/`); nada mais é guardado.
- Formas: `pix|boleto|cartao`; Pix só com `pix_chave`.
- Sem commits sem autorização; editar por cima das alterações pendentes.

## Review Focus

- Cliente troca a forma várias vezes: um só pagamento pendente, sem duplicar. → Task 2.
- Empresa configura sinal depois de o cliente escolher pagar o total: o integral cobre tudo e nenhum sinal novo aparece no mesmo agendamento. → Task 2.
- Cliente paga avulso e depois assina um plano: o agendamento já marcado não muda de valor. → registrado (comportamento esperado; sem teste).
- id de agendamento/cobrança de outra empresa na URL de `pagar.php`: 404. → Task 2 (dono) e Task 3 (página).
- Duplo clique em "Pagar" com cartão: um registro só (atualiza o mesmo pendente). → Task 2.

---

### Task 1: Migração e escolha da forma da mensalidade

**Files:** Modify `models/Cobranca.php` (`METODOS`, `rotuloMetodo`, `garantirEstrutura`), `models/AssinaturaCliente.php` (`escolherMetodo`; `cobrancaPendente` traz as colunas novas), `banco.sql`, `banco_postgres.sql`, `scripts/preparar_hospedagem.php` (rodar), `scripts/migrar.php`, `scripts/preparar_runtime.php`, `admin/assinaturas_clientes.php` (coluna forma); DER regenerado; Test `tests/pagamento_avulso.php`.

**Produces:** `Cobranca::METODOS = ['pix' => 'Pix', 'boleto' => 'Boleto', 'cartao' => 'Cartão']`; `Cobranca::rotuloPagamento(?string $metodo, ?string $final): string` ("Cartão final 1234", "-" se nulo); `Cobranca::garantirEstrutura(): void`; `Cobranca::metodosDisponiveis(): array` (sem Pix se `pix_chave` vazia); `AssinaturaCliente::escolherMetodo(int $idCobranca, int $idCliente, string $metodo, ?string $cartaoFinal): void` (lança `DomainException`/`InvalidArgumentException`).

- [ ] testes → ver falhar → implementar → MySQL e PostgreSQL verdes (+ `assinaturas_clientes`, `modelo_bd`).

### Task 2: PagamentoAvulso

**Files:** Create `models/PagamentoAvulso.php`; Test `tests/pagamento_avulso.php`.

**Produces:** `valorAPagar(array $agendamento): float`; `pagavel(array $agendamento, int $idCliente): bool`; `pendente(int $idAgendamento): ?array` (integral pendente ou pago mais recente); `solicitar(int $idAgendamento, int $idCliente, string $metodo, ?string $cartaoFinal): array` (linha de `pagamentos`). `Diferencial::pagamentos()` passa a trazer `tipo`, `metodo`, `cartao_final` e `status_agendamento`.

- [ ] testes (spec + Review Focus) → ver falhar → implementar → verdes nos dois bancos (+ `sinal_horarios`, `assinaturas_clientes`).

### Task 3: Telas

**Files:** Create `cliente/pagar.php`; Modify `cliente/beneficios.php` (botão "Pagar mensalidade", cartão "Sem plano", carregar `assinatura.js`), `cliente/agendamentos.php` e `cliente/agendar.php` ("Pagar agora"), `admin/diferenciais.php` (lista "Pagamentos").

- [ ] Playwright 390/1280: mensalidade nas três formas; avulso (cartão inválido barrado no navegador, cartão válido em análise, boleto, Pix); admin marca pago; outro cliente → 404; sem rolagem horizontal.
- [ ] Suíte completa nos dois bancos (3 exceções conhecidas no PostgreSQL).
