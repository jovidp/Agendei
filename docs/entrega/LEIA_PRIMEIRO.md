# Entrega revisada — Agendei



Revisão preparada em 10/10/2026 e atualizada em 19/11/2026. Os documentos originais em Downloads foram preservados.



## Arquivos principais



- `AGENDEI - DOCUMENTAÇÃO DO PROJETO.doc`: documento em formato Word 97–2003, conforme a extensão do enunciado.

- Arquivos com o mesmo nome em `.docx` e `.pdf`: versão editável moderna e versão para conferência.

- `ANEXO - Dicionário de dados PostgreSQL.docx` e `.pdf`: 34 tabelas, 341 colunas, restrições e 73 FKs físicas.

- `der_postgres_completo.svg`: DER completo, ampliável sem perda de qualidade.

- `der_pgsql_completo.mmd` e `der_mysql_completo.mmd`: fontes do DER completo de cada dialeto.

- Sete fontes `.mmd`, desenhos `.svg` e PNGs por módulo.

- Três diagramas de MER conceitual em `.mmd`, `.svg` e PNG: pessoas/empresas, agendamento/atendimento e operação comercial.

- `DOCUMENTACAO_REVISADA.md`: texto consolidado pesquisável.

- `AGENDEI - ENTREGA REVISADA.zip`: documentação e código da aplicação.



## Ajustes realizados



1. Consolidação do relatório, requisitos, cronograma e registros de reuniões.

2. Numeração coerente, sumário automático, cabeçalho e páginas.

3. Requisitos atualizados para identidade global, vínculos por empresa, entrada global/local, TOTP e PostgreSQL.

4. Correção da unicidade do e-mail: global em `usuarios`, com login local em `vinculos`.

5. Inclusão de `vinculos` na descrição das tabelas centrais.

6. DER gerado diretamente do SQL, incluindo alterações de filiais, chaves compostas e FKs dos responsáveis por criar bloqueios/cancelar reservas.

7. Distinção das referências históricas e de pacote sem FK; remoção da indicação de FK inexistente em `sessoes_lembradas.id_estabelecimento`.

8. Capturas reais de telas públicas no computador e celular, com exemplo de validação local do cadastro.

9. Registro preciso dos testes executados e de seus limites.

10. Pacote de código sem arquivos de credenciais locais, `.env`, logs ou diretório Git.

11. Inclusão de diagramas conceituais explícitos para o MER, com entidades, relacionamentos, atributos e cardinalidades.

12. Relatos das reuniões ampliados com explicações das atividades documentadas; títulos exibem somente a data.

13. Número do grupo removido da capa, do texto de orientação e dos nomes dos documentos, conforme solicitado.



## Atualização de 19/11/2026



14. Inclusão das funcionalidades entregues entre 3 e 11/11: planos com preço e pagamento da empresa (Pix com QR, boleto e cartão em demonstração), assinaturas de clientes, pagamento avulso, bot do WhatsApp, confirmação de e-mail e recuperação de senha por código e requisitos legais (seção 2.6, RF47–RF55 e RN17–RN22).

15. Esquema reextraído dos SQL atuais: 34 tabelas, 341 colunas e 73 FKs; DERs dos módulos 04 e 05, DER completo e os três MERs conceituais redesenhados; dicionário de dados regerado.

16. `agendamentos.id_cliente_assinatura` registrada como referência lógica sem FK.

17. Seção 4.4 com oito capturas de telas autenticadas, feitas em 03/11/2026 com os dados de demonstração.

18. Verificação refeita: as 25 suítes de `tests/` passaram em MariaDB local descartável.

19. Guia da banca atualizado (números, concorrência no PostgreSQL, pagamentos em demonstração, novas perguntas e checklist).



Os `.docx` originais do grupo não estavam mais em Downloads; por isso a atualização foi aplicada sobre o documento já consolidado por `fontes/atualizar_entrega.py`, e `fontes/gerar_documentacao.py` só pode ser repetido com aqueles arquivos.



## Informações preservadas e pontos a conferir



- A identificação utiliza o nome do projeto e os integrantes. Os relatos originais se referem a 2026.

- Os relatos de 27/08 e 03/09 e a lista semanal foram mantidos em ordem de data, sem declarar que são reuniões adicionais nem escolher uma lista como verdadeira.

- O capítulo 6 foi reescrito em 19/11/2026 por orientação de João Vitor: o desenvolvimento é atribuído a João Vitor e Thayrine, e o texto registra que Gabriel Lima Maciel, Carlos Eduardo Correa Machado e Fabiano Gonçalves não tiveram ideias, participação ou colaboração no projeto. As mudanças de cada reunião foram conferidas no histórico Git; as reuniões de agosto aparecem como planejamento, porque o código começa em 03/09. Essa versão substitui as atas de 10/10, que atribuíam tarefas a todos os cinco integrantes.

- Todos os títulos de reunião exibem somente a data, sem horários ou marcadores de informação ausente.

- O relatório declara um período até 07/10, mas não fornece reunião nessa data; não foi criada uma ata para completar o período.

- As capturas do capítulo 4.1 são de telas públicas (10/10/2026); as da seção 4.4 são de telas autenticadas com dados de demonstração (03/11/2026). Telas criadas depois de 03/11, como assinaturas de clientes e bot, não têm captura.

- A reunião de 05/11/2026 foi incluída com as mudanças registradas nos commits de 06/11 a 11/11.



## Verificação



- `php tests/modelo_bd.php`: 710 verificações passaram, sem acesso ao banco (19/11/2026).

- `php tests/requisitos.php`: 65 verificações, zero falhas, sem acesso ao banco.

- Quatro páginas públicas em duas resoluções: oito respostas HTTP 200; sem transbordamento horizontal nas resoluções verificadas.

- Validação local do cadastro: 17 campos inválidos destacados e envio impedido; nenhum cadastro foi enviado.

- As 25 suítes de `tests/` passaram em 19/11/2026 contra um MariaDB local descartável (2.259 verificações contadas); o dialeto PostgreSQL não foi testado nesta rodada.



Os scripts de criação SQL incluídos contêm comandos de limpeza. Use-os somente em instalação nova ou banco descartável. Para preservar dados de uma instalação existente, utilize as migrações do projeto.

