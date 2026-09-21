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

$verificacoes = Saude::verificacoes();
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
<?php require RAIZ . '/includes/painel_footer.php'; ?>
