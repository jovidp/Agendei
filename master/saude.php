<?php
/**
 * Saude do sistema.
 *
 * Responde, sem abrir o servidor, as perguntas que aparecem quando algo vai
 * mal: o banco esta respondendo, as tabelas de apoio existem, o ambiente esta
 * em modo de producao, o instalador continua no ar.
 *
 * A tela e so de leitura: um diagnostico que altera o sistema nao serve para
 * diagnosticar.
 */
define('AREA_MASTER', true);
require_once __DIR__ . '/../config/config.php';
exigirLogin('master');

// A unica acao da tela: mandar um e-mail de teste para o proprio master. Nao
// altera nada no sistema, so confirma que a configuracao entrega.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('acao') === 'testar_email') {
    exigirCsrf();
    $destino = (string) ($_SESSION['usuario_email'] ?? '');
    [$assunto, $texto, $html] = emailTeste((string) ($_SESSION['usuario_nome'] ?? ''));
    if (Email::enviar($destino, $assunto, $texto, $html)) {
        definirFlash('sucesso', 'E-mail de teste enviado para ' . $destino . '. Confira a caixa de entrada e o spam.');
    } else {
        definirFlash('erro', 'Nao foi possivel enviar: ' . Email::ultimoErro());
    }
    redirecionar('master/saude.php');
}

$verificacoes = Saude::verificacoes();
$emailConfig = Email::configuracao();
$resumo = Saude::resumo($verificacoes);

$tituloPagina  = 'Saude do sistema';
$subtituloTopo = $resumo[Saude::ERRO] > 0
    ? $resumo[Saude::ERRO] . ' problema(s) encontrado(s)'
    : ($resumo[Saude::AVISO] > 0 ? $resumo[Saude::AVISO] . ' ponto(s) de atencao' : 'Nenhum problema encontrado');
require RAIZ . '/includes/painel_header.php';
?>
<div class="grade-indicadores">
    <div class="indicador <?= $resumo[Saude::ERRO] > 0 ? 'indicador-destaque' : '' ?>">
        <span class="indicador-rotulo">Problemas</span>
        <strong class="indicador-valor"><?= (int) $resumo[Saude::ERRO] ?></strong>
        <span class="indicador-nota">Impedem alguma parte de funcionar</span>
    </div>
    <div class="indicador">
        <span class="indicador-rotulo">Pontos de atencao</span>
        <strong class="indicador-valor"><?= (int) $resumo[Saude::AVISO] ?></strong>
        <span class="indicador-nota">Funciona, mas vale rever</span>
    </div>
    <div class="indicador">
        <span class="indicador-rotulo">Verificacoes em ordem</span>
        <strong class="indicador-valor"><?= (int) $resumo[Saude::OK] ?></strong>
        <span class="indicador-nota">de <?= count($verificacoes) ?> no total</span>
    </div>
</div>

<div class="cartao">
    <div class="cartao-cabecalho"><h3>Diagnostico</h3><small>Conferido agora, <?= e(date('d/m/Y H:i')) ?></small></div>
    <div class="tabela-area">
        <table class="tabela">
            <thead><tr><th>Verificacao</th><th>Situacao</th><th>Valor</th><th>O que significa</th></tr></thead>
            <tbody>
            <?php foreach ($verificacoes as $item): ?>
                <tr>
                    <td class="celula-principal"><?= e($item['nome']) ?></td>
                    <td>
                        <span class="badge badge-<?= $item['estado'] === Saude::OK ? 'ativo' : ($item['estado'] === Saude::AVISO ? 'pendente' : 'inativo') ?>">
                            <?= e(Saude::estadoTexto($item['estado'])) ?>
                        </span>
                    </td>
                    <td><?= e($item['valor']) ?></td>
                    <td><?= e($item['detalhe']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="cartao">
    <div class="cartao-cabecalho"><h3>Envio de e-mail</h3><small>Configurado por variaveis de ambiente ou config/email.local.php</small></div>
    <div class="cartao-corpo">
        <?php if (Email::meio() === 'api'): ?>
            <p><strong>Caminho:</strong> API da Brevo por HTTPS
               &middot; <strong>Remetente:</strong> <?= e($emailConfig['nome']) ?> &lt;<?= e($emailConfig['remetente']) ?>&gt;</p>
            <p class="ajuda-campo">O remetente precisa estar validado na conta Brevo, senao a API recusa o envio.</p>
        <?php endif; ?>
        <?php if (Email::meio() === 'smtp'): ?>
            <p><strong>Servidor:</strong> <?= e($emailConfig['host']) ?>:<?= (int) $emailConfig['porta'] ?> (<?= e($emailConfig['seguranca']) ?>)
               &middot; <strong>Usuario:</strong> <?= e($emailConfig['usuario'] ?: 'sem autenticacao') ?>
               &middot; <strong>Remetente:</strong> <?= e($emailConfig['nome']) ?> &lt;<?= e($emailConfig['remetente']) ?>&gt;</p>
            <p class="ajuda-campo">Se o teste estourar o tempo de conexao, a hospedagem bloqueia SMTP para fora (o Render gratuito faz isso). Nesse caso troque para a API da Brevo: AGENDEI_EMAIL_API_CHAVE e AGENDEI_EMAIL_REMETENTE.</p>
        <?php endif; ?>
        <?php if (Email::configurado()): ?>
            <form method="post"><?= campoCsrf() ?>
                <input type="hidden" name="acao" value="testar_email">
                <p class="ajuda-campo">Envia uma mensagem para <?= e((string) ($_SESSION['usuario_email'] ?? '')) ?>, o e-mail da sua conta master.</p>
                <button class="btn btn-secundario" type="submit">Enviar e-mail de teste</button>
            </form>
        <?php else: ?>
            <p>Nenhum servidor configurado. Enquanto isso, o sistema funciona normalmente, mas os avisos de cadastro e a recuperacao de senha nao saem por e-mail.</p>
            <p class="ajuda-campo">No Render, defina <code>AGENDEI_EMAIL_API_CHAVE</code> (chave da API da Brevo) e <code>AGENDEI_EMAIL_REMETENTE</code>. Em servidor com SMTP liberado, <code>AGENDEI_EMAIL_HOST</code>, <code>AGENDEI_EMAIL_USUARIO</code>, <code>AGENDEI_EMAIL_SENHA</code> e <code>AGENDEI_EMAIL_REMETENTE</code>. Localmente, crie <code>config/email.local.php</code>. O passo a passo esta em DEPLOY.md.</p>
        <?php endif; ?>
    </div>
</div>
<?php require RAIZ . '/includes/painel_footer.php'; ?>
