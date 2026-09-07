<?php
/**
 * Dashboard administrativo com indicadores e listas de acompanhamento.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/../config/config.php';

// Restringe esta página a administradores autenticados.
exigirLogin('admin');

// Consulta os totais consolidados usados nos cartões do painel administrativo.
$indicadores = Relatorio::indicadores();

$proximos = Agendamento::listar([
    'a_partir_de_hoje' => true,
    'status_em'        => ['agendado', 'confirmado'],
    'ordem'            => 'asc',
    'limite'           => 8,
]);

$inicioMes = date('Y-m-01');
$fimMes    = date('Y-m-t');

$ranking   = Relatorio::servicosMaisAgendados($inicioMes, $fimMes, 5);
$recentes  = Relatorio::agendamentosRecentes(5);
// Usa o maior resultado do ranking como referência proporcional para as barras.
$maiorTotal = $ranking !== [] ? (int) $ranking[0]['total'] : 0;

// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina  = 'Dashboard';
$subtituloTopo = dataExtenso(date('Y-m-d'));
$acoesTopo     = '<a href="' . url('admin/agendamentos.php?acao=novo') . '" class="btn btn-pequeno">Novo agendamento</a>';

// Renderiza a estrutura comum do painel após preparar os dados desta tela.
require_once RAIZ . '/includes/painel_header.php';
?>

<div class="grade-indicadores">
    <div class="indicador indicador-destaque">
        <span class="indicador-rotulo">Agendamentos hoje</span>
        <span class="indicador-valor"><?= (int) $indicadores['agendamentos_hoje'] ?></span>
        <span class="indicador-nota"><?= formatarData(date('Y-m-d')) ?></span>
    </div>
    <div class="indicador">
        <span class="indicador-rotulo">Agendamentos da semana</span>
        <span class="indicador-valor"><?= (int) $indicadores['agendamentos_semana'] ?></span>
        <span class="indicador-nota">Segunda a domingo</span>
    </div>
    <div class="indicador">
        <span class="indicador-rotulo">Clientes cadastrados</span>
        <span class="indicador-valor"><?= (int) $indicadores['clientes_total'] ?></span>
        <span class="indicador-nota"><?= (int) $indicadores['clientes_ativos'] ?> ativos</span>
    </div>
    <div class="indicador indicador-positivo">
        <span class="indicador-rotulo">Servicos realizados</span>
        <span class="indicador-valor"><?= (int) $indicadores['servicos_realizados'] ?></span>
        <span class="indicador-nota">Concluidos no mes</span>
    </div>
    <div class="indicador indicador-destaque">
        <span class="indicador-rotulo">Faturamento estimado</span>
        <span class="indicador-valor"><?= formatarMoeda($indicadores['faturamento_mes']) ?></span>
        <span class="indicador-nota"><?= formatarMoeda($indicadores['faturamento_realizado_mes']) ?> ja realizados</span>
    </div>
    <div class="indicador indicador-negativo">
        <span class="indicador-rotulo">Cancelamentos</span>
        <span class="indicador-valor"><?= (int) $indicadores['cancelamentos_mes'] ?></span>
        <span class="indicador-nota">No mes atual</span>
    </div>
</div>

<div class="grade-painel">
    <div class="cartao">
        <div class="cartao-cabecalho">
            <h3>Proximos agendamentos</h3>
            <a href="<?= url('admin/agenda.php') ?>" class="btn-texto">Ver agenda</a>
        </div>

        <?php if ($proximos === []): ?>
            <div class="estado-vazio">
                <strong>Nenhum agendamento futuro</strong>
                <p>Os proximos atendimentos aparecerao aqui.</p>
            </div>
        <?php else: ?>
            <div class="tabela-area">
                <?php /* Tabela de apresentação dos registros retornados pela consulta. */ ?><table class="tabela">
                    <thead>
                        <tr>
                            <th>Horario</th>
                            <th>Cliente</th>
                            <th>Servico</th>
                            <th>Profissional</th>
                            <th class="coluna-acoes">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($proximos as $agendamento): ?>
                            <tr>
                                <td>
                                    <span class="celula-principal"><?= formatarHora($agendamento['hora_inicio']) ?></span>
                                    <span class="celula-secundaria"><?= formatarData($agendamento['data_agendamento']) ?></span>
                                </td>
                                <td>
                                    <?= e($agendamento['nome_cliente']) ?>
                                    <span class="celula-secundaria"><?= e(formatarTelefone($agendamento['telefone_cliente'])) ?></span>
                                </td>
                                <td><?= e($agendamento['nome_servico']) ?></td>
                                <td><?= e($agendamento['nome_profissional']) ?></td>
                                <td class="coluna-acoes"><?= badgeStatus($agendamento['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div>
        <div class="cartao">
            <div class="cartao-cabecalho">
                <h3>Servicos mais agendados</h3>
                <span class="texto-pequeno texto-secundario">Mes atual</span>
            </div>

            <?php if ($ranking === []): ?>
                <div class="estado-vazio">
                    <strong>Sem dados no mes</strong>
                    <p>Nenhum agendamento registrado neste periodo.</p>
                </div>
            <?php else: ?>
                <ul class="lista-simples">
                    <?php foreach ($ranking as $posicao => $servico): ?>
                        <li>
                            <span class="posicao"><?= $posicao + 1 ?></span>
                            <div class="conteudo">
                                <strong><?= e($servico['nome']) ?></strong>
                                <span><?= (int) $servico['total'] ?> agendamento(s) &middot; <?= formatarMoeda($servico['valor_total']) ?></span>
                                <div class="barra-progresso">
                                    <div style="width: <?= $maiorTotal > 0 ? (int) round(((int) $servico['total'] / $maiorTotal) * 100) : 0 ?>%"></div>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="cartao">
            <div class="cartao-cabecalho">
                <h3>Agendamentos recentes</h3>
                <a href="<?= url('admin/agendamentos.php') ?>" class="btn-texto">Ver todos</a>
            </div>

            <?php if ($recentes === []): ?>
                <div class="estado-vazio">
                    <strong>Nenhum agendamento registrado</strong>
                    <p>Os ultimos registros criados aparecerao aqui.</p>
                </div>
            <?php else: ?>
                <ul class="lista-simples">
                    <?php foreach ($recentes as $agendamento): ?>
                        <li>
                            <div class="conteudo">
                                <strong><?= e($agendamento['nome_cliente']) ?></strong>
                                <span>
                                    <?= e($agendamento['nome_servico']) ?> &middot;
                                    <?= formatarData($agendamento['data_agendamento']) ?> as <?= formatarHora($agendamento['hora_inicio']) ?>
                                </span>
                            </div>
                            <span class="valor"><?= badgeStatus($agendamento['status']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>
