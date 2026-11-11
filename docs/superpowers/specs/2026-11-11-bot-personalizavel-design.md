# Bot do WhatsApp personalizável — desenho

Data: 2026-11-11. Status: desenho aprovado em conversa.

## Objetivo

O bot continua sem IA, por menu numerado, mas cada estabelecimento passa a
personalizar: os textos principais, as opções do menu (ligar/desligar, renomear,
reordenar) e opções próprias com resposta pronta. Quem nunca personalizar tem o
bot de hoje, com as mesmas mensagens.

## Fora do escopo

Submenus, imagens/arquivos nas respostas, horário de atendimento automático,
mensagens por filial, personalizar os passos internos de marcar/cancelar
("Qual serviço?", "Com quem?", dias, horários, confirmação).

## Dados

**Tabela `bot_opcoes`** (padrão multiempresa, nos três `.sql`, em `Bot::ddl()` e
na exclusão de empresa `Estabelecimento::excluirGlobal`):
`id_bot_opcao, id_estabelecimento, tipo, rotulo VARCHAR(60), resposta VARCHAR(1000) NULL,
ordem SMALLINT, ativo (0/1), data_criacao`.
`tipo ∈ marcar | meus | cancelar | servicos | equipe | resposta`. Os cinco primeiros
são as opções embutidas (uma de cada por empresa, `UNIQUE (id_estabelecimento, tipo)`
não serve por causa de `resposta`; a unicidade é garantida no código). FK para
`estabelecimento`.

**Padrão** (criado sob demanda por `BotMenu::garantirPadrao()` quando a empresa
não tem nenhuma linha): marcar "Marcar horário", meus "Meus agendamentos",
cancelar "Cancelar um agendamento", servicos "Serviços e preços", equipe "Falar
com a equipe", nessa ordem, todas ligadas.

**Textos** em `configuracoes`, vazio = padrão:

| chave | padrão (exatamente o texto de hoje) |
|---|---|
| `bot_texto_boas_vindas` | `Olá, {nome}! Sou o assistente virtual de *{empresa}*. Responda com o número:` |
| `bot_texto_sem_cadastro` | `Olá! Sou o assistente virtual de *{empresa}*.\n\nNão encontrei um cadastro com este número. Para marcar horário por aqui, crie sua conta usando este mesmo celular:\n{link_cadastro}\n\nEnquanto isso, responda com o número:` |
| `bot_texto_equipe` | `Certo! Avisei a equipe, e alguém vai te responder por aqui. Quando quiser falar com o assistente de novo, mande *menu*.` |
| `bot_texto_nao_entendi` | `Não entendi. Responda com um dos números do menu.` |

Variáveis: `{nome}` (primeiro nome; vazio sem cadastro), `{empresa}`, `{link_cadastro}`.
Variável desconhecida fica como está. Limites: 1.000 caracteres por texto/resposta,
60 por rótulo, até 12 opções.

## Montagem do menu

- Opções visíveis = ligadas, em `ordem`; para quem não tem cadastro, sem
  `marcar`, `meus`, `cancelar`.
- Número = posição na lista visível (1..N). A mesma lista resolve a escolha.
- Cliente com cadastro: `boas_vindas` + `\n\n` + linhas `N. rótulo` + `\n\nA qualquer
  momento, mande *menu* para voltar aqui.` Sem cadastro: `sem_cadastro` + `\n` + linhas.
  Com as opções padrão o resultado é byte a byte o menu de hoje.
- Nenhuma opção visível: só o texto de boas-vindas (ou sem cadastro) e
  `Mande *menu* a qualquer momento.`
- Escolha de opção `resposta`: devolve o texto dela + `\n\nMande *menu* para ver as opções.`
  e fica na etapa `menu`.
- Mensagens que citavam números fixos passam a usar o número real:
  "Mande *5* para falar com a equipe" → número atual da opção `equipe` para esse
  cliente, ou a frase some se ela estiver desligada; "Responda *1* para marcar um"
  → número de `marcar`, ou some.

## Tela `admin/bot.php` (Gestão → Bot do WhatsApp)

Só com `Plano::temRecurso('bot_whatsapp')` (senão `fora_do_plano.php`); editável
sem Evolution configurada. Seções:
1. Textos (4 campos com as variáveis explicadas; vazio = padrão; botão "Restaurar padrão" por texto).
2. Opções do menu: tabela com ordem, rótulo, tipo, ligada; ações subir, descer,
   ligar/desligar, editar (rótulo; resposta se for própria), apagar (só próprias).
3. Nova opção própria (rótulo + resposta).
4. Prévia: o menu como cliente com cadastro (nome "Maria") e sem cadastro.

Todo POST com CSRF, PRG e mensagem de erro de validação. O cartão do bot em
`admin/diferenciais.php` ganha o link para a tela; ligar/desligar e webhook ficam lá.

## Testes

`tests/bot_personalizado.php` (banco temporário, MySQL e PostgreSQL), chamando
`Bot::atender` e `BotMenu`:
- padrão idêntico ao texto de hoje (cliente e sem cadastro); `tests/planos_bot.php` segue verde;
- desligar `cancelar` renumera: "Serviços e preços" vira 3 e a escolha 3 responde serviços;
- reordenar muda número e escolha;
- opção própria responde seu texto; apagada some; embutida não pode ser apagada;
- textos com `{nome}`, `{empresa}`, `{link_cadastro}`; vazio volta ao padrão;
- sem cadastro não vê nem escolhe `marcar`;
- "Mande *N*" com o número real da equipe e sem a frase quando desligada;
- limites (13ª opção, rótulo/resposta longos) recusados com mensagem;
- isolamento: opções de uma empresa não aparecem na outra.
