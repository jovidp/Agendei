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
$tituloPagina = 'Visão geral';
$subtituloTopo = 'Administração global e isolamento dos estabelecimentos';
$acoesTopo = '<a class="btn btn-pequeno" href="' . url('master/estabelecimentos.php?acao=novo') . '">Novo estabelecimento</a>';
require RAIZ . '/includes/painel_header.php';
?>
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
<?php require RAIZ . '/includes/painel_footer.php'; ?>
