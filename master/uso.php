<?php
/**
 * Uso da plataforma por estabelecimento.
 *
 * A tela de estabelecimentos conta cadastro: clientes, profissionais,
 * servicos. Isso nao diz se a empresa esta viva — uma base cadastrada e
 * abandonada continua parecendo grande. Aqui o que conta e movimento:
 * agendamentos criados na janela, clientes novos e o ultimo acesso da equipe.
 *
 * E a partir daqui que se enxerga quem esta prestes a sair sem avisar.
 */
define('AREA_MASTER', true);
require_once __DIR__ . '/../config/config.php';
exigirLogin('master');

// Janelas curtas mostram o agora; 90 dias mostra tendencia. Fora da lista, cai
// no padrao: o valor vira consulta ao banco e nao pode vir solto da URL.
$janelas = [7 => '7 dias', 30 => '30 dias', 90 => '90 dias'];
$dias = (int) get('dias', '30');
if (!isset($janelas[$dias])) {
    $dias = 30;
}

$empresas = Estabelecimento::uso($dias);
$agora = time();

$ativas = 0;
$comMovimento = 0;
$paradas = [];
$totalAgendamentos = 0;

foreach ($empresas as $empresa) {
    if ($empresa['status'] === 'ativo') {
        $ativas++;
    }

    $totalAgendamentos += (int) $empresa['agendamentos_periodo'];

    if ((int) $empresa['agendamentos_periodo'] > 0) {
        $comMovimento++;
    } elseif ($empresa['status'] === 'ativo') {
        // Empresa ligada e sem nenhum agendamento na janela: e o caso que
        // merece um telefonema antes de virar cancelamento.
        $paradas[] = $empresa;
    }
}

/** Dias inteiros desde a data informada, ou null quando nunca houve acesso. */
$diasDesde = static function (?string $dataHora) use ($agora): ?int {
    if (empty($dataHora)) {
        return null;
    }
    return (int) floor(($agora - strtotime((string) $dataHora)) / 86400);
};

$tituloPagina  = 'Uso da plataforma';
$subtituloTopo = 'Movimento de cada estabelecimento nos ultimos ' . $janelas[$dias];
require RAIZ . '/includes/painel_header.php';
?>
<div class="grade-indicadores">
    <div class="indicador indicador-destaque">
        <span class="indicador-rotulo">Empresas com movimento</span>
        <strong class="indicador-valor"><?= $comMovimento ?></strong>
        <span class="indicador-nota">de <?= $ativas ?> ativa(s)</span>
    </div>
    <div class="indicador">
        <span class="indicador-rotulo">Ativas e paradas</span>
        <strong class="indicador-valor"><?= count($paradas) ?></strong>
        <span class="indicador-nota">Sem nenhum agendamento na janela</span>
    </div>
    <div class="indicador">
        <span class="indicador-rotulo">Agendamentos criados</span>
        <strong class="indicador-valor"><?= $totalAgendamentos ?></strong>
        <span class="indicador-nota">Somando toda a plataforma</span>
    </div>
    <div class="indicador">
        <span class="indicador-rotulo">Estabelecimentos</span>
        <strong class="indicador-valor"><?= count($empresas) ?></strong>
        <span class="indicador-nota"><?= count($empresas) - $ativas ?> inativo(s)</span>
    </div>
</div>

<div class="cartao">
    <div class="cartao-cabecalho">
        <h3>Movimento por estabelecimento</h3>
        <div class="grupo-botoes">
            <?php foreach ($janelas as $valor => $rotulo): ?>
                <a class="btn btn-pequeno <?= $dias === $valor ? '' : 'btn-contorno' ?>"
                   href="<?= url('master/uso.php?dias=' . $valor) ?>"><?= e($rotulo) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($empresas === []): ?>
        <div class="estado-vazio">
            <strong>Nenhum estabelecimento cadastrado.</strong>
            <p>Os numeros aparecem assim que a primeira empresa comecar a usar o sistema.</p>
        </div>
    <?php else: ?>
        <div class="tabela-area">
            <table class="tabela">
                <thead>
                    <tr>
                        <th>Estabelecimento</th>
                        <th>Agendamentos (<?= e($janelas[$dias]) ?>)</th>
                        <th>Cancelados</th>
                        <th>Clientes novos</th>
                        <th>Total acumulado</th>
                        <th>Ultimo acesso</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($empresas as $empresa): ?>
                    <?php $ociosa = $diasDesde($empresa['ultimo_acesso']); ?>
                    <tr>
                        <td class="celula-principal">
                            <?= e($empresa['nome']) ?>
                            <span class="celula-secundaria"><?= e($empresa['slug']) ?></span>
                        </td>
                        <td><strong><?= (int) $empresa['agendamentos_periodo'] ?></strong></td>
                        <td><?= (int) $empresa['cancelados_periodo'] ?></td>
                        <td><?= (int) $empresa['clientes_novos'] ?></td>
                        <td>
                            <?= (int) $empresa['agendamentos_total'] ?> agendamento(s)
                            <span class="celula-secundaria"><?= (int) $empresa['clientes_total'] ?> cliente(s)</span>
                        </td>
                        <td>
                            <?php if ($ociosa === null): ?>
                                <span class="celula-secundaria">nunca entrou</span>
                            <?php else: ?>
                                <?= $ociosa === 0 ? 'hoje' : 'há ' . $ociosa . ' dia(s)' ?>
                            <?php endif; ?>
                        </td>
                        <td><?= badgeStatus($empresa['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($paradas !== []): ?>
<div class="cartao">
    <div class="cartao-cabecalho">
        <h3>Ativas, porém sem movimento</h3>
        <small>Nenhum agendamento criado nos ultimos <?= e($janelas[$dias]) ?></small>
    </div>
    <div class="tabela-area">
        <table class="tabela">
            <thead><tr><th>Estabelecimento</th><th>Ultimo acesso</th><th>Base cadastrada</th><th class="coluna-acoes">Ação</th></tr></thead>
            <tbody>
            <?php foreach ($paradas as $empresa): ?>
                <?php $ociosa = $diasDesde($empresa['ultimo_acesso']); ?>
                <tr>
                    <td class="celula-principal"><?= e($empresa['nome']) ?><span class="celula-secundaria"><?= e($empresa['slug']) ?></span></td>
                    <td><?= $ociosa === null ? 'nunca entrou' : ($ociosa === 0 ? 'hoje' : 'há ' . $ociosa . ' dia(s)') ?></td>
                    <td><?= (int) $empresa['clientes_total'] ?> cliente(s), <?= (int) $empresa['agendamentos_total'] ?> agendamento(s)</td>
                    <td class="coluna-acoes">
                        <a class="btn btn-contorno btn-pequeno"
                           href="<?= url('master/estabelecimentos.php?acao=ver&id=' . (int) $empresa['id_estabelecimento']) ?>">Abrir</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require RAIZ . '/includes/painel_footer.php'; ?>
