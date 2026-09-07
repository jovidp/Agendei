<?php
/**
 * Agenda do profissional: visao por dia (com acoes) ou por semana.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/../config/config.php';

// Restringe esta página a profissionais autenticados.
exigirLogin('profissional');

// Obtém o profissional da sessão para restringir os dados à sua própria agenda.
$idProfissional = perfilId();

// Processa o formulário enviado antes de montar o HTML da página.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Confere o token da sessão antes de aceitar alterações enviadas pelo formulário.
    exigirCsrf();

    $idAgendamento = (int) post('id_agendamento');
    $agendamento   = Agendamento::porId($idAgendamento);
    $novoStatus    = post('status');
    $retorno       = 'profissional/agenda.php?' . http_build_query(array_filter([
        'visao' => get('visao'),
        'data'  => get('data'),
    ]));

    // Confere a propriedade da reserva antes de permitir uma ação do profissional.
    if (!$agendamento || (int) $agendamento['id_profissional'] !== $idProfissional) {
        definirFlash('erro', 'Agendamento nao encontrado na sua agenda.');
    } elseif (!in_array($novoStatus, ['confirmado', 'concluido'], true)) {
        definirFlash('erro', 'Acao invalida.');
    } elseif ($novoStatus === 'concluido' && !Agendamento::jaComecou($agendamento)) {
        definirFlash('erro', 'O atendimento so pode ser concluido a partir do horario de inicio.');
    } else {
        Agendamento::alterarStatus($idAgendamento, $novoStatus);
        definirFlash('sucesso', $novoStatus === 'confirmado' ? 'Atendimento confirmado.' : 'Atendimento concluido.');
    }

    redirecionar($retorno);
}

$visao    = get('visao') === 'semana' ? 'semana' : 'dia';
$dataBase = validarData(get('data')) ? get('data') : date('Y-m-d');

$inicioPeriodo = $visao === 'semana'
    ? date('Y-m-d', strtotime('monday this week', strtotime($dataBase)))
    : $dataBase;

$fimPeriodo = $visao === 'semana'
    ? date('Y-m-d', strtotime($inicioPeriodo . ' +6 days'))
    : $dataBase;

$agendamentos = Agendamento::doPeriodo($inicioPeriodo, $fimPeriodo, $idProfissional);
$bloqueios    = Bloqueio::listar([
    'id_profissional' => $idProfissional,
    'data_inicial'    => $inicioPeriodo,
    'data_final'      => $fimPeriodo,
]);

$parametros   = ['visao' => $visao];
$dataAnterior = date('Y-m-d', strtotime($visao === 'semana' ? $inicioPeriodo . ' -7 days' : $dataBase . ' -1 day'));
$dataProxima  = date('Y-m-d', strtotime($visao === 'semana' ? $inicioPeriodo . ' +7 days' : $dataBase . ' +1 day'));

$rotuloPeriodo = $visao === 'semana'
    ? formatarData($inicioPeriodo) . ' a ' . formatarData($fimPeriodo)
    : dataExtenso($dataBase);

// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina  = 'Minha agenda';
$subtituloTopo = count($agendamentos) . ' atendimento(s) no periodo';

// Renderiza a estrutura comum do painel após preparar os dados desta tela.
require_once RAIZ . '/includes/painel_header.php';
?>

<div class="cartao">
    <div class="agenda-barra">
        <div class="agenda-navegacao">
            <a href="?<?= e(http_build_query($parametros + ['data' => $dataAnterior])) ?>"
               class="btn btn-contorno btn-pequeno" aria-label="Periodo anterior">&lsaquo;</a>
            <a href="?<?= e(http_build_query($parametros + ['data' => date('Y-m-d')])) ?>"
               class="btn btn-contorno btn-pequeno">Hoje</a>
            <a href="?<?= e(http_build_query($parametros + ['data' => $dataProxima])) ?>"
               class="btn btn-contorno btn-pequeno" aria-label="Proximo periodo">&rsaquo;</a>
            <span class="agenda-data-atual"><?= e($rotuloPeriodo) ?></span>
        </div>

        <span class="agenda-visoes">
            <a href="?<?= e(http_build_query(['visao' => 'dia', 'data' => $dataBase])) ?>"
               class="<?= $visao === 'dia' ? 'ativo' : '' ?>">Dia</a>
            <a href="?<?= e(http_build_query(['visao' => 'semana', 'data' => $dataBase])) ?>"
               class="<?= $visao === 'semana' ? 'ativo' : '' ?>">Semana</a>
        </span>
    </div>

    <?php if ($visao === 'dia'): ?>

        <?php if ($bloqueios !== []): ?>
            <div class="agenda-legenda">
                <?php foreach ($bloqueios as $bloqueio): ?>
                    <span>
                        Bloqueio: <?= formatarHora($bloqueio['hora_inicio']) ?> as <?= formatarHora($bloqueio['hora_fim']) ?>
                        <?= !empty($bloqueio['motivo']) ? ' - ' . e($bloqueio['motivo']) : '' ?>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($agendamentos === []): ?>
            <div class="estado-vazio">
                <strong>Nenhum atendimento neste dia</strong>
                <p>Escolha outra data ou confira seus horarios de atendimento.</p>
            </div>
        <?php else: ?>
            <div class="tabela-area">
                <?php /* Tabela de apresentação dos registros retornados pela consulta. */ ?><table class="tabela">
                    <thead>
                        <tr>
                            <th>Horario</th>
                            <th>Cliente</th>
                            <th>Servico</th>
                            <th>Valor</th>
                            <th>Status</th>
                            <th class="coluna-acoes">Acoes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($agendamentos as $agendamento): ?>
                            <tr>
                                <td>
                                    <span class="celula-principal"><?= formatarHora($agendamento['hora_inicio']) ?></span>
                                    <span class="celula-secundaria">ate <?= formatarHora($agendamento['hora_fim']) ?></span>
                                </td>
                                <td>
                                    <?= e($agendamento['nome_cliente']) ?>
                                    <span class="celula-secundaria"><?= e(formatarTelefone($agendamento['telefone_cliente'])) ?></span>
                                </td>
                                <td>
                                    <?= e($agendamento['nome_servico']) ?>
                                    <?php if (!empty($agendamento['observacao'])): ?>
                                        <span class="celula-secundaria"><?= e(limitarTexto($agendamento['observacao'], 60)) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= formatarMoeda($agendamento['valor']) ?></td>
                                <td><?= badgeStatus($agendamento['status']) ?></td>
                                <td class="coluna-acoes">
                                    <?php if ($agendamento['status'] === 'agendado'): ?>
                                        <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" style="display:inline">
                                            <?= campoCsrf() ?>
                                            <input type="hidden" name="id_agendamento" value="<?= (int) $agendamento['id_agendamento'] ?>">
                                            <input type="hidden" name="status" value="confirmado">
                                            <button type="submit" class="btn btn-contorno btn-pequeno">Confirmar</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (in_array($agendamento['status'], ['agendado', 'confirmado'], true) && Agendamento::jaComecou($agendamento)): ?>
                                        <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" style="display:inline">
                                            <?= campoCsrf() ?>
                                            <input type="hidden" name="id_agendamento" value="<?= (int) $agendamento['id_agendamento'] ?>">
                                            <input type="hidden" name="status" value="concluido">
                                            <button type="submit" class="btn btn-secundario btn-pequeno">Concluir</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <?php else: ?>

        <?php
        $eventos = [];
        foreach ($agendamentos as $agendamento) {
            $eventos[$agendamento['data_agendamento']][] = $agendamento;
        }

        $bloqueiosPorDia = [];
        foreach ($bloqueios as $bloqueio) {
            $bloqueiosPorDia[$bloqueio['data_bloqueio']][] = $bloqueio;
        }
        ?>

        <div class="agenda-area">
            <table class="agenda-tabela">
                <thead>
                    <tr>
                        <?php for ($indice = 0; $indice < 7; $indice++): ?>
                            <?php $dia = date('Y-m-d', strtotime($inicioPeriodo . " +{$indice} days")); ?>
                            <th>
                                <?= e(diaSemanaNome((int) date('w', strtotime($dia)), true)) ?>
                                <small><?= date('d/m', strtotime($dia)) ?><?= $dia === date('Y-m-d') ? ' (hoje)' : '' ?></small>
                            </th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <?php for ($indice = 0; $indice < 7; $indice++): ?>
                            <?php $dia = date('Y-m-d', strtotime($inicioPeriodo . " +{$indice} days")); ?>
                            <td style="height:auto;min-height:120px">
                                <?php foreach ($bloqueiosPorDia[$dia] ?? [] as $bloqueio): ?>
                                    <span class="agenda-bloqueio">
                                        Bloqueio <?= formatarHora($bloqueio['hora_inicio']) ?>-<?= formatarHora($bloqueio['hora_fim']) ?>
                                    </span>
                                <?php endforeach; ?>

                                <?php if (empty($eventos[$dia])): ?>
                                    <span class="texto-pequeno texto-secundario">Sem atendimentos</span>
                                <?php else: ?>
                                    <?php foreach ($eventos[$dia] as $agendamento): ?>
                                        <a class="agenda-evento status-<?= e($agendamento['status']) ?>"
                                           href="?<?= e(http_build_query(['visao' => 'dia', 'data' => $dia])) ?>">
                                            <strong><?= formatarHora($agendamento['hora_inicio']) ?> <?= e(limitarTexto($agendamento['nome_cliente'], 16)) ?></strong>
                                            <span><?= e(limitarTexto($agendamento['nome_servico'], 20)) ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="agenda-legenda">
            <span><i class="legenda-agendado"></i> Agendado</span>
            <span><i class="legenda-confirmado"></i> Confirmado</span>
            <span><i class="legenda-concluido"></i> Concluido</span>
            <span>Clique em um atendimento para abrir o dia</span>
        </div>

    <?php endif; ?>
</div>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>
