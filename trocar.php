<?php
/**
 * Trocar de estabelecimento, ou de papel, sem sair da conta.
 *
 * A pessoa com mais de um vinculo (cliente aqui e profissional ali, ou
 * administradora e profissional do proprio negocio) escolhe onde quer estar.
 * A sessao e sempre um vinculo so: a troca fecha a atual e abre a escolhida,
 * pelo mesmo caminho do login (registrarSessao), inclusive o segundo fator
 * quando o vinculo novo o exige. A URL sozinha continua nao trocando nada: a
 * escolha e um POST com CSRF, e so daqui o Contexto pode assumir outra empresa.
 */
define('TROCA_VINCULO', true);
require_once __DIR__ . '/config/config.php';

exigirLogin(['cliente', 'profissional', 'admin']);

$idUsuario = (int) usuarioId();
$atual     = vinculoId();
$vinculos  = array_values(array_filter(
    Vinculo::daPessoa($idUsuario, true),
    static fn (array $vinculo): bool => (int) $vinculo['id_vinculo'] !== $atual
));
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();

    $idVinculo = (int) post('id_vinculo');
    $alvo = null;
    foreach ($vinculos as $vinculo) {
        if ((int) $vinculo['id_vinculo'] === $idVinculo) {
            $alvo = $vinculo;
        }
    }

    if ($alvo === null) {
        $erros[] = 'Escolha um dos seus vinculos.';
    } else {
        // A saida da empresa atual fica no log dela, como no logout.
        $saindo = Usuario::daSessao();
        if ($saindo !== null) {
            LogAutenticacao::registrar('logout', usuarioLogin(), $saindo, null, cpfDoUsuario($saindo));
        }

        $email = (string) $alvo['email'];
        Contexto::assumir((int) $alvo['id_estabelecimento']);
        $usuario = Usuario::porVinculo($idVinculo);

        if ($usuario === null || $usuario['status'] !== 'ativo') {
            $erros[] = 'Este vinculo nao esta mais disponivel.';
        } elseif (exigeSegundoFator($usuario)) {
            // O segundo fator e conferido por vinculo: a sessao atual fecha e a
            // nova so abre depois da resposta, como num login comum.
            encerrarSessao();
            iniciarSessao();
            iniciarSegundoFator($usuario, $email);
            definirFlash('info', 'Confirme o segundo fator para entrar em ' . $alvo['estabelecimento_nome'] . '.');
            redirecionar('dois_fatores.php');
        } else {
            LogAutenticacao::registrar('login_sucesso', $email, $usuario, null, cpfDoUsuario($usuario));
            registrarSessao($usuario);
            definirFlash('sucesso', 'Agora voce esta em ' . $alvo['estabelecimento_nome'] . ' como ' . mb_strtolower(perfilRotulo($usuario['tipo'])) . '.');
            header('Location: ' . url(painelDe($usuario['tipo'])));
            exit;
        }
    }
}

$tituloPagina  = 'Trocar de estabelecimento';
$subtituloTopo = 'Sua conta vale em mais de um lugar';
$jsExtra       = [];

require_once RAIZ . '/includes/painel_header.php';
?>

<?php if ($erros !== []): ?>
    <div class="alerta alerta-erro"><span class="alerta-texto"><?= e($erros[0]) ?></span></div>
<?php endif; ?>

<div class="cartao">
    <div class="cartao-cabecalho">
        <h3>Onde voce quer estar?</h3>
        <small>Voce esta em <?= e(Estabelecimento::dados()['nome']) ?> como <?= e(mb_strtolower(perfilRotulo())) ?>.</small>
    </div>
    <div class="cartao-corpo">
        <?php if ($vinculos === []): ?>
            <p class="texto-secundario sem-margem">Sua conta so tem este vinculo por enquanto.</p>
        <?php else: ?>
            <form method="post">
                <?= campoCsrf() ?>
                <ul class="lista-escolha">
                    <?php foreach ($vinculos as $vinculo): ?>
                        <li>
                            <button type="submit" class="btn btn-contorno" name="id_vinculo" value="<?= (int) $vinculo['id_vinculo'] ?>">
                                <strong><?= e($vinculo['estabelecimento_nome']) ?></strong>
                                <small><?= e(Usuario::TIPOS[$vinculo['tipo']] ?? $vinculo['tipo']) ?></small>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </form>
            <p class="ajuda-campo">A troca fecha a sessao atual e abre a escolhida. Se o vinculo novo exigir a verificacao em duas etapas, ela e pedida de novo.</p>
        <?php endif; ?>
    </div>
</div>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>
