# Cartão, boleto e avulso online — desenho

Data: 2026-11-11. Status: desenho aprovado em conversa.

## Objetivo

1. A mensalidade da assinatura de cliente pode ser paga por Pix, boleto ou cartão.
2. Quem não quer plano paga cada atendimento ("avulso") pelo sistema, com as
   mesmas três formas. É opcional: pagar no local continua valendo.

Boleto e cartão são de demonstração, como em `assinatura.php` (empresa → Agendei):
boleto com linha digitável de `Cobranca::boleto`, cartão conferido no navegador
(`assets/js/assinatura.js`, Luhn) e só os 4 últimos dígitos enviados e guardados.
Quem confirma o pagamento continua sendo o admin.

## Fora do escopo

Gateway real, estorno automático, parcelamento, pagar vários atendimentos de uma vez.

## Dados (migração idempotente em `Cobranca::garantirEstrutura()`, chamada por
`scripts/migrar.php` e `scripts/preparar_runtime.php`; também nos `.sql`)

- `cobrancas_assinatura_cliente`: `metodo` (`pix|boleto|cartao`, NULL = ainda não
  escolheu) e `cartao_final CHAR(4) NULL`.
- `pagamentos`: `metodo` passa a aceitar `boleto` (ENUM no MySQL; CHECK
  `ck_pagamento_metodo` no PostgreSQL, no padrão de `Diferencial::garantirStatusSinal`)
  e ganha `cartao_final CHAR(4) NULL`. O avulso usa `tipo = 'integral'`, que já existe.

## Página `cliente/pagar.php`

Uma só para os dois casos: `?mensalidade=<id_cobranca>` ou `?agendamento=<id_agendamento>`.
- Só o dono (cliente logado) vê; outro id → 404 amigável.
- Mostra o que está sendo pago e o valor.
- Sem forma escolhida (ou pedindo "trocar forma"): rádio Pix / Boleto / Cartão
  (Pix só se a empresa tiver `pix_chave`) e os campos do cartão de `assinatura.php`.
- Depois de escolher:
  - Pix: QR + copia e cola (chave da empresa).
  - Boleto: linha digitável e vencimento.
  - Cartão: "Pagamento no cartão final NNNN em análise".
  - Em todos: "o estabelecimento confirma o pagamento".
- Botão "Trocar forma de pagamento" enquanto pendente.
- Carrega `assinatura.js` (cartão e "Copiar código").

## Mensalidade

- `AssinaturaCliente::escolherMetodo(int $idCobranca, int $idCliente, string $metodo, ?string $cartaoFinal): void`.
  Só cobrança `pendente` do cliente. Cartão exige 4 dígitos. Pix exige chave.
- "Meus benefícios" troca o QR embutido por "Pagar mensalidade" → `pagar.php`.
- A tela de assinantes do admin mostra a forma escolhida (e o final do cartão).

## Avulso

- `PagamentoAvulso::valorAPagar(array $agendamento): float`: `valor` menos o sinal já pago
  (status `pago`), nunca negativo. Zero (coberto pela assinatura, ou já quitado) → não pagável.
- Pagável: agendamento do cliente, `agendado|confirmado`, valor a pagar > 0, sem
  pagamento `integral` já `pago`.
- `PagamentoAvulso::solicitar(int $idAgendamento, int $idCliente, string $metodo, ?string $cartaoFinal): array`.
  Em transação: cria ou atualiza o `pagamentos` `integral` `pendente`
  (`valor = valorAPagar`, `referencia = 'AV' . id_agendamento`, método e final do
  cartão) e cancela o sinal `pendente`. Isso porque pagar o total dispensa o sinal.
- Botão "Pagar agora" na confirmação de `cliente/agendar.php` e em cada agendamento
  pagável de `cliente/agendamentos.php`.
- Vitrine de "Meus benefícios": cartão "Sem plano: pague só quando usar" com
  "Marcar horário" (sempre que houver planos na vitrine).
- Cancelamento: o pendente já é cancelado por `Diferencial::cancelarFinanceiro`.
  O integral pago fica pago, e o admin vê o aviso "agendamento cancelado:
  devolver ou combinar crédito". Ele pode marcar como `estornado` (o status já existe).

## Admin

`admin/diferenciais.php`: "Pagamentos de sinal" vira "Pagamentos", com as colunas
tipo (Sinal/Atendimento), forma (Pix/Boleto/Cartão final NNNN) e o aviso de cancelado.
As ações continuam sendo "Marcar pago" e "Estornado" para integral pago em
agendamento cancelado.

## Testes

`tests/pagamento_avulso.php` (MySQL e PostgreSQL):
- migração idempotente (colunas e `boleto` aceito);
- escolher a forma da mensalidade: dono sim, outro cliente não; cartão sem 4 dígitos
  recusado; Pix sem chave recusado;
- avulso: valor cheio; com sinal pago só a diferença; o sinal pendente é cancelado;
  trocar a forma atualiza o mesmo pagamento; coberto pela assinatura (R$ 0) e já
  pago não são pagáveis; de outro cliente recusado; cancelado não pagável;
- cancelar o agendamento cancela o integral pendente; o pago continua pago;
- confirmação do admin (`Diferencial::atualizarPagamento`) marca o pago.

Conferência no navegador (Playwright) em 390 e 1280 px dos dois fluxos.
