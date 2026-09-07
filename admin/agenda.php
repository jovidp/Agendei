<?php
/**
 * Agenda visual do estabelecimento (visao por dia ou por semana).
 * Montada apenas com PHP, HTML, CSS e JavaScript, sem bibliotecas externas.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/../config/config.php';

// Restringe esta página a administradores autenticados.
exigirLogin('admin');

// Normaliza a visão e a data antes de calcular o período mostrado na agenda.
$visao          = get('visao') === 'semana' ? 'semana' : 'dia';
$dataBase       = validarData(get('data')) ? get('data') : date('Y-m-d');
$idProfissional = (int) get('id_profissional');

$profissionais = Profissional::ativos();

if ($idProfissional > 0 && !Profissional::porId($idProfissional)) {
    $idProfissional = 0;
}

$inicioPeriodo = $visao === 'semana'
    ? date('Y-m-d', strtotime('monday this week', strtotime($dataBase)))
    : $dataBase;

$fimPeriodo = $visao === 'semana'
    ? date('Y-m-d', strtotime($inicioPeriodo . ' +6 days'))
    : $dataBase;

$agendamentos = Agendamento::doPeriodo($inicioPeriodo, $fimPeriodo, $idProfissional ?: null);

$bloqueios = Bloqueio::listar(array_filter([
    'id_profissional' => $idProfissional ?: null,
    'data_inicial'    => $inicioPeriodo,
    'data_final'      => $fimPeriodo,
]));

// ---------------------------------------------------------------------
// Faixa de horarios exibida na grade
// ---------------------------------------------------------------------
$inicioGrade = 8 * 60;
$fimGrade    = 19 * 60;

$profissionaisGrade = $idProfissional > 0
    ? array_values(array_filter($profissionais, fn ($p) => (int) $p['id_profissional'] === $idProfissional))
    : $profissionais;

$dataIteracao = $inicioPeriodo;
while ($dataIteracao <= $fimPeriodo) {
    $diaSemana = (int) date('w', strtotime($dataIteracao));

    foreach ($profissionaisGrade as $profissional) {
        foreach (Horario::faixasAtivas((int) $profissional['id_profissional'], $diaSemana) as $faixa) {
            $inicioGrade = min($inicioGrade, horaParaMinutos($faixa['hora_inicio']));
            $fimGrade    = max($fimGrade, horaParaMinutos($faixa['hora_fim']));
        }
    }

    $dataIteracao = date('Y-m-d', strtotime($dataIteracao . ' +1 day'));
}

foreach ($agendamentos as $agendamento) {
    $inicioGrade = min($inicioGrade, horaParaMinutos($agendamento['hora_inicio']));
    $fimGrade    = max($fimGrade, horaParaMinutos($agendamento['hora_fim']));
}

$inicioGrade = (int) (floor($inicioGrade / 30) * 30);
$fimGrade    = (int) (ceil($fimGrade / 30) * 30);

// ---------------------------------------------------------------------
// Distribuicao dos eventos pelas celulas
// ---------------------------------------------------------------------
/** Encaixa o horario na linha da grade, sem deixar nada fora do intervalo exibido. */
// Converte cada horário em uma linha da grade visual usada para posicionar os eventos.
$linhaDaGrade = static function (string $hora) use ($inicioGrade, $fimGrade): int {
    $slot = (int) (floor(horaParaMinutos($hora) / 30) * 30);
    return max($inicioGrade, min($slot, $fimGrade - 30));
};

$eventos = [];

foreach ($agendamentos as $agendamento) {
    $coluna = $visao === 'semana' ? $agendamento['data_agendamento'] : (int) $agendamento['id_profissional'];
    $eventos[$coluna][$linhaDaGrade($agendamento['hora_inicio'])][] = $agendamento;
}

$eventosBloqueio = [];

foreach ($bloqueios as $bloqueio) {
    $coluna = $visao === 'semana' ? $bloqueio['data_bloqueio'] : (int) $bloqueio['id_profissional'];
    $eventosBloqueio[$coluna][$linhaDaGrade($bloqueio['hora_inicio'])][] = $bloqueio;
}

// ---------------------------------------------------------------------
// Colunas da grade
// ---------------------------------------------------------------------
$colunas = [];

if ($visao === 'semana') {
    for ($indice = 0; $indice < 7; $indice++) {
        $dia = date('Y-m-d', strtotime($inicioPeriodo . " +{$indice} days"));
        $colunas[] = [
            'chave'    => $dia,
            'titulo'   => diaSemanaNome((int) date('w', strtotime($dia)), true),
            'subtitulo' => date('d/m', strtotime($dia)),
            'hoje'     => $dia === date('Y-m-d'),
        ];
    }
} else {
    foreach ($profissionaisGrade as $profissional) {
        $colunas[] = [
            'chave'     => (int) $profissional['id_profissional'],
            'titulo'    => $profissional['nome'],
            'subtitulo' => $profissional['especialidade'] ?: 'Profissional',
            'hoje'      => false,
        ];
    }
}

$parametrosBase = array_filter([
    'visao'           => $visao,
    'id_profissional' => $idProfissional ?: null,
]);

$dataAnterior = $visao === 'semana'
    ? date('Y-m-d', strtotime($inicioPeriodo . ' -7 days'))
    : date('Y-m-d', strtotime($dataBase . ' -1 day'));

$dataProxima = $visao === 'semana'
    ? date('Y-m-d', strtotime($inicioPeriodo . ' +7 days'))
    : date('Y-m-d', strtotime($dataBase . ' +1 day'));

$rotuloPeriodo = $visao === 'semana'
    ? formatarData($inicioPeriodo) . ' a ' . formatarData($fimPeriodo)
    : dataExtenso($dataBase);

// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina  = 'Agenda';
$subtituloTopo = count($agendamentos) . ' agendamento(s) no periodo';
$acoesTopo     = '<a href="' . url('admin/agendamentos.php?acao=novo') . '" class="btn btn-pequeno">Novo agendamento</a>';

// Renderiza a estrutura comum do painel após preparar os dados desta tela.
require_once RAIZ . '/includes/painel_header.php';
?>

<div class="cartao">
    <div class="agenda-barra">
        <div class="agenda-navegacao">
            <a href="?<?= e(http_build_query($parametrosBase + ['data' => $dataAnterior])) ?>"
               class="btn btn-contorno btn-pequeno" aria-label="Periodo anterior">&lsaquo;</a>
            <a href="?<?= e(http_build_query($parametrosBase + ['data' => date('Y-m-d')])) ?>"
               class="btn btn-contorno btn-pequeno">Hoje</a>
            <a href="?<?= e(http_build_query($parametrosBase + ['data' => $dataProxima])) ?>"
               class="btn btn-contorno btn-pequeno" aria-label="Proximo periodo">&rsaquo;</a>
            <span class="agenda-data-atual"><?= e($rotuloPeriodo) ?></span>
        </div>

        <?php /* Filtros enviados na URL para permitir atualizar e compartilhar a consulta. */ ?><form method="get" class="agenda-navegacao">
        <input type="hidden" name="estabelecimento" value="<?= e(Contexto::slug()) ?>">
            <input type="hidden" name="visao" value="<?= e($visao) ?>">
            <input type="hidden" name="data" value="<?= e($dataBase) ?>">

            <select name="id_profissional" data-envia-ao-mudar aria-label="Filtrar por profissional">
                <option value="">Todos os profissionais</option>
                <?php foreach ($profissionais as $profissional): ?>
                    <option value="<?= (int) $profissional['id_profissional'] ?>"
                            <?= $idProfissional === (int) $profissional['id_profissional'] ? 'selected' : '' ?>>
                        <?= e($profissional['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <span class="agenda-visoes">
                <a href="?<?= e(http_build_query(array_filter(['visao' => 'dia', 'data' => $dataBase, 'id_profissional' => $idProfissional ?: null]))) ?>"
                   class="<?= $visao === 'dia' ? 'ativo' : '' ?>">Dia</a>
                <a href="?<?= e(http_build_query(array_filter(['visao' => 'semana', 'data' => $dataBase, 'id_profissional' => $idProfissional ?: null]))) ?>"
                   class="<?= $visao === 'semana' ? 'ativo' : '' ?>">Semana</a>
            </span>
        </form>
    </div>

    <?php if ($colunas === []): ?>
        <div class="estado-vazio">
            <strong>Nenhum profissional ativo</strong>
            <p>Cadastre profissionais para visualizar a agenda.</p>
            <a href="<?= url('admin/profissionais.php?acao=novo') ?>" class="btn margem-topo">Novo profissional</a>
        </div>
    <?php else: ?>
        <div class="agenda-area">
            <table class="agenda-tabela">
                <thead>
                    <tr>
                        <th class="agenda-hora">Horario</th>
                        <?php foreach ($colunas as $coluna): ?>
                            <th>
                                <?= e($coluna['titulo']) ?>
                                <small><?= e($coluna['subtitulo']) ?><?= $coluna['hoje'] ? ' (hoje)' : '' ?></small>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($minuto = $inicioGrade; $minuto < $fimGrade; $minuto += 30): ?>
                        <tr>
                            <td class="agenda-hora"><?= e(substr(minutosParaHora($minuto), 0, 5)) ?></td>

                            <?php foreach ($colunas as $coluna): ?>
                                <td>
                                    <?php foreach ($eventosBloqueio[$coluna['chave']][$minuto] ?? [] as $bloqueio): ?>
                                        <span class="agenda-bloqueio">
                                            Bloqueio <?= formatarHora($bloqueio['hora_inicio']) ?>-<?= formatarHora($bloqueio['hora_fim']) ?>
                                            <?= $visao === 'semana' ? ' - ' . e($bloqueio['nome_profissional']) : '' ?>
                                        </span>
                                    <?php endforeach; ?>

                                    <?php foreach ($eventos[$coluna['chave']][$minuto] ?? [] as $agendamento): ?>
                                        <button type="button" class="agenda-evento status-<?= e($agendamento['status']) ?>"
                                                data-modal="modalAgenda"
                                                data-campo-cliente="<?= e($agendamento['nome_cliente']) ?>"
                                                data-campo-contato="<?= e(formatarTelefone($agendamento['telefone_cliente'])) ?>"
                                                data-campo-servico="<?= e($agendamento['nome_servico']) ?>"
                                                data-campo-profissional="<?= e($agendamento['nome_profissional']) ?>"
                                                data-campo-data="<?= e(dataExtenso($agendamento['data_agendamento'])) ?>"
                                                data-campo-horario="<?= formatarHora($agendamento['hora_inicio']) ?> as <?= formatarHora($agendamento['hora_fim']) ?>"
                                                data-campo-valor="<?= formatarMoeda($agendamento['valor']) ?>"
                                                data-campo-status="<?= e(ucfirst($agendamento['status'])) ?>"
                                                data-campo-observacao="<?= e($agendamento['observacao'] ?: 'Sem observacoes.') ?>">
                                            <strong><?= formatarHora($agendamento['hora_inicio']) ?> <?= e(limitarTexto($agendamento['nome_cliente'], 18)) ?></strong>
                                            <span><?= e(limitarTexto($agendamento['nome_servico'], 22)) ?></span>
                                            <?php if ($visao === 'semana' && $idProfissional === 0): ?>
                                                <span><?= e(limitarTexto($agendamento['nome_profissional'], 22)) ?></span>
                                            <?php endif; ?>
                                        </button>
                                    <?php endforeach; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <div class="agenda-legenda">
            <span><i class="legenda-agendado"></i> Agendado</span>
            <span><i class="legenda-confirmado"></i> Confirmado</span>
            <span><i class="legenda-concluido"></i> Concluido</span>
            <span><i class="legenda-cancelado"></i> Cancelado</span>
            <span>Clique em um atendimento para ver os detalhes</span>
        </div>
    <?php endif; ?>
</div>

<?php /* Janela controlada pelo JavaScript para detalhes ou ações da página. */ ?><div class="modal" id="modalAgenda">
    <div class="modal-caixa">
        <div class="modal-cabecalho">
            <h3>Detalhes do atendimento</h3>
            <button type="button" class="modal-fechar" data-fechar-modal aria-label="Fechar">&times;</button>
        </div>
        <div class="modal-corpo">
            <dl class="lista-detalhes">
                <div><dt>Cliente</dt><dd data-preenche="cliente">-</dd></div>
                <div><dt>Contato</dt><dd data-preenche="contato">-</dd></div>
                <div><dt>Servico</dt><dd data-preenche="servico">-</dd></div>
                <div><dt>Profissional</dt><dd data-preenche="profissional">-</dd></div>
                <div><dt>Data</dt><dd data-preenche="data">-</dd></div>
                <div><dt>Horario</dt><dd data-preenche="horario">-</dd></div>
                <div><dt>Valor</dt><dd data-preenche="valor">-</dd></div>
                <div><dt>Status</dt><dd data-preenche="status">-</dd></div>
                <div><dt>Observacao</dt><dd data-preenche="observacao">-</dd></div>
            </dl>
        </div>
        <div class="modal-rodape">
            <a href="<?= url('admin/agendamentos.php') ?>" class="btn btn-contorno">Gerenciar agendamentos</a>
            <button type="button" class="btn" data-fechar-modal>Fechar</button>
        </div>
    </div>
</div>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>
