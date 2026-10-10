AGENDEI

Sistema de agendamento de serviços

Documentação consolidada do projeto

Front-end • Back-end • Requisitos • MER e DER • Registros do grupo

Grupo XX — número a preencher

Integrantes:

João Vitor Duarte Peçanha Rosa

Gabriel Lima Maciel

Thayrine de Lira Maciel

Carlos Eduardo Correa Machado

Fabiano Gonçalves

Registros de desenvolvimento: agosto a outubro de 2026

Revisão documental: 10 de outubro de 2026

SUMÁRIO

# 1 Apresentação da entrega

Este documento reúne o relatório do sistema, a análise de requisitos, as telas públicas capturadas, o modelo de dados e os registros de reuniões e atividades fornecidos pelo grupo. As informações técnicas foram revisadas com base nos arquivos versionados do Agendei.

| Critério de avaliação | Localização |
| --- | --- |
| Requisitos funcionais e não funcionais | Capítulo 2: requisitos numerados e regras de negócio. |
| Identidade visual, telas, validações e responsividade | Capítulos 3 e 4: descrição e capturas reais de telas públicas. |
| Modelo MER e DER | Capítulo 5 e anexos: modelo conceitual, diagramas físicos e dicionário. |
| Reuniões, decisões e atividades por componente | Capítulo 6: cronologia e contribuições individuais registradas. |
| Front-end, PHP e criação do banco | Capítulo 7 e pacote ZIP: código da aplicação e scripts SQL. |

A nomenclatura 2024-2 do arquivo foi mantida conforme o enunciado fornecido. Os registros de reuniões dos documentos de origem são de 2026. O número do grupo não foi informado e permanece indicado por XX.

# 2 Análise de requisitos

## 2.1 Atores

| Ator | Responsabilidade |
| --- | --- |
| Visitante | Consultar a apresentação pública da plataforma e acessar os formulários de entrada e cadastro. |
| Cliente | Consultar serviços e disponibilidade, reservar, acompanhar e cancelar os próprios atendimentos. |
| Profissional | Consultar a própria agenda e executar operações autorizadas de atendimento e bloqueio. |
| Administrador do estabelecimento | Gerenciar os dados operacionais e as configurações da empresa. |
| Administrador master global | Administrar estabelecimentos e contas globais em área separada. |

## 2.2 Requisitos funcionais

| Código | Requisito |
| --- | --- |
| RF01 | Visualizar a página inicial: apresentar o produto Agendei, seus recursos e os acessos de cadastro e login. O link de um estabelecimento resolve sua entrada local; o catálogo para agendamento é consultado na área do cliente. |
| RF02 | Cadastrar cliente: permitir cadastro com nome, CPF, nascimento, sexo, nome materno, contatos, endereço, login, senha e confirmação. |
| RF03 | Validar cadastro: conferir os campos no navegador e no servidor; e-mail é único na plataforma, enquanto CPF e login possuem restrições por estabelecimento. Contas existentes utilizam o fluxo de vínculo. |
| RF04 | Realizar login: na entrada global, aceitar e-mail e senha; na entrada da empresa, aceitar e-mail ou login local e senha. Concluir o acesso após o segundo fator aplicável. |
| RF05 | Encerrar sessão: usuários devem conseguir sair do sistema. |
| RF06 | Recuperar senha: o usuário deve poder solicitar um link para redefinição de senha. |
| RF07 | Controlar permissões: o sistema deve restringir funcionalidades conforme o perfil do usuário. |
| RF08 | Visualizar painel do cliente: exibir próximos agendamentos, histórico e estatísticas. |
| RF09 | Consultar serviços disponíveis: exibir nome, descrição, preço e duração. |
| RF10 | Consultar profissionais: apresentar os profissionais que executam determinado serviço. |
| RF11 | Consultar disponibilidade: exibir dias e horários livres de cada profissional. |
| RF12 | Realizar agendamento: permitir selecionar serviço, profissional, data, horário e observação. |
| RF13 | Confirmar agendamento: exibir os dados do atendimento após a reserva. |
| RF14 | Listar agendamentos: permitir consultar agendamentos por status. |
| RF15 | Visualizar detalhes: mostrar serviço, profissional, data, horário, duração, valor e observações. |
| RF16 | Cancelar agendamento: permitir o cancelamento dentro do prazo configurado. |
| RF17 | Consultar histórico: exibir agendamentos concluídos e cancelados. |
| RF18 | Atualizar perfil: permitir alterar dados pessoais e senha. |
| RF19 | Visualizar painel profissional: mostrar agenda do dia, próximos atendimentos e taxa de ocupação. |
| RF20 | Consultar agenda: permitir visualizar os atendimentos por dia ou período. |
| RF21 | Confirmar atendimento: permitir alterar o status de “agendado” para “confirmado”. |
| RF22 | Concluir atendimento: permitir marcar um atendimento como “concluído” após seu início. |
| RF23 | Visualizar expediente: consultar horários de trabalho cadastrados. |
| RF24 | Visualizar serviços executados: consultar os serviços vinculados ao profissional. |
| RF25 | Criar bloqueio de agenda: permitir que o profissional bloqueie períodos da própria agenda quando autorizado pela configuração do estabelecimento e por sua permissão individual. |
| RF26 | Remover bloqueio: permitir a exclusão de bloqueios da própria agenda segundo as permissões verificadas no servidor. |
| RF27 | Atualizar perfil profissional: alterar apresentação, biografia, telefone e senha. |
| RF28 | Visualizar dashboard administrativo: exibir indicadores e agendamentos recentes. |
| RF29 | Gerenciar serviços: cadastrar, editar, ativar, desativar e excluir serviços. |
| RF30 | Gerenciar profissionais: cadastrar, editar, ativar, desativar e excluir profissionais. |
| RF31 | Associar serviços a profissionais: definir quais serviços cada profissional executa. |
| RF32 | Configurar expediente: cadastrar, ativar, desativar, excluir e replicar faixas de horário. |
| RF33 | Gerenciar bloqueios: criar e excluir bloqueios de agenda dos profissionais. |
| RF34 | Gerenciar clientes: consultar clientes, visualizar histórico, alterar status e registrar observações internas. |
| RF35 | Criar agendamento manual: permitir que o administrador faça agendamentos pelo sistema. |
| RF36 | Gerenciar agendamentos: consultar, filtrar, confirmar, concluir e cancelar agendamentos. |
| RF37 | Visualizar agenda geral: exibir a agenda por semana ou por profissional. |
| RF38 | Gerar relatórios: apresentar dados de serviços, profissionais, clientes, movimento diário, quantidade de atendimentos e faturamento. |
| RF39 | Configurar estabelecimento: alterar nome, descrição, endereço, contatos, redes sociais e horários de funcionamento. |
| RF40 | Configurar regras: definir antecedência mínima, antecedência máxima, prazo para cancelamento, intervalo dos horários e confirmação automática. |
| RF41 | Gerenciar vínculos: permitir que uma pessoa utilize a mesma identidade em diferentes estabelecimentos e selecione o contexto autorizado de acesso. |
| RF42 | Autenticar em segunda etapa: validar código TOTP quando ativado ou o desafio cadastral aplicável; limitar tentativas. |
| RF43 | Administrar a plataforma: disponibilizar área master separada para gestão de estabelecimentos, contas e auditoria global. |
| RF44 | Gerenciar filiais: associar unidades, profissionais e reservas conforme as regras implementadas. |
| RF45 | Gerenciar diferenciais: disponibilizar, quando habilitados, espera, pacotes, fidelidade, avaliações e sinal Pix com confirmação manual. |
| RF46 | Gerenciar lembretes: manter fila e estado de envio; usar modo manual ou provedor configurado para WhatsApp. |

## 2.3 Regras de negócio

| Código | Regra |
| --- | --- |
| RN01 | não deve haver dois agendamentos conflitantes para o mesmo profissional. |
| RN02 | o cliente não pode realizar agendamento fora do expediente. |
| RN03 | horários bloqueados não podem ser agendados. |
| RN04 | somente serviços ativos podem ser agendados. |
| RN05 | somente profissionais ativos podem receber agendamentos. |
| RN06 | o profissional precisa estar vinculado ao serviço escolhido. |
| RN07 | O mesmo cliente não pode possuir agendamentos ativos com intervalos sobrepostos, mesmo com profissionais diferentes. |
| RN08 | o cliente não pode agendar em data passada. |
| RN09 | o cliente deve respeitar os limites de antecedência mínima e máxima. |
| RN10 | o cliente só pode cancelar dentro do prazo configurado. |
| RN11 | clientes não podem acessar áreas administrativas. |
| RN12 | profissionais não podem acessar funcionalidades exclusivas do administrador. |
| RN13 | Identidade global e vínculo local: usuarios.email é único na plataforma; vinculos.tipo define o perfil em cada estabelecimento. |
| RN14 | Preservar dados da reserva: valor e hora_fim são gravados na criação e não recalculados por alterações posteriores do catálogo. |
| RN15 | Cancelar preserva a reserva: alterar o status, registrar motivo/responsável quando aplicáveis e liberar o intervalo. |
| RN16 | Crédito de pacote: id_cliente_pacote não possui FK física; a aplicação valida titularidade, serviço, saldo, validade e pagamento. |

## 2.4 Requisitos não funcionais

| Código | Requisito |
| --- | --- |
| RNF01 | Plataforma: o sistema deve funcionar em navegadores web. |
| RNF02 | Tecnologia: utilizar PHP 8 ou superior, PDO, HTML, CSS e JavaScript, com suporte a MySQL/MariaDB ou PostgreSQL. |
| RNF03 | Banco de dados: deve utilizar PDO para comunicação com o banco. |
| RNF04 | Segurança de senhas: as senhas devem ser armazenadas usando password_hash() e verificadas com password_verify(). |
| RNF05 | Segurança contra SQL Injection: as consultas devem utilizar comandos preparados. |
| RNF06 | Segurança contra CSRF: os formulários devem utilizar tokens CSRF. |
| RNF07 | Controle de acesso: cada perfil deve acessar somente suas próprias funcionalidades. |
| RNF08 | Proteção contra XSS: dados exibidos na tela devem ser escapados com htmlspecialchars(). |
| RNF09 | Segurança de sessão: os cookies de sessão devem utilizar configurações como HttpOnly e SameSite. |
| RNF10 | Integridade dos dados: utilizar PKs, FKs, restrições de unicidade, índices e transações. Referências lógicas sem FK devem ser identificadas como tais no modelo. |
| RNF11 | Concorrência: o sistema deve impedir reservas simultâneas para o mesmo horário utilizando transações e bloqueio de registros. |
| RNF12 | Usabilidade: o fluxo de agendamento deve ser simples e dividido em etapas. |
| RNF13 | Responsividade: as telas devem se adaptar a computadores, tablets e celulares. |
| RNF14 | Internacionalização: o sistema deve utilizar português do Brasil, horário de São Paulo e moeda brasileira. |
| RNF15 | Manutenibilidade: as regras de banco devem ficar concentradas nos Models e a autenticação em arquivos próprios. |
| RNF16 | Modularidade: o sistema deve possuir módulos separados para administração, clientes, profissionais, APIs e configurações. |
| RNF17 | Desempenho: empregar índices e filtros; aplicar paginação nas listagens que a implementam. Não se estabelece aqui uma meta de tempo de resposta medida. |
| RNF18 | Interoperabilidade: as APIs de profissionais, dias e horários devem retornar dados no formato JSON. |

## 2.5 Rastreabilidade das funcionalidades

| Requisitos | Arquivos de referência |
| --- | --- |
| RF01–RF07, RF41–RF42 | index.php; cadastro.php; entrar.php; login.php; vincular.php; dois_fatores.php; includes/auth.php |
| RF08–RF18 | cliente/dashboard.php; cliente/agendar.php; cliente/agendamentos.php; cliente/historico.php; cliente/perfil.php |
| RF19–RF27 | profissional/dashboard.php; profissional/agenda.php; profissional/horarios.php; profissional/bloqueios.php; profissional/perfil.php |
| RF28–RF40 | admin/dashboard.php; admin/servicos.php; admin/profissionais.php; admin/horarios.php; admin/agendamentos.php; admin/relatorios.php; admin/configuracoes.php |
| RF43–RF46 | master/; admin/filiais.php; admin/diferenciais.php; models/Diferencial.php; models/Lembrete.php; models/WhatsApp.php |
| RN01–RN10, RN14–RN16 | models/Agendamento.php; models/Disponibilidade.php; models/Diferencial.php |
| RNF03–RNF11 | config/database.php; includes/seguranca.php; includes/auth.php; models/Sql.php; banco.sql; banco_postgres.sql |

# 3 Relatório técnico do sistema

## 3.1 Introdução

A transformação digital modificou a maneira como pessoas e empresas organizam serviços, atendimentos e compromissos. Em muitos estabelecimentos, entretanto, a marcação de horários ainda depende de ligações, atendimento presencial ou troca de mensagens, o que pode gerar retrabalho, dificuldade de controle, esquecimento de informações e conflitos de agenda.

O Agendei foi desenvolvido como uma solução web para centralizar o processo de agendamento. A aplicação organiza, em um mesmo ambiente, os dados do estabelecimento, dos clientes, dos profissionais, dos serviços, dos expedientes, dos bloqueios e dos atendimentos. O cliente passa a consultar a disponibilidade e efetuar a reserva diretamente pela internet, enquanto o estabelecimento administra a operação por meio de painéis e cadastros específicos.

A proposta não é limitada a um único segmento. Como o catálogo de serviços, a equipe, os horários e as regras são configurados por estabelecimento, a aplicação pode ser utilizada em barbearias, salões de beleza, clínicas, oficinas, serviços de manutenção, estética, atendimento técnico e outros negócios que operem por horário marcado.

### 3.1.1 Contextualização do problema

Quando os agendamentos são realizados de forma descentralizada, torna-se mais difícil identificar horários efetivamente livres, preservar o histórico dos atendimentos e acompanhar cancelamentos. Também aumenta a dependência de uma pessoa para responder mensagens, conferir agendas e repassar informações aos profissionais.

Nesse contexto, o Agendei concentra as regras do processo no próprio sistema. A disponibilidade é calculada a partir do expediente cadastrado de cada profissional, da duração do serviço, dos bloqueios existentes e dos agendamentos já registrados. Assim, o horário apresentado ao cliente representa uma vaga que pode ser validada novamente pelo servidor antes da confirmação.

### 3.1.2 Justificativa

A construção de uma plataforma própria permite integrar recursos de cadastro, agenda, autenticação, relatórios e segurança em uma única aplicação. Além de reduzir atividades manuais, o sistema cria uma base organizada de informações que pode ser consultada por diferentes perfis de usuário conforme suas permissões.

Do ponto de vista acadêmico, o projeto também reúne conceitos importantes de desenvolvimento web, modelagem de banco de dados, validação de formulários, autenticação, controle de acesso, segurança da informação, responsividade, acessibilidade e aplicação de regras de negócio no servidor.

## 3.2 Objetivos

### 3.2.1 Objetivo geral

Desenvolver um sistema web capaz de permitir que estabelecimentos disponibilizem serviços para agendamento on-line, mantendo controle real da disponibilidade dos profissionais e oferecendo aos clientes um processo digital para seleção de serviço, profissional, data e horário.

### 3.2.2 Objetivos específicos

facilitar o agendamento de serviços pela internet;

reduzir a dependência de processos manuais para marcação de horários;

organizar os horários disponíveis com base no expediente dos profissionais;

impedir conflitos de agenda para o mesmo profissional;

manter registros estruturados de clientes, profissionais, serviços e atendimentos;

permitir o acompanhamento dos agendamentos por clientes, profissionais e administradores;

oferecer indicadores e relatórios gerenciais ao estabelecimento;

registrar eventos de autenticação para auditoria e segurança;

aplicar diferentes níveis de acesso conforme o perfil do usuário;

oferecer uma interface utilizável em computadores e dispositivos móveis.

## 3.3 Visão geral do sistema

O Agendei funciona por meio da interação entre quatro perfis principais: cliente, profissional, administrador do estabelecimento e administrador master da plataforma. Cada perfil possui uma área própria e visualiza somente as funções necessárias à sua atuação.

### 3.3.1 Perfis de acesso

| Perfil | Função no sistema | Principais permissões |
| --- | --- | --- |
| Cliente | Usuário que contrata serviços | Cadastro, login, agendamento, histórico, perfil, cancelamento e privacidade. |
| Profissional | Usuário responsável pelos atendimentos | Consulta da própria agenda, conclusão de atendimentos, expediente e bloqueios autorizados. |
| Administrador | Responsável pelo estabelecimento | Dashboard, agenda, serviços, profissionais, clientes, relatórios, configurações, usuários e logs. |
| Master global | Administração da plataforma | Criação de estabelecimentos e acompanhamento estrutural da plataforma. |

Fonte: elaborado pelos autores com base na documentação do sistema Agendei.

### 3.3.2 Arquitetura multiestabelecimento

A aplicação foi estruturada para permitir que vários estabelecimentos compartilhem o mesmo banco de dados sem compartilhar indevidamente os registros entre si. Os dados operacionais possuem vínculo com o estabelecimento por meio do campo id_estabelecimento, utilizado em contas, clientes, profissionais, serviços, horários, agendamentos, configurações e logs.

O isolamento é reforçado por chaves compostas associadas ao estabelecimento. Cada empresa possui endereço público identificado por slug e pode personalizar nome, logotipo, cores e fonte. O cadastro cria um vínculo local; a identidade global do usuário pode ser reutilizada em outras empresas mediante autenticação.

## 3.4 Funcionamento do sistema

### 3.4.1 Fluxo do cliente

O fluxo do cliente foi organizado para reduzir etapas desnecessárias e conduzir o usuário da criação da conta até a confirmação do atendimento.

1. acessar a página pública do estabelecimento;

2. realizar o cadastro com dados pessoais, endereço e credenciais;

3. efetuar o login;

4. responder ao segundo fator de autenticação;

5. consultar os serviços disponíveis;

6. selecionar o serviço desejado;

7. escolher um profissional habilitado;

8. selecionar uma data com disponibilidade;

9. escolher um horário livre;

10. confirmar o agendamento;

11. acompanhar posteriormente a situação da reserva e, quando permitido, realizar o cancelamento.

### 3.4.2 Fluxo administrativo

O administrador configura o ambiente que torna o agendamento possível. Primeiramente são cadastrados os serviços, incluindo nome, descrição, duração, valor e situação. Em seguida são cadastrados os profissionais e definidos os serviços que cada um executa. Por fim, são registrados o expediente semanal e eventuais bloqueios de agenda.

A partir dessas informações o sistema calcula os horários que podem ser oferecidos ao cliente. O administrador também pode acompanhar a agenda, criar agendamentos manualmente, alterar a situação dos atendimentos, cancelar reservas, consultar clientes, relatórios, configurações e registros de autenticação.

## 3.5 Cadastro de usuários

O cadastro público cria usuários com perfil de cliente e não concede privilégios administrativos automaticamente. O formulário reúne dados pessoais, dados de contato, endereço e credenciais de acesso.

| Grupo | Informações principais |
| --- | --- |
| Identificação | Nome completo, data de nascimento, sexo, nome materno e CPF. |
| Contato | E-mail, telefone celular e telefone fixo. |
| Endereço | CEP, logradouro, número, complemento, bairro, cidade e UF. |
| Credenciais | Login, senha e confirmação de senha. |

Fonte: elaborado pelos autores com base na documentação do sistema Agendei.

### 3.5.1 Validações de cadastro

As regras de validação são aplicadas no navegador para orientar o preenchimento e repetidas no servidor antes da gravação. Essa duplicidade é importante porque qualquer validação realizada somente com JavaScript pode ser contornada pelo usuário.

| Campo | Regra implementada |
| --- | --- |
| Nome completo | Entre 15 e 80 caracteres, utilizando letras e espaços. |
| CPF | 11 dígitos, rejeição de sequências repetidas e validação dos dígitos verificadores. |
| Nome materno | Entre 5 e 120 caracteres, utilizando letras e espaços. |
| E-mail | Formato válido e unicidade global em usuarios.email. A mesma conta pode possuir vínculos com diferentes estabelecimentos. |
| CEP | Oito dígitos, com possibilidade de consulta automática de endereço. |
| UF | Validação entre as 27 unidades da Federação. |
| Telefone celular | DDD mais nove dígitos. |
| Telefone fixo | DDD mais oito dígitos. |
| Login | Exatamente seis caracteres alfabéticos e unicidade no estabelecimento. |
| Senha | Exatamente oito caracteres alfabéticos, armazenada por hash. |
| Confirmação | Deve corresponder à senha informada. |

Fonte: elaborado pelos autores com base na documentação do sistema Agendei.

A consulta de CEP pode ser realizada por serviço externo. Caso esse serviço esteja indisponível, o endereço permanece editável para que o cadastro possa ser concluído manualmente.

## 3.6 Login, autenticação e controle de acesso

A tela de login aceita o identificador de seis letras ou o endereço de e-mail associado à conta. A senha é verificada por meio de funções de hash do PHP, evitando a comparação com uma senha armazenada em texto puro.

Após a verificação inicial, a aplicação solicita um segundo fator. Quando o autenticador TOTP está ativo, exige o código do aplicativo. Nas contas que não o ativaram, mantém a verificação acadêmica por nome materno, data de nascimento ou CEP. A sessão somente é concluída após a validação do fator correspondente.

### 3.6.1 Autenticação em duas etapas

O segundo fator possui limite de três tentativas. Após a terceira resposta incorreta, o fluxo pendente é descartado e o usuário precisa iniciar novamente o processo de login. A comparação das respostas considera diferentes formatos de data e CEP e normaliza diferenças como máscaras e acentuação.

### 3.6.2 Sessão e permissões

Após a autenticação, a sessão mantém informações como identificador, nome, perfil e estabelecimento do usuário. A navegação é adaptada ao perfil, mas a proteção não depende apenas da interface. As páginas restritas executam uma verificação no servidor para impedir que um usuário acesse diretamente uma URL sem possuir a permissão necessária.

## 3.7 Serviços, profissionais e disponibilidade

### 3.7.1 Cadastro de serviços

Cada serviço possui nome, descrição, duração em minutos, valor e situação. A duração é essencial para a montagem da agenda, pois determina o intervalo que será ocupado pelo atendimento. Serviços inativos deixam de ser disponibilizados para novas reservas, mas permanecem associados ao histórico de agendamentos já realizados.

### 3.7.2 Relação entre profissional e serviço

Os profissionais são vinculados aos serviços que estão aptos a executar. Consequentemente, quando o cliente seleciona um serviço, somente os profissionais habilitados para aquela atividade são exibidos.

### 3.7.3 Expediente e bloqueios

A disponibilidade é calculada individualmente para cada profissional. O sistema considera o expediente semanal, os períodos de bloqueio, a duração do serviço, os horários já ocupados e as regras configuradas pelo estabelecimento.

## 3.8 Processo de agendamento

O agendamento é a função central do Agendei. Na interface do cliente, o fluxo é sequencial: serviço, profissional, data e horário. Os horários disponíveis podem ser carregados dinamicamente sem recarregar toda a página, enquanto o servidor permanece responsável pela validação definitiva.

### 3.8.1 Validação antes da confirmação

o serviço deve existir, estar ativo e pertencer ao estabelecimento correto;

o profissional deve existir, estar ativo e executar o serviço selecionado;

a data e o horário devem ser válidos;

o período deve caber integralmente no expediente do profissional;

o horário não pode estar incluído em um bloqueio de agenda;

o atendimento não pode estar no passado;

os limites de antecedência mínima e máxima devem ser respeitados;

não pode existir outro agendamento ativo para o mesmo profissional no intervalo solicitado.

### 3.8.2 Prevenção de conflito simultâneo

A gravação do agendamento ocorre dentro de uma transação no banco de dados. Antes da confirmação definitiva, o sistema faz uma verificação final de conflito com bloqueio de concorrência. Dessa forma, se dois clientes tentarem confirmar a mesma vaga no mesmo instante, apenas uma reserva é registrada e a outra solicitação recebe a indicação de indisponibilidade.

A disponibilidade é calculada por profissional, permitindo atendimentos simultâneos em equipes diferentes. Além disso, a criação verifica se o mesmo cliente já possui outro atendimento ativo sobreposto, evitando duas reservas conflitantes para esse cliente.

## 3.9 Dados e situações do agendamento

| Informação | Finalidade |
| --- | --- |
| Cliente | Identificar quem solicitou o atendimento. |
| Profissional | Identificar quem executará o serviço. |
| Serviço | Definir o atendimento contratado. |
| Data e horário | Posicionar o atendimento na agenda. |
| Horário final | Calculado conforme a duração do serviço. |
| Valor | Registrar o preço associado ao atendimento. |
| Status | Indicar a etapa atual do agendamento. |
| Observação | Registrar informações adicionais. |
| Origem | Indicar se a reserva foi criada pelo cliente, administrador ou profissional. |
| Cancelamento | Registrar motivo e responsável quando houver. |
| Datas de controle | Registrar criação e última atualização. |

Fonte: elaborado pelos autores com base na documentação do sistema Agendei.

### 3.9.1 Status

| Status | Significado |
| --- | --- |
| agendado | Reserva criada e aguardando confirmação, quando a confirmação automática não estiver ativa. |
| confirmado | Atendimento confirmado. |
| concluído | Atendimento realizado. |
| cancelado | Reserva cancelada pelo cliente ou pelo estabelecimento. |

Fonte: elaborado pelos autores com base na documentação do sistema Agendei.

Somente os estados agendado e confirmado ocupam espaço na agenda. O cancelamento libera o horário, enquanto os atendimentos concluídos permanecem disponíveis para histórico e relatórios.

## 3.10 Consulta e cancelamento de agendamentos

### 3.10.1 Consulta

O cliente visualiza somente os próprios agendamentos. Essa restrição é aplicada também nas consultas ao banco de dados, e não apenas na tela. O administrador possui visão abrangente dos registros do estabelecimento, por meio da agenda, da listagem de agendamentos e do dashboard. O profissional, por sua vez, consulta somente a própria agenda.

### 3.10.2 Cancelamento

O cancelamento altera a situação da reserva e registra o motivo e o usuário responsável. O registro não é excluído automaticamente, permitindo preservar o histórico operacional. O cliente pode cancelar dentro do prazo definido pelo estabelecimento; fora desse prazo, a operação depende do administrador.

Quando um cancelamento libera uma vaga compatível, a aplicação pode utilizar a lista de espera para identificar clientes interessados naquele serviço e horário.

## 3.11 Gerenciamento de usuários e logs

### 3.11.1 Gerenciamento de usuários

A área administrativa disponibiliza consulta de usuários comuns, pesquisa por nome, visualização dos dados cadastrados e exclusão mediante confirmação. Também pode ser gerada uma listagem em PDF respeitando a pesquisa aplicada.

### 3.11.2 Registro de autenticação

Os eventos de acesso são registrados em estrutura própria. Entre as informações mantidas estão data e hora, nome, CPF, login informado, perfil, endereço IP, evento ocorrido e, quando aplicável, a pergunta utilizada no segundo fator.

login com sucesso

falha de login

sucesso no segundo fator

falha no segundo fator

bloqueio após tentativas

logout

Esses registros podem auxiliar na auditoria, na investigação de erros e na identificação de tentativas de acesso indevido. O histórico de autenticação é mantido separado da conta principal do usuário, permitindo preservar evidências operacionais mesmo quando uma conta é removida.

## 3.12 Alteração e recuperação de senha

A alteração de senha exige a senha atual, a nova senha e sua confirmação. O sistema valida as regras aplicáveis, compara a senha atual com o hash armazenado e grava um novo hash somente quando todas as condições são atendidas.

Para situações em que o usuário perde o acesso, existe um fluxo de recuperação por token. O token possui validade limitada e é destinado ao processo de redefinição, permitindo criar uma nova senha sem revelar a senha anterior.

## 3.13 Banco de dados e modelagem

O Agendei suporta MySQL/MariaDB e PostgreSQL por meio de PDO e da camada de adaptação SQL. banco.sql contém o esquema MySQL/MariaDB; banco_postgres.sql contém o equivalente PostgreSQL. Ambos possuem 28 tabelas, 290 colunas e 63 chaves estrangeiras declaradas no código versionado. Os dados operacionais são associados ao estabelecimento; a identidade da pessoa permanece global em usuarios.

### 3.13.1 Tabelas centrais

| Tabela | Finalidade |
| --- | --- |
| estabelecimento | Dados institucionais, identidade visual e endereço público da empresa. |
| usuarios | Identidade global, credenciais, dados pessoais e autenticação. O perfil por empresa é definido em vinculos.tipo. |
| vinculos | Associação da pessoa ao estabelecimento, com perfil, status, login e último acesso locais. |
| clientes | Informações específicas do cliente e dados de fidelidade. |
| profissionais | Informações do profissional e percentual de comissão. |
| administradores | Dados específicos do administrador do estabelecimento. |
| administradores_master | Contas do administrador global da plataforma. |
| servicos | Catálogo de serviços com duração, valor e situação. |
| profissional_servico | Relacionamento entre profissionais e serviços. |
| horarios_profissionais | Expediente semanal de cada profissional. |
| bloqueios_agenda | Períodos indisponíveis da agenda. |
| agendamentos | Reservas e relacionamentos entre cliente, profissional e serviço. |
| logs_autenticacao | Histórico de eventos de autenticação. |
| configuracoes | Regras ajustáveis do estabelecimento. |

Fonte: elaborado pelos autores com base na documentação do sistema Agendei.

### 3.13.2 Tabelas complementares

A aplicação também possui estruturas destinadas aos recursos complementares, entre elas lista de espera, notificações, pagamentos, movimentações de fidelidade, pacotes, vínculo de pacotes a clientes e avaliações.

### 3.13.3 Relacionamentos

usuarios representa a pessoa com e-mail único na plataforma. vinculos relaciona essa pessoa ao estabelecimento e define o tipo cliente, profissional ou admin. As tabelas clientes, profissionais e administradores complementam cada vínculo, com id_vinculo único em cada tabela de perfil. Uma pessoa pode participar de várias empresas. Os agendamentos ligam cliente, profissional e serviço; FKs compostas reforçam a associação à mesma empresa.

## 3.14 Tecnologias utilizadas

| Tecnologia | Aplicação no projeto |
| --- | --- |
| HTML | Estrutura das páginas, formulários e elementos semânticos. |
| CSS | Identidade visual, organização das telas, responsividade e variáveis de tema. |
| JavaScript | Máscaras, validações de apoio, consulta de CEP, carregamento dinâmico e recursos de acessibilidade. |
| PHP 8+ | Autenticação, sessões, regras de negócio, cadastros, consultas, disponibilidade, relatórios e validações do servidor. |
| MySQL/MariaDB ou PostgreSQL | Persistência das informações e relacionamentos entre as entidades. |
| Apache | Execução do sistema em ambientes como XAMPP, WAMP ou Laragon. |

Fonte: elaborado pelos autores com base na documentação do sistema Agendei.

O projeto foi estruturado sem framework de aplicação e sem necessidade de Composer para executar as funcionalidades centrais. O acesso ao banco de dados é realizado por PDO e consultas preparadas.

## 3.15 Organização da aplicação

O código foi dividido em pastas por responsabilidade, evitando concentrar toda a lógica em páginas únicas. A camada de modelos centraliza o acesso ao banco e as páginas utilizam esses componentes para executar as operações.

| Diretório/arquivo | Responsabilidade |
| --- | --- |
| admin/ | Painel administrativo, agenda, cadastros e relatórios. |
| api/ | Endpoints utilizados no carregamento de dados e disponibilidade. |
| assets/ | Arquivos CSS, JavaScript e imagens. |
| cliente/ | Painel, agendamento, histórico e perfil do cliente. |
| config/ | Configuração geral e conexão com o banco de dados. |
| includes/ | Autenticação, funções utilitárias, layout e componentes. |
| master/ | Área do administrador global da plataforma. |
| models/ | Classes de acesso ao banco e regras específicas das entidades. |
| profissional/ | Agenda, expediente, bloqueios e perfil profissional. |
| scripts/ | Rotinas de atualização ou migração de banco. |
| tests/ | Testes automatizados. |
| banco.sql | Script de criação da estrutura e dados iniciais para MySQL/MariaDB. |
| index.php | Página pública do estabelecimento. |
| banco_postgres.sql | Esquema equivalente de criação para PostgreSQL. |

Fonte: elaborado pelos autores com base na documentação do sistema Agendei.

### 3.15.1 Instalação

Em uma base nova, importe banco.sql para MySQL/MariaDB ou banco_postgres.sql para PostgreSQL. Configure o driver e a conexão em config/database.local.php ou pelas variáveis AGENDEI_DB_*. O instalador pode criar dados demonstrativos; deve ser removido do ambiente de produção após o uso. Os scripts de criação contêm comandos de limpeza, portanto não substituem migrações de uma base existente.

### 3.15.2 Testes automatizados

| Comando | Objetivo |
| --- | --- |
| php tests/requisitos.php | Verificar regras de validação do cadastro e comportamento da autenticação em duas etapas. |
| php tests/fluxo_projeto.php | Testar cadastro, login, segundo fator, log e exclusão de usuário. |
| php tests/multitenancy.php | Validar o isolamento dos dados entre estabelecimentos. |
| php tests/modelo_bd.php | Verificar cobertura e cardinalidades do modelo sem acesso ao banco. |

Fonte: elaborado pelos autores com base na documentação do sistema Agendei.

Os testes que dependem de banco podem utilizar uma base temporária própria, evitando alterar os registros do ambiente normal de uso.

## 3.16 Segurança da informação

A segurança foi tratada em diferentes camadas da aplicação. As senhas são protegidas por hash, as consultas ao banco utilizam prepared statements e as páginas restritas verificam o perfil autorizado no servidor.

armazenamento de senha por hash e verificação segura;

rehash automático quando necessário;

consultas preparadas com PDO;

token CSRF em formulários;

escape de conteúdo exibido na interface;

controle de acesso por perfil;

autenticação em duas etapas com limite de tentativas;

validação de dados no navegador e no servidor;

isolamento dos dados entre estabelecimentos;

restrição dos agendamentos ao cliente proprietário;

registro de eventos de autenticação;

recuperação de senha por token de uso limitado;

restrição de acesso a diretórios internos;

configuração de produção para não exibir detalhes técnicos de erros ao usuário.

## 3.17 Proteção de dados pessoais e lgpd

Como o Agendei trata informações de pessoas identificadas, como nome, CPF, endereço, telefone, e-mail, dados de autenticação e histórico de agendamentos, a operação do sistema deve considerar as regras aplicáveis à proteção de dados pessoais.

A Lei nº 13.709/2018, conhecida como Lei Geral de Proteção de Dados Pessoais (LGPD), disciplina o tratamento de dados pessoais inclusive em meios digitais e estabelece princípios como finalidade, adequação, necessidade, livre acesso, transparência, segurança e prevenção. A aplicação desses princípios exige que os dados sejam utilizados de maneira compatível com a finalidade do serviço e com acesso limitado aos usuários que efetivamente necessitam das informações.

No Agendei, alguns mecanismos técnicos contribuem para esse objetivo: cada cliente consulta apenas seus próprios agendamentos; os profissionais acessam a própria agenda; os administradores ficam vinculados ao estabelecimento; as senhas são protegidas por hash; os eventos de autenticação são registrados; e a aplicação possui uma área de privacidade que permite ao usuário obter uma cópia dos próprios dados e solicitar o encerramento da conta com tratamento apropriado das informações pessoais.

A utilização real do sistema por uma organização também exige procedimentos administrativos, definição de responsabilidades, informações transparentes ao titular e regras de retenção compatíveis com a legislação e com a finalidade do tratamento.

## 3.18 Acessibilidade

A interface utiliza rótulos associados aos campos, botões identificáveis, contraste adequado e organização consistente. Além disso, uma barra de acessibilidade está disponível nas telas e oferece modo de alto contraste e três tamanhos de fonte.

A preferência de acessibilidade pode ser armazenada no navegador para ser reaplicada em acessos futuros. Caso o armazenamento local esteja indisponível, a aplicação continua funcionando sem interromper a navegação.

## 3.19 Responsividade e interface

### 3.19.1 Responsividade

As páginas foram organizadas para se adaptar a computadores, notebooks, tablets e smartphones. Em telas menores, menus podem ser recolhidos, formulários passam a utilizar menos colunas, tabelas permitem rolagem horizontal e cartões de indicadores são reposicionados. Essa adaptação é especialmente importante no fluxo do cliente, que frequentemente realiza o agendamento pelo celular.

### 3.19.2 Diretrizes visuais

A identidade visual busca uma aparência profissional e próxima de sistemas comerciais. Predominam fundos claros, cores discretas, campos organizados, bordas com pouco arredondamento, tabelas para registros, botões de fácil identificação e navegação separada por perfil.

O estabelecimento pode personalizar cores, fonte e logotipo pela área administrativa, sem necessidade de alterar diretamente o código-fonte.

## 3.20 Benefícios do sistema

### 3.20.1 Benefícios para o cliente

agendamento on-line sem necessidade de contato manual;

consulta ao catálogo com duração e valor dos serviços;

escolha do profissional;

visualização de horários efetivamente disponíveis;

acompanhamento de reservas e histórico;

possibilidade de cancelamento dentro das regras configuradas;

acesso por diferentes dispositivos;

recursos de acessibilidade;

mecanismos de privacidade e gerenciamento da própria conta.

### 3.20.2 Benefícios para o estabelecimento

centralização dos agendamentos;

organização do expediente e das agendas individuais;

prevenção de conflito de horários;

cadastro estruturado de clientes e profissionais;

histórico de atendimentos;

controle por status;

agenda por dia e semana;

indicadores e relatórios gerenciais;

configuração de regras de negócio sem alteração do código;

registro de autenticação e rastreabilidade;

redução de tarefas manuais.

## 3.21 Configurações de negócio

Algumas regras podem ser ajustadas individualmente por estabelecimento. Isso torna a aplicação mais flexível e evita que alterações comuns exijam mudanças diretamente no código.

| Configuração | Função |
| --- | --- |
| antecedencia_minima_horas | Define quanto tempo antes do atendimento o cliente pode realizar a reserva. |
| antecedencia_maxima_dias | Define até quantos dias no futuro é permitido agendar. |
| cancelamento_limite_horas | Define o limite para cancelamento autônomo pelo cliente. |
| intervalo_slots_minutos | Determina o intervalo padrão utilizado na apresentação dos horários. |
| permitir_bloqueio_profissional | Autoriza ou impede o profissional de bloquear a própria agenda. |
| confirmar_automaticamente | Define se os agendamentos do cliente são confirmados automaticamente. |

Fonte: elaborado pelos autores com base na documentação do sistema Agendei.

## 3.22 Recursos complementares

Além das funções essenciais de cadastro, autenticação e agendamento, a arquitetura foi ampliada com recursos destinados à operação comercial e ao relacionamento com os clientes.

recuperação de senha por token;

cadastro de profissionais e vínculo com serviços;

controle individual da agenda;

bloqueios de datas e períodos;

relatórios gerenciais e dashboard;

histórico de atendimentos por cliente;

geração de PDF da lista de usuários;

barra de acessibilidade;

personalização visual por estabelecimento;

operação multiestabelecimento;

lista de espera;

fila de lembretes para WhatsApp, com modo manual e envio automático condicionado à configuração do provedor;

cobrança de sinal por chave Pix com confirmação manual;

agendamentos recorrentes;

pontos de fidelidade;

pacotes de serviços com créditos e validade;

comissão por profissional;

avaliações após atendimentos concluídos;

feed iCalendar privado;

área de privacidade com cópia dos dados e encerramento da conta.

## 3.23 Possibilidades de evolução

A separação entre modelos, configurações e entidades facilita a expansão do projeto sem concentrar todas as mudanças em um único ponto da aplicação. Entre as evoluções já previstas estão:

ampliar e validar em produção a integração de WhatsApp já implementada, conforme a configuração do provedor;

confirmação bancária automática de pagamentos Pix;

ampliar os eventos de confirmação e lembretes por e-mail, utilizando a infraestrutura de envio já existente;

cadastro e tratamento específico de feriados;

controle financeiro mais completo e novas formas de pagamento;

geração de comprovante de agendamento;

integração bidirecional com o Google Calendar.

## 3.24 Conclusão

O Agendei foi desenvolvido para tornar o processo de marcação de atendimentos mais organizado, confiável e acessível. O estabelecimento cadastra serviços, profissionais e expedientes e passa a acompanhar os atendimentos em um ambiente único. O cliente, por sua vez, pode criar uma conta, consultar os serviços, selecionar um profissional, escolher data e horário disponível e confirmar a reserva pela internet.

A solução vai além da simples reserva de horários, pois integra autenticação em duas etapas, controle de acesso por perfil, prevenção de conflitos, logs de autenticação, relatórios, acessibilidade, responsividade e recursos de privacidade. A organização em camadas e a utilização de banco de dados relacional permitem manter as informações estruturadas e criar novas funcionalidades sem comprometer o núcleo do sistema.

Com PHP, PDO, MySQL/MariaDB ou PostgreSQL, HTML, CSS e JavaScript, o projeto aplica conceitos de desenvolvimento web e banco de dados à organização de atendimentos, oferecendo autonomia ao cliente e controle ao estabelecimento.

# 4 Interface, telas e validações

As figuras deste capítulo foram capturadas da aplicação PHP executada localmente em 10/10/2026, usando navegador Edge em modo automatizado. Foram utilizadas resoluções de 1366 × 900 e 390 × 844 pixels. As capturas mostram telas públicas reais; não foram criadas representações dos painéis autenticados.

## 4.1 Identidade visual e responsividade

A página do produto apresenta a marca Agendei, navegação, chamadas de cadastro e entrada e seus recursos. A entrada global utiliza e-mail; a entrada local da empresa também pode aceitar o login do vínculo. O cadastro organiza dados pessoais, contato, endereço e credenciais, com rótulos e orientações de preenchimento.

Figura 1 — Página inicial do produto em computador.

Figura 2 — Entrada global: formulário de e-mail e senha.

Figura 3 — Cadastro do cliente: organização dos dados pessoais.

Figura 4 — Cadastro público de estabelecimento.

|  |  |
| --- | --- |

Figura 5 — Página inicial e entrada global em celular (390 × 844).

Nas oito combinações de tela e resolução verificadas, a largura do documento coincidiu com a largura da viewport. Essa medição verifica ausência de rolagem horizontal nessas telas e resoluções; não constitui uma auditoria de todas as páginas ou dispositivos.

## 4.2 Críticas dos dados preenchidos

| Campo | Exemplo inválido | Resposta prevista |
| --- | --- | --- |
| Nome | Ana | Indicar o limite de 15 a 80 caracteres e o uso de letras e espaços. |
| CPF | 111.111.111-11 | Rejeitar sequência repetida e informar CPF inválido. |
| E-mail | texto sem formato de e-mail | Informar erro de formato; o servidor também verifica a unicidade global. |
| Login | abc | Exigir exatamente seis letras no cadastro local. |
| Senha | abc123 | Exigir a regra acadêmica de oito letras no cadastro do cliente. |
| Confirmação | Valor diferente da senha | Informar que as senhas não conferem. |
| Reserva | Intervalo já ocupado | Revalidar no servidor e impedir uma reserva conflitante. |

Figura 6 — Mensagens reais de crítica de cadastro com nome curto, CPF repetido e campos obrigatórios incompletos.

O evento de validação local destacou 17 campos inválidos e impediu o envio do formulário. Não foi criado um cadastro. A implementação PHP repete as verificações no servidor, independentemente do JavaScript.

## 4.3 Telas autenticadas previstas no projeto

| Perfil | Telas implementadas de referência |
| --- | --- |
| Cliente | Dashboard, agendamento por etapas, listagem de reservas, histórico e perfil. |
| Profissional | Dashboard, agenda, expediente, bloqueios e perfil. |
| Administrador | Dashboard, agenda, serviços, profissionais, clientes, horários, relatórios, configurações e logs. |
| Master global | Dashboard, estabelecimentos, contas, segurança e auditoria. |

As telas autenticadas estão descritas e identificadas no código, mas suas capturas não foram incluídas nesta revisão. A demonstração acadêmica deve utilizar uma base de teste para apresentar reserva, conflito, cancelamento e permissões por perfil.

# 5 MER e DER

## 5.1 Modelo conceitual

O núcleo do modelo separa pessoa, empresa e participação. usuarios identifica a pessoa; estabelecimento identifica a empresa; vinculos relaciona ambos e define o perfil local. clientes, profissionais e administradores complementam o vínculo. A conta master global é independente dessa especialização.

O catálogo reúne serviços e profissionais. profissional_servico resolve a associação N:N entre eles. Expedientes e bloqueios determinam disponibilidade. agendamentos relaciona cliente, profissional e serviço e preserva data, intervalo e valor da reserva. Filiais organizam unidades. Pacotes, pagamentos, fidelidade, espera, avaliações e notificações complementam a operação.

| Relacionamento | Cardinalidade e interpretação |
| --- | --- |
| usuarios → vinculos | Uma pessoa pode ter zero ou vários vínculos; cada vínculo possui uma pessoa. |
| estabelecimento → vinculos | Uma empresa pode ter zero ou vários vínculos; cada vínculo pertence a uma empresa. |
| vinculos → tabelas de perfil | Um vínculo tem zero ou um registro em cada tabela de perfil, conforme a unicidade de id_vinculo. A aplicação seleciona o perfil compatível com vinculos.tipo. |
| profissionais ↔ servicos | N:N resolvida por profissional_servico; a associação usa chave primária composta. |
| clientes/profissionais/servicos → agendamentos | Cada reserva exige um cliente, um profissional e um serviço. Cada participante pode possuir zero ou várias reservas. |
| filiais → profissionais/agendamentos | A filial é opcional no registro operacional; uma filial pode possuir zero ou vários registros. |
| estabelecimento → estabelecimento_plano/assinaturas | Cada empresa possui zero ou um registro em cada tabela; id_estabelecimento também é a PK dessas tabelas. |

## 5.2 Modelo físico e legenda

Os DERs revisados foram gerados por código a partir do esquema PostgreSQL versionado, incluindo colunas e FKs adicionadas por ALTER TABLE. Não representam inspeção de uma base instalada. Os dois scripts possuem 28 tabelas, 290 colunas e 63 FKs declaradas; algumas FKs simples reforçadas por compostas aparecem agrupadas apenas no desenho.

PK = chave primária; FK = participação em chave estrangeira física; UK = participação em restrição de unicidade, que pode ser composta. A marca UK em uma coluna não significa que essa coluna seja única isoladamente. || = exatamente um; círculo e barra = zero ou um; círculo e pé de galinha = zero ou vários. Linhas contínuas representam relações identificadoras; tracejadas representam relações não identificadoras. Ambas correspondem a FKs físicas.

Os diagramas por módulo mostram todas as PKs e FKs das entidades selecionadas e resumem os atributos descritivos. Entidades de referência podem aparecer em mais de um módulo. Relações que atravessam módulos devem ser consultadas no DER completo e no dicionário de dados. As páginas dos diagramas usam A3 em paisagem para facilitar a leitura.

## 5.3 Referências lógicas sem FK

| Referência | Tratamento |
| --- | --- |
| agendamentos.id_cliente_pacote → cliente_pacotes | Não possui FOREIGN KEY nos scripts; saldo, validade, titularidade e serviço são conferidos pela aplicação. |
| logs_autenticacao.id_usuario → usuarios | Identificador histórico sem FK; o log pode sobreviver à exclusão da pessoa. |
| logs_master.id_master → administradores_master | Referência histórica sem FK. |
| logs_master.id_estabelecimento → estabelecimento | Referência histórica sem FK. |
| sessoes_lembradas.id_estabelecimento | Coluna sem FK física. A tabela possui FKs declaradas para id_usuario e id_vinculo. |

bloqueios_agenda.id_usuario_criou e agendamentos.id_usuario_cancelou possuem FKs reais para usuarios e foram corrigidas nos diagramas. id_cliente_pacote permanece sem marcação FK. A tabela sessoes_lembradas não recebeu uma FK de empresa inexistente.

## 5.4 DER — Núcleo, empresas e perfis

DER físico PostgreSQL por módulo. Fonte: banco_postgres.sql; tipos resumidos. Dicionário completo em anexo.

## 5.5 DER — Catálogo e disponibilidade

DER físico PostgreSQL por módulo. Fonte: banco_postgres.sql; tipos resumidos. Dicionário completo em anexo.

## 5.6 DER — Reserva e seus participantes

DER físico PostgreSQL por módulo. Fonte: banco_postgres.sql; tipos resumidos. Dicionário completo em anexo.

## 5.7 DER — Espera, notificações e avaliações

DER físico PostgreSQL por módulo. Fonte: banco_postgres.sql; tipos resumidos. Dicionário completo em anexo.

## 5.8 DER — Pacotes, pagamentos e fidelidade

DER físico PostgreSQL por módulo. Fonte: banco_postgres.sql; tipos resumidos. Dicionário completo em anexo.

## 5.9 DER — Planos e assinatura da empresa

DER físico PostgreSQL por módulo. Fonte: banco_postgres.sql; tipos resumidos. Dicionário completo em anexo.

## 5.10 DER — Acesso, sessões e auditoria

DER físico PostgreSQL por módulo. Fonte: banco_postgres.sql; tipos resumidos. Dicionário completo em anexo.

# 6 Gestão e atividades do grupo

## 6.1 Integrantes e responsabilidades registradas

| Integrante | Área | Atividades descritas nos registros |
| --- | --- | --- |
| João Vitor Duarte Peçanha Rosa | Back-end | Rotas e endpoints; operações de agendamento, alteração e cancelamento; testes e revisão de funcionalidades. |
| Gabriel Lima Maciel | Front-end | Navegação, componentes visuais, formulários e integração das telas com a API. |
| Thayrine de Lira Maciel | Back-end | Regras de negócio, validações, prevenção de conflitos, tratamento de erros e testes. |
| Carlos Eduardo Correa Machado | Back-end | Estrutura do servidor, gerenciamento dos dados, integração com o banco e revisão final. |
| Fabiano Gonçalves | Front-end | Criação das telas e do layout, ajustes visuais e finalização da interface. |

As contribuições acima e os relatos seguintes reproduzem as informações fornecidas pelo grupo. Não foram deduzidos autores a partir do código ou do histórico Git.

## 6.2 Reuniões em ordem cronológica

Os documentos de origem continham uma lista semanal e dois relatos datados de 27/08 e 03/09, chamados de primeira e segunda reunião. A consolidação ordena todos os registros pela data e remove essa numeração conflitante. Mantém 17h somente nas reuniões em que esse horário foi informado.

### 15/08/2026 — 17h

A equipe definiu os objetivos do sistema de agendamento e dividiu as responsabilidades. Carlos Eduardo, João Duarte e Thayrine Lira ficaram responsáveis pelo Back-end, enquanto Fabiano e Gabriel ficaram responsáveis pelo Front-end.

### 22/08/2026 — 17h

Carlos Eduardo organizou a estrutura inicial do Back-end. João Duarte planejou as rotas da API, e Thayrine Lira definiu as regras de negócio. Fabiano iniciou a criação das telas, enquanto Gabriel organizou a navegação do sistema.

### 27/08/2026 — Não informado

A primeira reunião contou com a participação de todos os integrantes. Foram definidas as funções e responsabilidades de cada membro, com o objetivo de organizar o desenvolvimento do projeto e distribuir as tarefas. Também foi formalizada a inclusão de Fabiano Gonçalves na equipe, passando a contribuir com as atividades e decisões relacionadas ao projeto. Durante a reunião, foram elaboradas as seções do documento referentes ao relatório do sistema e à análise de requisitos. Todos participaram da discussão sobre a proposta do Agendei, suas principais funcionalidades e as necessidades dos usuários.

### 29/08/2026 — 17h

Carlos Eduardo trabalhou no gerenciamento dos dados. João Duarte desenvolveu as operações de agendamento, e Thayrine Lira definiu as validações. Fabiano desenvolveu as telas de cadastro, enquanto Gabriel organizou os componentes visuais.

### 03/09/2026 — Não informado

A segunda reunião contou com a participação de todos os integrantes e teve como pauta a apresentação da versão inicial do sistema Agendei, a revisão dos requisitos e a discussão das funcionalidades desenvolvidas. Foi apresentada a página inicial, contendo os serviços oferecidos, os profissionais e as informações de contato do estabelecimento. Também foram abordados o cadastro de clientes, o login e a divisão das permissões de acesso entre clientes, profissionais e administradores. Na sequência, foi apresentado o processo de agendamento, com escolha do serviço, profissional, data e horário disponível. Foram discutidas as regras para evitar conflitos de horários, respeitar os períodos de trabalho e os bloqueios dos profissionais, além dos prazos de agendamento e cancelamento. A equipe também analisou as áreas do sistema: Área do cliente: acompanhamento dos agendamentos, consulta ao histórico e atualização do perfil. Área do profissional: consulta à agenda, visualização dos horários de trabalho e bloqueio de períodos. Painel administrativo: gerenciamento de clientes, profissionais, serviços, horários e agendamentos, além de relatórios e configurações. Por fim, foram apresentadas a estrutura do banco de dados e a organização das informações do sistema. Todos os integrantes participaram da discussão sobre as funcionalidades e da definição dos próximos passos, incluindo a revisão das telas, os testes de funcionamento e a atualização da documentação.

### 05/09/2026 — 17h

Carlos Eduardo trabalhou na integração com o banco de dados. João Duarte implementou as operações de alteração e cancelamento. Thayrine Lira verificou as regras para evitar conflitos de horários. Fabiano ajustou o layout, e Gabriel trabalhou na comunicação com a API.

### 12/09/2026 — 17h

Carlos Eduardo revisou a estrutura do servidor. João Duarte testou as rotas de agendamento, e Thayrine Lira revisou as regras de negócio. Fabiano corrigiu detalhes visuais, enquanto Gabriel verificou os formulários.

### 19/09/2026 — 17h

Carlos Eduardo realizou ajustes nos serviços do Back-end. João Duarte revisou os endpoints, e Thayrine Lira verificou o tratamento de erros. Fabiano aprimorou as telas, enquanto Gabriel ajustou a integração com a API.

### 26/09/2026 — 17h

Carlos Eduardo testou o servidor e o acesso aos dados. João Duarte verificou as operações de agendamento, e Thayrine Lira testou as validações. Fabiano corrigiu problemas visuais, enquanto Gabriel ajustou os componentes do Front-end.

### 03/10/2026 — 17h

Carlos Eduardo organizou os ajustes finais do Back-end. João Duarte revisou as funcionalidades de agendamento, e Thayrine Lira realizou testes complementares. Fabiano finalizou o layout, enquanto Gabriel revisou a navegação e a integração entre as telas e a API.

O relato de 27/08 informa a formalização da inclusão de Fabiano, enquanto a lista semanal já o cita em atividades anteriores. Ambos foram preservados como registros de origem; esta revisão não determina a data efetiva de ingresso. O intervalo declarado no relatório vai até 07/10, mas a última reunião datada fornecida é 03/10; não foi acrescentada reunião em 07/10.

## 6.3 Cronograma de referência

| Semana | Atividade | Entregável |
| --- | --- | --- |
| 1 | Levantamento do problema, objetivos e público-alvo | Documento de requisitos |
| 2 | Definição dos requisitos funcionais e não funcionais | Lista de requisitos aprovada |
| 3 | Modelagem do banco de dados e diagrama MER | Modelo do banco e arquivo SQL |
| 4 | Criação da estrutura do projeto e layout inicial | Protótipo das telas principais |
| 5 | Implementação de login, cadastro, sessões e permissões | Módulo de autenticação |
| 6 | Implementação dos agendamentos, serviços, horários e bloqueios | Fluxo completo de agendamento |
| 7 | Implementação dos painéis de cliente, profissional e administrador | Módulos administrativos concluídos |
| 8 | Testes, correções, documentação e apresentação | Sistema final e documentação |

O cronograma foi preservado como planejamento de oito semanas, sem atribuir datas ou comprovação de execução não presentes no documento original.

# 7 Código, banco e instalação

| Componente | Arquivos incluídos no pacote |
| --- | --- |
| Front-end | Páginas PHP que produzem HTML; assets/css/; assets/js/; assets/img/; includes/ de layout. |
| Back-end | PHP da raiz; admin/; api/; cliente/; profissional/; master/; models/; includes/; config/. |
| Criação do banco | banco.sql (MySQL/MariaDB) e banco_postgres.sql (PostgreSQL). |
| Atualização de instalações existentes | scripts/migrar*.php e migrations/. |
| Modelo | DER completo .mmd e .svg; sete diagramas por módulo; dicionário PostgreSQL. |
| Documentação | Este documento em .docx, .doc e PDF; LEIAME.md e guias do repositório. |

Para uma instalação nova: preparar PHP e a extensão PDO do banco escolhido; importar somente o SQL correspondente; configurar AGENDEI_DB_DRIVER e as demais variáveis de conexão ou o arquivo local; iniciar o servidor web; executar a configuração demonstrativa apenas em ambiente de teste. O esquema PostgreSQL deve ser importado em um banco previamente criado, pois não contém CREATE DATABASE nem USE.

Os arquivos de criação contêm comandos DROP TABLE e não devem ser importados em uma base com dados a preservar. Atualizações de uma instalação existente devem utilizar as migrações previstas no projeto. O pacote omite credenciais locais, arquivos .env, logs de execução e dados de usuários.

# 8 Verificação realizada nesta revisão

| Verificação | Resultado | Limite |
| --- | --- | --- |
| php tests/modelo_bd.php | 598 verificações passaram. | Cobertura do esquema e cardinalidades; não conecta ao banco. |
| php tests/requisitos.php | 65 verificações; zero falhas. | Regras de validação e autenticação verificadas sem banco. |
| Páginas públicas | Oito capturas em computador/celular; HTTP 200. | Página inicial, entrada global e dois cadastros. |
| Largura responsiva | Sem transbordamento horizontal nas oito combinações. | 1366 e 390 pixels; não abrange todas as telas. |
| Crítica de cadastro | Envio impedido; 17 campos destacados. | Validação JavaScript local; nenhuma gravação de cadastro. |

Não foram executados nesta revisão os testes de integração com banco nem a navegação autenticada. As verificações acima comprovam somente os resultados indicados, sem afirmar aprovação de todos os requisitos ou funcionamento dos provedores externos.

# Referências e fontes do projeto

ASSOCIAÇÃO BRASILEIRA DE NORMAS TÉCNICAS. NBR 14724: Informação e documentação — Trabalhos acadêmicos — Apresentação. Rio de Janeiro: ABNT, 2024.

ASSOCIAÇÃO BRASILEIRA DE NORMAS TÉCNICAS. NBR 6028: Informação e documentação — Resumo, resenha e recensão — Apresentação. Rio de Janeiro: ABNT, 2021.

BRASIL. Lei nº 13.709, de 14 de agosto de 2018. Lei Geral de Proteção de Dados Pessoais (LGPD). Brasília, DF: Presidência da República, 2018.

PHP DOCUMENTATION GROUP. PHP Manual. Documentação oficial da linguagem PHP.

ORACLE. MySQL Documentation. Documentação oficial do sistema de gerenciamento de banco de dados MySQL.

POSTGRESQL GLOBAL DEVELOPMENT GROUP. Documentação oficial do PostgreSQL. Referência técnica complementar ao esquema banco_postgres.sql.

FONTES FORNECIDAS PELO GRUPO. DOCUMENTAÇÃO DO PROJETO.docx e partes (1), (2), (3) e (4), além dos três diagramas PNG encaminhados para revisão.

FONTES LOCAIS. banco.sql; banco_postgres.sql; models/ModeloBanco.php; config/database.php; models/Usuario.php; models/Agendamento.php; includes/auth.php; páginas PHP e assets do repositório Agendei.
