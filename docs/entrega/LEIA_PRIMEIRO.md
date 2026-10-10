# Entrega revisada — Agendei

Revisão preparada em 10/10/2026. Os documentos originais em Downloads foram preservados.

## Arquivos principais

- `AGENDEI - DOCUMENTAÇÃO DO PROJETO.doc`: documento em formato Word 97–2003, conforme a extensão do enunciado.
- Arquivos com o mesmo nome em `.docx` e `.pdf`: versão editável moderna e versão para conferência.
- `ANEXO - Dicionário de dados PostgreSQL.docx` e `.pdf`: 28 tabelas, 290 colunas, restrições e 63 FKs físicas.
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

## Informações preservadas e pontos a conferir

- A identificação utiliza o nome do projeto e os integrantes. Os relatos originais se referem a 2026.
- Os relatos de 27/08 e 03/09 e a lista semanal foram mantidos em ordem de data, sem declarar que são reuniões adicionais nem escolher uma lista como verdadeira.
- A formalização da inclusão de Fabiano em 27/08 e sua participação registrada antes dessa data são apresentadas como informações das fontes, com nota explicativa. Só o grupo pode confirmar a data efetiva de ingresso.
- Todos os títulos de reunião exibem somente a data, sem horários ou marcadores de informação ausente.
- O relatório declara um período até 07/10, mas não fornece reunião nessa data; não foi criada uma ata para completar o período.
- As capturas incluídas são de telas públicas. Não se acrescentaram imagens inventadas dos painéis autenticados. A demonstração acadêmica deve apresentar o fluxo de reserva e os perfis em uma base de teste.

## Verificação

- `php tests/modelo_bd.php`: 598 verificações passaram, sem acesso ao banco.
- `php tests/requisitos.php`: 65 verificações, zero falhas, sem acesso ao banco.
- Quatro páginas públicas em duas resoluções: oito respostas HTTP 200; sem transbordamento horizontal nas resoluções verificadas.
- Validação local do cadastro: 17 campos inválidos destacados e envio impedido; nenhum cadastro foi enviado.
- A criação/migração do banco e os testes de integração não foram executados nesta revisão.

Os scripts de criação SQL incluídos contêm comandos de limpeza. Use-os somente em instalação nova ou banco descartável. Para preservar dados de uma instalação existente, utilize as migrações do projeto.
