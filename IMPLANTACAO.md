# Colocar o Agendei no ar

Guia para publicar o sistema numa hospedagem compartilhada com PHP 8 e MySQL,
mantendo o ambiente local do XAMPP funcionando como está.

O projeto continua em **PHP + MySQL**, como exige a especificação. Nada aqui
depende de Composer, shell ou cron — o que permite usar hospedagem gratuita.

---

## 1. Escolher a hospedagem

O sistema precisa de: PHP 8.x, MySQL/MariaDB, `.htaccess` e acesso a phpMyAdmin.

| Serviço | Banco | PHP | Observação |
|---|---|---|---|
| **Byet.host** (recomendado) | MariaDB 11.4 | 8.3 | 5 GB, SSL grátis, sem cartão |
| InfinityFree | MySQL 5.6 | 8.3 | Banco antigo — ver alerta abaixo |
| Hostinger / similares | MySQL 8 | 8.3 | Pago, porém estável para a apresentação |

> **Por que o banco importa aqui.** O `banco.sql` usa 7 constraints `CHECK`
> (preço não-negativo, `hora_fim > hora_inicio`, nota de 1 a 5, entre outras).
> MySQL 5.6 e 5.7 **aceitam a sintaxe e ignoram a regra em silêncio**: a
> importação passa, mas a validação deixa de existir sem nenhum aviso.
> MariaDB 10.2+ e MySQL 8 aplicam de verdade. Prefira um host com MariaDB 11
> ou MySQL 8.

Hospedagem gratuita cai. Se a nota depende de uma demonstração ao vivo,
**mantenha o XAMPP local funcionando como plano B** — nada neste guia quebra
o ambiente local.

---

## 2. Criar o banco no painel

1. No painel da hospedagem, crie um banco MySQL.
2. Anote os quatro valores que o painel mostra: **host**, **nome do banco**,
   **usuário** e **senha**. Em host compartilhado o nome vem com prefixo
   (algo como `if0_12345678_agendei`) e não pode ser escolhido.

---

## 3. Gerar e importar o SQL

O `banco.sql` começa com `CREATE DATABASE` e `USE`, que exigem privilégio que
você não tem em host compartilhado. Gere a versão sem esses comandos:

```bash
php scripts/preparar_hospedagem.php
```

Isso cria `banco_hospedagem.sql` (arquivo gerado, fora do git). No phpMyAdmin
da hospedagem, **selecione o banco criado no passo 2** e importe esse arquivo.
Confira ao final: devem existir **20 tabelas**.

---

## 4. Enviar os arquivos

Envie por FTP ou pelo gerenciador de arquivos, para dentro de `htdocs`
(ou `public_html`, conforme o painel).

**Não envie:**

| Pasta/arquivo | Motivo |
|---|---|
| `tests/` | Só roda por linha de comando; cria e apaga bancos |
| `scripts/` | Ferramentas de manutenção local |
| `.git/`, `.vscode/` | Não pertencem ao servidor |
| `banco.sql`, `banco_hospedagem.sql` | Já importados; não devem ficar públicos |
| `config/database.local.php` | Contém as credenciais **locais**, não as do servidor |

As pastas `config/`, `includes/`, `models/`, `scripts/` e `tests/` têm
`.htaccess` bloqueando acesso direto pelo navegador. A pasta `api/` fica
acessível de propósito — são os endpoints AJAX.

---

## 5. Apontar para o banco do servidor

Crie **no servidor** o arquivo `config/database.local.php`, a partir do
modelo `config/database.local.php.example`:

```php
<?php

return [
    'host'    => 'sqlXXX.byet.org',
    'banco'   => 'byet_12345678_agendei',
    'usuario' => 'byet_12345678',
    'senha'   => 'a-senha-do-painel',
];
```

Esse arquivo é o único lugar com as credenciais reais e está no `.gitignore`.
As chaves omitidas caem no padrão de desenvolvimento definido em
`config/database.php` — por isso basta informar o que muda.

Não é preciso configurar ambiente: o sistema detecta sozinho que não está em
`localhost` e entra em modo produção, escondendo mensagens técnicas de erro.
Para forçar, defina a variável de ambiente `AGENDEI_AMBIENTE`.

---

## 6. Carregar os dados iniciais

Acesse `https://seu-dominio/instalar.php` **uma única vez**. Ele cria o
estabelecimento, o usuário master, profissionais, um cliente e agendamentos de
exemplo.

Depois disso, **apague `instalar.php` do servidor**. Ele já se bloqueia sozinho
em produção depois da carga (responde 404), mas apagar é mais garantido.

---

## 7. Trocar as senhas — obrigatório

O instalador cria todos os usuários com a **mesma senha**:

| Perfil | Login | Senha inicial |
|---|---|---|
| Master (`admin`) | `admini` | `agendeis` |
| Comum (`cliente`) | `anabea` | `agendeis` |

Essas credenciais estão neste repositório e no código do instalador. Num site
público, **qualquer pessoa que conheça o projeto entra como master**. Troque as
duas senhas logo após o primeiro acesso, em *Meu perfil*.

Vale o mesmo para as respostas do segundo fator (nome da mãe, data de
nascimento, CEP): as de exemplo são conhecidas. Ajuste-as no cadastro.

---

## 8. Conferir antes de divulgar

- [ ] `https://seu-dominio/` abre a tela principal
- [ ] Login funciona pelo campo **Login** e também por e-mail
- [ ] O segundo fator aparece e bloqueia na terceira tentativa errada
- [ ] `instalar.php` foi apagado
- [ ] Senhas padrão trocadas
- [ ] `https://seu-dominio/config/database.php` responde **403**, não o código
- [ ] `https://seu-dominio/tests/requisitos.php` responde **403** ou **404**
- [ ] Forçar um erro **não** mostra rastro de pilha na tela
- [ ] O cadeado de HTTPS aparece (SSL ativado no painel)

---

## Limitações conhecidas

**Latência.** O banco deixa de ser local. Telas com muitas consultas ficam
perceptivelmente mais lentas que no XAMPP.

**Sem linha de comando.** Hospedagem gratuita não dá shell, então
`scripts/migrar.php` não roda no servidor. Ao mudar o schema, o caminho é
alterar o `banco.sql`, gerar de novo com `preparar_hospedagem.php` e reimportar
— ou aplicar o `ALTER TABLE` à mão pelo phpMyAdmin.

**Limite de tamanho.** InfinityFree limita cada banco a 50 MB. Para este
projeto é folgado, mas não serve para carga de longo prazo.

**E-mail.** Hospedagem gratuita costuma bloquear `mail()`. Por isso o sistema
não usa `mail()`: ele fala SMTP com um provedor (Gmail com senha de app, Brevo
etc.), configurado por variáveis `AGENDEI_EMAIL_*` ou por
`config/email.local.php`. O passo a passo está em [DEPLOY.md](DEPLOY.md). Sem
configuração o sistema funciona, mas não avisa por e-mail e o "esqueci minha
senha" só mostra o link em desenvolvimento.

---

## Voltar a rodar local

Nada muda. Sem `config/database.local.php`, o sistema usa `localhost`, banco
`agendei`, usuário `root` e senha vazia — o padrão do XAMPP. Em `localhost` o
modo de desenvolvimento volta sozinho e os erros aparecem na tela de novo.
