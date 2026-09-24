# Publicar o Agendei online (PHP no Render + banco no Supabase)

Depois disto o sistema tem **URL publica** e fica **sempre no ar** — qualquer
pessoa acessa pelo link, sem instalar nada (nem XAMPP). Quem precisa do XAMPP
e so a sua maquina de desenvolvimento; o servidor publico e o Render.

O que ja esta pronto no projeto: `Dockerfile`, `docker-entrypoint.sh` e
`.dockerignore`. O Render le esses arquivos e monta o servidor sozinho.

---

## O que so voce pode fazer

Eu nao consigo criar conta nem clicar em "deploy" no seu lugar. Os passos
abaixo levam ~10 minutos.

### 1. Trocar a senha do Supabase (obrigatorio)

A senha atual vazou no chat. *Supabase -> Project Settings -> Database ->
Reset database password.* Guarde a senha nova para o passo 4.

### 2. Mandar o codigo para o GitHub

O Render puxa o codigo de um repositorio. Se ainda nao tem:

1. Crie um repositorio novo (pode ser privado) em github.com.
2. No projeto:
   ```
   git add -A
   git commit -m "Agendei pronto para deploy"
   git branch -M main
   git remote add origin https://github.com/SEU_USUARIO/agendei.git
   git push -u origin main
   ```

> O `config/database.local.php` (com a senha) NAO vai para o GitHub — o
> `.gitignore` bloqueia. As credenciais entram pelo painel do Render (passo 4).

### 3. Criar o servico no Render

1. Entre em render.com e crie a conta (nao pede cartao no plano free).
2. **New +  ->  Web Service**.
3. Conecte a conta do GitHub e escolha o repositorio.
4. Configuracao:
   - **Language / Runtime:** Docker  (o Render detecta o `Dockerfile` sozinho)
   - **Instance Type:** Free
   - deixe o resto no padrao.

### 4. Configurar as variaveis de ambiente

Ainda na criacao do servico, abra **Environment / Advanced** e adicione:

| Chave | Valor |
|---|---|
| `AGENDEI_DB_DRIVER` | `pgsql` |
| `AGENDEI_DB_HOST` | `aws-0-sa-east-1.pooler.supabase.com` |
| `AGENDEI_DB_PORT` | `5432` |
| `AGENDEI_DB_NAME` | `postgres` |
| `AGENDEI_DB_USER` | `postgres.cmhoboiajxfodmbwosuu` |
| `AGENDEI_DB_PASS` | *(a senha NOVA do passo 1)* |

`AGENDEI_AMBIENTE=producao` ja vem embutido na imagem; nao precisa repetir.

Para o sistema mandar e-mail (aviso de cadastro de empresa, aprovacao e
recuperacao de senha), adicione tambem as variaveis da secao
[E-mail](#e-mail-avisos-e-recuperacao-de-senha), mais abaixo. Sem elas o
sistema funciona, so nao avisa por e-mail.

### 5. Deploy

Clique em **Create Web Service**. O Render monta a imagem e sobe. Ao terminar,
ele mostra a URL publica, algo como `https://agendei.onrender.com`.

Como o banco (Supabase) ja esta instalado, o sistema abre pronto — nao precisa
rodar o `instalar.php` de novo. Faca login e **troque as senhas padrao**.

> Se esse banco foi criado antes das filiais (unidades), rode uma vez, da sua
> maquina, `php scripts/migrar_filiais.php` com o `driver` em `pgsql` apontando
> para o Supabase (veja [SUPABASE.md](SUPABASE.md), passo 3). O script cria a
> tabela `filiais` e a unidade **Matriz**. Banco novo, importado do
> `banco_postgres.sql`, ja vem com ela.

---

## E-mail: avisos e recuperacao de senha

O Render nao tem servidor de e-mail, `mail()` nao funciona la e o plano
gratuito **bloqueia as portas de SMTP para fora** (25, 465 e 587): tentar
o Gmail por SMTP termina em "Connection timed out". O caminho que funciona
e mandar por HTTPS, pela API da Brevo (gratuita, ate 300 e-mails/dia, sem
cartao). Leva uns cinco minutos:

1. Crie a conta em brevo.com com o e-mail que vai aparecer como remetente e
   confirme o e-mail que ela manda.
2. No menu do canto superior direito, abra **SMTP & API**, aba **API keys**,
   e clique em **Generate a new API key**. De um nome (Agendei) e copie a
   chave: ela comeca com `xkeysib-` e so aparece uma vez.
3. No Render, em **Environment**, adicione:

| Chave | Valor |
|---|---|
| `AGENDEI_EMAIL_API_CHAVE` | a chave `xkeysib-...` |
| `AGENDEI_EMAIL_REMETENTE` | o e-mail da conta Brevo (ou outro remetente validado la em *Senders*) |
| `AGENDEI_EMAIL_NOME` | `Agendei` (opcional) |

Salve: o Render reinicia o servico sozinho. Depois entre na area master, abra
**Saude do sistema** e clique em **Enviar e-mail de teste**. A mensagem vai
para o e-mail da sua conta master; se nao chegar, a tela mostra o motivo que
a Brevo devolveu (o mais comum e remetente nao validado).

### SMTP, para servidor com a porta liberada

Fora do Render gratuito (maquina local, VPS, hospedagem propria), o sistema
tambem fala SMTP direto. Com Gmail: ative a verificacao em duas etapas e crie
uma *senha de app* em myaccount.google.com/apppasswords. As variaveis sao:

| Chave | Valor |
|---|---|
| `AGENDEI_EMAIL_HOST` | `smtp.gmail.com` (ou `smtp-relay.brevo.com`) |
| `AGENDEI_EMAIL_PORTA` | `587` |
| `AGENDEI_EMAIL_SEGURANCA` | `tls` (587) ou `ssl` (465); pode omitir |
| `AGENDEI_EMAIL_USUARIO` | o e-mail completo (ou o login SMTP da Brevo) |
| `AGENDEI_EMAIL_SENHA` | a senha de app de 16 letras (ou a chave SMTP) |
| `AGENDEI_EMAIL_REMETENTE` | o e-mail que aparece como remetente |

Se `AGENDEI_EMAIL_API_CHAVE` tambem estiver definida, a API vence.

Na sua maquina, em vez de variaveis, crie `config/email.local.php` (o
`.gitignore` ja o mantem fora do GitHub), com as mesmas chaves em minusculas
e sem o prefixo:

```php
<?php
return [
    // Pela API da Brevo:
    'api_chave' => 'xkeysib-...',
    'remetente' => 'voce@gmail.com',
    'nome'      => 'Agendei',
    // ...ou por SMTP:
    // 'host' => 'smtp.gmail.com', 'porta' => 587,
    // 'usuario' => 'voce@gmail.com', 'senha' => 'senha-de-app',
];
```

---

## WhatsApp: lembretes automaticos

O lembrete sai de uma tarefa periodica. O Render (plano free) nao tem cron,
entao a tarefa e chamada por URL, por um agendador gratuito. Tres passos:

### 1. Ligar o gatilho

No Render, em **Environment**, adicione `AGENDEI_TOKEN_TAREFAS` com uma chave
longa e aleatoria (gere uma com `php -r "echo bin2hex(random_bytes(24));"`).
Sem essa variavel a pagina `tarefas.php` responde 404 para todo mundo.

### 2. Agendar a chamada

Em cron-job.org (gratuito, sem cartao) crie um job apontando para
`https://SEU-APP.onrender.com/tarefas.php?chave=SUA_CHAVE`, a cada 10 ou 15
minutos. A resposta e um JSON com o resumo por empresa; qualquer coisa que nao
seja HTTP 200 o cron-job.org avisa por e-mail. Chave errada responde 404 e,
depois de cinco erros da mesma origem, a origem fica bloqueada por uma hora.

> No plano free o Render adormece o servico depois de 15 minutos sem acesso.
> A chamada do agendador o acorda, entao ela tambem serve para manter o
> sistema no ar; a primeira resposta depois do sono demora uns 30 segundos.

### 3. Escolher quem envia

Duas formas, que podem coexistir:

- **Cada empresa com o proprio numero.** Nada a fazer no Render. O
  administrador da empresa configura o provedor em **Admin > Diferenciais >
  Lembretes por WhatsApp** e clica em **Enviar teste**.
- **A plataforma envia por um numero unico.** Adicione no Render as
  variaveis abaixo; toda empresa que deixar "Padrao da plataforma" usa esse numero.

| Chave | Evolution API | Meta Cloud API |
|---|---|---|
| `AGENDEI_WHATSAPP_PROVEDOR` | `evolution` | `meta` |
| `AGENDEI_WHATSAPP_URL` | `https://sua-evolution.com` | - |
| `AGENDEI_WHATSAPP_INSTANCIA` | nome da instancia | - |
| `AGENDEI_WHATSAPP_TOKEN` | apikey | token de acesso permanente |
| `AGENDEI_WHATSAPP_TELEFONE_ID` | - | phone number id |
| `AGENDEI_WHATSAPP_MODELO` | - | nome do modelo aprovado |

**Evolution API** e a opcao rapida: e um servidor de codigo aberto que voce
sobe (Docker, ha imagem pronta) e conecta a um WhatsApp comum pelo QR code,
sem aprovacao da Meta. **Meta Cloud API** e a oficial: exige conta comercial
verificada e um modelo de mensagem aprovado, em pt_BR, com quatro variaveis
na ordem nome, servico, data e hora. Exemplo de corpo do modelo:

```text
Ola, {{1}}! Lembrete: {{2}} em {{3}} as {{4}}. Se precisar remarcar, avise com antecedencia.
```

Banco criado antes desta versao: rode uma vez, da sua maquina,
`php scripts/migrar_lembretes.php` com o driver em `pgsql` apontando para o
Supabase (mesmo procedimento do `migrar_filiais.php`, acima).

---

## Entrar com o Google

O botao "Entrar com o Google" so aparece com as credenciais definidas. Ele
nao cria conta: abre a conta que ja existe com o e-mail confirmado pelo Google.

1. Em console.cloud.google.com crie um projeto (ou use um existente) e abra
   **Google Auth Platform**. Em **Visao geral** clique em **Vamos comecar**:
   nome do app, e-mail de suporte, publico-alvo **Externo**, e-mail de
   contato e **Criar**.
2. Em **Clientes > Criar cliente**, tipo **Aplicativo da Web**. Em **URIs de
   redirecionamento autorizados** coloque exatamente
   `https://SEU-APP.onrender.com/google_login.php` (e, para a sua maquina,
   `http://localhost/agendei/google_login.php`). Ao criar, o Google mostra o
   ID do cliente e a chave secreta; a chave so aparece nessa hora.
3. Em **Publico-alvo** clique em **Publicar app**; enquanto ele estiver "em
   teste", so os e-mails cadastrados como testadores entram.
4. Copie o ID do cliente e a chave secreta para o Render, em **Environment**:

| Chave | Valor |
|---|---|
| `AGENDEI_GOOGLE_CLIENT_ID` | o ID, termina em `.apps.googleusercontent.com` |
| `AGENDEI_GOOGLE_CLIENT_SECRET` | a chave secreta do cliente |

Na sua maquina, em vez de variaveis, crie `config/google.local.php` (o
`.gitignore` ja o mantem fora do GitHub):

```php
<?php
return ['client_id' => '....apps.googleusercontent.com', 'client_secret' => '...'];
```

"Manter conectado" nao precisa de configuracao, so da tabela
`sessoes_lembradas`: banco criado antes desta versao roda uma vez, da sua
maquina, `php scripts/migrar_lembrar.php` apontando para o Supabase.

O e-mail passou a ser unico em toda a plataforma (e o que identifica a pessoa
na entrada geral e no Google): banco criado antes desta versao roda uma vez,
da sua maquina, `php scripts/migrar_email_unico.php` apontando para o
Supabase. Se houver e-mails repetidos entre empresas, o script lista as contas
e para sem alterar nada; corrija os e-mails e rode de novo.

---

## Detalhes que importam

**O plano free hiberna.** Depois de ~15 min sem acesso, o Render "dorme" o
servico; o proximo acesso demora ~50s para acordar. Some so no plano free.
Para uma apresentacao, abra o site 1 min antes.

**Porta do Supabase.** Use 5432 (modo sessao). Se algum dia trocar para 6543
(modo transacao), o sistema liga sozinho a emulacao de prepared statements.

**Atualizar o site depois.** Cada `git push` na branch `main` faz o Render
refazer o deploy automaticamente.

---

## Alternativa: Koyeb

Mesma ideia do Render, tambem free e sem cartao. Em koyeb.com: **Create
Service -> GitHub -> Dockerfile**, e as mesmas variaveis do passo 4. A Koyeb
costuma nao hibernar no free, mas oferece menos recursos. Qualquer um dos dois
funciona com o `Dockerfile` que ja esta no projeto.
