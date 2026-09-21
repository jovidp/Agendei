<?php

/**
 * Cadastro do aplicativo autenticador da conta master.
 *
 * Mesma mecanica de autenticador.php, em tabela propria. A diferenca que
 * importa esta no aviso da tela: a conta master nao tem pergunta cadastral de
 * reserva, entao perder o celular exige acesso ao servidor para liberar.
 */
define('AREA_MASTER', true);
require_once __DIR__ . '/../config/config.php';

exigirLogin('master');

$idMaster = (int) ($_SESSION['master_id'] ?? 0);
$master   = Master::porId($idMaster);

if ($master === null) {
    definirFlash('erro', 'Conta master nao encontrada.');
    redirecionar('master/dashboard.php');
}

$erros   = [];
$ativo   = Master::totpAtivo($master);
$segredo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $acao = post('acao');

    if ($acao === 'preparar') {
        Master::totpPreparar($idMaster, Totp::gerarSegredo());
        $master = Master::porId($idMaster);
        $ativo  = false;
        registrarEventoSeguranca('master_totp_preparado', ['master' => $idMaster]);
    }

    if ($acao === 'ativar') {
        $codigo = post('codigo');
        $emUso  = Master::totpSegredo($master);
        $contadorUsado = null;

        $bloqueio = conferirBloqueio(['master_ip' => ipCliente()]);

        if ($bloqueio !== '') {
            $erros[] = $bloqueio;
        } elseif ($emUso === '') {
            $erros[] = 'Gere um novo codigo de configuracao antes de confirmar.';
        } elseif (!Totp::confere($emUso, $codigo, $contadorUsado)) {
            anotarFalha('master_ip', ipCliente());
            atrasarResposta();
            $erros[] = 'Codigo incorreto. Confira a hora do celular e tente o codigo atual.';
        } else {
            Master::totpAtivar($idMaster, (int) $contadorUsado);
            limparFalhas('master_ip', ipCliente());
            registrarEventoSeguranca('master_totp_ativado', ['master' => $idMaster]);

            definirFlash('sucesso', 'Aplicativo autenticador ativado. O proximo login master vai pedir o codigo.');
            redirecionar('master/autenticador.php');
        }

        $master = Master::porId($idMaster);
        $ativo  = Master::totpAtivo($master);
    }

    if ($acao === 'desativar') {
        if (!Master::senhaConfere($idMaster, post('senha_atual'))) {
            anotarFalha('master_ip', ipCliente());
            atrasarResposta();
            $erros[] = 'A senha informada esta incorreta.';
        } else {
            Master::totpDesativar($idMaster);
            registrarEventoSeguranca('master_totp_desativado', ['master' => $idMaster]);

            definirFlash('aviso', 'Aplicativo autenticador desligado. O login master volta a pedir apenas a senha.');
            redirecionar('master/autenticador.php');
        }

        $master = Master::porId($idMaster);
        $ativo  = Master::totpAtivo($master);
    }
}

if (!$ativo) {
    $segredo = Master::totpSegredo($master);
}

$uri = $segredo !== '' ? Totp::uri($segredo, (string) $master['email'], 'Agendei Master') : '';

$tituloPagina  = 'Verificacao em duas etapas';
$subtituloTopo = 'Codigo de uso unico para a administracao da plataforma';

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
                Desde <?= e(formatarData($master['totp_ativado_em'])) ?>, entrar na administracao
                master exige o codigo de seis digitos do seu aplicativo depois da senha.
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
                Sem o codigo, a administracao master volta a depender somente da senha —
                e ela comanda todos os estabelecimentos da plataforma.
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
        <div class="cartao-cabecalho"><h3>A conta master ainda entra apenas com a senha</h3></div>
        <div class="cartao-corpo">
            <p>
                Esta conta gerencia todos os estabelecimentos da plataforma. Um aplicativo
                autenticador acrescenta um codigo que muda a cada 30 segundos e existe
                somente no seu celular.
            </p>

            <div class="alerta alerta-aviso">
                <span class="alerta-texto">
                    Antes de ativar, saiba disto: a conta master nao tem pergunta cadastral de
                    reserva. Se voce perder o celular, so sera possivel liberar o acesso rodando
                    <strong>php scripts/liberar_2fa.php</strong> no servidor. Ative apenas se
                    tiver esse acesso, ou guarde a chave de configuracao em local seguro.
                </span>
            </div>

            <?php /* Gera o segredo apenas quando o master decide comecar. */ ?><form method="post">
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
                        Guarde esta chave num gerenciador de senhas: com ela e possivel
                        cadastrar o aplicativo de novo se o celular for perdido.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="cartao">
        <div class="cartao-cabecalho"><h3>Passo 2 — confirme o codigo</h3></div>
        <div class="cartao-corpo">
            <p class="ajuda-campo">
                A verificacao so passa a valer depois desta confirmacao. Enquanto isso, o
                login master continua funcionando apenas com a senha.
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
