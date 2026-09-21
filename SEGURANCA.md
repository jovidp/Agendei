# Seguranca do Agendei

Guia da camada de seguranca do sistema: o que ela faz, o que precisa ser
configurado antes de publicar e como conferir se esta no ar.

O codigo central fica em [`includes/seguranca.php`](includes/seguranca.php) e em
[`models/Tentativa.php`](models/Tentativa.php). Tudo e carregado por
`config/config.php`, entao vale para toda pagina que inclui o bootstrap — nao ha
nada para lembrar de chamar em cada tela.

---

## 1. Antes de publicar

### Variaveis de ambiente

| Variavel | Valor | Para que serve |
|---|---|---|
| `AGENDEI_AMBIENTE` | `producao` | Desliga a exibicao de erros na tela. Sem a variavel, qualquer host que nao seja local ja entra como producao. |
| `AGENDEI_PROXY_CONFIAVEL` | `1` | Ligue **somente** se houver um proxy/balanceador na frente (Render, Koyeb, Fly, Cloudflare). Sem ela, o sistema so enxerga o IP interno da plataforma e o bloqueio por origem trataria todos os visitantes como uma pessoa so. Em servidor sem proxy, deixe desligada: o cabecalho `X-Forwarded-For` e forjavel. |
| `AGENDEI_CHAVE_TOTP` | segredo longo | Protege os segredos do segundo fator gravados no banco. Defina **antes** de alguem cadastrar o primeiro aplicativo: trocar depois invalida os aplicativos ja cadastrados, que precisarao ser cadastrados de novo (use `scripts/liberar_2fa.php` para destravar). Sem ela, o sistema cifra com uma chave derivada e avisa no log. |
| `AGENDEI_TOKEN_INSTALACAO` | segredo longo | Chave de acesso a `instalar.php`. Sem ela definida, o instalador **nao existe** em producao (responde 404). |
| `AGENDEI_DB_*` | credenciais | Banco. Nunca versione senha: veja `config/database.php`. |

No Dockerfile, `AGENDEI_AMBIENTE` e `AGENDEI_PROXY_CONFIAVEL` ja vem definidas.

### Banco

A tabela `tentativas_acesso` guarda o contador de forca bruta. Ela ja esta em
`banco.sql` e `banco_postgres.sql`. Para uma instalacao que ja existe:

```
php scripts/migrar_seguranca.php
php scripts/migrar_totp.php
```

O segundo acrescenta as colunas do segundo fator por codigo a `usuarios` e a
`administradores_master`, e abre o campo `fator_2fa` do log para o valor `totp`.

O script funciona nos dois dialetos e pode ser executado de novo sem efeito.
A aplicacao tambem cria a tabela sozinha no primeiro uso; rodar o script antes
apenas evita depender da permissao de DDL do usuario do banco em producao.

### Depois de instalar

1. Troque as senhas padrao (`admin@agendei.com.br` / `agendeis` e
   `master@agendei.com.br` / `agendei-master-2026`). Elas estao publicadas na
   documentacao do projeto — em producao sao credenciais conhecidas.
2. Apague `instalar.php` do servidor, ou remova `AGENDEI_TOKEN_INSTALACAO`.
3. Confirme que `banco*.sql` nao responde pela web (veja a secao 5).

---

## 2. O que a camada protege

### Forca bruta no acesso

A senha do usuario comum tem **exatamente oito letras** por exigencia da
especificacao do projeto. Isso da um espaco de busca pequeno, e um formulario de
login sem limite derruba uma senha dessas com um dicionario simples. Os limites
estao em `politicaLimites()`:

| Fluxo | Escopo | Falhas toleradas | Janela | Bloqueio |
|---|---|---|---|---|
| Login | por conta | 8 | 15 min | 15 min |
| Login | por origem | 25 | 15 min | 30 min |
| Login master | por origem | 5 | 15 min | 30 min |
| Segundo fator | por origem | 15 | 15 min | 15 min |
| Recuperar senha | por origem | 10 | 60 min | 30 min |
| Cadastro | por origem | 10 | 60 min | 30 min |
| Chave do instalador | por origem | 5 | 60 min | 60 min |

Sao dois baldes independentes no login: o **por conta** segura o ataque de
dicionario contra um alvo; o **por origem** segura a varredura que testa uma
senha em muitas contas. Um acesso bem-sucedido limpa o balde da conta.

Cada falha tambem gera um atraso aleatorio de 180 a 420 ms (`atrasarResposta()`),
que encarece a tentativa e embaralha a medicao de tempo que revelaria se o login
existe.

O contador grava no banco. Se o banco recusar a tabela, ele cai sozinho para
arquivos no diretorio temporario — protecao mais fraca, mas o login nunca fica
sem limite nenhum.

**O que fica gravado:** apenas o hash SHA-256 da origem, o escopo e o instante.
Nunca o e-mail nem o IP em texto claro. Se o banco vazar, a tabela nao vira uma
lista de contas validas.

### Segundo fator por codigo (TOTP)

O caminho padrao do segundo fator e um **codigo de seis digitos gerado por
aplicativo autenticador** (Google Authenticator, Microsoft Authenticator, Authy,
2FAS, Bitwarden e qualquer outro compativel). Implementacao em
[`models/Totp.php`](models/Totp.php), seguindo as RFC 4226 e RFC 6238.

Por que assim, e nao por e-mail ou SMS: o sistema nao tem servidor de e-mail
configurado, e SMS custa por mensagem. O codigo do aplicativo nao depende de
nenhum dos dois — e derivado do relogio e de um segredo compartilhado, entao
funciona ate com o celular sem internet.

| Item | Valor |
|---|---|
| Algoritmo | HMAC-SHA1, 6 digitos, janela de 30 s |
| Tolerancia de relogio | uma janela para cada lado (±30 s) |
| Segredo | 160 bits, mostrado em Base32 e em QR code |
| Reuso de codigo | recusado: a janela aproveitada fica gravada na conta |
| Guarda do segredo | cifrado com AES-256-GCM (`AGENDEI_CHAVE_TOTP`) |

**Quem pode usar:** qualquer conta — cliente, profissional, administrador do
estabelecimento e a conta master. A tela fica em *Verificacao em 2 etapas*, no
menu lateral de cada painel.

**Cadastro em dois passos.** O segredo nasce guardado mas **sem data de
ativacao**: enquanto o usuario nao digitar um codigo valido, o login continua
pelo caminho antigo. Quem abre a tela e desiste no meio nao fica trancado fora
da conta.

**O QR code e gerado no proprio servidor** ([`models/QrCode.php`](models/QrCode.php),
PHP puro, saida em SVG). Nao ha chamada a nenhum servico externo de QR — o
endereco `otpauth://` carrega o segredo, e entrega-lo a um terceiro anularia a
protecao que ele deveria criar.

**Anti-reapresentacao.** A janela usada em cada acesso e gravada em
`totp_ultimo_contador`. Um codigo visto por cima do ombro, ou capturado num
computador comprometido, nao vale uma segunda vez — nem nos segundos que
restarem da janela. Isso vale tambem para o codigo gasto na propria ativacao.

**Se o aplicativo cair fora do ar:**

- *Conta comum:* volta a pergunta cadastral. O administrador do estabelecimento
  nao precisa fazer nada.
- *Conta master:* nao ha reserva — a saida e rodar no servidor
  `php scripts/liberar_2fa.php master <e-mail>`. O script tambem lista quem usa
  codigo hoje, e nao tem versao pela web de proposito: desligar a protecao exige
  acesso a maquina.

### Pergunta cadastral (reserva)

As perguntas da especificacao do projeto — nome da mae, data de nascimento e
CEP — continuam atendendo as contas comuns que ainda nao cadastraram o
aplicativo. **Conta que tem aplicativo nunca cai na pergunta**: deixar os dois
caminhos abertos em paralelo rebaixaria a seguranca ao elo mais fraco, e esses
tres dados nao sao segredos para quem conhece a pessoa.

A regra das tres tentativas e a mensagem exigidas pela especificacao valem nos
dois caminhos.

### Sessao

| Regra | Valor | Risco coberto |
|---|---|---|
| Inatividade | 20 min (admin/master), 30 min (profissional), 45 min (cliente) | Estacao abandonada sem logout |
| Duracao maxima | 12 h | Cookie copiado valendo para sempre |
| Assinatura do navegador | conferida a cada requisicao | Cookie usado em outro dispositivo |
| Troca do identificador | a cada 15 min e a cada login | Janela de um cookie roubado |
| `session.use_strict_mode` | ligado | Fixacao de sessao por link |
| `use_only_cookies` | ligado | Identificador vazando pela URL, Referer e log |
| Prefixo `__Host-` | sob HTTPS na raiz do dominio | Subdominio vizinho sobrescrevendo o cookie |

O token CSRF e descartado a cada login, entao um formulario aberto antes da
autenticacao nao serve depois dela.

### Cabecalhos

Enviados em toda resposta por `aplicarCabecalhosSeguranca()`:

```
Content-Security-Policy: default-src 'self'; script-src 'self'; ...
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=(), ...
Cross-Origin-Opener-Policy: same-origin
Strict-Transport-Security: max-age=31536000  (so em producao sob HTTPS)
```

A politica de conteudo e restritiva de proposito: **`script-src 'self'`, sem
`unsafe-inline`**. O projeto nao tem nenhum `<script>` embutido nem atributo
`onclick`, entao qualquer JavaScript injetado por uma falha de escape e barrado
pelo navegador, sem quebrar tela nenhuma.

Dois pontos merecem atencao ao mexer no codigo:

- **`style-src` ainda aceita `'unsafe-inline'`**, porque varias telas usam
  atributo `style=` e `includes/tema.php` imprime um bloco `<style>` com as
  cores da empresa. Migrar esses estilos para classes permitiria fechar tambem
  o `style-src`.
- **`connect-src` libera `https://viacep.com.br`**, unico destino externo do
  sistema (busca de endereco no cadastro). Qualquer outro endereco aparecendo
  numa requisicao e sinal de codigo injetado.

Paginas de quem esta autenticado saem com `Cache-Control: no-store`, para que o
botao voltar depois do logout nao mostre agenda e dados pessoais do ultimo
usuario — problema real em computador compartilhado.

### CSRF

O token na sessao continua sendo a defesa principal, comparado com
`hash_equals()`. Foi somada uma segunda barreira independente: `origemConfere()`
confere o cabecalho `Origin` (ou o `Referer`, quando nao ha `Origin`) contra o
host da requisicao. Se nenhum dos dois vier, a verificacao se abstem e o token
decide sozinho.

### Instalador

`instalar.php` cria o administrador e a conta master com senhas padrao que estao
na documentacao. Um banco ainda vazio — servidor novo, migracao interrompida,
restauracao pela metade — deixaria qualquer visitante criar essas contas e
entrar como dono do sistema.

Em producao a pagina so responde com `?chave=<AGENDEI_TOKEN_INSTALACAO>`
correta. Sem a variavel definida, ela responde 404. A chave errada conta no
balde `instalador_ip` (5 tentativas por hora).

### Registro de eventos

`registrarEventoSeguranca()` anota no log do servidor: bloqueio ativado, CSRF
recusado, acesso negado por perfil, sessao derrubada, login master (sucesso e
falha) e instalador recusado. Vai para o `error_log` de proposito — e o unico
destino que existe em qualquer hospedagem, sobrevive a falha do banco e nao
depende de tabela criada. O IP entra com o ultimo octeto mascarado
(`203.0.113.x`): basta para correlacionar sem guardar o endereco de quem so
errou a senha.

---

## 3. Servidor web

`.htaccess` na raiz cobre o que o PHP nunca chega a ver:

- **Dumps do banco e documentacao fora do ar.** `banco.sql`, `banco_postgres.sql`
  e `banco_hospedagem.sql` ficam na raiz do projeto e descrevem o esquema
  inteiro; os `.md` trazem caminhos, variaveis de ambiente e passos de
  implantacao. Bloqueados junto com `.log`, `.bak`, `.ini`, `.sh`, `.yml`,
  `.json` e `.example`.
- **Arquivos ocultos e `.git`**: um repositorio exposto entrega o codigo-fonte.
- **Listagem de diretorio desligada** (`Options -Indexes`).
- **Metodos** limitados a GET, POST e HEAD.
- **Corpo da requisicao** limitado a 10 MB.
- **Cabecalhos de defesa repetidos**, para cobrir CSS, JS, imagens e paginas de
  erro do Apache, que nao passam pelo PHP.

`assets/.htaccess` impede que qualquer `.php` naquela pasta seja executado.

As pastas `config/`, `includes/`, `models/`, `scripts/` e `tests/` ja tinham
`.htaccess` proprio negando acesso direto.

### Em Nginx

Nao ha `.htaccess`. As regras equivalentes precisam ir para o `server` block:

```nginx
location ~ /\.                                 { deny all; }
location ~* \.(sql|md|log|bak|old|ini|sh|ya?ml|lock|example)$ { deny all; }
location ~ ^/(config|includes|models|scripts|tests)/ { deny all; }
location ~ ^/assets/.*\.php$                   { deny all; }

autoindex off;
client_max_body_size 10m;

add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "DENY" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
```

---

## 4. Envio de imagem

A logo da empresa (`Tema::receberLogo()`) nunca vira arquivo no disco: o
conteudo e validado com `getimagesizefromstring()`, limitado a PNG, JPEG ou
WebP de ate 512 KB e 2048 x 2048 px, e guardado no banco como `data:` URI. Isso
elimina de saida a classe de ataque de envio de arquivo executavel.

---

## 5. Como conferir depois de publicar

```bash
# Dumps do banco e documentacao devem responder 403 ou 404, nunca 200.
curl -sI https://SEU-DOMINIO/banco.sql          | head -1
curl -sI https://SEU-DOMINIO/banco_postgres.sql | head -1
curl -sI https://SEU-DOMINIO/LEIAME.md          | head -1
curl -sI https://SEU-DOMINIO/config/config.php  | head -1
curl -sI https://SEU-DOMINIO/.git/config        | head -1

# Instalador sem a chave deve responder 404.
curl -sI https://SEU-DOMINIO/instalar.php | head -1

# Listagem de diretorio deve estar desligada.
curl -s https://SEU-DOMINIO/assets/ | head -3

# Cabecalhos de defesa presentes.
curl -sI https://SEU-DOMINIO/login.php | grep -iE 'content-security|x-frame|x-content-type|strict-transport|referrer'
```

Bloqueio por forca bruta: erre a senha nove vezes seguidas na mesma conta. A
partir da nona tentativa a tela deve responder "Muitas tentativas seguidas.
Aguarde 15 minutos antes de tentar novamente." — e **a mensagem nao pode
revelar** se a conta existe.

Segundo fator por codigo: cadastre o aplicativo em *Verificacao em 2 etapas*,
saia e entre de novo. O login deve parar na tela de codigo. Confira tambem que
**o mesmo codigo nao entra duas vezes**: repetir o codigo recem-usado tem de
responder "Codigo incorreto ou ja utilizado".

Testes automatizados da camada:

```
php tests/seguranca.php     controle de forca bruta, sessao, origem do POST
php tests/totp.php          codigo de uso unico, contra os vetores das RFC 4226/6238
php tests/qrcode.php        gerador de QR, com leitura de volta do simbolo
```

---

## 6. O que ainda fica em aberto

Itens que exigem decisao de produto ou infraestrutura, e nao mudanca de codigo:

1. **Senha de oito letras.** E a regra da especificacao do projeto, e e o ponto
   mais fraco do sistema. Os limites de tentativa compensam, mas nao substituem
   uma senha melhor. Se a especificacao permitir, aumentar o tamanho e exigir
   numero e simbolo vale mais do que qualquer item desta lista.
2. **Adesao ao aplicativo autenticador.** O codigo e o caminho padrao, mas cada
   conta precisa cadastrar o aplicativo uma vez; enquanto nao cadastrar, ela cai
   na pergunta cadastral, que e fraca. Se quiser tornar obrigatorio para os
   perfis administrativos, o lugar e `exigeSegundoFator()` —
   bastaria recusar o login de `admin` sem `totp_ativado_em`.
3. **Recuperacao de senha nao envia e-mail.** O link e gerado mas so aparece em
   desenvolvimento. Em producao, o fluxo nao se completa.
4. **`style-src` com `unsafe-inline`.** Fecha quando os atributos `style=` das
   telas virarem classes de CSS.
5. **Backup e restauracao.** Nao ha rotina definida.
6. **Firewall de aplicacao (WAF)** e limite de requisicoes por segundo ficam a
   cargo da hospedagem (Cloudflare, por exemplo).
