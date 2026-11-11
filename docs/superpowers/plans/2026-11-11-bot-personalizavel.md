# Bot personalizável — plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (escolhido: nativo). Steps use checkbox (`- [ ]`) syntax.

**Goal:** a empresa personaliza textos, opções embutidas e opções próprias do menu do bot de WhatsApp.

**Architecture:** model novo `BotMenu` (opções, textos, montagem do menu); `Bot` passa a pedir o menu e a resolver a escolha a ele; tela `admin/bot.php` edita. Sem personalização, a saída é idêntica à de hoje.

**Tech Stack:** PHP 8.2, PDO MySQL/PostgreSQL, testes CLI em `tests/`.

**Spec:** `docs/superpowers/specs/2026-11-11-bot-personalizavel-design.md`

## Global Constraints

- Testes nunca na conexão padrão (Supabase): MariaDB local e PostgreSQL descartável na 5433, como no plano das assinaturas.
- Todo SQL filtra `id_estabelecimento = Contexto::id()`.
- Textos padrão exatamente os da spec; `tests/planos_bot.php` tem de seguir verde sem alteração.
- Limites: 12 opções, rótulo 60, resposta/texto 1.000.
- Sem commits sem autorização do usuário; editar por cima das alterações pendentes dele.

## Review Focus

- Empresa desliga todas as opções: o bot não pode quebrar nem mandar menu vazio sem instrução. → Task 2.
- Cliente responde um número que existia antes da empresa reordenar/desligar (conversa aberta na etapa `menu`): vale a numeração atual, sem erro. → Task 2.
- Texto com `{variavel}` desconhecida ou chaves soltas: sai como digitado. → Task 1.
- Duas abas criando a 12ª e a 13ª opção juntas: limite pode passar por 1 (aceito; checagem simples). → registrado, sem teste.
- Resposta própria com `*negrito*` e quebras de linha: preservadas no WhatsApp. → Task 1.

---

### Task 1: BotMenu — dados, textos e montagem

**Files:** Create `models/BotMenu.php`; Modify `models/Bot.php` (`ddl()` ganha `bot_opcoes`), `banco.sql`, `banco_postgres.sql`, `scripts/preparar_hospedagem.php` (rodar), `models/Estabelecimento.php` (`excluirGlobal`: `bot_opcoes` antes de `estabelecimento`), `tests/modelo_bd.php` (34 tabelas), `tests/excluir_estabelecimento.php` (fixture + contagem), DER (`php scripts/gerar_modelo_bd.php`); Test: `tests/bot_personalizado.php` (novo, padrão de `tests/planos_bot.php`).

**Produces:**
- `BotMenu::TIPOS_EMBUTIDOS = ['marcar','meus','cancelar','servicos','equipe']`, `BotMenu::TEXTOS` = chave → padrão (spec).
- `garantirPadrao(): void`; `opcoes(): array` (todas, em ordem, com `id_bot_opcao, tipo, rotulo, resposta, ordem, ativo:int`).
- `visiveis(bool $comCadastro): array` (ligadas, sem `marcar/meus/cancelar` sem cadastro).
- `numeroDe(string $tipo, bool $comCadastro): ?int`; `escolha(int $numero, bool $comCadastro): ?array`.
- `texto(string $chave, array $variaveis): string` (configuração ou padrão, com troca de `{nome}`, `{empresa}`, `{link_cadastro}`).
- `definirTexto(string $chave, string $valor): void` (valida chave e 1.000).
- `menu(bool $comCadastro, array $variaveis): string` (formato da spec).
- `criar(string $rotulo, string $resposta): int`, `atualizar(int $id, string $rotulo, ?string $resposta): void`, `alternar(int $id): void`, `mover(int $id, int $direcao): void` (−1/+1, troca ordem com o vizinho), `apagar(int $id): void` (só `resposta`; embutida lança `DomainException`). Validações lançam `InvalidArgumentException` com mensagem para o usuário.

- [ ] Step 1: testes de `BotMenu` (padrão criado uma vez; `menu(true, …)` e `menu(false, …)` iguais ao texto de hoje; variáveis; chave desconhecida mantida; `*negrito*` e `\n` preservados; criar/atualizar/alternar/mover/apagar; limites; isolamento).
- [ ] Step 2: rodar e ver falhar.
- [ ] Step 3: implementar esquema + `BotMenu`.
- [ ] Step 4: `bot_personalizado`, `modelo_bd`, `excluir_estabelecimento` nos dois bancos → OK.

### Task 2: Bot usa o BotMenu

**Files:** Modify `models/Bot.php` (`menu`, `noMenu`, mensagens com `*5*`/`*1*`); Test: `tests/bot_personalizado.php`, `tests/planos_bot.php`.

**Consumes:** Task 1. `noMenu` resolve com `BotMenu::escolha($escolha, $cliente !== null)` e despacha pelo `tipo`; `resposta` devolve o texto + rodapé; `null` → `nao_entendi` + menu. "Mande *5*…" usa `numeroDe('equipe', …)`; "Responda *1* para marcar um" usa `numeroDe('marcar', true)`; sem número a frase sai.

- [ ] Step 1: testes via `Bot::atender` (desligar `cancelar` → escolha 3 = serviços; reordenar; opção própria; sem cadastro escolhendo o número que seria `marcar` cai em não entendi; todas desligadas → só boas-vindas + "Mande *menu* a qualquer momento."; texto da equipe personalizado; "Mande *N*" com número real).
- [ ] Step 2: ver falhar. Step 3: implementar. Step 4: `bot_personalizado` + `planos_bot` nos dois bancos → OK.

### Task 3: Tela admin/bot.php

**Files:** Create `admin/bot.php`; Modify `includes/sidebar.php` (item "Bot do WhatsApp" depois de "Assinaturas de clientes"), `admin/diferenciais.php` (link no cartão do bot).

**Consumes:** Task 1. Padrão de `admin/assinaturas_clientes.php` (CSRF, PRG, `fora_do_plano` com `bot_whatsapp`, `.tabela-area`, `input type="text"`). Prévia com `BotMenu::menu(true, ['nome'=>'Maria', …])` e `menu(false, …)` em `<pre>` com quebra de linha.

- [ ] Step 1: Playwright MCP a 1280 e 390 px: editar texto, restaurar, desligar/ligar, subir/descer, criar/editar/apagar própria, prévia atualiza, sem rolagem horizontal; plano sem `bot_whatsapp` mostra o aviso.
- [ ] Step 2: suíte completa nos dois bancos (mesmas 3 exceções conhecidas no PostgreSQL).
