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

### 5. Deploy

Clique em **Create Web Service**. O Render monta a imagem e sobe. Ao terminar,
ele mostra a URL publica, algo como `https://agendei.onrender.com`.

Como o banco (Supabase) ja esta instalado, o sistema abre pronto — nao precisa
rodar o `instalar.php` de novo. Faca login e **troque as senhas padrao**.

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
