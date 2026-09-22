<?php

/**
 * Relatorios gerenciais por periodo.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/../config/config.php';

// Restringe esta página a administradores autenticados.
exigirLogin('admin');

$periodo = get('periodo');

$dataInicial = validarData(get('data_inicial')) ? get('data_inicial') : date('Y-m-01');
$dataFinal   = validarData(get('data_final')) ? get('data_final') : date('Y-m-t');

if ($periodo === 'hoje') {
    $dataInicial = $dataFinal = date('Y-m-d');
} elseif ($periodo === 'semana') {
    $dataInicial = date('Y-m-d', strtotime('monday this week'));
    $dataFinal   = date('Y-m-d', strtotime('sunday this week'));
} elseif ($periodo === 'mes') {
    $dataInicial = date('Y-m-01');
    $dataFinal   = date('Y-m-t');
} elseif ($periodo === 'mes_anterior') {
    $dataInicial = date('Y-m-01', strtotime('first day of last month'));
    $dataFinal   = date('Y-m-t', strtotime('last day of last month'));
}

// Corrige a ordem das datas para que as consultas recebam um intervalo válido.
if ($dataInicial > $dataFinal) {
    [$dataInicial, $dataFinal] = [$dataFinal, $dataInicial];
}

$resumo        = Relatorio::resumoPorStatus($dataInicial, $dataFinal);
$faturamento   = Relatorio::faturamento($dataInicial, $dataFinal);
// Separa os valores de serviços concluídos do total que também considera reservas futuras.
$realizado     = Relatorio::faturamento($dataInicial, $dataFinal, ['concluido']);
$servicos      = Relatorio::servicosMaisAgendados($dataInicial, $dataFinal, 10);
$profissionais = Relatorio::profissionaisMaisAtendimentos($dataInicial, $dataFinal, 10);
$clientes      = Relatorio::clientesMaisFrequentes($dataInicial, $dataFinal, 10);
$movimento     = Relatorio::movimentoPorDia($dataInicial, $dataFinal);
// Comparativo entre as unidades no mesmo periodo (ja ordenado por faturamento).
$faturamentoFiliais = Filial::faturamento($dataInicial, $dataFinal);

$totalPeriodo = array_sum(array_column($resumo, 'total'));
$taxaCancelamento = $totalPeriodo > 0
    ? round(($resumo['cancelado']['total'] / $totalPeriodo) * 100)
    : 0;

// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina  = 'Relatorios';
$subtituloTopo = 'Periodo de ' . formatarData($dataInicial) . ' a ' . formatarData($dataFinal);

// Renderiza a estrutura comum do painel após preparar os dados desta tela.
require_once RAIZ . '/includes/painel_header.php';
?>

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
            <button type="submit" class="btn">Aplicar periodo</button>
        </div>

        <div class="campo">
            <span class="rotulo">Atalhos</span>
            <div class="grupo-botoes">
                <a href="?periodo=hoje" class="btn btn-contorno btn-pequeno">Hoje</a>
                <a href="?periodo=semana" class="btn btn-contorno btn-pequeno">Semana</a>
                <a href="?periodo=mes" class="btn btn-contorno btn-pequeno">Mes atual</a>
                <a href="?periodo=mes_anterior" class="btn btn-contorno btn-pequeno">Mes anterior</a>
            </div>
        </div>
    </form>
</div>

<div class="grade-indicadores">
    <div class="indicador indicador-destaque">
        <span class="indicador-rotulo">Agendamentos</span>
        <span class="indicador-valor"><?= (int) $totalPeriodo ?></span>
        <span class="indicador-nota">Todos os status</span>
    </div>
    <div class="indicador indicador-positivo">
        <span class="indicador-rotulo">Concluidos</span>
        <span class="indicador-valor"><?= (int) $resumo['concluido']['total'] ?></span>
        <span class="indicador-nota"><?= formatarMoeda($realizado) ?> faturados</span>
    </div>
    <div class="indicador">
        <span class="indicador-rotulo">Faturamento previsto</span>
        <span class="indicador-valor"><?= formatarMoeda($faturamento) ?></span>
        <span class="indicador-nota">Exclui cancelamentos</span>
    </div>
    <div class="indicador indicador-negativo">
        <span class="indicador-rotulo">Cancelamentos</span>
        <span class="indicador-valor"><?= (int) $resumo['cancelado']['total'] ?></span>
        <span class="indicador-nota"><?= (int) $taxaCancelamento ?>% do periodo</span>
    </div>
</div>

<div class="grade-painel-igual">
    <div class="cartao">
        <div class="cartao-cabecalho">
            <h3>Servicos mais realizados</h3>
        </div>

        <?php if ($servicos === []): ?>
            <div class="estado-vazio"><strong>Sem dados no periodo</strong>
                <p>Nenhum agendamento registrado.</p>
            </div>
        <?php else: ?>
            <div class="tabela-area">
                <?php /* Tabela de apresentação dos registros retornados pela consulta. */ ?><table class="tabela" style="min-width:auto">
                    <thead>
                        <tr>
                            <th>Servico</th>
                            <th>Agendamentos</th>
                            <th class="coluna-acoes">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($servicos as $servico): ?>
                            <tr>
                                <td class="celula-principal"><?= e($servico['nome']) ?></td>
                                <td><?= (int) $servico['total'] ?></td>
                                <td class="coluna-acoes"><?= formatarMoeda($servico['valor_total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="cartao">
        <div class="cartao-cabecalho">
            <h3>Profissionais com mais atendimentos</h3>
        </div>

        <?php if ($profissionais === []): ?>
            <div class="estado-vazio"><strong>Sem dados no periodo</strong>
                <p>Nenhum atendimento registrado.</p>
            </div>
        <?php else: ?>
            <div class="tabela-area">
                <?php /* Tabela de apresentação dos registros retornados pela consulta. */ ?><table class="tabela" style="min-width:auto">
                    <thead>
                        <tr>
                            <th>Profissional</th>
                            <th>Total</th>
                            <th>Concluidos</th>
                            <th class="coluna-acoes">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($profissionais as $profissional): ?>
                            <tr>
                                <td class="celula-principal"><?= e($profissional['nome']) ?></td>
                                <td><?= (int) $profissional['total'] ?></td>
                                <td><?= (int) $profissional['concluidos'] ?></td>
                                <td class="coluna-acoes"><?= formatarMoeda($profissional['valor_total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="grade-painel-igual">
    <div class="cartao">
        <div class="cartao-cabecalho">
            <h3>Clientes mais frequentes</h3>
        </div>

        <?php if ($clientes === []): ?>
            <div class="estado-vazio"><strong>Sem dados no periodo</strong>
                <p>Nenhum cliente atendido.</p>
            </div>
        <?php else: ?>
            <div class="tabela-area">
                <?php /* Tabela de apresentação dos registros retornados pela consulta. */ ?><table class="tabela" style="min-width:auto">
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>Atendimentos</th>
                            <th>Ultimo</th>
                            <th class="coluna-acoes">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clientes as $cliente): ?>
                            <tr>
                                <td>
                                    <span class="celula-principal"><?= e($cliente['nome']) ?></span>
                                    <span class="celula-secundaria"><?= e(formatarTelefone($cliente['telefone'])) ?></span>
                                </td>
                                <td><?= (int) $cliente['total'] ?></td>
                                <td><?= formatarData($cliente['ultimo_atendimento']) ?></td>
                                <td class="coluna-acoes"><?= formatarMoeda($cliente['valor_total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="cartao">
        <div class="cartao-cabecalho">
            <h3>Movimento por dia</h3>
        </div>

        <?php if ($movimento === []): ?>
            <div class="estado-vazio"><strong>Sem dados no periodo</strong>
                <p>Nenhum agendamento no intervalo.</p>
            </div>
        <?php else: ?>
            <div class="tabela-area" style="max-height:420px;overflow-y:auto">
                <?php /* Tabela de apresentação dos registros retornados pela consulta. */ ?><table class="tabela" style="min-width:auto">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Agendamentos</th>
                            <th>Cancelados</th>
                            <th class="coluna-acoes">Faturamento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movimento as $dia): ?>
                            <tr>
                                <td class="celula-principal"><?= formatarData($dia['data_agendamento']) ?></td>
                                <td><?= (int) $dia['total'] ?></td>
                                <td><?= (int) $dia['cancelados'] ?></td>
                                <td class="coluna-acoes"><?= formatarMoeda($dia['valor_total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="cartao">
    <div class="cartao-cabecalho">
        <h3>Faturamento por unidade</h3>
    </div>

    <?php if ($faturamentoFiliais === []): ?>
        <div class="estado-vazio"><strong>Nenhuma unidade cadastrada</strong>
            <p>Cadastre uma filial para acompanhar o faturamento por unidade.</p>
        </div>
    <?php else: ?>
        <div class="tabela-area">
            <?php /* Tabela de apresentacao dos registros retornados pela consulta. */ ?><table class="tabela" style="min-width:auto">
                <thead>
                    <tr>
                        <th>Unidade</th>
                        <th>Atendimentos</th>
                        <th>Concluidos</th>
                        <th class="coluna-acoes">Faturamento</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($faturamentoFiliais as $posicao => $filial): ?>
                        <?php // A lista ja vem ordenada da que mais fatura para a que menos; a primeira so ganha destaque quando ha valor. ?>
                        <?php $lider = $posicao === 0 && (float) $filial['valor_total'] > 0; ?>
                        <tr>
                            <td>
                                <span class="celula-principal"><?= e($filial['nome']) ?></span>
                                <?php if ($lider): ?>
                                    <span class="badge badge-ativo">Maior faturamento</span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $filial['atendimentos'] ?></td>
                            <td><?= (int) $filial['concluidos'] ?></td>
                            <td class="coluna-acoes">
                                <?php if ($lider): ?>
                                    <strong><?= formatarMoeda($filial['valor_total']) ?></strong>
                                <?php else: ?>
                                    <?= formatarMoeda($filial['valor_total']) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>