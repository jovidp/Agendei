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
| Login               | `login.php`              | Visitante       |
| Erro                | `erro.php`               | Master e comum  |
| 2FA                 | `dois_fatores.php`       | Master e comum  |
| Consulta de usuario | `admin/usuarios.php`     | Somente master  |
| Alteracao de senha  | `cliente/perfil.php`     | Somente comum   |
| Modelo do BD        | `modelo_bd.php`          | Master e comum  |
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
```

Os dois ultimos criam e descartam um banco proprio e nunca tocam o banco de uso normal.

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
## Diferenciais comerciais

O menu **Admin > Diferenciais** reúne os recursos opcionais de cada estabelecimento:

- lista de espera com aviso quando um cancelamento libera uma vaga compatível;
- fila de lembretes com mensagem pronta para WhatsApp;
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
