# Prompt para apresentar o MER e o DER do Agendei

Use este prompt junto com o documento completo [docs/MER_DER.md](docs/MER_DER.md).
Os diagramas prontos estão em [der_mysql.mmd](docs/der_mysql.mmd) e
[der_postgres.mmd](docs/der_postgres.mmd). A tela `modelo_bd.php` consulta os mesmos
scripts SQL para mostrar campos, chaves e relações.

Para atualizar estes arquivos: `php scripts/gerar_modelo_bd.php`.

--- INÍCIO DO PROMPT ---

Apresente o MER e o DER do sistema Agendei estritamente conforme o documento
`docs/MER_DER.md` fornecido junto com este prompt. Não invente nem omita tabelas,
colunas, restrições ou relacionamentos. Use as 28 tabelas documentadas, incluindo
assinaturas, planos, administração master, agenda, benefícios e segurança.

1. Explique o MER conceitual e seus relacionamentos com cardinalidade mínima e
   máxima, distinguindo regras de negócio de restrições impostas pelo banco.
2. Reproduza o DER completo em Mermaid. Escolha explicitamente MySQL/MariaDB ou
   PostgreSQL e respeite as definições daquele dialeto. Os arquivos `.mmd`
   fornecidos são os diagramas físicos de referência.
3. Inclua o dicionário completo, preservando tipos, nulabilidade, defaults,
   PK, conjuntos UK, FKs simples/compostas e ações de exclusão/atualização.
4. Mostre separadamente as referências lógicas sem FOREIGN KEY. Não marque
   agendamentos.id_cliente_pacote, logs_autenticacao.id_usuario ou os
   identificadores históricos de logs_master como FKs.
5. Explique por que o admin de empresa é diferente do master global; por que
   cada subtipo de usuário é opcional no modelo físico; como o código verifica
   disponibilidade, crédito de pacotes e avaliações; e por que valor e hora_fim
   preservam o histórico da reserva.
6. Se criar vistas menores para impressão, identifique-as como recortes do DER
   completo. Não apresente um recorte como representação de todo o sistema.

Use português do Brasil. Não afirme ter inspecionado o banco instalado; a fonte
é o esquema versionado e o funcionamento descrito nos models PHP. Não confunda
linha não identificadora do Mermaid com ausência de FK. Não trate uma coluna
integrante de UK composta como única isoladamente. Não crie vínculos de FK entre
assinaturas, planos e pagamentos, pois eles não existem nos scripts fornecidos.

--- FIM DO PROMPT ---
