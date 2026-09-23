# Agendei - Sistema de Agendamento de Servicos

Sistema web completo de agendamento (PHP + MySQL + HTML/CSS/JS puros, sem frameworks).

O sistema aceita vários estabelecimentos no mesmo banco. Contas, clientes,
profissionais, serviços, horários e agendamentos carregam o vínculo da empresa,
e as chaves do banco impedem associações entre estabelecimentos diferentes.

## Requisitos

- PHP 8.0 ou superior (extensao PDO MySQL habilitada)
- MySQL 5.7+ ou MariaDB 10.2+
- Apache (XAMPP, WAMP, Laragon) ou qualquer servidor com PHP

## Instalacao

1. Copie a pasta do projeto para dentro do diretorio publico do servidor
   (ex.: `C:\xampp\htdocs\agendei`).
2. Importe o arquivo `banco.sql` no phpMyAdmin ou pelo terminal:
   ```
   mysql -u root -p < banco.sql
   ```
   O script cria o banco `agendei`, todas as tabelas, os servicos de exemplo,
   os dados do estabelecimento e as configuracoes iniciais.
3. Ajuste as credenciais do banco em `config/database.php` (apenas neste arquivo).
   Para atualizar uma base criada pela versão anterior, execute uma vez:
   ```
   php scripts/migrar.php
   ```
4. Acesse `http://localhost/agendei/instalar.php` e clique em **Executar instalacao**.
   Serao criados o administrador, tres profissionais com expediente configurado,
   um cliente de demonstracao e alguns agendamentos.
5. **Apague o arquivo `instalar.php`** depois da instalacao.

Para publicar o sistema numa hospedagem na internet, veja
[IMPLANTACAO.md](IMPLANTACAO.md).

### Acessos criados pelo instalador

| Perfil do projeto | Perfil interno | Login  | E-mail                 | Senha    |
|-------------------|----------------|--------|------------------------|----------|
| Usuario master    | admin          | admini | admin@agendei.com.br   | agendeis |
| Usuario comum     | cliente        | anabea | cliente@agendei.com.br | agendeis |
| (fora do escopo)  | profissional   | -      | marcos@agendei.com.br  | agendeis |
| (fora do escopo)  | master global  | -      | master@agendei.com.br  | agendei-master-2026 |

O campo **Login** da tela de entrada aceita tanto o login de 6 letras quanto o e-mail.

Respostas do segundo fator (2FA) das contas de demonstracao:

| Conta  | Nome da mae              | Nascimento | CEP       |
|--------|--------------------------|------------|-----------|
| admini | Helena Duarte do Sistema | 10/02/1985 | 01000-000 |
| anabea | Marcia Lima Souza        | 18/04/1995 | 01310-100 |

Altere as senhas apos o primeiro acesso.

O administrador master entra em `master/login.php`. Ele cria cada estabelecimento
junto com sua primeira conta administrativa. O administrador local cadastra sua
equipe e seus serviços, compartilha o link exclusivo mostrado em **Aparência** e
personaliza nome, logo, cores e fonte. Clientes cadastrados por esse link recebem
o mesmo vínculo e veem apenas os serviços e profissionais daquela empresa.

A área master tem ainda:

| Tela | Para que serve |
|------|----------------|
| **Estabelecimentos** | Criar empresas, ligar e desligar o acesso delas, adicionar administradores, redefinir a senha de quem perdeu o acesso ao painel |
| **Segurança** | Acessos e falhas de login de todas as empresas em uma consulta só, e liberação dos bloqueios do controle de força bruta |
| **Auditoria** | O que cada conta master fez: empresa criada, acesso alterado, senha redefinida, bloqueio liberado — com data, alvo e origem |
| **Contas master** | Criar e desativar contas globais. Duas regras não podem ser quebradas: a última conta ativa nunca é desligada e ninguém desliga a si mesmo |
| **Uso da plataforma** | Movimento de cada empresa em 7, 30 ou 90 dias: agendamentos criados, clientes novos e último acesso. Separa quem está ativo de quem só tem cadastro antigo |
| **Planos e limites** | Tetos de profissionais, serviços e agendamentos por mês, conferidos no cadastro e na reativação. Campo em branco = ilimitado, e empresa sem plano continua sem teto |
| **Saúde do sistema** | Banco, tabelas de apoio, versão do PHP, HTTPS, instalador exposto e empresas sem administrador — tudo sem abrir o servidor |

Redefinir a senha de um administrador local pede a senha master de novo: a ação
dá acesso aos dados dos clientes daquela empresa e fica registrada na auditoria.

Para atender um chamado sem tirar o acesso de quem pediu ajuda, **Estabelecimentos
→ Entrar no painel** abre o painel do cliente em nome de um administrador dele.
Enquanto durar, uma faixa fixa no topo diz de quem é a tela, a entrada e a saída
vão para a auditoria e o "último acesso" da conta não é alterado. Também aqui a
senha master é pedida de novo.

## Atendimento aos requisitos do projeto academico

O sistema foi adaptado para atender a especificacao da disciplina sem descartar o
que ja existia. Os dois perfis exigidos correspondem a perfis que o sistema ja
tinha, e o controle continua sendo feito pela sessao (`usuarios.tipo`):

| Perfil da especificacao | Perfil no sistema | Onde entra                 |
|-------------------------|-------------------|----------------------------|
| Usuario master          | `admin`           | Criado pelo `instalar.php` |
| Usuario comum           | `cliente`         | Cria a propria conta       |

Os perfis `profissional` e o master global da plataforma continuam existindo e
funcionando, mas ficam fora do escopo avaliado.

### Telas exigidas

| Tela                | Arquivo                  | Acesso          |
|---------------------|--------------------------|-----------------|
| Principal           | `index.php`              | Master e comum  |
| Cadastro de usuario | `cadastro.php`           | Visitante       |
| Cadastro de empresa | `cadastro_empresa.php`   | Visitante       |
| Login               | `login.php`              | Visitante       |
| Erro                | `erro.php`               | Master e comum  |
| 2FA                 | `dois_fatores.php`       | Master e comum  |
| Consulta de usuario | `admin/usuarios.php`     | Somente master  |
| Alteracao de senha  | `cliente/perfil.php`     | Somente comum   |
| Modelo do BD        | `modelo_bd.php`          | Logado, so pelo endereco (fora do menu) |
| Log                 | `admin/logs.php`         | Somente master  |

### Regras de validacao do cadastro

Todas sao conferidas no navegador (`assets/js/cadastro.js`) e novamente no
servidor (`includes/funcoes.php`), porque a validacao do cliente pode ser burlada.

| Campo           | Regra                                              |
|-----------------|----------------------------------------------------|
| Nome completo   | 15 a 80 caracteres, apenas letras e espacos        |
| CPF             | Conferencia dos dois digitos verificadores         |
| CEP             | 8 digitos, preenche o endereco pela API ViaCEP     |
| Telefone celular| DDD + 9 digitos, gravado como `(+55)XX-XXXXXXXXX`  |
| Telefone fixo   | DDD + 8 digitos, gravado como `(+55)XX-XXXXXXXX`   |
| Login           | Exatamente 6 caracteres alfabeticos, unico         |
| Senha           | Exatamente 8 caracteres alfabeticos, com hash      |
| Confirmar senha | Igual a senha                                      |

Sem conexao com a API do CEP, os campos de endereco continuam editaveis para
preenchimento manual.

### Segundo fator de autenticacao

Apos validar login e senha, o sistema sorteia uma entre tres perguntas (nome da
mae, data de nascimento ou CEP) e so abre a sessao depois da resposta correta.
A resposta ignora acentos, caixa e mascara. Na terceira tentativa sem exito o
sistema mostra `3 tentativas sem sucesso! Favor realizar Login novamente.` e volta
para a tela de login.

Os dados que respondem as perguntas ficam em `usuarios`, e nao em `clientes`,
para que o master tambem consiga passar pelo 2FA.

### Log de autenticacao

A tabela `logs_autenticacao` guarda data e hora, nome, CPF, login informado,
evento e qual pergunta do 2FA foi usada. O nome e o CPF sao gravados por copia e
a tabela nao tem chave estrangeira para `usuarios`: assim o historico sobrevive
quando o master exclui um usuario. A tela filtra por nome, por CPF ou por todos,
sempre da entrada mais recente para a mais antiga.

### Desafios extras

- **PDF da lista de usuarios**: botao *Baixar PDF* em `admin/usuarios.php`.
  O arquivo e gerado por `models/PdfSimples.php`, escrito em PHP puro, sem
  bibliotecas externas nem Composer.
- **Barra de acessibilidade**: `assets/js/acessibilidade.js` e carregada por
  `includes/tema.php`, portanto aparece em todas as telas. Oferece alto contraste
  (fundo escuro / fonte clara) e tres tamanhos de fonte, com a preferencia
  guardada no navegador.

### Testes

```bash
php tests/requisitos.php      # regras de validacao e 2FA (nao usa banco)
php tests/fluxo_projeto.php   # cadastro, login, 2FA, log e exclusao (banco temporario)
php tests/multitenancy.php    # isolamento entre estabelecimentos (banco temporario)
php tests/master.php          # auditoria, contas master e bloqueios (banco temporario)
php tests/entrada_global.php  # login geral sem link da empresa (nao usa banco)
php tests/lembretes.php       # lembretes por WhatsApp, com provedor simulado (banco temporario)
php tests/lembrar_google.php  # manter conectado e entrada com o Google (nao usa banco)
```

Os tres ultimos criam e descartam um banco proprio e nunca tocam o banco de uso normal.

## Identidade visual

O sistema tem marca propria, separada da identidade de cada empresa atendida.
O slogan e **Marcou, confirmou.** (`MARCA_SLOGAN`, em `includes/marca.php`);
o guia completo de marca, com tom de voz, regras do logotipo e mensagens de
venda, esta em [MARCA.md](MARCA.md).

| Cor | Codigo | Uso |
| --- | --- | --- |
| Primaria | `#1F4E5F` | corpo do simbolo, titulos, botoes e menu lateral |
| Destaque | `#5FAF8B` | faixa do simbolo, confirmacoes e estados de sucesso |
| Fundo | `#F5F1EA` | fundo das telas e versao clara do simbolo |

Sao as mesmas cores do tema de fabrica (`Tema::PADRAO`), mantidas sem alteracao.

**O simbolo** e uma agenda confirmada: a faixa superior na cor de destaque e o
"check" do horario marcado no corpo. Funciona de 16 px (aba do navegador) a
qualquer tamanho, e tem versao para fundo claro, para fundo escuro e para o
modo de alto contraste.

**Onde aparece cada marca:**

- A **marca do sistema** assina o que e do produto: pagina inicial sem link de
  empresa (`index.php`), entrada geral (`entrar.php`), icone da aba, instalador,
  tela de saida, rodape publico e o rodape das telas de entrada.
- A **marca da empresa** (logo e cores cadastradas em *Aparencia*) continua
  mandando nas telas do estabelecimento, via `Tema::marca()`. A marca do
  sistema nunca a substitui.

**Arquivos:**

- `includes/marca.php` - `simboloSistema()`, `marcaSistema()` e `faviconSistema()`,
  que desenham a marca em SVG embutido e acompanham o modo de alto contraste.
- `assets/img/` - os mesmos desenhos em arquivo, para uso fora do sistema
  (documentacao, e-mail, material impresso):
  `agendei-logo.svg` e `agendei-logo-claro.svg` (marca com o nome),
  `agendei-simbolo.svg` e `agendei-simbolo-claro.svg` (so o simbolo),
  `favicon.svg` (icone da aba).

## Estrutura do projeto

```
/agendei
  /admin           Painel administrativo (dashboard, agenda, CRUDs, relatorios)
  /api             Endpoints JSON (filiais, profissionais, horarios livres, dias com vaga)
  /assets          css, js e imagens
  /cliente         Painel do cliente (dashboard, agendamento, historico, perfil)
  /config          config.php (bootstrap) e database.php (conexao PDO)
  /includes        auth.php, funcoes.php, layout (header/sidebar/footer), icones e marca
  /models          Regras de acesso ao banco (uma classe por entidade)
  /profissional    Painel do profissional (agenda, horarios, bloqueios, perfil)
  banco.sql        Estrutura e dados iniciais do banco
  index.php        Pagina inicial do produto (sem link de empresa) ou entrada da empresa
  entrar.php       Entrada geral: e-mail e senha, sem saber o link da empresa
  cadastro_empresa.php  Cadastro de empresa pela pagina inicial, aprovado pelo master
  login.php / cadastro.php / logout.php / recuperar_senha.php / redefinir_senha.php
```

### Como o sistema esta organizado

- **`config/config.php`** e o unico arquivo que cada pagina precisa incluir.
  Ele carrega a conexao, as funcoes, a autenticacao e registra o autoload dos models.
- **Models** concentram todo o SQL (PDO + prepared statements). Nenhuma pagina
  monta consulta diretamente.
- **`models/Disponibilidade.php`** e o motor de horarios: gera os slots livres a
  partir do expediente do profissional, descontando agendamentos e bloqueios.
- **`models/Agendamento.php::criar()`** abre uma transacao, revalida tudo com
  `SELECT ... FOR UPDATE` e so entao grava - dois clientes nunca conseguem
  reservar o mesmo horario.

### Filiais (unidades)

Um estabelecimento pode ter mais de uma unidade (matriz e filiais), cada uma
com nome, endereco, telefone e foto. Cada profissional pertence a uma filial,
e os servicos que uma unidade oferece sao os dos profissionais ativos que
trabalham nela - nao existe cadastro separado de servico por filial. No
agendamento, o cliente escolhe o servico e em seguida a unidade; so entao ve
os profissionais daquela filial. Todo agendamento grava a filial em que foi
marcado. Uma filial inativa deixa de aparecer para o cliente, mas o historico
dela permanece.

O administrador cadastra as unidades em **Admin > Filiais** e acompanha o
faturamento por unidade em **Relatorios**.

Para atualizar uma base criada antes das filiais, execute uma vez:

```
php scripts/migrar.php            # MySQL (ja encadeia a migracao das filiais)
php scripts/migrar_filiais.php    # PostgreSQL
```

O script cria a tabela `filiais`, uma filial **Matriz** para cada
estabelecimento e vincula a ela os profissionais e agendamentos que ja
existiam. Bancos novos ja nascem com a Matriz (`banco.sql` e
`banco_postgres.sql`).

## Cadastro de empresas pela pagina inicial

A pagina inicial oferece **Cadastrar minha empresa** (`cadastro_empresa.php`):
nome da empresa, endereco exclusivo, responsavel, e-mail, telefone e senha. O
envio cria a empresa **inativa** e a conta administrativa do responsavel, e
registra uma solicitacao (`models/Solicitacao.php`, tabela
`solicitacoes_cadastro`, criada na primeira chamada). Ate a decisao:

- o login da empresa explica que o cadastro aguarda aprovacao;
- a entrada geral nao aceita a conta, porque a empresa esta inativa;
- o master ve o pedido em **Estabelecimentos > Cadastros aguardando aprovacao**
  e um aviso na visao geral.

**Aprovar** ativa a empresa e libera o login. **Recusar** apaga a empresa e a
conta; a solicitacao fica como historico. As tres acoes (pedido, aprovacao e
recusa) entram na auditoria master. O envio e limitado por origem
(`cadastro_empresa_ip`) para nao encher a fila com pedidos automatizados.

## E-mail

O sistema envia e-mail sem dependencias (`models/Email.php`), pela API da
Brevo por HTTPS (o caminho para o Render, que bloqueia SMTP) ou por SMTP, para:
confirmar o cadastro de empresa ao responsavel e avisar os masters; comunicar a
aprovacao ou a recusa; e entregar o link de recuperacao de senha. As mensagens
ficam em `includes/emails.php`.

A configuracao vem de variaveis de ambiente (`AGENDEI_EMAIL_API_CHAVE` e
`_REMETENTE` para a API; `AGENDEI_EMAIL_HOST`, `_PORTA`, `_SEGURANCA`,
`_USUARIO`, `_SENHA`, `_REMETENTE`, `_NOME` para SMTP) ou de
`config/email.local.php` (nao versionado). Sem configuracao nada quebra: os
envios devolvem falso, o motivo vai para o log e as telas mostram o caminho
manual. Em **Master > Saude do sistema** ha um botao para enviar um e-mail de
teste. Passo a passo com Gmail ou Brevo em [DEPLOY.md](DEPLOY.md).

## Manter conectado e entrar com o Google

**Manter conectado.** A opcao no login (e na entrada geral) guarda um segundo
cookie, valido por 30 dias, separado do cookie de sessao. Ele carrega um
seletor e um segredo; o banco (`sessoes_lembradas`) guarda so o hash do
segredo, que e trocado a cada uso. Quando a sessao cai (navegador fechado,
inatividade), o bootstrap a reabre a partir dele (`restaurarSessaoLembrada()`
em `includes/auth.php`) e registra a entrada no log de autenticacao. Sair da
conta apaga o registro; trocar ou redefinir a senha apaga todos os
dispositivos lembrados da conta. O cookie nao age num link de outra empresa
nem na area master, e conta ou empresa inativa o invalida. Quem exige segundo
fator so e lembrado depois de responde-lo.

**Entrar com o Google.** Com `AGENDEI_GOOGLE_CLIENT_ID` e
`AGENDEI_GOOGLE_CLIENT_SECRET` definidas (ou `config/google.local.php`), o
login e a entrada geral mostram o botao. O Google confirma o e-mail (OpenID
Connect, `models/Google.php` e `google_login.php`) e o sistema abre a conta
daquele e-mail: pelo link de uma empresa, a conta dela; pela entrada geral,
todas, com a mesma escolha de empresa. O Google nao cria conta, porque o
cadastro exige dados que ele nao fornece; e-mail sem conta e orientado a se
cadastrar. Nas telas de cadastro (cliente e empresa) o botao "Cadastrar com
o Google" confirma o e-mail e volta com nome e e-mail preenchidos; a pessoa
completa o restante, e um e-mail que ja tem conta entra direto. O segundo
fator continua valendo. Passo a passo das credenciais em
[DEPLOY.md](DEPLOY.md).

Bancos criados antes desta versao precisam de `php scripts/migrar_lembrar.php`
(MySQL e PostgreSQL); o `migrar.php` ja o encadeia.

## Lembretes por WhatsApp

O sistema lembra o cliente do atendimento pelo WhatsApp, sem ninguem clicar.
A fila e uma so (tabela `notificacoes`): `Diferencial::gerarLembretes()` poe
nela uma mensagem para cada agendamento das proximas N horas (**Admin >
Diferenciais**, "Gerar lembrete ate (horas)"), e `models/Lembrete.php` envia
o que esta pronto pelo provedor configurado. Sem provedor, a fila continua
manual como antes: o painel mostra a mensagem com o link "Abrir WhatsApp".

Provedores (`models/WhatsApp.php`):

| Provedor | Quando usar | O que informar |
|---|---|---|
| **Evolution API** | Numero comum de WhatsApp, sem aprovacao da Meta. Voce hospeda a Evolution (codigo aberto) e conecta o numero pelo QR code | URL, instancia e apikey |
| **WhatsApp Cloud API (Meta)** | Numero oficial verificado. Mensagem iniciada pela empresa precisa de um modelo aprovado | ID do numero, token e nome do modelo, criado em pt_BR com 4 variaveis nesta ordem: nome, servico, data, hora |

Cada empresa escolhe o provedor em **Admin > Diferenciais > Lembretes por
WhatsApp**, envia uma mensagem de teste e acompanha a fila: pendentes,
tentativas, razao da falha e as ultimas mensagens enviadas ou canceladas.
"Padrao da plataforma" usa o numero de quem hospeda o sistema, definido nas
variaveis `AGENDEI_WHATSAPP_PROVEDOR` (`evolution` ou `meta`), `_URL`,
`_INSTANCIA`, `_TOKEN`, `_TELEFONE_ID` e `_MODELO`.

O envio acontece numa tarefa periodica, que roda de 10 em 10 ou 15 em 15 minutos:

```bash
php scripts/enviar_lembretes.php                 # cron do servidor
https://seu-dominio/tarefas.php?chave=SUA_CHAVE  # hospedagem sem cron (Render)
```

O gatilho por URL so existe com `AGENDEI_TOKEN_TAREFAS` definida (16+
caracteres); chave errada responde 404 e entra no controle de forca bruta.
Um agendador gratuito como cron-job.org chama a URL no intervalo escolhido.

Regras que a tarefa aplica antes de cada envio: agendamento cancelado ou
concluido cancela o lembrete (tambem na hora do cancelamento, por
`Agendamento::cancelar`); horario que ja passou e telefone sem DDD nao saem;
falha do provedor conta uma tentativa, e depois de tres a mensagem fica so na
fila manual, com a razao visivel. Na API da Meta apenas o lembrete e enviado
sozinho, porque so ele tem modelo; o aviso de vaga da lista de espera segue manual.

Bancos criados antes desta versao precisam de `php scripts/migrar_lembretes.php`
(MySQL e PostgreSQL); o `migrar.php` ja o encadeia.

## Regras de negocio garantidas pelo servidor

- Sem dois agendamentos no mesmo horario para o mesmo profissional.
- Sem agendamento fora do expediente cadastrado.
- Sem agendamento em horario bloqueado.
- Sem agendamento com profissional ou servico inativo.
- Sem agendamento fora das janelas de antecedencia minima e maxima.
- Cliente nao acessa area administrativa; profissional nao acessa funcoes de admin.
- O cliente cancela apenas dentro do prazo configurado.

As mesmas validacoes existem no JavaScript apenas para melhorar a experiencia:
a decisao final e sempre do PHP.

## Configuracoes ajustaveis (Admin > Configuracoes)

| Chave                          | Descricao                                          |
|--------------------------------|----------------------------------------------------|
| antecedencia_minima_horas      | Tempo minimo entre o agendamento e o atendimento   |
| antecedencia_maxima_dias       | Ate quantos dias no futuro o cliente pode agendar  |
| cancelamento_limite_horas      | Prazo para o cliente cancelar sozinho              |
| intervalo_slots_minutos        | Intervalo padrao entre os horarios oferecidos      |
| permitir_bloqueio_profissional | Profissional pode bloquear a propria agenda        |
| confirmar_automaticamente      | Agendamentos do cliente ja nascem confirmados      |

## Seguranca

- Senhas com `password_hash()` / `password_verify()` (e rehash automatico).
- Todas as consultas usam PDO com prepared statements.
- Token CSRF em todos os formularios (`campoCsrf()` + `exigirCsrf()`).
- Saida escapada com `htmlspecialchars()` atraves da funcao `e()`.
- Controle de acesso por perfil em todas as paginas (`exigirLogin('admin')`).
- `.htaccess` bloqueando acesso direto a `config/`, `models/` e `includes/`.

Em producao, altere `AMBIENTE` para `'producao'` em `config/config.php` para
esconder as mensagens de erro detalhadas.

## Preparado para evoluir

A arquitetura ja isola os pontos de extensao para WhatsApp, lembretes,
lista de espera, avaliacoes, cupons, pagamentos, comissoes e integracao com
Google Calendar:

- `agendamentos.origem` identifica o canal de criacao.
- `configuracoes` permite novas regras sem alterar codigo.
- `recuperar_senha.php` tem o ponto de integracao de envio de mensagens marcado.
- Cada entidade tem seu model isolado, entao novos campos e tabelas nao afetam as telas.
## Diferenciais comerciais

O menu **Admin > Diferenciais** reúne os recursos opcionais de cada estabelecimento:

- lista de espera com aviso quando um cancelamento libera uma vaga compatível;
- lembretes por WhatsApp enviados sozinhos (Evolution API ou Meta), com fila manual de reserva;
- cobrança de sinal por chave Pix e confirmação manual do pagamento;
- agendamentos semanais recorrentes;
- pontos de fidelidade creditados quando o atendimento é concluído;
- pacotes de serviços com créditos e validade;
- comissão por profissional e resumo mensal;
- avaliações feitas somente após atendimentos concluídos;
- feed iCalendar privado para Google Agenda, Outlook e Apple Calendar.

O cliente acompanha esses recursos em **Meus benefícios**. Em **Privacidade**,
pode baixar uma cópia de seus dados ou encerrar a conta com anonimização das
informações pessoais. O envio automático pela API oficial do WhatsApp e a
confirmação bancária automática do Pix exigem credenciais de fornecedores
externos; sem elas, o sistema mantém a fila de mensagens, abre a conversa pronta
e permite que o administrador confirme o recebimento.
