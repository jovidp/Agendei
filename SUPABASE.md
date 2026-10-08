# Rodar o Agendei no Supabase (PostgreSQL)

O sistema fala dois bancos: **MySQL** (padrao, exigido pela especificacao) e
**PostgreSQL**, que e o que o Supabase oferece. O mesmo codigo atende os dois;
a escolha fica em `config/database.local.php`, na chave `driver`.

> **Lembre-se:** o Supabase e so o banco. As paginas PHP (`index.php`,
> `login.php`, ...) precisam de uma hospedagem que rode PHP 8. Veja
> [IMPLANTACAO.md](IMPLANTACAO.md) para a parte da aplicacao. Durante o
> desenvolvimento, o PHP roda no seu XAMPP e so o banco fica no Supabase.

---

## Passo 1 — Trocar a senha do Supabase

A senha que apareceu no chat precisa ser trocada antes de usar:

*Supabase → Project Settings → Database → Reset database password.*

Copie a senha nova. Ela vai para um so lugar: o arquivo do passo 3.

---

## Passo 2 — Importar o esquema

O arquivo `banco_postgres.sql` (gerado a partir de `banco.sql`, ja convertido
para PostgreSQL) cria as 27 tabelas, os indices e as restricoes.

No painel do Supabase:

1. Abra **SQL Editor**.
2. Cole todo o conteudo de `banco_postgres.sql`.
3. Clique em **Run**.

Confira em **Table Editor** que apareceram as 27 tabelas.

> O `scripts/migrar.php` atende apenas MySQL e recusa rodar no PostgreSQL:
> nao e necessario aqui, pois `banco_postgres.sql` ja traz o esquema completo.
>
> As migracoes pontuais rodam nos dois bancos e servem para um Supabase criado
> em versao anterior, sempre com o `driver` em `pgsql` (passo 3):
> `php scripts/migrar_filiais.php` (cria a tabela `filiais` e a unidade
> **Matriz** e vincula a ela o que ja existia), `migrar_lembretes.php`,
> `migrar_lembrar.php`, `migrar_email_unico.php` (torna o e-mail unico em
> toda a plataforma; se houver repeticoes, lista as contas e para sem alterar
> nada) e, por ultimo, `migrar_vinculos.php` (separa pessoa e vinculo: cada
> conta antiga vira a pessoa mais um vinculo com a empresa dela). Instalacoes
> novas ja vem com tudo isso pelo `banco_postgres.sql`.

---

## Passo 3 — Apontar o sistema para o Supabase

O arquivo `config/database.local.php` ja existe, pre-preenchido. Faca duas
edicoes nele:

1. Troque `'driver' => 'mysql'` por `'driver' => 'pgsql'`.
2. Na secao `'pgsql'`, cole a senha nova em `'senha'`.

Confira que a porta e **5432** (modo sessao). A 6543 tambem funciona — o
sistema liga a emulacao de prepared statements sozinho —, mas a 5432 e a
recomendada para um aplicativo PHP tradicional.

Para voltar ao MySQL depois, basta trocar `driver` de volta para `'mysql'`.

---

## Passo 4 — Carregar os dados iniciais

Com o driver em `pgsql`, acesse `instalar.php` uma vez (local ou no servidor).
Ele cria o estabelecimento, o usuario master, profissionais e um cliente de
exemplo — igual ao MySQL. Depois, **troque as senhas padrao** (veja
[IMPLANTACAO.md](IMPLANTACAO.md), secao 7).

---

## Conferir que funcionou

Com a senha no lugar, da para rodar a suite de fluxo contra o Supabase, sem
tocar no banco de uso (ela cria um banco temporario proprio):

```bash
AGENDEI_DB_DRIVER=pgsql php tests/fluxo_projeto.php
```

Deve terminar com `OK: 53 verificacoes do fluxo do projeto.` Se o Supabase
recusar criar o banco temporario (alguns planos limitam isso), teste pela
propria aplicacao: cadastro, login, segundo fator e a Consulta de Usuario.

---

## O que muda entre os dois bancos

Nada no comportamento visivel. Por baixo, a classe `models/Sql.php` cuida dos
poucos pontos em que os dialetos divergem:

| Recurso | MySQL | PostgreSQL |
|---|---|---|
| Somar dias/horas a uma data | `DATE_ADD` | `make_interval` |
| Diferenca em minutos | `TIMESTAMPDIFF` | `EXTRACT(EPOCH ...)` |
| Inserir ignorando duplicata | `INSERT IGNORE` | `ON CONFLICT DO NOTHING` |
| Atualizar se existir (upsert) | `ON DUPLICATE KEY` | `ON CONFLICT ... DO UPDATE` |
| Busca sem diferenciar maiuscula | `LIKE` (collation) | `ILIKE` |

Diferenca menor conhecida: no MySQL a busca tambem ignora acento; o `ILIKE`
do PostgreSQL ignora so a caixa. Para a Consulta de Usuario do projeto, isso
nao muda o resultado esperado.
