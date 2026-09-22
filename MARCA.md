# Marca Agendei

Guia curto da marca do produto: o que ela promete, como fala e como aparece.
As cores e os arquivos do logotipo estao descritos tambem em
[LEIAME.md](LEIAME.md#identidade-visual); aqui entra o que falta para
divulgar o produto com uma so voz.

## Essencia

O Agendei existe para tirar a agenda do papel e do WhatsApp sem tirar o
controle de quem atende. Cada horario marcado e um horario garantido: o cliente
sabe que foi atendido no pedido, o profissional sabe o que vem, e o
estabelecimento para de perder tempo com confirmacao manual.

O simbolo diz isso sem palavras: uma agenda com o "check" do horario marcado.
Tudo o que a marca comunica deve caber nessa imagem.

## Slogan

**Marcou, confirmou.**

Duas palavras, uma promessa. E a frase que acompanha o simbolo na entrada
geral, nas assinaturas e no material de divulgacao. Esta em `MARCA_SLOGAN`
(`includes/marca.php`) para ser usada no sistema sem copiar o texto na mao.

Regras de uso:

- Sempre com virgula e ponto final, nunca com exclamacao.
- Sempre em minusculas depois da primeira letra. Nao vira "MARCOU, CONFIRMOU".
- Nao se traduz nem se adapta. Fora do portugues, usa-se so o nome.
- Nunca se emenda ao nome ("Agendei: marcou, confirmou"). Nome em cima,
  slogan embaixo, ou o slogan sozinho ao lado do simbolo.

Frase de apoio, para quando o slogan precisa de contexto (descricao de loja de
aplicativos, meta description, primeiro paragrafo de uma pagina):

> Agendamento online para quem atende e para quem marca.

Variacoes para campanhas, sempre subordinadas ao slogan principal:

| Variacao | Fala com | Quando usar |
| --- | --- | --- |
| Seu horario, confirmado. | o cliente | pagina publica de agendamento, lembretes |
| Agenda cheia, sem falta. | o estabelecimento | material de venda, apresentacao comercial |

## Tom de voz

A marca fala como um bom recepcionista: direto, cordial e sem enrolacao.

- **Curto.** Frases de uma linha. Se precisa de dois pontos ou parenteses, reescreva.
- **Concreto.** Diz o que acontece, nao o que o sistema "permite" ou "possibilita".
- **Sem jargao.** Nada de "solucao", "plataforma completa", "otimize", "experiencia".
- **Sem exclamacao.** A confianca vem do tom, nao da pontuacao.
- **Voce, nunca "o usuario".** O texto conversa com quem le.

| Diga | Evite |
| --- | --- |
| Marque um horario em um minuto. | Nossa plataforma permite agendar de forma rapida e facil! |
| O cliente recebe a confirmacao na hora. | Experiencia otimizada de confirmacao para seus usuarios. |
| Entrar | Acessar a plataforma |
| Horario confirmado. | Sucesso! Seu agendamento foi realizado. |

## Cores

| Nome | Codigo | Onde |
| --- | --- | --- |
| Primaria | `#1F4E5F` | corpo do simbolo, titulos, botoes, fundos escuros |
| Destaque | `#5FAF8B` | faixa do simbolo, o "check", confirmacoes e sucesso |
| Fundo | `#F5F1EA` | fundo das telas e versao clara do simbolo |
| Branco | `#FFFFFF` | cartoes e texto sobre a primaria |

Proporcao de referencia: primaria e fundo dominam, o destaque aparece pouco e
sempre com significado (algo confirmado, algo certo). Um material com muito
verde perde o gesto do "check".

Contraste: texto branco sobre a primaria e texto primaria sobre o fundo passam
no nivel AA. O destaque nao serve para texto pequeno sobre branco; use-o em
faixas, icones e estados.

## Logotipo

Arquivos em `assets/img/`:

| Arquivo | O que e | Fundo |
| --- | --- | --- |
| `agendei-logo.svg` | simbolo + nome | claro |
| `agendei-logo-claro.svg` | simbolo + nome | escuro |
| `agendei-simbolo.svg` | so o simbolo | claro |
| `agendei-simbolo-claro.svg` | so o simbolo | escuro |
| `favicon.svg` | icone da aba | qualquer |

Regras:

- Tamanho minimo: 16 px para o simbolo, 96 px de largura para a marca com nome.
- Area de respiro: metade da altura do simbolo em volta, livre de outros elementos.
- O simbolo pode aparecer sozinho quando o nome ja esta no contexto (aba,
  icone de aplicativo, avatar de rede social).
- Como marca d'agua, use a versao clara com opacidade de ate 10 % sobre a
  primaria, como no painel da entrada geral.

Nao faca:

- trocar as cores do simbolo pelas cores de uma empresa atendida;
- esticar, inclinar ou aplicar sombra e contorno;
- colocar o simbolo dentro de um circulo ou de outra forma;
- escrever o nome com outra fonte ao lado do simbolo.

## Tipografia

A marca usa a fonte do sistema (Segoe UI, com Roboto e Arial como reserva),
pesos 600 e 700 para titulos e 400 para texto. E a mesma pilha do tema de
fabrica, de proposito: material e sistema ficam iguais sem instalar nada.

## Mensagens de venda

Uma frase, para quando so cabe uma:

> Agendei: agenda online para o seu estabelecimento. Marcou, confirmou.

Tres argumentos para quem atende:

1. **Zero confirmacao manual.** O cliente marca, o sistema confirma e lembra.
2. **Cada empresa com a sua cara.** Logo, cores e link proprio, sem parecer um sistema de terceiros.
3. **Cresce com o negocio.** Lista de espera, sinal por Pix, fidelidade e pacotes, ligados so quando fizerem sentido.

Tres argumentos para quem marca:

1. Marca em um minuto, sem ligar nem esperar resposta.
2. Recebe a confirmacao na hora e o lembrete antes.
3. Entra com o mesmo e-mail em qualquer estabelecimento que use o Agendei.

Bio para redes sociais (ate 150 caracteres):

> Agenda online para quem atende e para quem marca. Marcou, confirmou.

Assinatura de e-mail e rodape de material:

> Agendei · Marcou, confirmou.

## Onde a marca aparece no sistema

- **Pagina inicial** (`index.php`, sem link de empresa): apresentacao do produto
  com slogan, argumentos para quem atende e para quem marca, como funciona e
  recursos. E a pagina de venda do Agendei.
- **Entrada geral** (`entrar.php`): painel com marca, slogan e simbolo em marca
  d'agua, sempre nas cores da marca. E a unica tela de entrada assinada pelo produto.
- **Telas da empresa** (login, cadastro, painel): a identidade e da empresa
  atendida. O Agendei aparece so na assinatura discreta do pe da tela.
- **Icone da aba, instalador, tela de saida e rodape publico**: marca do sistema.

A regra que resolve qualquer duvida: se a tela pertence a uma empresa, a marca
da empresa manda. Se pertence ao produto, o Agendei fala com a sua propria voz.
