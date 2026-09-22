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

O Render nao tem servidor de e-mail e `mail()` nao funciona la. O Agendei
fala SMTP direto com um provedor, sem instalar nada. Qualquer conta SMTP
serve; duas opcoes gratuitas:

- **Gmail** (ate ~500 mensagens/dia): ative a verificacao em duas etapas na
  conta Google e crie uma *senha de app* em myaccount.google.com -> Seguranca
  -> Senhas de app. Servidor `smtp.gmail.com`, porta `587`, usuario e o
  e-mail completo, senha e a senha de app (16 letras).
- **Brevo** (ate 300/dia, sem cartao): em brevo.com crie a conta, va em
  *SMTP & API -> SMTP* e gere uma chave. Servidor `smtp-relay.brevo.com`,
  porta `587`, usuario e o login mostrado la, senha e a chave SMTP. O
  remetente precisa ser um e-mail validado na Brevo.

No Render, em **Environment**, adicione:

| Chave | Valor |
|---|---|
| `AGENDEI_EMAIL_HOST` | `smtp.gmail.com` ou `smtp-relay.brevo.com` |
| `AGENDEI_EMAIL_PORTA` | `587` |
| `AGENDEI_EMAIL_SEGURANCA` | `tls` (587) ou `ssl` (465); pode omitir |
| `AGENDEI_EMAIL_USUARIO` | o usuario do SMTP |
| `AGENDEI_EMAIL_SENHA` | a senha de app ou a chave SMTP |
| `AGENDEI_EMAIL_REMETENTE` | o e-mail que aparece como remetente |
| `AGENDEI_EMAIL_NOME` | `Agendei` (opcional) |

Salve: o Render reinicia o servico sozinho. Depois entre na area master, abra
**Saude do sistema** e clique em **Enviar e-mail de teste**. A mensagem vai
para o e-mail da sua conta master; se nao chegar, a tela mostra o motivo que
o servidor SMTP devolveu.

Na sua maquina, em vez de variaveis, crie `config/email.local.php` (o
`.gitignore` ja o mantem fora do GitHub):

```php
<?php
return [
    'host'      => 'smtp.gmail.com',
    'porta'     => 587,
    'usuario'   => 'voce@gmail.com',
    'senha'     => 'senha-de-app',
    'remetente' => 'voce@gmail.com',
    'nome'      => 'Agendei',
];
```

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
