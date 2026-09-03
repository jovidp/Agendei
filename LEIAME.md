# Agendei - Sistema de Agendamento de Servicos

Sistema web completo de agendamento (PHP + MySQL + HTML/CSS/JS puros, sem frameworks).

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
4. Acesse `http://localhost/agendei/instalar.php` e clique em **Executar instalacao**.
   Serao criados o administrador, tres profissionais com expediente configurado,
   um cliente de demonstracao e alguns agendamentos.
5. **Apague o arquivo `instalar.php`** depois da instalacao.

### Acessos criados pelo instalador

| Perfil        | E-mail                    | Senha       |
|---------------|---------------------------|-------------|
| Administrador | admin@agendei.com.br      | agendei123  |
| Profissional  | marcos@agendei.com.br     | agendei123  |
| Cliente       | cliente@agendei.com.br    | agendei123  |

Altere as senhas apos o primeiro acesso.

## Estrutura do projeto

```
/agendei
  /admin           Painel administrativo (dashboard, agenda, CRUDs, relatorios)
  /api             Endpoints JSON (profissionais, horarios livres, dias com vaga)
  /assets          css, js e imagens
  /cliente         Painel do cliente (dashboard, agendamento, historico, perfil)
  /config          config.php (bootstrap) e database.php (conexao PDO)
  /includes        auth.php, funcoes.php, layout (header/sidebar/footer) e icones
  /models          Regras de acesso ao banco (uma classe por entidade)
  /profissional    Painel do profissional (agenda, horarios, bloqueios, perfil)
  banco.sql        Estrutura e dados iniciais do banco
  index.php        Pagina publica do estabelecimento
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
lista de espera, avaliacoes, cupons, pagamentos, comissoes, multiplas unidades
e integracao com Google Calendar:

- `agendamentos.origem` identifica o canal de criacao.
- `configuracoes` permite novas regras sem alterar codigo.
- `recuperar_senha.php` tem o ponto de integracao de envio de mensagens marcado.
- Cada entidade tem seu model isolado, entao novos campos e tabelas nao afetam as telas.
