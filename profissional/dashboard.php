<?php
/**
 * Painel inicial do profissional: atendimentos do dia e indicadores.
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

    // Impede que uma alteração enviada por ID alcance a agenda de outro profissional.
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

    redirecionar('profissional/dashboard.php');
}

$hoje      = date('Y-m-d');
$doDia     = Agendamento::doDia($hoje, $idProfissional);
// Obtém a proporção entre minutos agendados e expediente para o indicador do dia.
$ocupacao  = Relatorio::ocupacaoDoDia($idProfissional, $hoje);
$inicioMes = date('Y-m-01');
$fimMes    = date('Y-m-t');

$proximos = Agendamento::listar([
    'id_profissional'  => $idProfissional,
    'status_em'        => ['agendado', 'confirmado'],
    'data_inicial'     => date('Y-m-d', strtotime('+1 day')),
    'ordem'            => 'asc',
    'limite'           => 6,
]);

$indicadores = [
    'hoje'       => count($doDia),
    'semana'     => Agendamento::contar([
        'id_profissional' => $idProfissional,
        'data_inicial'    => date('Y-m-d', strtotime('monday this week')),
        'data_final'      => date('Y-m-d', strtotime('sunday this week')),
        'status_em'       => ['agendado', 'confirmado', 'concluido'],
    ]),
    'concluidos' => Agendamento::contar([
        'id_profissional' => $idProfissional,
        'data_inicial'    => $inicioMes,
        'data_final'      => $fimMes,
        'status'          => 'concluido',
    ]),
];

// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina  = 'Dashboard';
$subtituloTopo = dataExtenso($hoje);
$acoesTopo     = '<a href="' . url('profissional/agenda.php') . '" class="btn btn-pequeno">Ver agenda</a>';

// Renderiza a estrutura comum do painel após preparar os dados desta tela.
require_once RAIZ . '/includes/painel_header.php';
?>

<div class="cabecalho-pagina">
    <div>
        <h2>Ola, <?= e(explode(' ', usuarioNome())[0]) ?></h2>
        <p>Acompanhe os atendimentos do dia e confirme sua agenda.</p>
    </div>
</div>

<div class="grade-indicadores">
    <div class="indicador indicador-destaque">
        <span class="indicador-rotulo">Atendimentos hoje</span>
        <span class="indicador-valor"><?= (int) $indicadores['hoje'] ?></span>
        <span class="indicador-nota"><?= formatarData($hoje) ?></span>
    </div>
    <div class="indicador">
        <span class="indicador-rotulo">Atendimentos da semana</span>
        <span class="indicador-valor"><?= (int) $indicadores['semana'] ?></span>
        <span class="indicador-nota">Segunda a domingo</span>
    </div>
    <div class="indicador indicador-positivo">
        <span class="indicador-rotulo">Concluidos no mes</span>
        <span class="indicador-valor"><?= (int) $indicadores['concluidos'] ?></span>
        <span class="indicador-nota">Servicos realizados</span>
    </div>
    <div class="indicador">
        <span class="indicador-rotulo">Ocupacao de hoje</span>
        <span class="indicador-valor"><?= (int) $ocupacao['percentual'] ?>%</span>
        <span class="indicador-nota">
            <?= e(duracaoTexto($ocupacao['minutos_agendados'])) ?> de <?= e(duracaoTexto($ocupacao['minutos_expediente'])) ?>
        </span>
    </div>
</div>

<div class="grade-painel">
    <div class="cartao">
        <div class="cartao-cabecalho">
            <h3>Agenda de hoje</h3>
            <a href="<?= url('profissional/agenda.php') ?>" class="btn-texto">Ver agenda completa</a>
        </div>

        <?php if ($doDia === []): ?>
            <div class="estado-vazio">
                <strong>Nenhum atendimento hoje</strong>
                <p>Aproveite para revisar seus horarios de atendimento.</p>
            </div>
        <?php else: ?>
            <div class="tabela-area">
                <?php /* Tabela de apresentação dos registros retornados pela consulta. */ ?><table class="tabela">
                    <thead>
                        <tr>
                            <th>Horario</th>
                            <th>Cliente</th>
                            <th>Servico</th>
                            <th>Status</th>
                            <th class="coluna-acoes">Acoes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($doDia as $agendamento): ?>
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
                                        <span class="celula-secundaria"><?= e(limitarTexto($agendamento['observacao'], 50)) ?></span>
                                    <?php endif; ?>
                                </td>
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
    </div>

    <div class="cartao">
        <div class="cartao-cabecalho"><h3>Proximos dias</h3></div>

        <?php if ($proximos === []): ?>
            <div class="estado-vazio">
                <strong>Sem atendimentos futuros</strong>
                <p>Seus proximos agendamentos aparecerao aqui.</p>
            </div>
        <?php else: ?>
            <ul class="lista-simples">
                <?php foreach ($proximos as $agendamento): ?>
                    <li>
                        <div class="conteudo">
                            <strong><?= formatarData($agendamento['data_agendamento']) ?> as <?= formatarHora($agendamento['hora_inicio']) ?></strong>
                            <span><?= e($agendamento['nome_cliente']) ?> &middot; <?= e($agendamento['nome_servico']) ?></span>
                        </div>
                        <span class="valor"><?= badgeStatus($agendamento['status']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>
