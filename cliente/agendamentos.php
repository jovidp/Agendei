<?php
/**
 * Lista de agendamentos do cliente, com detalhes e cancelamento.
 */
require_once __DIR__ . '/../config/config.php';

exigirLogin('cliente');

$idCliente = perfilId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();

    $idAgendamento = (int) post('id_agendamento');
    $agendamento   = Agendamento::porId($idAgendamento);

    if (!$agendamento || (int) $agendamento['id_cliente'] !== $idCliente) {
        definirFlash('erro', 'Agendamento nao encontrado.');
    } elseif (!Agendamento::clientePodeCancelar($agendamento)) {
        $limite = Configuracao::obterInteiro('cancelamento_limite_horas', 4);
        definirFlash('erro', "Este agendamento nao pode mais ser cancelado pelo painel (limite de {$limite}h antes do atendimento). Entre em contato com o estabelecimento.");
    } else {
        Agendamento::cancelar($idAgendamento, usuarioId(), limitarTexto(post('motivo'), 200));
        definirFlash('sucesso', 'Agendamento cancelado.');
    }

    redirecionar('cliente/agendamentos.php');
}

$status      = get('status');
$porPagina   = 10;
$pagina      = max(1, (int) get('pagina', '1'));

$filtros = [
    'id_cliente' => $idCliente,
    'ordem'      => 'desc',
];

if (in_array($status, Agendamento::STATUS, true)) {
    $filtros['status'] = $status;
}

$total        = Agendamento::contar($filtros);
$agendamentos = Agendamento::listar($filtros + [
    'limite'       => $porPagina,
    'deslocamento' => ($pagina - 1) * $porPagina,
]);

$limiteCancelamento = Configuracao::obterInteiro('cancelamento_limite_horas', 4);

$tituloPagina  = 'Meus agendamentos';
$subtituloTopo = 'Acompanhe e gerencie seus atendimentos';
$acoesTopo     = '<a href="' . url('cliente/agendar.php') . '" class="btn btn-pequeno">Novo agendamento</a>';

require_once RAIZ . '/includes/painel_header.php';
?>

<div class="cartao">
    <div class="barra-filtros">
        <form method="get" class="barra-filtros" style="padding:0;border:0;background:none;flex:1">
            <div class="campo campo-busca">
                <label for="busca">Buscar</label>
                <input type="search" id="busca" placeholder="Servico ou profissional"
                       data-busca-tabela="#tabelaAgendamentos" data-busca-vazio="#semResultado">
            </div>

            <div class="campo">
                <label for="status">Status</label>
                <select id="status" name="status" data-envia-ao-mudar>
                    <option value="">Todos</option>
                    <?php foreach (Agendamento::STATUS as $opcao): ?>
                        <option value="<?= e($opcao) ?>" <?= $status === $opcao ? 'selected' : '' ?>>
                            <?= e(ucfirst($opcao)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>

    <?php if ($agendamentos === []): ?>
        <div class="estado-vazio">
            <strong>Nenhum agendamento encontrado</strong>
            <p><?= $status !== '' ? 'Nenhum agendamento com este status.' : 'Voce ainda nao possui agendamentos.' ?></p>
            <a href="<?= url('cliente/agendar.php') ?>" class="btn margem-topo">Agendar agora</a>
        </div>
    <?php else: ?>
        <div class="tabela-area">
            <table class="tabela" id="tabelaAgendamentos">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Servico</th>
                        <th>Profissional</th>
                        <th>Horario</th>
                        <th>Status</th>
                        <th>Valor</th>
                        <th class="coluna-acoes">Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agendamentos as $agendamento): ?>
                        <tr data-linha>
                            <td class="celula-principal"><?= formatarData($agendamento['data_agendamento']) ?></td>
                            <td><?= e($agendamento['nome_servico']) ?></td>
                            <td><?= e($agendamento['nome_profissional']) ?></td>
                            <td>
                                <?= formatarHora($agendamento['hora_inicio']) ?>
                                <span class="celula-secundaria">ate <?= formatarHora($agendamento['hora_fim']) ?></span>
                            </td>
                            <td><?= badgeStatus($agendamento['status']) ?></td>
                            <td><?= formatarMoeda($agendamento['valor']) ?></td>
                            <td class="coluna-acoes">
                                <button type="button" class="btn btn-contorno btn-pequeno"
                                        data-modal="modalDetalhes"
                                        data-campo-servico="<?= e($agendamento['nome_servico']) ?>"
                                        data-campo-profissional="<?= e($agendamento['nome_profissional']) ?>"
                                        data-campo-data="<?= e(dataExtenso($agendamento['data_agendamento'])) ?>"
                                        data-campo-horario="<?= formatarHora($agendamento['hora_inicio']) ?> as <?= formatarHora($agendamento['hora_fim']) ?>"
                                        data-campo-duracao="<?= e(duracaoTexto((int) $agendamento['duracao_minutos'])) ?>"
                                        data-campo-valor="<?= formatarMoeda($agendamento['valor']) ?>"
                                        data-campo-status="<?= e(ucfirst($agendamento['status'])) ?>"
                                        data-campo-observacao="<?= e($agendamento['observacao'] ?: 'Sem observacoes.') ?>">
                                    Detalhes
                                </button>

                                <?php if (Agendamento::clientePodeCancelar($agendamento)): ?>
                                    <button type="button" class="btn btn-perigo btn-pequeno"
                                            data-modal="modalCancelar"
                                            data-campo-id_agendamento="<?= (int) $agendamento['id_agendamento'] ?>"
                                            data-campo-resumo="<?= e($agendamento['nome_servico']) ?> em <?= formatarData($agendamento['data_agendamento']) ?> as <?= formatarHora($agendamento['hora_inicio']) ?>">
                                        Cancelar
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="semResultado" class="estado-vazio oculto">
            <strong>Nenhum resultado para a busca</strong>
            <p>Tente outro termo.</p>
        </div>

        <?= renderizarPaginacao($total, $pagina, $porPagina, array_filter(['status' => $status])) ?>
    <?php endif; ?>
</div>

<p class="texto-pequeno texto-secundario">
    Cancelamentos pelo painel sao permitidos ate <?= (int) $limiteCancelamento ?>h antes do horario do atendimento.
</p>

<!-- Modal de detalhes -->
<div class="modal" id="modalDetalhes">
    <div class="modal-caixa">
        <div class="modal-cabecalho">
            <h3>Detalhes do agendamento</h3>
            <button type="button" class="modal-fechar" data-fechar-modal aria-label="Fechar">&times;</button>
        </div>
        <div class="modal-corpo">
            <dl class="lista-detalhes">
                <div><dt>Servico</dt><dd data-preenche="servico">-</dd></div>
                <div><dt>Profissional</dt><dd data-preenche="profissional">-</dd></div>
                <div><dt>Data</dt><dd data-preenche="data">-</dd></div>
                <div><dt>Horario</dt><dd data-preenche="horario">-</dd></div>
                <div><dt>Duracao</dt><dd data-preenche="duracao">-</dd></div>
                <div><dt>Valor</dt><dd data-preenche="valor">-</dd></div>
                <div><dt>Status</dt><dd data-preenche="status">-</dd></div>
                <div><dt>Observacao</dt><dd data-preenche="observacao">-</dd></div>
            </dl>
        </div>
        <div class="modal-rodape">
            <button type="button" class="btn btn-contorno" data-fechar-modal>Fechar</button>
        </div>
    </div>
</div>

<!-- Modal de cancelamento -->
<div class="modal" id="modalCancelar">
    <form method="post" class="modal-caixa">
        <?= campoCsrf() ?>
        <input type="hidden" name="id_agendamento" value="">

        <div class="modal-cabecalho">
            <h3>Cancelar agendamento</h3>
            <button type="button" class="modal-fechar" data-fechar-modal aria-label="Fechar">&times;</button>
        </div>

        <div class="modal-corpo">
            <p>Deseja realmente cancelar o agendamento <strong data-preenche="resumo"></strong>?</p>

            <div class="campo">
                <label for="motivo">Motivo (opcional)</label>
                <input type="text" id="motivo" name="motivo" maxlength="200" placeholder="Ex.: imprevisto no trabalho">
            </div>
        </div>

        <div class="modal-rodape">
            <button type="button" class="btn btn-contorno" data-fechar-modal>Voltar</button>
            <button type="submit" class="btn btn-perigo">Confirmar cancelamento</button>
        </div>
    </form>
</div>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>
