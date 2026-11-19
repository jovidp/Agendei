<?php

/**
 * Dashboard administrativo: indicadores com variacao em relacao ao periodo
 * anterior, movimento dos ultimos 14 dias, atalhos e listas de acompanhamento.
 * Todos os numeros vem do banco (Relatorio); nada aqui e ilustrativo.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/../config/config.php';

// Restringe esta página a administradores autenticados.
exigirLogin('admin');
require_once RAIZ . '/includes/icones.php';

// Consulta os totais consolidados usados nos cartões do painel administrativo.
$indicadores = Relatorio::indicadores();
$comparativo = Relatorio::comparativos();
$serie       = Relatorio::serieDiaria(14);

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

/**
 * Selo de variacao (seta + percentual + "vs. ..."). Sem base no periodo
 * anterior nao ha percentual honesto: mostra so a comparacao em texto.
 */
function seloVariacao(float $atual, float $anterior, string $referencia, bool $menorEhMelhor = false): string
{
    if ($anterior <= 0) {
        return $atual > 0 ? '<span class="indicador-nota">Sem movimento ' . e($referencia) . '</span>' : '';
    }
    $percentual = (int) round((($atual - $anterior) / $anterior) * 100);
    if ($percentual === 0) {
        return '<span class="variacao">0%</span><span class="indicador-nota">' . e($referencia) . '</span>';
    }
    $subiu = $percentual > 0;
    $bom = $subiu !== $menorEhMelhor;
    return '<span class="variacao ' . ($bom ? 'variacao-boa' : 'variacao-ruim') . '">'
        . icone($subiu ? 'subir' : 'descer')
        . '<span class="sr-only">' . ($subiu ? 'Aumento de' : 'Queda de') . '</span>'
        . abs($percentual) . '%</span><span class="indicador-nota">' . e($referencia) . '</span>';
}

$hora = (int) date('G');
$saudacao = $hora < 12 ? 'Bom dia' : ($hora < 18 ? 'Boa tarde' : 'Boa noite');
$primeiroNome = explode(' ', trim(usuarioNome()))[0] ?? '';
$maiorDia = max(array_column($serie, 'total') ?: [0]);
$totalPeriodo = array_sum(array_column($serie, 'total'));

$atalhos = [
    ['rotulo' => 'Novo agendamento', 'descricao' => 'Marcar para um cliente', 'arquivo' => 'admin/agendamentos.php?acao=novo', 'icone' => 'novo'],
    ['rotulo' => 'Agenda do dia', 'descricao' => 'Horários por profissional', 'arquivo' => 'admin/agenda.php', 'icone' => 'agenda'],
    ['rotulo' => 'Clientes', 'descricao' => (int) $indicadores['clientes_ativos'] . ' ativos', 'arquivo' => 'admin/clientes.php', 'icone' => 'clientes'],
    ['rotulo' => 'Serviços', 'descricao' => (int) $indicadores['servicos_ativos'] . ' no catálogo', 'arquivo' => 'admin/servicos.php', 'icone' => 'servicos'],
    ['rotulo' => 'Profissionais', 'descricao' => (int) $indicadores['profissionais_ativos'] . ' ativos', 'arquivo' => 'admin/profissionais.php', 'icone' => 'profissionais'],
    ['rotulo' => 'Relatórios', 'descricao' => 'Faturamento e desempenho', 'arquivo' => 'admin/relatorios.php', 'icone' => 'relatorios'],
];

// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina  = 'Dashboard';
$acoesTopo     = '<a href="' . url('admin/agenda.php') . '" class="btn btn-contorno btn-pequeno">Ver agenda</a>'
    . '<a href="' . url('admin/agendamentos.php?acao=novo') . '" class="btn btn-pequeno">Novo agendamento</a>';

// Renderiza a estrutura comum do painel após preparar os dados desta tela.
require_once RAIZ . '/includes/painel_header.php';
?>

<div class="inicio-cabecalho">
    <div>
        <h2><?= e($saudacao) ?><?= $primeiroNome !== '' ? ', ' . e($primeiroNome) : '' ?></h2>
        <p><?= e(dataExtenso(date('Y-m-d'))) ?> · Resumo de <?= e($estabelecimento['nome']) ?></p>
    </div>
</div>

<div class="grade-indicadores grade-kpis">
    <div class="indicador">
        <div class="indicador-topo"><span class="indicador-icone"><?= icone('agenda') ?></span><span class="indicador-rotulo">Agendamentos hoje</span></div>
        <span class="indicador-valor"><?= (int) $indicadores['agendamentos_hoje'] ?></span>
        <div class="indicador-rodape"><span class="indicador-nota">Agendados, confirmados e concluídos</span></div>
    </div>
    <div class="indicador">
        <div class="indicador-topo"><span class="indicador-icone"><?= icone('agendamentos') ?></span><span class="indicador-rotulo">Agendamentos da semana</span></div>
        <span class="indicador-valor"><?= (int) $indicadores['agendamentos_semana'] ?></span>
        <div class="indicador-rodape"><?= seloVariacao($comparativo['semana_atual'], $comparativo['semana_anterior'], 'vs. semana passada') ?: '<span class="indicador-nota">Segunda a domingo</span>' ?></div>
    </div>
    <div class="indicador">
        <div class="indicador-topo"><span class="indicador-icone"><?= icone('clientes') ?></span><span class="indicador-rotulo">Clientes cadastrados</span></div>
        <span class="indicador-valor"><?= (int) $indicadores['clientes_total'] ?></span>
        <div class="indicador-rodape"><span class="indicador-nota"><?= (int) $indicadores['clientes_ativos'] ?> ativos</span></div>
    </div>
    <div class="indicador">
        <div class="indicador-topo"><span class="indicador-icone"><?= icone('concluido') ?></span><span class="indicador-rotulo">Serviços realizados</span></div>
        <span class="indicador-valor"><?= (int) $indicadores['servicos_realizados'] ?></span>
        <div class="indicador-rodape"><?= seloVariacao($comparativo['concluidos_atual'], $comparativo['concluidos_anterior'], 'vs. mês passado') ?: '<span class="indicador-nota">Concluídos no mês</span>' ?></div>
    </div>
    <div class="indicador">
        <div class="indicador-topo">
            <span class="indicador-icone"><?= icone('dinheiro') ?></span>
            <span class="indicador-rotulo">Faturamento estimado
                <button type="button" class="dica" aria-describedby="dica-faturamento">?<span class="dica-texto" role="tooltip" id="dica-faturamento">Soma dos agendamentos do mês que não foram cancelados, inclusive os que ainda vão acontecer. A variação compara só o valor já realizado.</span></button>
            </span>
        </div>
        <span class="indicador-valor"><?= formatarMoeda($indicadores['faturamento_mes']) ?></span>
        <div class="indicador-rodape">
            <span class="indicador-nota"><?= formatarMoeda($indicadores['faturamento_realizado_mes']) ?> realizados</span>
            <?= seloVariacao($comparativo['realizado_atual'], $comparativo['realizado_anterior'], 'vs. mês passado') ?>
        </div>
    </div>
    <div class="indicador">
        <div class="indicador-topo"><span class="indicador-icone"><?= icone('cancelado') ?></span><span class="indicador-rotulo">Cancelamentos</span></div>
        <span class="indicador-valor"><?= (int) $indicadores['cancelamentos_mes'] ?></span>
        <div class="indicador-rodape"><?= seloVariacao($comparativo['cancelados_atual'], $comparativo['cancelados_anterior'], 'vs. mês passado', true) ?: '<span class="indicador-nota">No mês atual</span>' ?></div>
    </div>
</div>

<div class="grade-inicio">
    <section class="cartao" aria-labelledby="titulo-movimento">
        <div class="cartao-cabecalho">
            <div>
                <h3 id="titulo-movimento">Agendamentos por dia</h3>
                <span class="texto-pequeno texto-secundario">Últimos 14 dias, sem os cancelados · <?= $totalPeriodo ?> no período</span>
            </div>
            <a href="<?= url('admin/relatorios.php') ?>" class="btn-texto">Relatórios</a>
        </div>
        <?php if ($totalPeriodo === 0): ?>
            <div class="estado-vazio">
                <span class="estado-vazio-icone"><?= icone('relatorios') ?></span>
                <strong>Sem agendamentos nos últimos 14 dias</strong>
                <p>Quando houver movimento, o gráfico mostra o volume de cada dia.</p>
            </div>
        <?php else: ?>
            <div class="grafico-barras" role="group" aria-label="Agendamentos por dia nos últimos 14 dias">
                <div class="grafico-eixo" aria-hidden="true"><span><?= $maiorDia ?></span><span><?= intdiv($maiorDia, 2) ?: '' ?></span><span>0</span></div>
                <div class="grafico-colunas">
                    <?php foreach ($serie as $dia): $altura = $maiorDia > 0 ? round($dia['total'] / $maiorDia * 100, 1) : 0; $ehHoje = $dia['data'] === date('Y-m-d'); ?>
                        <div class="grafico-coluna<?= $ehHoje ? ' hoje' : '' ?>" tabindex="0"
                             aria-label="<?= e(formatarData($dia['data'])) ?>: <?= $dia['total'] ?> agendamento(s), <?= e(formatarMoeda($dia['valor'])) ?>">
                            <span class="grafico-barra" style="height: <?= $altura ?>%"></span>
                            <span class="grafico-dica" aria-hidden="true"><strong><?= e(diaSemanaNome((int) date('w', strtotime($dia['data'])))) ?>, <?= e(formatarData($dia['data'])) ?></strong><?= $dia['total'] ?> agendamento(s)<br><?= e(formatarMoeda($dia['valor'])) ?></span>
                            <span class="grafico-rotulo" aria-hidden="true"><?= $ehHoje ? 'Hoje' : e(date('d/m', strtotime($dia['data']))) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <details class="grafico-tabela">
                <summary>Ver como tabela</summary>
                <div class="tabela-area">
                    <table class="tabela">
                        <thead><tr><th>Dia</th><th>Agendamentos</th><th>Valor</th></tr></thead>
                        <tbody>
                            <?php foreach (array_reverse($serie) as $dia): ?>
                                <tr><td><?= e(formatarData($dia['data'])) ?></td><td><?= $dia['total'] ?></td><td><?= e(formatarMoeda($dia['valor'])) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php endif; ?>
    </section>

    <section class="cartao" aria-labelledby="titulo-atalhos">
        <div class="cartao-cabecalho"><h3 id="titulo-atalhos">Acesso rápido</h3></div>
        <ul class="atalhos">
            <?php foreach ($atalhos as $atalho): ?>
                <li>
                    <a href="<?= url($atalho['arquivo']) ?>">
                        <span class="indicador-icone"><?= icone($atalho['icone']) ?></span>
                        <span class="atalho-texto"><strong><?= e($atalho['rotulo']) ?></strong><span><?= e($atalho['descricao']) ?></span></span>
                        <?= icone('avancar', 'sidebar-icone atalho-seta') ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>

<div class="grade-painel">
    <section class="cartao" aria-labelledby="titulo-proximos">
        <div class="cartao-cabecalho">
            <h3 id="titulo-proximos">Próximos agendamentos</h3>
            <a href="<?= url('admin/agenda.php') ?>" class="btn-texto">Ver agenda</a>
        </div>

        <?php if ($proximos === []): ?>
            <div class="estado-vazio">
                <span class="estado-vazio-icone"><?= icone('agenda') ?></span>
                <strong>Nenhum agendamento futuro</strong>
                <p>Os próximos atendimentos aparecerão aqui.</p>
                <a href="<?= url('admin/agendamentos.php?acao=novo') ?>" class="btn btn-contorno btn-pequeno">Criar agendamento</a>
            </div>
        <?php else: ?>
            <div class="tabela-area">
                <?php /* Tabela de apresentação dos registros retornados pela consulta. */ ?><table class="tabela">
                    <thead>
                        <tr>
                            <th>Horário</th>
                            <th>Cliente</th>
                            <th>Serviço</th>
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
    </section>

    <div>
        <section class="cartao" aria-labelledby="titulo-ranking">
            <div class="cartao-cabecalho">
                <h3 id="titulo-ranking">Serviços mais agendados</h3>
                <span class="texto-pequeno texto-secundario">Mês atual</span>
            </div>

            <?php if ($ranking === []): ?>
                <div class="estado-vazio">
                    <span class="estado-vazio-icone"><?= icone('servicos') ?></span>
                    <strong>Sem dados no mês</strong>
                    <p>Nenhum agendamento registrado neste período.</p>
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
        </section>

        <section class="cartao" aria-labelledby="titulo-recentes">
            <div class="cartao-cabecalho">
                <h3 id="titulo-recentes">Atividade recente</h3>
                <a href="<?= url('admin/agendamentos.php') ?>" class="btn-texto">Ver todos</a>
            </div>

            <?php if ($recentes === []): ?>
                <div class="estado-vazio">
                    <span class="estado-vazio-icone"><?= icone('historico') ?></span>
                    <strong>Nenhum agendamento registrado</strong>
                    <p>Os últimos registros criados aparecerão aqui.</p>
                </div>
            <?php else: ?>
                <ul class="lista-simples lista-atividade">
                    <?php foreach ($recentes as $agendamento): ?>
                        <li>
                            <span class="avatar avatar-pequeno" aria-hidden="true"><?= e(iniciais($agendamento['nome_cliente'])) ?></span>
                            <div class="conteudo">
                                <strong><?= e($agendamento['nome_cliente']) ?></strong>
                                <span>
                                    <?= e($agendamento['nome_servico']) ?> &middot;
                                    <?= formatarData($agendamento['data_agendamento']) ?> às <?= formatarHora($agendamento['hora_inicio']) ?>
                                </span>
                            </div>
                            <span class="valor"><?= badgeStatus($agendamento['status']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>
