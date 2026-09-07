<?php

/**
 * Historico de atendimentos anteriores do cliente.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/../config/config.php';

// Restringe esta página a clientes autenticados.
exigirLogin('cliente');

// Usa o perfil da sessão para consultar e alterar os dados do próprio cliente.
$idCliente = perfilId();

$dataInicial = get('data_inicial');
$dataFinal   = get('data_final');
$porPagina   = 10;
// Mantém a página como inteiro positivo para calcular a listagem e sua navegação.
$pagina      = max(1, (int) get('pagina', '1'));

// Reúne os critérios usados para consultar a lista e calcular os totais.
$filtros = [
    'id_cliente' => $idCliente,
    'ate_hoje'   => true,
    'ordem'      => 'desc',
];

// Aplica os limites de data somente quando o formato recebido é válido.
if (validarData($dataInicial)) {
    $filtros['data_inicial'] = $dataInicial;
}
if (validarData($dataFinal)) {
    $filtros['data_final'] = $dataFinal;
}

$total   = Agendamento::contar($filtros);
$lista   = Agendamento::listar($filtros + [
    'limite'       => $porPagina,
    'deslocamento' => ($pagina - 1) * $porPagina,
]);

$concluidos = Agendamento::contar($filtros + ['status' => 'concluido']);
$cancelados = Agendamento::contar($filtros + ['status' => 'cancelado']);

$valorTotal = 0.0;
foreach (Agendamento::listar($filtros + ['status' => 'concluido']) as $registro) {
    $valorTotal += (float) $registro['valor'];
}

// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina  = 'Historico';
$subtituloTopo = 'Atendimentos ja realizados';

// Renderiza a estrutura comum do painel após preparar os dados desta tela.
require_once RAIZ . '/includes/painel_header.php';
?>

<div class="grade-indicadores">
    <div class="indicador">
        <span class="indicador-rotulo">Atendimentos no periodo</span>
        <span class="indicador-valor"><?= (int) $total ?></span>
        <span class="indicador-nota">Todos os status</span>
    </div>
    <div class="indicador indicador-positivo">
        <span class="indicador-rotulo">Concluidos</span>
        <span class="indicador-valor"><?= (int) $concluidos ?></span>
        <span class="indicador-nota">Servicos realizados</span>
    </div>
    <div class="indicador">
        <span class="indicador-rotulo">Cancelados</span>
        <span class="indicador-valor"><?= (int) $cancelados ?></span>
        <span class="indicador-nota">No periodo filtrado</span>
    </div>
    <div class="indicador indicador-destaque">
        <span class="indicador-rotulo">Total investido</span>
        <span class="indicador-valor"><?= formatarMoeda($valorTotal) ?></span>
        <span class="indicador-nota">Somente atendimentos concluidos</span>
    </div>
</div>

<div class="cartao">
    <?php /* Filtros enviados na URL para permitir atualizar e compartilhar a consulta. */ ?><form method="get" class="barra-filtros">
        <input type="hidden" name="estabelecimento" value="<?= e(Contexto::slug()) ?>">
        <div class="campo">
            <label for="data_inicial">De</label>
            <input type="date" id="data_inicial" name="data_inicial" value="<?= e($dataInicial) ?>">
        </div>
        <div class="campo">
            <label for="data_final">Ate</label>
            <input type="date" id="data_final" name="data_final" value="<?= e($dataFinal) ?>">
        </div>
        <div class="campo">
            <button type="submit" class="btn">Filtrar</button>
        </div>
        <?php if ($dataInicial !== '' || $dataFinal !== ''): ?>
            <div class="campo">
                <a href="<?= url('cliente/historico.php') ?>" class="btn btn-contorno">Limpar</a>
            </div>
        <?php endif; ?>
    </form>

    <?php if ($lista === []): ?>
        <div class="estado-vazio">
            <strong>Nenhum atendimento no periodo</strong>
            <p>Ajuste o filtro de datas ou agende um novo atendimento.</p>
        </div>
    <?php else: ?>
        <div class="tabela-area">
            <?php /* Tabela de apresentação dos registros retornados pela consulta. */ ?><table class="tabela">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Servico</th>
                        <th>Profissional</th>
                        <th>Horario</th>
                        <th>Status</th>
                        <th class="coluna-acoes">Valor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista as $agendamento): ?>
                        <tr>
                            <td class="celula-principal"><?= formatarData($agendamento['data_agendamento']) ?></td>
                            <td>
                                <?= e($agendamento['nome_servico']) ?>
                                <?php if (!empty($agendamento['observacao'])): ?>
                                    <span class="celula-secundaria"><?= e(limitarTexto($agendamento['observacao'], 60)) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($agendamento['nome_profissional']) ?></td>
                            <td><?= formatarHora($agendamento['hora_inicio']) ?></td>
                            <td><?= badgeStatus($agendamento['status']) ?></td>
                            <td class="coluna-acoes"><?= formatarMoeda($agendamento['valor']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= renderizarPaginacao($total, $pagina, $porPagina, array_filter([
            'data_inicial' => $dataInicial,
            'data_final'   => $dataFinal,
        ])) ?>
    <?php endif; ?>
</div>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>