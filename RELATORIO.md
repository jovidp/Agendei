# RELATÓRIO DO SISTEMA DE AGENDAMENTO DE SERVIÇOS — AGENDEI

## 1. Introdução

O Agendei é um sistema web de agendamento de serviços que organiza o
relacionamento entre estabelecimentos prestadores de serviço e seus clientes.
O estabelecimento publica seu catálogo de serviços e a agenda da sua equipe, e o
cliente realiza o agendamento pela internet, escolhendo serviço, profissional,
data e horário.

A proposta surgiu da necessidade de substituir processos manuais de marcação de
horário — atendimento presencial, telefone ou aplicativos de mensagem — por um
ambiente único onde serviços, clientes, profissionais, expedientes, bloqueios e
agendamentos ficam registrados de forma estruturada.

O sistema não é específico de um segmento. A mesma instalação atende salões de
beleza, barbearias, clínicas, oficinas, profissionais autônomos, serviços de
manutenção e estética, porque o catálogo de serviços, o expediente e as regras
de funcionamento são cadastrados por cada estabelecimento.

O sistema está implementado e em funcionamento em PHP 8 com banco de dados
MySQL/MariaDB, com todo o processamento realizado no servidor.

## 2. Objetivo do Sistema

O objetivo principal é permitir que um estabelecimento disponibilize seus
serviços para agendamento online, com controle de disponibilidade real.

O estabelecimento cadastra seus dados, seus serviços, sua equipe e o expediente
de cada profissional. O cliente acessa o sistema, realiza seu cadastro, consulta
os serviços disponíveis, seleciona o serviço desejado, escolhe o profissional,
a data e um horário efetivamente livre, e confirma o agendamento.

Entre os objetivos específicos atendidos estão:

- facilitar o agendamento de serviços pela internet;
- reduzir os agendamentos feitos manualmente;
- organizar os horários disponíveis a partir do expediente cadastrado;
- impedir conflitos de horário no mesmo profissional;
- manter um registro estruturado dos clientes;
- permitir o acompanhamento dos agendamentos por cliente, profissional e administrador;
- oferecer controle administrativo sobre os atendimentos e indicadores gerenciais;
- registrar os eventos de autenticação para fins de auditoria;
- tornar o processo de agendamento mais rápido e acessível.

## 3. Público-Alvo

O sistema trabalha com quatro perfis de acesso. Dois deles correspondem
diretamente aos perfis exigidos pela especificação do projeto acadêmico, e os
outros dois existem para dar suporte à operação completa do estabelecimento.

| Perfil da especificação | Perfil no sistema (`vinculos.tipo`) | Onde entra |
|-------------------------|--------------------------------------|------------|
| Usuário master | `admin` | Criado pelo `instalar.php` |
| Usuário comum | `cliente` | Cria a própria conta em `cadastro.php` |
| (fora do escopo avaliado) | `profissional` | Cadastrado pelo administrador |
| (fora do escopo avaliado) | master global da plataforma | Tabela própria, entrada por `master/login.php` |

### 3.1 Cliente (usuário comum)

É o usuário que deseja contratar um serviço. Para agendar, precisa realizar o
cadastro com seus dados pessoais e criar suas credenciais de acesso.

Depois de autenticado, consulta o catálogo de serviços, escolhe o profissional,
a data e o horário, confirma o agendamento, acompanha seus próprios
agendamentos, consulta o histórico de atendimentos anteriores, altera a própria
senha e cancela reservas dentro do prazo configurado.

### 3.2 Estabelecimento / Administrador (usuário master)

Responsável pelo gerenciamento do estabelecimento. Possui nível de acesso
superior ao usuário comum e acessa funções que o cliente não enxerga:
dashboard com indicadores, agenda completa, cadastro de serviços, cadastro de
profissionais, expediente e bloqueios, configurações de regra de negócio,
relatórios, consulta de usuários, exclusão de usuários comuns e leitura dos
logs de autenticação.

### 3.3 Profissional

Perfil operacional. Consulta a própria agenda do dia e da semana, marca
atendimentos como concluídos, consulta o próprio expediente e, quando o
administrador autoriza pela configuração `permitir_bloqueio_profissional`,
bloqueia períodos da própria agenda.

### 3.4 Master global da plataforma

Perfil de infraestrutura, com login separado em `master/login.php`. Cria cada
estabelecimento junto com sua primeira conta administrativa e acompanha o uso da
plataforma. Não participa da operação diária de nenhum estabelecimento.

A área master reúne quatro grupos de tela:

- **Contas e acesso** — criação de estabelecimentos, manutenção dos
  administradores locais (ativar, desativar, redefinir senha) e das próprias
  contas globais. Duas regras são garantidas no modelo, e não apenas na tela:
  a última conta master ativa nunca é desligada e um estabelecimento ativo
  nunca fica sem administrador ativo.
- **Suporte** — a opção *Entrar no painel* abre o painel de um estabelecimento
  em nome de um administrador dele, para atender um chamado sem redefinir a
  senha de quem pediu ajuda. Enquanto dura, uma faixa fixa no topo identifica a
  simulação, o campo `ultimo_acesso` da conta não é alterado e a entrada e a
  saída ficam registradas na auditoria.
- **Acompanhamento** — *Uso da plataforma* mostra movimento (agendamentos
  criados, clientes novos e último acesso) em janelas de 7, 30 ou 90 dias, o que
  separa a empresa ativa daquela que só tem cadastro antigo; *Saúde do sistema*
  responde, sem abrir o servidor, se o banco responde, se as tabelas de apoio
  existem, se o ambiente está em modo de produção e se o instalador continua
  publicado.
- **Governança** — *Auditoria* registra toda ação da conta global (empresa
  criada, acesso alterado, senha redefinida, bloqueio liberado, simulação
  iniciada e encerrada) com autor, alvo, data e origem; *Segurança* consolida o
  log de autenticação de todas as empresas e permite liberar um bloqueio do
  controle de força bruta; *Planos e limites* define tetos de profissionais,
  serviços e agendamentos por mês.

Os limites do plano são conferidos quando algo passa a ocupar uma vaga — no
cadastro e também na reativação, já que religar um profissional ou um serviço
desativado aumenta o total ativo exatamente como criar um novo. Nunca são
aplicados retroativamente sobre o que já existe: baixar o plano de uma empresa
não apaga nada, apenas impede o crescimento até que ela volte para dentro do
teto. Empresa sem plano continua sem limite, que é o comportamento original do
sistema.

## 4. Funcionamento Geral

O funcionamento é baseado na interação entre o estabelecimento e seus clientes.

O administrador cadastra os serviços (nome, descrição, duração, valor e
situação), cadastra os profissionais, vincula quais serviços cada profissional
executa e define o expediente semanal de cada um. A partir desses dados o
sistema passa a calcular sozinho quais horários estão livres.

O processo do cliente é o seguinte:

1. acessa o endereço público do estabelecimento (`index.php`);
2. realiza seu cadastro em `cadastro.php`;
3. informa os dados pessoais e de endereço;
4. cria suas credenciais de acesso (login e senha);
5. realiza o login em `login.php`;
6. responde à pergunta do segundo fator de autenticação;
7. consulta os serviços disponíveis;
8. seleciona o serviço desejado;
9. seleciona o profissional que executa aquele serviço;
10. escolhe uma data entre as que possuem vaga;
11. seleciona um dos horários livres oferecidos;
12. confirma o agendamento;
13. o sistema revalida tudo no servidor e grava o registro;
14. o cliente consulta, acompanha ou cancela o agendamento depois.

Todas as etapas são realizadas digitalmente, sem necessidade de contato direto
com o estabelecimento.

### 4.1 Arquitetura multiestabelecimento

O sistema aceita vários estabelecimentos no mesmo banco de dados. Contas,
clientes, profissionais, serviços, horários, agendamentos, configurações e logs
carregam o vínculo da empresa (`id_estabelecimento`), e as chaves estrangeiras
compostas do banco impedem, na própria estrutura, que um registro de um
estabelecimento seja associado a outro.

Cada estabelecimento possui um endereço público exclusivo (identificado por um
slug) e personaliza nome, logotipo, cores e fonte em **Admin > Aparência**. Os
clientes cadastrados por aquele endereço recebem o mesmo vínculo e enxergam
apenas os serviços e profissionais daquela empresa.

A pessoa, porém, é uma só na plataforma: a tabela `usuarios` guarda o e-mail
(único), a senha, o segundo fator e o cadastro completo, e a tabela `vinculos`
guarda o que ela é em cada estabelecimento (cliente, profissional ou
administradora, com status e login próprios). Assim a mesma pessoa pode ser
cliente de um salão, profissional de outro e dona do próprio negócio, e o
sistema atende também o profissional autônomo, que administra e atende no
mesmo estabelecimento.

## 5. Cadastro de Usuários

O cadastro público (`cadastro.php`) cria sempre um usuário com perfil **comum**.
Usuários comuns nunca recebem privilégios administrativos automaticamente.

Os campos solicitados são: nome completo, data de nascimento, sexo, nome
materno, CPF, e-mail, telefone celular, telefone fixo, CEP, logradouro, número,
complemento, bairro, cidade, UF, login, senha e confirmação de senha.

As regras de validação são aplicadas duas vezes: no navegador
(`assets/js/cadastro.js`), para orientar o preenchimento, e novamente no
servidor (`includes/funcoes.php`), porque a validação feita no navegador pode
ser burlada e a decisão final é sempre do PHP.

| Campo | Regra aplicada |
|-------|----------------|
| Nome completo | 15 a 80 caracteres, apenas letras e espaços |
| CPF | 11 dígitos, sequências repetidas rejeitadas e conferência dos dois dígitos verificadores |
| Nome materno | 5 a 120 caracteres, apenas letras e espaços |
| E-mail | Formato válido e único em toda a plataforma: identifica a pessoa, que pode ter vínculo com vários estabelecimentos |
| CEP | 8 dígitos; preenche o endereço automaticamente pela API ViaCEP |
| UF | Uma das 27 unidades da federação |
| Telefone celular | DDD + 9 dígitos, gravado como `(+55)XX-XXXXXXXXX` |
| Telefone fixo | DDD + 8 dígitos, gravado como `(+55)XX-XXXXXXXX` |
| Login | Exatamente 6 caracteres alfabéticos, único dentro do estabelecimento |
| Senha | Exatamente 8 caracteres alfabéticos, gravada com hash |
| Confirmar senha | Igual à senha |

Quando a API de CEP está indisponível, os campos de endereço permanecem
editáveis para preenchimento manual, de modo que a falha externa não impede o
cadastro.

## 6. Login e Autenticação

A tela de login aceita, no mesmo campo, o login de 6 letras ou o e-mail da
conta. A senha é conferida com `password_verify()` contra o hash armazenado, e,
quando o algoritmo de hash do PHP evolui, o sistema regrava a senha com o
algoritmo novo automaticamente.

O controle do usuário autenticado é feito por sessão PHP. A sessão guarda o
identificador, o nome, o perfil e o estabelecimento do usuário, e o menu
apresentado muda conforme o perfil:

- **Perfil comum (cliente)**: painel próprio, agendamento, histórico, perfil,
  benefícios e privacidade;
- **Perfil master (admin)**: dashboard, agenda, agendamentos, clientes,
  serviços, profissionais, horários, relatórios, configurações, aparência,
  consulta de usuários e logs.

O controle de acesso não depende apenas do menu. Toda página restrita chama
`exigirLogin()` com os perfis autorizados, de modo que digitar o endereço
diretamente na barra do navegador também é bloqueado.

## 7. Autenticação em Duas Etapas (2FA)

Depois de validar login e senha, o sistema ainda não abre a sessão: encaminha o
usuário para `dois_fatores.php`, onde a identidade é confirmada por um segundo
fator. O sistema oferece dois caminhos, e escolhe automaticamente qual aplicar.

### 7.1 Código de uso único (caminho padrão)

Quando a conta tem um aplicativo autenticador cadastrado, o segundo fator é um
**código de seis dígitos que muda a cada 30 segundos**, gerado no celular por
Google Authenticator, Microsoft Authenticator, Authy, 2FAS ou equivalente.

O código segue o padrão TOTP, definido nas normas RFC 6238 e RFC 4226: servidor
e aplicativo compartilham um segredo de 160 bits e derivam dele, por HMAC-SHA1,
o mesmo número a cada intervalo de tempo. Nada trafega pela rede no momento da
conferência, de modo que o recurso não depende de envio de e-mail nem de SMS, e
funciona mesmo com o celular sem conexão.

O cadastro é feito na tela **Verificação em 2 etapas**, disponível no menu de
todos os perfis. O sistema gera o segredo, apresenta-o como QR code e também em
texto, para digitação manual. O QR é desenhado pelo próprio servidor
(`models/QrCode.php`), sem recorrer a serviço externo: como o endereço
`otpauth://` carrega o segredo da conta, enviá-lo a terceiros anularia a
proteção pretendida.

A ativação só se conclui depois que o usuário digita um código válido, o que
prova que o aplicativo leu o segredo corretamente. Até lá a conta continua
usando o caminho anterior, de forma que ninguém fique impedido de entrar por ter
interrompido a configuração.

Três cuidados complementam o mecanismo:

- o segredo é gravado **cifrado** no banco, com AES-256-GCM, e a chave vem de
  variável de ambiente — um vazamento da base não permite gerar códigos;
- cada código vale **uma única vez**: a janela de tempo já aproveitada fica
  registrada na conta, o que impede reapresentar um código observado;
- a conferência tolera até 30 segundos de diferença entre o relógio do celular
  e o do servidor, para cada lado.

### 7.2 Pergunta cadastral (caminho de reserva)

Para as contas que ainda não cadastraram o aplicativo, o sistema sorteia uma
entre três perguntas pessoais informadas no cadastro:

- nome da mãe;
- data de nascimento;
- CEP.

A comparação ignora acentos, maiúsculas e máscaras: a data aceita tanto
`10/03/1990` quanto `1990-03-10`, e o CEP aceita com ou sem hífen. Os dados que
respondem às perguntas ficam na tabela `usuarios`, e não em `clientes`, para que
o perfil administrador também passe pelo segundo fator.

Uma conta que tenha aplicativo cadastrado **nunca** recebe a pergunta: manter os
dois caminhos abertos em paralelo reduziria a segurança ao elo mais fraco, já
que nome da mãe, data de nascimento e CEP não são segredos para quem convive com
a pessoa.

### 7.3 Regras comuns aos dois caminhos

A sessão só é aberta depois que o segundo fator é confirmado. O usuário tem
**três tentativas**; na terceira sem êxito o sistema exibe a mensagem
`3 tentativas sem sucesso! Favor realizar Login novamente.`, descarta o fluxo
pendente e devolve o usuário à tela de login.

A conta de administração global (master) também passa pelo segundo fator por
código, em fluxo próprio (`master/dois_fatores.php`), por ser a credencial de
maior alcance do sistema.

Essa etapa reduz o risco de acesso indevido mesmo quando a senha do usuário é
descoberta por terceiros.

## 8. Cadastro e Consulta de Serviços

Os serviços são cadastrados pelo administrador em **Admin > Serviços**, com
nome, descrição, duração em minutos, valor e situação (ativo ou inativo).

A duração é o dado que alimenta o cálculo da agenda: é ela que define o tamanho
do bloco ocupado e, portanto, quais horários ainda cabem no expediente do
profissional.

Cada serviço é vinculado aos profissionais que o executam. Quando o cliente
escolhe um serviço, o sistema oferece apenas os profissionais habilitados para
ele. Serviços inativos deixam de aparecer para o cliente, mas os agendamentos
já realizados continuam no histórico.

A estrutura não é limitada a um único tipo de negócio: corte de cabelo,
manicure, consulta, manutenção, instalação, limpeza e atendimento técnico são
apenas exemplos de catálogo.

## 9. Processo de Agendamento

O agendamento é a funcionalidade central do sistema. O fluxo do cliente
(`cliente/agendar.php`) é sequencial: serviço, profissional, data e horário.

A lista de horários exibida na tela é calculada por
`models/Disponibilidade.php`, que gera os intervalos a partir do expediente
cadastrado do profissional e desconta os agendamentos existentes e os bloqueios
de agenda. Os endpoints em `api/` alimentam a tela sem recarregar a página.

Antes de gravar, o servidor revalida integralmente a solicitação:

- o serviço existe, está ativo e pertence ao estabelecimento;
- o profissional existe, está ativo e executa aquele serviço;
- a data é válida;
- o horário é válido e cabe no expediente daquele dia da semana;
- o horário não está dentro de um bloqueio de agenda;
- o horário não está no passado;
- a antecedência mínima configurada é respeitada;
- a antecedência máxima configurada é respeitada;
- não existe outro agendamento ativo do mesmo profissional naquele intervalo.

A gravação em `Agendamento::criar()` acontece dentro de uma transação, e a
verificação final de conflito usa `SELECT ... FOR UPDATE`. Com isso, dois
clientes que confirmam o mesmo horário no mesmo instante não conseguem ocupar a
mesma vaga: um dos dois recebe o aviso de indisponibilidade.

O conflito é apurado **por profissional**, e não pelo estabelecimento inteiro,
porque profissionais diferentes atendem simultaneamente.

As mesmas validações existem no JavaScript apenas para melhorar a experiência
do usuário. A decisão final é sempre do PHP.

## 10. Informações do Agendamento

Cada agendamento registra as informações necessárias para seu gerenciamento:

| Informação | Campo |
|------------|-------|
| Cliente | `id_cliente` |
| Profissional | `id_profissional` |
| Serviço | `id_servico` |
| Data | `data_agendamento` |
| Horário de início | `hora_inicio` |
| Horário de término | `hora_fim` (calculado pela duração do serviço) |
| Valor | `valor` |
| Situação | `status` |
| Observação | `observacao` |
| Canal de criação | `origem` (cliente, admin ou profissional) |
| Motivo do cancelamento | `motivo_cancelamento` |
| Quem cancelou | `id_usuario_cancelou` |
| Data de criação | `data_criacao` |
| Última alteração | `data_atualizacao` |

Com esses dados, o estabelecimento identifica quem agendou, com quem, qual
serviço será executado, quando, por qual canal a reserva entrou e, no caso de
cancelamento, quem cancelou e por quê.

## 11. Status dos Agendamentos

O campo `status` identifica a situação do agendamento e assume quatro valores:

| Status | Significado |
|--------|-------------|
| `agendado` | Reserva criada, aguardando confirmação do estabelecimento ou do cliente (botão em Meus agendamentos ou link do lembrete) |
| `confirmado` | Atendimento confirmado |
| `concluido` | Atendimento realizado |
| `cancelado` | Reserva cancelada pelo cliente ou pelo estabelecimento |

Quando a configuração `confirmar_automaticamente` está ativa, os agendamentos
criados pelo cliente já nascem confirmados e o passo manual é dispensado.
Com `liberar_sem_confirmacao_horas` maior que zero, a tarefa periódica
cancela, N horas antes, as reservas que continuam `agendado` depois de o
lembrete ter sido enviado, e avisa a lista de espera (`models/Confirmacao.php`).

Apenas os agendamentos nas situações `agendado` e `confirmado` ocupam espaço na
agenda. Os cancelados liberam o horário imediatamente, e os concluídos
alimentam o histórico e os relatórios.

## 12. Consulta de Agendamentos

Cada usuário comum visualiza somente os próprios agendamentos. A restrição é
aplicada na consulta ao banco, e não apenas na tela: as consultas do painel do
cliente filtram sempre pelo identificador do cliente da sessão. Isso garante a
privacidade das informações e impede que um cliente veja a reserva de outro.

O perfil master possui visão ampla dos agendamentos do seu estabelecimento, em
três telas complementares:

- **Agenda** (`admin/agenda.php`): visão por dia ou por semana, montada apenas
  com PHP, HTML, CSS e JavaScript, sem bibliotecas externas;
- **Agendamentos** (`admin/agendamentos.php`): listagem com filtros, criação
  manual, mudança de status e cancelamento;
- **Dashboard** (`admin/dashboard.php`): indicadores e listas de acompanhamento.

O profissional enxerga apenas a própria agenda.

## 13. Cancelamento de Agendamentos

O cancelamento altera o `status` do agendamento para `cancelado` e registra o
motivo e o responsável. O registro permanece no banco.

Esse comportamento é mais adequado do que excluir a linha, porque preserva o
histórico da operação para consulta, auditoria e relatórios, ao mesmo tempo em
que devolve o horário para a agenda.

O cliente cancela sozinho apenas dentro do prazo definido pela configuração
`cancelamento_limite_horas`. Fora desse prazo, o cancelamento depende do
administrador, que pode cancelar qualquer agendamento do estabelecimento.

Quando um cancelamento libera uma vaga compatível, o sistema verifica a lista de
espera e prepara o aviso para os clientes interessados.

## 14. Gerenciamento de Usuários

A tela **Consulta de usuários** (`admin/usuarios.php`) é exclusiva do perfil
master e oferece:

- listagem dos usuários comuns do estabelecimento;
- pesquisa por parte do nome;
- visualização dos dados cadastrados e do perfil de acesso;
- exclusão da conta selecionada, com confirmação explícita, avisando que os
  agendamentos vinculados também serão removidos;
- download da lista em PDF, respeitando a pesquisa aplicada.

A exclusão remove o usuário e seus dados vinculados, mas **não apaga o histórico
de autenticação**, conforme descrito na seção seguinte.

O cadastro de clientes também pode ser consultado em **Admin > Clientes**, com o
histórico individual de atendimentos.

## 15. Registro de Logs

A tabela `logs_autenticacao` armazena os eventos de acesso ao sistema. Cada
entrada registra data e hora, nome, CPF, login informado, perfil, endereço IP,
o evento ocorrido e, quando aplicável, qual pergunta do segundo fator foi
sorteada.

Os eventos registrados são: `login_sucesso`, `login_falha`, `2fa_sucesso`,
`2fa_falha`, `2fa_bloqueio` e `logout`.

O nome e o CPF são gravados por cópia, e a tabela não possui chave estrangeira
para `usuarios`. Essa decisão é intencional: assim o histórico sobrevive quando
o master exclui um usuário, e o registro de uma tentativa de acesso não
desaparece junto com a conta.

A tela `admin/logs.php`, exclusiva do perfil master, filtra por nome, por CPF ou
exibe todos, sempre da entrada mais recente para a mais antiga.

Em ambiente real, esses registros auxiliam na identificação de tentativas de
acesso indevido, na auditoria, na investigação de erros e na verificação de
atividades suspeitas.

## 16. Alteração de Senha

O usuário comum altera a própria senha em `cliente/perfil.php`, e o profissional
em `profissional/perfil.php`. O processo exige a senha atual, a nova senha e a
confirmação.

A rotina `alterarSenhaUsuario()` confere a senha atual com `password_verify()`,
aplica a mesma regra de senha do cadastro (exatamente 8 caracteres
alfabéticos), verifica se a confirmação coincide e só então grava o novo hash.

As senhas nunca são armazenadas em texto puro. O sistema utiliza
`password_hash()` na gravação e `password_verify()` na conferência, com rehash
automático quando o algoritmo padrão do PHP é atualizado.

Existe ainda o fluxo de recuperação de senha por token
(`recuperar_senha.php` e `redefinir_senha.php`), com token de uso único e prazo
de expiração gravados na conta.

## 17. Banco de Dados

O banco `agendei` é criado pelo arquivo `banco.sql` e possui 27 tabelas.
Todas as tabelas de dados operacionais carregam a coluna `id_estabelecimento`.

### 17.1 Tabelas centrais

| Tabela | Finalidade |
|--------|------------|
| `estabelecimento` | Dados, identidade visual e endereço público de cada empresa |
| `usuarios` | A pessoa, única na plataforma: nome, e-mail, hash da senha, telefones, endereço, bloqueio global e os dados do segundo fator (nome materno, data de nascimento e CEP) |
| `vinculos` | A pessoa em cada estabelecimento: perfil (cliente, profissional ou administrador), situação, login de 6 letras e último acesso |
| `clientes` | Dados específicos do cliente, incluindo o CPF e os pontos de fidelidade |
| `profissionais` | Dados específicos do profissional e percentual de comissão |
| `administradores` | Dados específicos do administrador do estabelecimento |
| `administradores_master` | Contas do master global da plataforma |
| `servicos` | Catálogo de serviços, com duração, valor e situação |
| `profissional_servico` | Quais serviços cada profissional executa |
| `horarios_profissionais` | Expediente semanal de cada profissional |
| `bloqueios_agenda` | Períodos indisponíveis (férias, folgas, compromissos) |
| `agendamentos` | Reservas, relacionando cliente, profissional e serviço |
| `logs_autenticacao` | Histórico dos eventos de autenticação |
| `configuracoes` | Regras ajustáveis por estabelecimento, no formato chave/valor |

### 17.2 Tabelas dos recursos complementares

`lista_espera`, `notificacoes`, `pagamentos`, `fidelidade_movimentos`,
`pacotes`, `cliente_pacotes` e `avaliacoes`.

### 17.3 Tabelas da administração da plataforma

`logs_master` guarda a trilha de auditoria da conta global; `tentativas_acesso`
é o contador do controle de força bruta; `planos` e `estabelecimento_plano`
descrevem os tetos contratados. As três primeiras ficam fora do recorte de
estabelecimento de propósito — a auditoria e o bloqueio protegem também a área
master, que não pertence a empresa nenhuma.

`logs_master` repete a desnormalização de `logs_autenticacao`: o nome do autor e
o da empresa são gravados por cópia, sem chave estrangeira, para que o histórico
continue legível depois que a conta ou o estabelecimento citado deixar de
existir.

### 17.4 Relacionamentos

Uma pessoa tem um ou mais vínculos, e cada vínculo é cliente, profissional ou
administrador de um estabelecimento. Um agendamento pertence a um cliente, a
um profissional e a um serviço. Um profissional possui vários
horários de expediente e executa vários serviços. As chaves estrangeiras são
compostas por `id_estabelecimento` + identificador, o que impede no nível do
banco qualquer associação entre registros de estabelecimentos diferentes.

A tela `modelo_bd.php`, acessível aos dois perfis, apresenta o diagrama
entidade-relacionamento das tabelas centrais.

## 18. Tecnologias Utilizadas

O sistema é desenvolvido sem frameworks e sem dependências externas de
instalação (não utiliza Composer nem bibliotecas de terceiros).

**HTML** — estrutura das páginas e dos formulários, com marcação semântica e
rótulos associados aos campos.

**CSS** — aparência da aplicação, organizada em folhas próprias
(`assets/css/`), com as cores do estabelecimento aplicadas por variáveis CSS
geradas a partir do banco.

**JavaScript** — interações no navegador: máscaras, validações de apoio,
consulta de CEP na API ViaCEP, carregamento dos horários livres sem recarregar
a página, agenda administrativa e barra de acessibilidade. O JavaScript nunca
decide sozinho: toda regra é reavaliada no servidor.

**PHP 8.0 ou superior** — responsável por autenticação, controle de sessão,
segundo fator, cadastro, consultas, alterações, exclusões, cálculo de
disponibilidade, regras de agendamento, relatórios, geração de PDF e validação
de todos os dados recebidos.

**MySQL 5.7+ / MariaDB 10.2+** — armazenamento permanente, acessado
exclusivamente por PDO com prepared statements.

**Ambiente de execução** — Apache com PHP (XAMPP, WAMP, Laragon ou equivalente).

## 19. Organização da Aplicação

O código é organizado em camadas, de modo que as páginas não montem consultas
SQL diretamente.

```
/agendei
  /admin           Painel administrativo (dashboard, agenda, CRUDs, relatórios)
  /api             Endpoints JSON (profissionais, horários livres, dias com vaga)
  /assets          css, js e imagens
  /cliente         Painel do cliente (dashboard, agendamento, histórico, perfil)
  /config          config.php (bootstrap) e database.php (conexão PDO)
  /includes        auth.php, funcoes.php, layout e ícones
  /master          Área do master global da plataforma
  /models          Regras de acesso ao banco, uma classe por entidade
  /profissional    Painel do profissional (agenda, horários, bloqueios, perfil)
  /scripts         Migrações de bases criadas por versões anteriores
  /tests           Testes automatizados
  banco.sql        Estrutura e dados iniciais
  index.php        Página pública do estabelecimento
```

Pontos relevantes da organização:

- `config/config.php` é o único arquivo que cada página precisa incluir: ele
  carrega a conexão, as funções utilitárias, a autenticação e registra o
  autoload dos models;
- os **models** concentram todo o SQL;
- `models/Disponibilidade.php` é o motor de horários do sistema;
- `models/Agendamento.php` concentra as regras de criação, alteração de status e
  cancelamento;
- `models/PdfSimples.php` gera o PDF da lista de usuários em PHP puro, sem
  bibliotecas externas.

### 19.1 Instalação

O arquivo `banco.sql` cria o banco, as tabelas, os serviços de exemplo e as
configurações iniciais. Em seguida, `instalar.php` cria o administrador, três
profissionais com expediente configurado, um cliente de demonstração e alguns
agendamentos. Após a instalação, o arquivo `instalar.php` deve ser apagado.

### 19.2 Testes automatizados

O projeto acompanha quatro suítes executáveis por linha de comando:

| Comando | O que verifica |
|---------|----------------|
| `php tests/requisitos.php` | Regras de validação do cadastro e comportamento do 2FA (não usa banco) |
| `php tests/fluxo_projeto.php` | Cadastro, login, 2FA, registro de log e exclusão de usuário |
| `php tests/multitenancy.php` | Isolamento dos dados entre estabelecimentos |
| `php tests/master.php` | Auditoria da administração master, contas globais, manutenção dos administradores locais e bloqueios de força bruta |

As três últimas criam e descartam um banco temporário próprio e nunca tocam a
base de uso normal.

## 20. Segurança

Os recursos de segurança implementados são:

- **Senhas com hash**: `password_hash()` na gravação, `password_verify()` na
  conferência e rehash automático;
- **Consultas preparadas**: todo acesso ao banco usa PDO com prepared
  statements, o que elimina a injeção de SQL;
- **Token CSRF** em todos os formulários (`campoCsrf()` e `exigirCsrf()`),
  impedindo que outro site envie requisições em nome do usuário logado;
- **Escape de saída** com `htmlspecialchars()` pela função `e()`, impedindo
  execução de scripts injetados em campos de texto;
- **Controle de acesso por perfil** em todas as páginas restritas, verificado no
  servidor e não apenas no menu;
- **Segundo fator de autenticação** com limite de três tentativas;
- **Validação dupla** dos dados, no navegador e novamente no servidor;
- **Isolamento entre estabelecimentos** garantido por chaves estrangeiras
  compostas;
- **Restrição de leitura dos agendamentos** ao próprio cliente;
- **Registro de logs** de todos os eventos de autenticação;
- **Recuperação de senha por token** de uso único e com prazo de expiração;
- **`.htaccess`** bloqueando o acesso direto às pastas `config/`, `models/` e
  `includes/`;
- **Modo de produção**: alterando a constante `AMBIENTE` para `'producao'` em
  `config/config.php`, as mensagens de erro detalhadas deixam de ser exibidas e
  passam a ser registradas no log do servidor.

## 21. Acessibilidade

A interface foi construída com informações organizadas, campos devidamente
identificados por rótulos associados, botões de texto claro, contraste
adequado, navegação simples e estrutura visual consistente entre as telas.

Além disso, o sistema possui uma **barra de acessibilidade**
(`assets/js/acessibilidade.js`), carregada por `includes/tema.php` e, portanto,
presente em todas as telas. Ela oferece:

- modo de alto contraste (fundo escuro com fonte clara);
- três tamanhos de fonte (100%, 115% e 130%).

A preferência escolhida fica guardada no navegador do usuário e é reaplicada nos
acessos seguintes. A leitura dessa preferência é tolerante a falhas: um
navegador com armazenamento bloqueado não quebra a página.

## 22. Responsividade

A interface se adapta a diferentes tamanhos de tela, permitindo o uso em
computador, notebook, tablet e smartphone.

Os componentes se reorganizam conforme o espaço disponível: o menu lateral se
recolhe, as tabelas ganham rolagem horizontal quando necessário, os formulários
passam de várias colunas para coluna única e os cartões de indicadores se
empilham. O fluxo de agendamento foi pensado para funcionar em tela pequena,
já que é a situação mais comum de uso pelo cliente.

## 23. Interface do Sistema

A interface tem aparência profissional, próxima à de sistemas comerciais, e
evita elementos visuais exagerados. A identidade visual utiliza:

- fundo predominantemente claro;
- cores discretas, definidas por cada estabelecimento em **Admin > Aparência**
  (cor primária, cor secundária, cor de fundo, fonte e logotipo);
- campos de formulário organizados em grupos;
- bordas com pouco arredondamento;
- tabelas para a visualização dos registros;
- botões de fácil identificação;
- menu de navegação lateral organizado por perfil;
- separação clara entre as funcionalidades.

As cores escolhidas pelo estabelecimento são aplicadas por variáveis CSS
geradas a partir do banco, o que permite personalizar a aparência sem alterar o
código.

## 24. Benefícios para o Cliente

- agendamento pela internet, a qualquer hora;
- consulta ao catálogo de serviços com duração e valor;
- escolha do profissional de preferência;
- visualização apenas dos horários efetivamente livres;
- confirmação imediata, sem depender de resposta do estabelecimento;
- consulta dos próprios agendamentos e do histórico de atendimentos;
- cancelamento dentro do prazo, sem precisar ligar;
- acesso por diferentes dispositivos;
- recursos de acessibilidade na própria tela;
- direito de baixar uma cópia dos próprios dados ou encerrar a conta.

## 25. Benefícios para o Estabelecimento

- centralização dos agendamentos em um único ambiente;
- organização dos horários a partir do expediente cadastrado;
- eliminação de conflitos de agenda, garantida pelo servidor;
- cadastro organizado de clientes, com histórico individual;
- registro estruturado dos serviços, com duração e valor;
- controle dos atendimentos por status;
- agenda visual por dia e por semana;
- indicadores no dashboard e relatórios gerenciais por período (faturamento,
  serviços mais agendados, profissionais com mais atendimentos, clientes mais
  frequentes, totais por status e movimento por dia);
- regras de negócio ajustáveis sem alteração de código;
- rastreabilidade dos acessos pelos logs;
- redução dos processos realizados manualmente.

### 25.1 Configurações ajustáveis

| Chave | Descrição |
|-------|-----------|
| `antecedencia_minima_horas` | Tempo mínimo entre o agendamento e o atendimento |
| `antecedencia_maxima_dias` | Até quantos dias no futuro o cliente pode agendar |
| `cancelamento_limite_horas` | Prazo para o cliente cancelar sozinho |
| `intervalo_slots_minutos` | Intervalo padrão entre os horários oferecidos |
| `permitir_bloqueio_profissional` | Se o profissional pode bloquear a própria agenda |
| `confirmar_automaticamente` | Se os agendamentos do cliente já nascem confirmados |

## 26. Recursos Complementares e Possibilidades de Evolução

### 26.1 Recursos já implementados além do escopo básico

- recuperação de senha por token;
- cadastro de profissionais e vínculo entre profissional e serviço;
- controle individual da agenda de cada profissional;
- definição de horário de funcionamento e de expediente semanal;
- bloqueio de datas e períodos;
- relatórios gerenciais por período e dashboard administrativo;
- histórico completo por cliente;
- geração de PDF da lista de usuários, em PHP puro;
- barra de acessibilidade com alto contraste e ajuste de fonte;
- personalização visual por estabelecimento;
- operação multiestabelecimento;
- lista de espera com aviso quando um cancelamento libera vaga compatível;
- fila de lembretes com mensagem pronta para WhatsApp;
- cobrança de sinal por chave Pix, com confirmação manual do pagamento;
- agendamentos semanais recorrentes;
- pontos de fidelidade creditados ao concluir o atendimento;
- pacotes de serviços com créditos e validade;
- comissão por profissional e resumo mensal;
- avaliações liberadas somente após atendimentos concluídos;
- feed iCalendar privado para Google Agenda, Outlook e Apple Calendar;
- área de privacidade: cópia dos próprios dados e encerramento de conta com
  anonimização das informações pessoais.

### 26.2 Evoluções previstas

A arquitetura isola os pontos de extensão para os próximos passos:

- envio automático de mensagens pela API oficial do WhatsApp (hoje o sistema
  mantém a fila e abre a conversa pronta para envio);
- confirmação bancária automática do Pix (hoje a confirmação é manual);
- envio de confirmação e lembretes por e-mail;
- cadastro de feriados;
- controle financeiro completo e novas formas de pagamento;
- comprovante de agendamento para o cliente;
- integração bidirecional com o Google Calendar.

A coluna `agendamentos.origem` já identifica o canal de criação, a tabela
`configuracoes` permite novas regras sem alterar código, e cada entidade possui
seu model isolado, de modo que novos campos e tabelas não afetam as telas
existentes.

## 27. Conclusão

O Agendei foi desenvolvido para tornar o processo de marcação de atendimentos
mais simples, organizado e confiável. O estabelecimento disponibiliza seus
serviços, cadastra sua equipe e o expediente, e passa a gerenciar os
agendamentos em um ambiente único. O cliente realiza seu cadastro, acessa a
conta, consulta os serviços, escolhe profissional, data e horário e confirma o
agendamento pelo próprio site.

Além da funcionalidade principal de agendamento, o projeto contempla
autenticação com segundo fator, controle de acesso por perfil, gerenciamento de
usuários, registro de logs de autenticação, cancelamento com preservação de
histórico, relatórios gerenciais, acessibilidade e responsividade.

O sistema está implementado em PHP e MySQL, com armazenamento permanente das
informações, processamento realizado no servidor, senhas protegidas por hash e
consultas preparadas em todo o acesso ao banco. Dessa maneira, o projeto atende
à proposta de desenvolver uma solução web capaz de conectar clientes e
estabelecimentos, proporcionando praticidade ao usuário e maior controle e
organização para os responsáveis pela prestação dos serviços.
