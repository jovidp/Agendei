<?php
/**
 * Consulta do expediente e dos servicos do profissional.
 * A alteracao do expediente e feita pelo administrador.
 */
require_once __DIR__ . '/../config/config.php';

exigirLogin('profissional');

$idProfissional = perfilId();
$horariosPorDia = Horario::agrupadoPorDia($idProfissional);
$servicos       = Profissional::servicos($idProfissional);

$minutosSemana = 0;
foreach ($horariosPorDia as $faixas) {
    foreach ($faixas as $faixa) {
        if ($faixa['status'] === 'ativo') {
            $minutosSemana += diferencaMinutos($faixa['hora_inicio'], $faixa['hora_fim']);
        }
    }
}

$tituloPagina  = 'Meus horarios';
$subtituloTopo = 'Expediente cadastrado e servicos executados';

require_once RAIZ . '/includes/painel_header.php';
?>

<div class="grade-indicadores">
    <div class="indicador indicador-destaque">
        <span class="indicador-rotulo">Carga semanal</span>
        <span class="indicador-valor"><?= e(duracaoTexto($minutosSemana)) ?></span>
        <span class="indicador-nota">Somando as faixas ativas</span>
    </div>
    <div class="indicador">
        <span class="indicador-rotulo">Servicos executados</span>
        <span class="indicador-valor"><?= count($servicos) ?></span>
        <span class="indicador-nota">Vinculados ao seu perfil</span>
    </div>
    <div class="indicador">
        <span class="indicador-rotulo">Bloqueios futuros</span>
        <span class="indicador-valor"><?= count(Bloqueio::listar(['id_profissional' => $idProfissional, 'futuros' => true])) ?></span>
        <span class="indicador-nota"><a href="<?= url('profissional/bloqueios.php') ?>">Gerenciar bloqueios</a></span>
    </div>
</div>

<div class="cartao">
    <div class="cartao-cabecalho">
        <h3>Expediente semanal</h3>
        <span class="texto-pequeno texto-secundario">Alteracoes devem ser solicitadas ao administrador</span>
    </div>
    <div class="cartao-corpo">
        <div class="grade-dias">
            <?php for ($dia = 0; $dia <= 6; $dia++): ?>
                <div class="cartao-dia">
                    <div class="cartao-dia-topo">
                        <span><?= e(diaSemanaNome($dia)) ?></span>
                    </div>
                    <div class="cartao-dia-corpo">
                        <?php if ($horariosPorDia[$dia] === []): ?>
                            <p class="dia-vazio">Sem atendimento.</p>
                        <?php else: ?>
                            <?php foreach ($horariosPorDia[$dia] as $faixa): ?>
                                <div class="faixa-horario <?= $faixa['status'] === 'inativo' ? 'inativa' : '' ?>">
                                    <div class="faixa-info">
                                        <strong><?= formatarHora($faixa['hora_inicio']) ?> as <?= formatarHora($faixa['hora_fim']) ?></strong>
                                        <span>Intervalos de <?= (int) $faixa['intervalo_minutos'] ?> min</span>
                                    </div>
                                    <?= badgeStatus($faixa['status']) ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<div class="cartao">
    <div class="cartao-cabecalho"><h3>Servicos que voce executa</h3></div>

    <?php if ($servicos === []): ?>
        <div class="estado-vazio">
            <strong>Nenhum servico vinculado</strong>
            <p>Solicite ao administrador o vinculo dos servicos que voce executa.</p>
        </div>
    <?php else: ?>
        <div class="tabela-area">
            <table class="tabela">
                <thead>
                    <tr><th>Servico</th><th>Duracao</th><th>Preco</th><th class="coluna-acoes">Status</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($servicos as $servico): ?>
                        <tr>
                            <td class="celula-principal"><?= e($servico['nome']) ?></td>
                            <td><?= e(duracaoTexto((int) $servico['duracao_minutos'])) ?></td>
                            <td><?= formatarMoeda($servico['preco']) ?></td>
                            <td class="coluna-acoes"><?= badgeStatus($servico['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>
