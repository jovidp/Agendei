<?php
define('AREA_MASTER', true);
require_once __DIR__ . '/../config/config.php';
exigirLogin('master');
$empresas = Estabelecimento::listarTodos();
$ativos = count(array_filter($empresas, fn ($e) => $e['status'] === 'ativo'));
$totais = ['clientes' => 0, 'profissionais' => 0, 'servicos' => 0];
foreach ($empresas as $empresa) {
    $totais['clientes'] += (int) $empresa['total_clientes'];
    $totais['profissionais'] += (int) $empresa['total_profissionais'];
    $totais['servicos'] += (int) $empresa['total_servicos'];
}
// Atividade recente da propria administração: o dashboard mostrava só o que
// foi cadastrado, nunca o que o master andou fazendo com esses cadastros.
$acoesRecentes = LogMaster::listar(['limite' => 6]);
$cadastrosPendentes = Solicitacao::contarPendentes();
$tituloPagina = 'Visão geral';
$subtituloTopo = 'Administração global e isolamento dos estabelecimentos';
$acoesTopo = '<a class="btn btn-pequeno" href="' . url('master/estabelecimentos.php?acao=novo') . '">Novo estabelecimento</a>';
require RAIZ . '/includes/painel_header.php';
?>
<?php if ($cadastrosPendentes > 0): ?>
<div class="alerta alerta-aviso" role="status"><span class="alerta-texto"><?= $cadastrosPendentes ?> cadastro(s) de empresa aguardando aprovação.</span> <a href="<?= url('master/estabelecimentos.php#cadastros-pendentes') ?>">Ver e decidir</a></div>
<?php endif; ?>
<div class="grade-indicadores">
    <div class="indicador indicador-destaque"><span class="indicador-rotulo">Estabelecimentos</span><strong class="indicador-valor"><?= count($empresas) ?></strong><span class="indicador-nota"><?= $ativos ?> ativo(s)</span></div>
    <div class="indicador"><span class="indicador-rotulo">Clientes vinculados</span><strong class="indicador-valor"><?= $totais['clientes'] ?></strong></div>
    <div class="indicador"><span class="indicador-rotulo">Profissionais</span><strong class="indicador-valor"><?= $totais['profissionais'] ?></strong></div>
    <div class="indicador"><span class="indicador-rotulo">Serviços</span><strong class="indicador-valor"><?= $totais['servicos'] ?></strong></div>
</div>
<div class="cartao">
    <div class="cartao-cabecalho"><h3>Estabelecimentos recentes</h3><a href="<?= url('master/estabelecimentos.php') ?>">Ver todos</a></div>
    <div class="tabela-area"><table class="tabela"><thead><tr><th>Estabelecimento</th><th>Admin ativo</th><th>Clientes</th><th>Status</th></tr></thead><tbody>
    <?php foreach (array_slice($empresas, 0, 8) as $empresa): ?><tr><td><strong><?= e($empresa['nome']) ?></strong><br><small><?= e($empresa['slug']) ?></small></td><td><?= (int) $empresa['admins_ativos'] ?></td><td><?= (int) $empresa['total_clientes'] ?></td><td><?= badgeStatus($empresa['status']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</div>
<div class="cartao">
    <div class="cartao-cabecalho"><h3>Atividade recente da administração</h3><a href="<?= url('master/auditoria.php') ?>">Ver auditoria</a></div>
    <?php if ($acoesRecentes === []): ?>
        <div class="estado-vazio"><strong>Nenhuma ação registrada ainda.</strong><p>Entradas na área master e alterações de acesso passam a aparecer aqui.</p></div>
    <?php else: ?>
        <div class="tabela-area"><table class="tabela"><thead><tr><th>Dia e hora</th><th>Master</th><th>Ação</th><th>Estabelecimento</th><th>Alvo</th></tr></thead><tbody>
        <?php foreach ($acoesRecentes as $registro): ?>
            <tr>
                <td class="celula-principal"><?= formatarData(substr((string) $registro['data_hora'], 0, 10)) ?><span class="celula-secundaria"><?= e(substr((string) $registro['data_hora'], 11, 8)) ?></span></td>
                <td><?= e((string) $registro['master_nome'] ?: '-') ?></td>
                <td><?= e(LogMaster::acaoTexto((string) $registro['acao'])) ?></td>
                <td><?= e((string) ($registro['estabelecimento_nome'] ?? '') ?: '-') ?></td>
                <td><?= e((string) ($registro['alvo'] ?? '') ?: '-') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>
<?php require RAIZ . '/includes/painel_footer.php'; ?>
