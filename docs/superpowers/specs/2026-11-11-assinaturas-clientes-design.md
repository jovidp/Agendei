# Assinaturas de clientes — desenho

Data: 2026-11-11. Status: aprovado em conversa, aguardando revisão desta especificação.

## Objetivo

Cada estabelecimento cria os próprios planos de assinatura e os vende aos seus
clientes (ex.: "Clube Barba", R$ 99/mês: 4 cortes, barba ilimitada e 15% de
desconto no restante). A mensalidade é cobrada por Pix gerado pelo sistema com a
chave da empresa, e a confirmação é do admin, como no sinal e nos pacotes.

Não confundir com `Assinatura`/`Plano`, que são a assinatura **da empresa com o
Agendei**. O módulo novo se chama `AssinaturaCliente`.

## Decisões tomadas

- Plano combina serviços incluídos (quantidade por mês ou ilimitado) **e**
  desconto % nos demais serviços.
- Cobrança: Pix com QR (BR Code) usando a chave Pix da empresa
  (`Configuracao 'pix_chave'`); o admin confirma o pagamento.
- O recurso fica atrás de `Plano::temRecurso('diferenciais')`, sem recurso novo
  nos planos do Agendei.
- Um cliente tem no máximo uma assinatura não cancelada por empresa.

## Fora do escopo

Proporcional e troca de plano (cancela e assina outro), gateway com débito
automático, aviso de cobrança por e-mail/WhatsApp, várias assinaturas simultâneas.

## Dados

Todas as tabelas seguem o padrão multiempresa de `pacotes`/`cliente_pacotes`:
`id_estabelecimento` em tudo, `UNIQUE (id_estabelecimento, id_*)` e FKs compostas
com o tenant. Entram em `banco.sql`, `banco_hospedagem.sql`, `banco_postgres.sql`
e numa migração idempotente `scripts/migrar_assinaturas_clientes.php` chamada por
`scripts/migrar.php`.

**planos_clientes** — o plano que a empresa vende.
`id_plano_cliente, id_estabelecimento, nome VARCHAR(120), descricao VARCHAR(500) NULL,
preco_mensal DECIMAL(10,2), desconto_percentual TINYINT UNSIGNED DEFAULT 0 (0–100),
status ENUM('ativo','inativo'), data_criacao`.

**plano_cliente_servicos** — serviços incluídos.
`id_estabelecimento, id_plano_cliente, id_servico, quantidade_mes SMALLINT UNSIGNED NULL`
(NULL = ilimitado). PK `(id_estabelecimento, id_plano_cliente, id_servico)`.

**cliente_assinaturas** — a assinatura de um cliente.
`id_cliente_assinatura, id_estabelecimento, id_cliente, id_plano_cliente,
status ENUM('pendente','ativa','cancelada'), periodo_inicio DATE NULL,
periodo_fim DATE NULL, data_cancelamento DATETIME NULL, data_criacao`.
FK do cliente com `ON DELETE CASCADE` (exclusão de conta pela LGPD leva junto).

**cobrancas_assinatura_cliente** — uma linha por mensalidade.
`id_cobranca, id_estabelecimento, id_cliente_assinatura, valor DECIMAL(10,2),
status ENUM('pendente','pago','cancelado'), referencia VARCHAR(40), data_pagamento NULL,
data_criacao`. No máximo uma `pendente` por assinatura (garantido no código, dentro
de transação com a assinatura travada).

**agendamentos.id_cliente_assinatura** — INT UNSIGNED NULL, sem FK (mesmo tratamento
de `id_cliente_pacote`), marca o agendamento coberto pela assinatura.

## Situação da assinatura (derivada, sem tarefa para suspender)

Guardado: `pendente`, `ativa`, `cancelada`. Calculado em `AssinaturaCliente::situacao()`:

| Guardado | Condição | Situação mostrada | Benefícios |
|---|---|---|---|
| pendente | — | Aguardando pagamento | não |
| ativa | hoje ≤ periodo_fim | Ativa | sim |
| ativa | hoje > periodo_fim | Suspensa (pagamento em atraso) | não |
| cancelada | hoje ≤ periodo_fim | Cancelada, vale até periodo_fim | sim |
| cancelada | hoje > periodo_fim ou sem período | Encerrada | não |

"Vigente" = benefícios sim. Suspender não precisa de tarefa: é só a data passar.

## Fluxos

**Admin — nova tela `admin/assinaturas_clientes.php`** (item no menu em "Gestão"):
- CRUD de planos: nome, descrição, preço, desconto %, serviços incluídos com
  quantidade ou "ilimitado", ativar/inativar (inativar não afeta quem já assina;
  só some da vitrine e para de aceitar novas).
- Lista de assinantes com situação e cobrança pendente; ações: confirmar pagamento,
  cancelar cobrança, cancelar assinatura, inscrever cliente num plano.

**Cliente — seção "Assinaturas" em `cliente/beneficios.php`**:
- Vitrine dos planos ativos (preço, incluídos, desconto) com "Assinar".
- Minha assinatura: situação, período, uso do mês por serviço ("2 de 4 cortes"),
  cobrança pendente com QR Pix e copia e cola, botão "Cancelar assinatura".

**Assinar** (cliente ou admin): cria `cliente_assinaturas` pendente + a primeira
cobrança pendente com `valor = preco_mensal` do plano naquele momento. Recusa se
já houver assinatura não encerrada, se o plano estiver inativo ou se a empresa não
tiver chave Pix.

**Confirmar pagamento** (admin), em transação com a assinatura travada:
- cobrança → `pago`, `data_pagamento = agora`;
- se a assinatura estava vigente (renovação em dia): `periodo_inicio` fica e
  `periodo_fim` avança um mês (`periodoFim(periodo_fim + 1)`), para o resto do mês
  já pago continuar valendo mesmo pagando adiantado;
  senão (primeira ou suspensa): `periodo_inicio = hoje`, `periodo_fim = periodoFim(hoje)`;
- status → `ativa`. Quem atrasou não paga pelos dias em que ficou suspenso.
- `periodo_inicio..periodo_fim` pode cobrir vários meses pagos em sequência; o mês
  de uma data (`AssinaturaCliente::janela`) é achado andando de mês em mês a partir
  de `periodo_inicio`, e a cota vale por mês.
  (Revisado em 2026-11-11 depois da revisão de código: a versão anterior movia o
  início no pagamento adiantado e tirava o benefício dos últimos dias já pagos.)

**Renovação** — em `tarefas.php`, uma vez ao dia (mesma janela da retenção LGPD,
`date('G') === 3`), `AssinaturaCliente::gerarRenovacoes()` percorre as empresas e,
para cada assinatura `ativa` com `periodo_fim ≤ hoje + 3 dias` e sem cobrança
pendente, cria a cobrança com o `preco_mensal` atual do plano. Idempotente.
A mesma checagem roda quando o cliente abre "Meus benefícios", para não depender
só da tarefa.

**Cancelar assinatura** (cliente ou admin): status → `cancelada`,
`data_cancelamento = agora`, cobranças pendentes → `cancelado`. Benefícios seguem
até `periodo_fim`. Assinatura pendente cancelada simplesmente encerra.

## Uso no agendamento

Em `Agendamento::criar`, antes do INSERT (onde hoje `valor = preco do serviço`),
`AssinaturaCliente::beneficio($idCliente, $idServico, $data)` devolve
`['id_cliente_assinatura' => ?int, 'valor' => float]`:

1. Sem assinatura vigente na **data do agendamento** (data entre `periodo_inicio`
   e `periodo_fim`): valor cheio, nada muda.
2. Serviço incluído e uso no período < quantidade (ou ilimitado): `valor = 0`,
   grava `id_cliente_assinatura`.
3. Caso contrário: `valor = preco × (1 − desconto/100)`, arredondado a 2 casas,
   sem `id_cliente_assinatura`.

Uso no período = agendamentos com aquele `id_cliente_assinatura` e `id_servico`,
data dentro do mês da assinatura que contém a data do agendamento (`janela`) e status diferente de cancelado. Não há contador
guardado: cancelar um agendamento devolve o uso sozinho. A contagem roda com a
linha da assinatura em `FOR UPDATE` dentro da transação do agendamento, para que
dois agendamentos simultâneos não estourem a cota.

Ordem com o que já existe: agendamento coberto (caso 2) não consome pacote nem
gera sinal. Nos casos 1 e 3 segue o fluxo atual (pacote, senão sinal, calculado
sobre o valor já com desconto).

Agendamento além do fim do período pago não é coberto (cai no caso 1 ou 3 conforme
a vigência na data). Os três caminhos de criação (admin, cliente, profissional) e o
bot passam por `Agendamento::criar`, então herdam a regra.

## Pix

`Cobranca::pix(float $valor, string $identificador)` ganha parâmetros opcionais
`?string $chave = null, ?string $nome = null`; sem eles, comportamento atual
(chave do Agendei). A assinatura de cliente passa `pix_chave` e o nome da empresa.
Referência/txid: `AC{id_cobranca}`.

## Testes (`tests/assinaturas_clientes.php`, MariaDB local)

- saldo: 4 cortes incluídos → 4 agendamentos a R$ 0, o 5º com desconto;
- ilimitado nunca esgota; serviço não incluído recebe desconto;
- cancelar agendamento coberto libera a cota;
- agendamento fora do período não é coberto;
- confirmar pagamento: primeira ativação, renovação em dia (emenda), renovação
  após suspensão (começa hoje);
- situação derivada: suspensa após `periodo_fim`, cancelada vale até o fim;
- `gerarRenovacoes` é idempotente e respeita a janela de 3 dias;
- isolamento: plano e assinatura de outra empresa não são visíveis nem aplicáveis;
- coberto não gera sinal nem consome pacote;
- `Cobranca::pix` com chave da empresa gera BR Code com CRC válido.

Também rodar as suítes existentes de agendamento, sinal e pacotes para garantir
que nada mudou sem assinatura. Se `ModeloBanco`/`docs/MER_DER.md` tiverem teste
que lista as tabelas, atualizá-los.
