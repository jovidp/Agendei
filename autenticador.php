<?php

/**
 * Cadastro do aplicativo autenticador (segundo fator por codigo).
 *
 * Tela unica para todos os perfis com conta em usuarios: cliente, profissional
 * e administrador. O segredo nasce aqui, fica guardado sem data de ativacao
 * ate que o usuario prove que o aplicativo esta lendo certo, e so entao passa
 * a valer no login.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/config/config.php';

// Qualquer conta autenticada pode proteger o proprio acesso.
exigirLogin(['cliente', 'profissional', 'admin']);

$idUsuario = (int) usuarioId();
$usuario   = Usuario::porId($idUsuario);

if ($usuario === null) {
    definirFlash('erro', 'Conta nao encontrada.');
    redirecionar(painelDe(perfil()));
}

$erros    = [];
$sucesso  = '';
$ativo    = Usuario::totpAtivo($usuario);
$segredo  = '';

// Processa o formulário enviado antes de montar o HTML da página.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Confere o token da sessão antes de aceitar alterações enviadas pelo formulário.
    exigirCsrf();
    $acao = post('acao');

    // -----------------------------------------------------------------
    // Sortear um segredo novo e mostrar o QR
    // -----------------------------------------------------------------
    if ($acao === 'preparar') {
        // Um segredo novo invalida o anterior: e isto que serve tambem para
        // quem trocou de celular e precisa cadastrar o aplicativo de novo.
        Usuario::totpPreparar($idUsuario, Totp::gerarSegredo());
        $usuario = Usuario::porId($idUsuario);
        $ativo   = false;
        registrarEventoSeguranca('totp_preparado', ['usuario' => $idUsuario]);
    }

    // -----------------------------------------------------------------
    // Confirmar: so ativa depois de o aplicativo provar que le o segredo
    // -----------------------------------------------------------------
    if ($acao === 'ativar') {
        $codigo = post('codigo');
        $emUso  = Usuario::totpSegredo($usuario);
        $contadorUsado = null;

        // O freio por origem vale aqui tambem: sem ele, este formulario seria
        // um oraculo para adivinhar codigo sem passar pela tela de login.
        $bloqueio = conferirBloqueio(['fator_ip' => ipCliente()]);

        if ($bloqueio !== '') {
            $erros[] = $bloqueio;
        } elseif ($emUso === '') {
            $erros[] = 'Gere um novo codigo de configuracao antes de confirmar.';
        } elseif (!Totp::confere($emUso, $codigo, $contadorUsado)) {
            anotarFalha('fator_ip', ipCliente());
            atrasarResposta();
            $erros[] = 'Codigo incorreto. Confira a hora do celular e tente o codigo atual.';
        } else {
            Usuario::totpAtivar($idUsuario, (int) $contadorUsado);
            limparFalhas('fator_ip', ipCliente());
            registrarEventoSeguranca('totp_ativado', ['usuario' => $idUsuario]);

            definirFlash('sucesso', 'Aplicativo autenticador ativado. O proximo login vai pedir o codigo.');
            redirecionar('autenticador.php');
        }

        $usuario = Usuario::porId($idUsuario);
        $ativo   = Usuario::totpAtivo($usuario);
    }

    // -----------------------------------------------------------------
    // Desligar: exige a senha atual
    // -----------------------------------------------------------------
    if ($acao === 'desativar') {
        // Pedir a senha impede que alguem sentado na maquina destravada do
        // usuario simplesmente remova o segundo fator e volte quando quiser.
        if (!Usuario::senhaConfere($idUsuario, post('senha_atual'))) {
            anotarFalha('login_conta', (string) ($usuario['login'] ?: $usuario['email']));
            atrasarResposta();
            $erros[] = 'A senha informada esta incorreta.';
        } else {
            Usuario::totpDesativar($idUsuario);
            registrarEventoSeguranca('totp_desativado', ['usuario' => $idUsuario]);

            definirFlash('aviso', 'Aplicativo autenticador desligado. Sua conta voltou a pergunta cadastral.');
            redirecionar('autenticador.php');
        }

        $usuario = Usuario::porId($idUsuario);
        $ativo   = Usuario::totpAtivo($usuario);
    }
}

// Segredo pendente de confirmacao: existe no banco, mas ainda sem ativacao.
if (!$ativo) {
    $segredo = Usuario::totpSegredo($usuario);
}

$estabelecimento = Estabelecimento::dados();
$contaNoApp = ($usuario['login'] ?? '') ?: $usuario['email'];
$uri = $segredo !== '' ? Totp::uri($segredo, $contaNoApp, $estabelecimento['nome']) : '';

// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina  = 'Verificacao em duas etapas';
$subtituloTopo = 'Proteja sua conta com um codigo que muda a cada 30 segundos';

// Renderiza a estrutura comum do painel após preparar os dados desta tela.
require_once RAIZ . '/includes/painel_header.php';
?>

<?php if ($erros !== []): ?>
    <div class="alerta alerta-erro">
        <div>
            <span class="alerta-texto">Corrija os itens abaixo:</span>
            <ul>
                <?php foreach ($erros as $mensagem): ?>
                    <li><?= e($mensagem) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<?php if ($ativo): ?>

    <div class="cartao">
        <div class="cartao-cabecalho"><h3>Aplicativo autenticador ativo</h3></div>
        <div class="cartao-corpo">
            <p>
                Desde <?= e(formatarData($usuario['totp_ativado_em'])) ?>, o acesso a esta conta
                pede o codigo de seis digitos do seu aplicativo depois da senha.
            </p>

            <div class="grupo-botoes">
                <?php /* Trocar de celular exige um segredo novo: o antigo deixa de valer. */ ?><form method="post">
                    <?= campoCsrf() ?>
                    <input type="hidden" name="acao" value="preparar">
                    <button type="submit" class="btn btn-contorno">Cadastrar em outro celular</button>
                </form>
            </div>
        </div>
    </div>

    <div class="cartao">
        <div class="cartao-cabecalho"><h3>Desligar a verificacao</h3></div>
        <div class="cartao-corpo">
            <p class="ajuda-campo">
                Ao desligar, sua conta volta a responder a pergunta cadastral no login —
                uma protecao mais fraca, porque nome da mae, nascimento e CEP nao sao segredos.
            </p>

            <?php /* A senha atual e exigida para que ninguem desligue a protecao numa maquina destravada. */ ?><form method="post" novalidate>
                <?= campoCsrf() ?>
                <input type="hidden" name="acao" value="desativar">

                <div class="campo">
                    <label for="senha_atual">Confirme sua senha</label>
                    <input type="password" id="senha_atual" name="senha_atual"
                           autocomplete="current-password" required>
                    <span class="mensagem-campo"></span>
                </div>

                <button type="submit" class="btn btn-perigo">Desligar verificacao em duas etapas</button>
            </form>
        </div>
    </div>

<?php elseif ($segredo === ''): ?>

    <div class="cartao">
        <div class="cartao-cabecalho"><h3>Sua conta ainda nao usa codigo</h3></div>
        <div class="cartao-corpo">
            <p>
                Hoje o seu login e confirmado por uma pergunta cadastral (nome da mae,
                data de nascimento ou CEP). Um aplicativo autenticador e bem mais seguro:
                o codigo muda a cada 30 segundos e so existe no seu celular.
            </p>

            <p class="ajuda-campo">
                Funciona com Google Authenticator, Microsoft Authenticator, Authy, 2FAS,
                Bitwarden e qualquer outro aplicativo compativel. Nao e preciso pagar nada
                nem ter internet no celular na hora de entrar.
            </p>

            <?php /* Gera o segredo apenas quando o usuario decide comecar. */ ?><form method="post">
                <?= campoCsrf() ?>
                <input type="hidden" name="acao" value="preparar">
                <button type="submit" class="btn">Comecar configuracao</button>
            </form>
        </div>
    </div>

<?php else: ?>

    <div class="cartao">
        <div class="cartao-cabecalho"><h3>Passo 1 — cadastre no aplicativo</h3></div>
        <div class="cartao-corpo">
            <div class="autenticador-configuracao">
                <div class="autenticador-qr">
                    <?= QrCode::svg($uri, 'Codigo QR para cadastrar o aplicativo autenticador') ?>
                </div>

                <div class="autenticador-chave">
                    <p><strong>Aponte a camera do aplicativo para o codigo acima.</strong></p>

                    <p class="ajuda-campo">
                        Se preferir digitar, escolha <em>inserir chave manualmente</em> no
                        aplicativo e use a chave abaixo. O tipo e <em>baseada em tempo</em>.
                    </p>

                    <p class="autenticador-segredo"><code><?= e(Totp::formatarSegredo($segredo)) ?></code></p>

                    <p class="ajuda-campo">
                        Abrindo esta pagina no proprio celular, este link cadastra direto:
                        <a href="<?= e($uri) ?>">abrir no aplicativo autenticador</a>.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="cartao">
        <div class="cartao-cabecalho"><h3>Passo 2 — confirme o codigo</h3></div>
        <div class="cartao-corpo">
            <p class="ajuda-campo">
                A verificacao so passa a valer depois desta confirmacao. Enquanto isso, seu
                login continua funcionando pela pergunta cadastral — voce nao corre risco de
                ficar trancado fora da conta.
            </p>

            <?php /* O codigo digitado prova que o aplicativo leu o segredo certo. */ ?><form method="post" novalidate>
                <?= campoCsrf() ?>
                <input type="hidden" name="acao" value="ativar">

                <div class="campo">
                    <label for="codigo">Codigo mostrado pelo aplicativo</label>
                    <input type="text" id="codigo" name="codigo" class="campo-codigo"
                           inputmode="numeric" pattern="[0-9 ]*" maxlength="7" placeholder="000000"
                           autocomplete="one-time-code" required autofocus>
                    <span class="mensagem-campo"></span>
                    <span class="ajuda-campo">Seis digitos. Se o codigo virar enquanto digita, use o novo.</span>
                </div>

                <div class="grupo-botoes">
                    <button type="submit" class="btn">Ativar verificacao</button>
                </div>
            </form>

            <?php /* Comecar de novo caso o cadastro no aplicativo tenha dado errado. */ ?><form method="post">
                <?= campoCsrf() ?>
                <input type="hidden" name="acao" value="preparar">
                <button type="submit" class="btn btn-contorno">Gerar outra chave</button>
            </form>
        </div>
    </div>

<?php endif; ?>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>
