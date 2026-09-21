# Prompt para gerar o MER e o DER do sistema Agendei

> Copie tudo o que está entre as linhas `--- INÍCIO DO PROMPT ---` e
> `--- FIM DO PROMPT ---` e cole em uma conversa nova da Claude.

--- INÍCIO DO PROMPT ---

Você é um analista de banco de dados. Preciso do **MER (modelo conceitual)** e do
**DER (modelo lógico)** de um sistema web de agendamento de serviços já
implementado em PHP 8 + MySQL/MariaDB. Abaixo está o dicionário de dados real
extraído do script de criação do banco. Não invente entidades, atributos nem
relacionamentos: use exatamente o que está descrito.

## O que eu quero como resposta

1. **MER conceitual** — lista das entidades, seus atributos (identificando os
   identificadores), os relacionamentos com nome, e a cardinalidade de cada
   lado na notação (mín, máx). Aponte as entidades fortes, as fracas, as
   especializações/generalizações e as entidades associativas.
2. **DER lógico** — diagrama em **Mermaid (`erDiagram`)**, com todas as tabelas,
   os tipos das colunas, marcação de PK/FK/UK e os relacionamentos rotulados.
   O código Mermaid precisa estar correto e renderizável.
3. **Diagrama simplificado** — uma segunda versão em Mermaid contendo apenas as
   7 entidades do núcleo acadêmico: `estabelecimento`, `usuarios`, `clientes`,
   `profissionais`, `servicos`, `agendamentos` e `logs_autenticacao`. Essa
   versão é a que vai para o relatório impresso, então precisa caber em uma
   página.
4. **Dicionário de dados** — tabela com: tabela, coluna, tipo, nulo, chave e
   descrição.
5. **Justificativa das decisões de modelagem** — explique em texto: a
   especialização de `usuarios`, as chaves compostas de multi-tenant, a
   desnormalização proposital em `logs_autenticacao` e a razão de `agendamentos`
   guardar `valor` e `hora_fim` em vez de derivá-los do serviço.

Escreva em português do Brasil, em tom técnico e objetivo.

## Contexto do sistema

- Sistema de agendamento de serviços com hora marcada (salões, barbearias,
  clínicas, oficinas, autônomos).
- **Multiestabelecimento**: vários estabelecimentos convivem no mesmo banco.
  Quase toda tabela carrega `id_estabelecimento`, e as chaves estrangeiras são
  compostas por `(id_estabelecimento, id_registro)` justamente para impedir, no
  nível do banco, que um registro de uma empresa seja associado a outra.
- **Quatro perfis**: `cliente`, `profissional` e `admin` (todos são linhas de
  `usuarios`, diferenciadas pela coluna `tipo` e por uma tabela de
  especialização própria) e o master global da plataforma, que vive em uma
  tabela separada, sem vínculo com estabelecimento.
- **Motor de agenda**: os horários livres são calculados a partir do expediente
  semanal do profissional (`horarios_profissionais`), descontando os
  agendamentos ativos e os bloqueios (`bloqueios_agenda`).
- O banco tem 20 tabelas.

## Dicionário de dados

### 1. estabelecimento
PK `id_estabelecimento` INT UNSIGNED AI.
Colunas: `nome` VARCHAR(120) NN, `slogan` VARCHAR(180), `descricao` TEXT,
`telefone` VARCHAR(20), `whatsapp` VARCHAR(20), `email` VARCHAR(150),
`endereco` VARCHAR(180), `bairro` VARCHAR(100), `cidade` VARCHAR(100),
`uf` CHAR(2), `cep` VARCHAR(9), `horario_funcionamento` TEXT,
`instagram` VARCHAR(120), `facebook` VARCHAR(120), `data_atualizacao` DATETIME,
`slug` VARCHAR(80) NN UNIQUE, `cor_primaria` CHAR(7), `cor_secundaria` CHAR(7),
`cor_fundo` CHAR(7), `fonte` VARCHAR(30), `logo` MEDIUMTEXT,
`status` ENUM('ativo','inativo').
É a entidade raiz do multi-tenant: todas as demais tabelas de dados apontam
para ela.

### 2. administradores_master
PK `id_master` INT UNSIGNED AI.
Colunas: `nome` VARCHAR(120) NN, `email` VARCHAR(150) NN UNIQUE,
`senha_hash` VARCHAR(255) NN, `status` ENUM('ativo','inativo'),
`cor_primaria` CHAR(7), `cor_secundaria` CHAR(7), `cor_fundo` CHAR(7),
`fonte` VARCHAR(30), `logo` MEDIUMTEXT, `ultimo_acesso` DATETIME,
`data_criacao` DATETIME, `data_atualizacao` DATETIME.
**Não possui `id_estabelecimento`**: é a conta global que cria os
estabelecimentos. Não se relaciona por FK com nenhuma outra tabela.

### 3. usuarios
PK `id_usuario` INT UNSIGNED AI. FK `id_estabelecimento` → estabelecimento.
Colunas: `nome` VARCHAR(120) NN, `email` VARCHAR(150) NN,
`senha_hash` VARCHAR(255) NN, `telefone` VARCHAR(20), `telefone_fixo` VARCHAR(20),
`login` VARCHAR(6), `sexo` ENUM('F','M','O'), `nome_materno` VARCHAR(120),
`data_nascimento` DATE, `cep` CHAR(8), `logradouro` VARCHAR(150),
`numero` VARCHAR(20), `complemento` VARCHAR(60), `bairro` VARCHAR(100),
`cidade` VARCHAR(100), `uf` CHAR(2),
`tipo` ENUM('cliente','profissional','admin') NN DEFAULT 'cliente',
`status` ENUM('ativo','inativo'), `token_recuperacao` VARCHAR(64),
`token_expiracao` DATETIME, `ultimo_acesso` DATETIME, `data_criacao` DATETIME,
`data_atualizacao` DATETIME.
UNIQUE: `(id_estabelecimento, id_usuario)`, `(id_estabelecimento, email)`,
`(id_estabelecimento, login)`.
Observações importantes para o modelo:
- É a **superentidade** de uma especialização total e exclusiva: toda linha de
  `usuarios` é exatamente uma de `clientes`, `profissionais` ou
  `administradores`, conforme o valor de `tipo`.
- `nome_materno`, `data_nascimento` e `cep` são os três dados que respondem às
  perguntas do segundo fator de autenticação. Ficam aqui, e não em `clientes`,
  porque o administrador também passa pelo 2FA.
- O `login` tem exatamente 6 letras e a senha exatamente 8 letras (regra de
  negócio validada na aplicação, gravada com hash).

### 4. clientes  (especialização de usuarios)
PK `id_cliente` INT UNSIGNED AI. FK `id_usuario` → usuarios (UNIQUE, 1:1,
ON DELETE CASCADE). FK `id_estabelecimento` → estabelecimento.
Colunas: `cpf` CHAR(11), `data_nascimento` DATE, `observacoes` TEXT,
`pontos_fidelidade` INT UNSIGNED DEFAULT 0, `data_cadastro` DATETIME.
UNIQUE: `(id_estabelecimento, id_cliente)`, `(id_estabelecimento, cpf)`.
O **CPF fica aqui**, não em `usuarios`.

### 5. profissionais  (especialização de usuarios)
PK `id_profissional` INT UNSIGNED AI. FK `id_usuario` → usuarios (UNIQUE, 1:1,
ON DELETE CASCADE). FK `id_estabelecimento` → estabelecimento.
Colunas: `especialidade` VARCHAR(120), `bio` TEXT, `foto` VARCHAR(255),
`pode_bloquear_agenda` TINYINT(1) DEFAULT 1, `data_cadastro` DATETIME,
`comissao_percentual` DECIMAL(5,2) DEFAULT 0.00, `token_calendario` CHAR(64).
UNIQUE: `(id_estabelecimento, id_profissional)`.

### 6. administradores  (especialização de usuarios)
PK `id_administrador` INT UNSIGNED AI. FK `id_usuario` → usuarios (UNIQUE, 1:1,
ON DELETE CASCADE). FK `id_estabelecimento` → estabelecimento.
Colunas: `nivel` ENUM('super','gerente') DEFAULT 'super', `data_cadastro` DATETIME.
UNIQUE: `(id_estabelecimento, id_administrador)`.

### 7. servicos
PK `id_servico` INT UNSIGNED AI. FK `id_estabelecimento` → estabelecimento.
Colunas: `nome` VARCHAR(120) NN, `descricao` TEXT,
`preco` DECIMAL(10,2) DEFAULT 0.00 (CHECK >= 0),
`duracao_minutos` SMALLINT UNSIGNED DEFAULT 30 (CHECK > 0),
`status` ENUM('ativo','inativo'), `destaque` TINYINT(1),
`data_cadastro` DATETIME, `data_atualizacao` DATETIME.
UNIQUE: `(id_estabelecimento, id_servico)`.
`duracao_minutos` é o atributo que define o tamanho do bloco ocupado na agenda.

### 8. profissional_servico  (entidade associativa N:N)
PK composta `(id_profissional, id_servico)`.
FKs: `id_profissional` → profissionais, `id_servico` → servicos,
`id_estabelecimento` → estabelecimento, mais as FKs compostas de tenant.
Não possui atributos próprios. Define quais serviços cada profissional executa.

### 9. horarios_profissionais  (expediente semanal)
PK `id_horario` INT UNSIGNED AI. FK `id_profissional` → profissionais
(ON DELETE CASCADE). FK `id_estabelecimento` → estabelecimento.
Colunas: `dia_semana` TINYINT UNSIGNED NN (CHECK entre 0 e 6),
`hora_inicio` TIME NN, `hora_fim` TIME NN (CHECK hora_fim > hora_inicio),
`intervalo_minutos` SMALLINT UNSIGNED DEFAULT 30,
`status` ENUM('ativo','inativo'), `data_cadastro` DATETIME.
UNIQUE: `(id_profissional, dia_semana, hora_inicio, hora_fim)`,
`(id_estabelecimento, id_horario)`.

### 10. bloqueios_agenda
PK `id_bloqueio` INT UNSIGNED AI. FK `id_profissional` → profissionais
(ON DELETE CASCADE). FK `id_usuario_criou` → usuarios (NULL, ON DELETE SET NULL).
FK `id_estabelecimento` → estabelecimento.
Colunas: `data_bloqueio` DATE NN, `hora_inicio` TIME DEFAULT '00:00:00',
`hora_fim` TIME DEFAULT '23:59:59' (CHECK hora_fim > hora_inicio),
`motivo` VARCHAR(255), `data_criacao` DATETIME.
UNIQUE: `(id_estabelecimento, id_bloqueio)`.

### 11. agendamentos  (entidade central)
PK `id_agendamento` INT UNSIGNED AI.
FKs: `id_cliente` → clientes (CASCADE), `id_profissional` → profissionais,
`id_servico` → servicos, `id_usuario_cancelou` → usuarios (NULL, SET NULL),
`id_cliente_pacote` → cliente_pacotes (NULL),
`id_estabelecimento` → estabelecimento, mais as FKs compostas de tenant que
garantem que cliente, profissional e serviço sejam do mesmo estabelecimento.
Colunas: `data_agendamento` DATE NN, `hora_inicio` TIME NN,
`hora_fim` TIME NN (CHECK hora_fim > hora_inicio),
`valor` DECIMAL(10,2) DEFAULT 0.00,
`status` ENUM('agendado','confirmado','concluido','cancelado') NN DEFAULT 'agendado',
`observacao` TEXT,
`origem` ENUM('cliente','admin','profissional') NN DEFAULT 'cliente',
`motivo_cancelamento` VARCHAR(255), `data_criacao` DATETIME,
`data_atualizacao` DATETIME, `grupo_recorrencia` CHAR(36) (identificador
compartilhado pelas repetições de um agendamento semanal recorrente).
UNIQUE: `(id_estabelecimento, id_agendamento)`.
Regras de negócio que o modelo precisa suportar:
- `hora_fim` e `valor` são **copiados** do serviço no momento da criação, para
  que a alteração futura do preço ou da duração não altere o histórico;
- não pode existir mais de um agendamento ativo (`agendado` ou `confirmado`)
  do **mesmo profissional** no mesmo intervalo de tempo;
- agendamentos cancelados liberam o horário, mas a linha permanece no banco.

### 12. logs_autenticacao
PK `id_log` INT UNSIGNED AI. FK `id_estabelecimento` → estabelecimento.
Colunas: `id_usuario` INT UNSIGNED NULL (**sem chave estrangeira**),
`login_informado` VARCHAR(150) NN, `nome` VARCHAR(120) NN DEFAULT '',
`cpf` CHAR(11), `perfil` VARCHAR(20),
`evento` ENUM('login_sucesso','login_falha','2fa_sucesso','2fa_falha','2fa_bloqueio','logout') NN,
`fator_2fa` ENUM('nome_materno','data_nascimento','cep'),
`ip` VARCHAR(45), `data_hora` DATETIME DEFAULT CURRENT_TIMESTAMP.
`nome` e `cpf` são gravados por cópia e a tabela **não tem FK para `usuarios`**:
é uma desnormalização proposital, para que o histórico de acessos sobreviva à
exclusão do usuário feita pelo administrador.

### 13. configuracoes
PK `id_configuracao` INT UNSIGNED AI. FK `id_estabelecimento` → estabelecimento.
Colunas: `chave` VARCHAR(60) NN, `valor` VARCHAR(255) NN,
`descricao` VARCHAR(255).
UNIQUE: `(id_estabelecimento, chave)`.
Formato chave/valor. Chaves em uso: `antecedencia_minima_horas`,
`antecedencia_maxima_dias`, `cancelamento_limite_horas`,
`intervalo_slots_minutos`, `permitir_bloqueio_profissional`,
`confirmar_automaticamente`.

### 14. lista_espera
PK `id_lista`. FKs compostas de tenant para `clientes`, `servicos` e
`profissionais` (este último NULL = qualquer profissional), e FK para
`estabelecimento`.
Colunas: `data_desejada` DATE NN,
`periodo` ENUM('qualquer','manha','tarde','noite') DEFAULT 'qualquer',
`status` ENUM('aguardando','avisado','convertido','cancelado') DEFAULT 'aguardando',
`data_aviso` DATETIME, `data_criacao` DATETIME.
UNIQUE: `(id_estabelecimento, id_cliente, id_servico, id_profissional, data_desejada)`.

### 15. notificacoes
PK `id_notificacao`. FKs compostas de tenant para `usuarios` (NULL) e
`agendamentos` (NULL), e FK para `estabelecimento`.
Colunas: `canal` ENUM('whatsapp','email','sistema') DEFAULT 'sistema',
`tipo` VARCHAR(40) NN, `destinatario` VARCHAR(150), `mensagem` TEXT NN,
`status` ENUM('pendente','enviada','lida','cancelada') DEFAULT 'pendente',
`data_programada` DATETIME, `data_envio` DATETIME, `data_criacao` DATETIME.
UNIQUE: `(id_estabelecimento, id_agendamento, tipo)`.

### 16. pagamentos
PK `id_pagamento`. FK composta de tenant para `agendamentos` (CASCADE) e FK para
`estabelecimento`.
Colunas: `tipo` ENUM('sinal','integral') DEFAULT 'sinal',
`valor` DECIMAL(10,2) NN,
`metodo` ENUM('pix','dinheiro','cartao','outro') DEFAULT 'pix',
`status` ENUM('pendente','pago','cancelado','estornado') DEFAULT 'pendente',
`referencia` VARCHAR(80), `data_pagamento` DATETIME, `data_criacao` DATETIME.
UNIQUE: `(id_estabelecimento, id_agendamento, tipo)`.

### 17. fidelidade_movimentos
PK `id_movimento`. FKs compostas de tenant para `clientes` (CASCADE) e
`agendamentos` (NULL, CASCADE), e FK para `estabelecimento`.
Colunas: `pontos` INT NN (aceita valor negativo para resgate),
`descricao` VARCHAR(180) NN, `data_criacao` DATETIME.
UNIQUE: `(id_estabelecimento, id_agendamento)` — um agendamento pontua uma vez só.

### 18. pacotes
PK `id_pacote`. FK composta de tenant para `servicos` e FK para `estabelecimento`.
Colunas: `nome` VARCHAR(120) NN, `quantidade` SMALLINT UNSIGNED NN,
`validade_dias` SMALLINT UNSIGNED DEFAULT 90, `preco` DECIMAL(10,2) NN,
`status` ENUM('ativo','inativo'), `data_criacao` DATETIME.

### 19. cliente_pacotes
PK `id_cliente_pacote`. FKs compostas de tenant para `clientes` (CASCADE) e
`pacotes`, e FK para `estabelecimento`.
Colunas: `creditos_total` SMALLINT UNSIGNED NN,
`creditos_restantes` SMALLINT UNSIGNED NN,
`status_pagamento` ENUM('pendente','pago','cancelado') DEFAULT 'pendente',
`data_expiracao` DATE NN, `data_criacao` DATETIME.
É referenciada por `agendamentos.id_cliente_pacote` quando a reserva consome um
crédito do pacote.

### 20. avaliacoes
PK `id_avaliacao`. FKs compostas de tenant para `agendamentos` (CASCADE),
`clientes` (CASCADE) e `profissionais`, e FK para `estabelecimento`.
Colunas: `nota` TINYINT UNSIGNED NN (CHECK entre 1 e 5),
`comentario` VARCHAR(500), `status` ENUM('publicada','oculta') DEFAULT 'publicada',
`data_criacao` DATETIME.
UNIQUE: `(id_estabelecimento, id_agendamento)` — uma avaliação por atendimento,
e só é permitida depois que o agendamento fica com status `concluido`.

## Relacionamentos e cardinalidades

| Relacionamento | Cardinalidade |
|----------------|---------------|
| estabelecimento **possui** usuarios | 1:N (um estabelecimento tem N usuários; todo usuário pertence a 1) |
| usuarios **é** clientes / profissionais / administradores | 1:1 opcional em cada especialização, total e exclusiva no conjunto |
| estabelecimento **oferece** servicos | 1:N |
| profissionais **executa** servicos | N:N, resolvido por `profissional_servico` |
| profissionais **cumpre** horarios_profissionais | 1:N |
| profissionais **registra** bloqueios_agenda | 1:N |
| usuarios **cria** bloqueios_agenda | 1:N opcional (`id_usuario_criou`) |
| clientes **realiza** agendamentos | 1:N |
| profissionais **atende** agendamentos | 1:N |
| servicos **é objeto de** agendamentos | 1:N |
| usuarios **cancela** agendamentos | 1:N opcional (`id_usuario_cancelou`) |
| estabelecimento **define** configuracoes | 1:N |
| estabelecimento **registra** logs_autenticacao | 1:N (sem FK para usuarios) |
| clientes **entra em** lista_espera | 1:N |
| agendamentos **gera** notificacoes | 1:N opcional |
| agendamentos **recebe** pagamentos | 1:N (limitado a um por `tipo`) |
| agendamentos **credita** fidelidade_movimentos | 1:1 opcional |
| servicos **compõe** pacotes | 1:N |
| clientes **adquire** pacotes | N:N, resolvido por `cliente_pacotes` |
| cliente_pacotes **custeia** agendamentos | 1:N opcional |
| agendamentos **recebe** avaliacoes | 1:1 opcional |

--- FIM DO PROMPT ---
