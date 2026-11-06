<?php

/**
 * Expediente semanal e bloqueios de agenda dos profissionais.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/../config/config.php';

// Restringe esta página a administradores autenticados.
exigirLogin('admin');

$idProfissional = (int) get('id_profissional');

// Processa o formulário enviado antes de montar o HTML da página.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Confere o token da sessão antes de aceitar alterações enviadas pelo formulário.
    exigirCsrf();

    $acao           = post('acao');
    $idProfissional = (int) post('id_profissional');
    $profissional   = Profissional::porId($idProfissional);
    $retorno        = 'admin/horarios.php?id_profissional=' . $idProfissional;

    if (!$profissional) {
        definirFlash('erro', 'Profissional não encontrado.');
        redirecionar('admin/horarios.php');
    }

    // Confere a faixa de expediente antes de cadastrá-la no dia escolhido.
    if ($acao === 'adicionar_horario') {
        $diaSemana  = (int) post('dia_semana');
        $horaInicio = post('hora_inicio');
        $horaFim    = post('hora_fim');
        $intervalo  = (int) post('intervalo_minutos', '30');

        if ($diaSemana < 0 || $diaSemana > 6) {
            definirFlash('erro', 'Dia da semana inválido.');
        } elseif (!validarHora($horaInicio) || !validarHora($horaFim)) {
            definirFlash('erro', 'Informe horários válidos.');
        } elseif (horaParaMinutos($horaFim) <= horaParaMinutos($horaInicio)) {
            definirFlash('erro', 'O horário final deve ser maior que o inicial.');
        } elseif ($intervalo < 5 || $intervalo > 240) {
            definirFlash('erro', 'O intervalo deve estar entre 5 e 240 minutos.');
        } elseif (Horario::existeSobreposicao($idProfissional, $diaSemana, $horaInicio, $horaFim)) {
            definirFlash('erro', 'Já existe uma faixa de expediente sobrepondo este horário.');
        } else {
            Horario::criar([
                'id_profissional'   => $idProfissional,
                'dia_semana'        => $diaSemana,
                'hora_inicio'       => $horaInicio,
                'hora_fim'          => $horaFim,
                'intervalo_minutos' => $intervalo,
            ]);
            definirFlash('sucesso', 'Faixa de expediente cadastrada.');
        }

        redirecionar($retorno);
    }

    // Ativa ou desativa uma faixa de expediente cadastrada.
    if ($acao === 'status_horario') {
        Horario::alterarStatus((int) post('id_horario'), post('status'));
        definirFlash('sucesso', 'Faixa atualizada.');
        redirecionar($retorno);
    }

    // Corrige o inicio, o fim ou o intervalo de um horario ja cadastrado.
    if ($acao === 'editar_horario') {
        $faixa      = Horario::porId((int) post('id_horario'));
        $horaInicio = post('hora_inicio');
        $horaFim    = post('hora_fim');
        $intervalo  = (int) post('intervalo_minutos', '30');

        if (!$faixa || (int) $faixa['id_profissional'] !== $idProfissional) {
            definirFlash('erro', 'Horário não encontrado.');
        } elseif (!validarHora($horaInicio) || !validarHora($horaFim)) {
            definirFlash('erro', 'Informe horários válidos.');
        } elseif (horaParaMinutos($horaFim) <= horaParaMinutos($horaInicio)) {
            definirFlash('erro', 'O horário final deve ser maior que o inicial.');
        } elseif ($intervalo < 5 || $intervalo > 240) {
            definirFlash('erro', 'O intervalo deve estar entre 5 e 240 minutos.');
        } elseif (Horario::existeSobreposicao($idProfissional, (int) $faixa['dia_semana'], $horaInicio, $horaFim, (int) $faixa['id_horario'])) {
            definirFlash('erro', 'Já existe outro horário neste dia sobrepondo este intervalo.');
        } else {
            Horario::atualizar((int) $faixa['id_horario'], [
                'dia_semana'        => (int) $faixa['dia_semana'],
                'hora_inicio'       => $horaInicio,
                'hora_fim'          => $horaFim,
                'intervalo_minutos' => $intervalo,
                'status'            => $faixa['status'],
            ]);
            definirFlash('sucesso', 'Horário atualizado.');
        }

        redirecionar($retorno);
    }

    // Copia os horarios do dia de origem para os dias escolhidos, no lugar dos que eles tinham.
    if ($acao === 'replicar') {
        $diaOrigem   = (int) post('dia_origem');
        $diasDestino = array_map('intval', (array) ($_POST['dias_destino'] ?? []));
        $diasDestino = array_values(array_diff($diasDestino, [$diaOrigem]));

        if ($diasDestino === []) {
            definirFlash('erro', 'Selecione ao menos um dia diferente do dia de origem.');
        } elseif ((Horario::agrupadoPorDia($idProfissional)[$diaOrigem] ?? []) === []) {
            definirFlash('erro', 'O dia de origem não tem horários para copiar.');
        } else {
            Horario::replicarDia($idProfissional, $diaOrigem, $diasDestino);
            definirFlash('sucesso', 'Horários copiados para ' . count($diasDestino) . ' dia(s).');
        }

        redirecionar($retorno);
    }

    redirecionar($retorno);
}

$profissionais = Profissional::listar();
$profissional  = $idProfissional > 0 ? Profissional::porId($idProfissional) : null;

// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina  = 'Horários';
$subtituloTopo = $profissional ? $profissional['nome'] : 'Expediente da equipe';

// Renderiza a estrutura comum do painel após preparar os dados desta tela.
require_once RAIZ . '/includes/painel_header.php';
?>

<div class="cartao">
    <?php /* Filtros enviados na URL para permitir atualizar e compartilhar a consulta. */ ?><form method="get" class="barra-filtros">
        <input type="hidden" name="estabelecimento" value="<?= e(Contexto::slug()) ?>">
        <div class="campo campo-busca">
            <label for="id_profissional">Profissional</label>
            <select id="id_profissional" name="id_profissional" data-envia-ao-mudar>
                <option value="">Selecione um profissional</option>
                <?php foreach ($profissionais as $item): ?>
                    <option value="<?= (int) $item['id_profissional'] ?>"
                        <?= $idProfissional === (int) $item['id_profissional'] ? 'selected' : '' ?>>
                        <?= e($item['nome']) ?><?= $item['status'] === 'inativo' ? ' (inativo)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php if ($profissionais === []): ?>
        <div class="estado-vazio">
            <strong>Nenhum profissional cadastrado.</strong>
            <p>Cadastre a equipe antes de configurar os horários.</p>
            <a href="<?= url('admin/profissionais.php?acao=novo') ?>" class="btn margem-topo">Novo profissional</a>
        </div>
    <?php elseif (!$profissional): ?>
        <div class="estado-vazio">
            <strong>Selecione um profissional</strong>
            <p>Escolha um profissional acima para configurar o expediente e os bloqueios.</p>
        </div>
    <?php endif; ?>
</div>

<?php if ($profissional): ?>
    <?php
    $horariosPorDia = Horario::agrupadoPorDia($idProfissional);
    $bloqueios      = Bloqueio::listar(['id_profissional' => $idProfissional, 'futuros' => true]);
    ?>

    <div class="cartao">
        <div class="cartao-cabecalho">
            <h3>Expediente semanal</h3>
            <button type="button" class="btn btn-pequeno" data-modal="modalHorario">Adicionar horário</button>
        </div>

        <div class="cartao-corpo">
            <div class="grade-dias">
                <?php for ($dia = 0; $dia <= 6; $dia++): ?>
                    <div class="cartao-dia">
                        <div class="cartao-dia-topo">
                            <span><?= e(diaSemanaNome($dia)) ?></span>
                            <span class="texto-pequeno texto-secundario"><?= count($horariosPorDia[$dia]) ?> horário(s)</span>
                        </div>
                        <div class="cartao-dia-corpo">
                            <?php if ($horariosPorDia[$dia] === []): ?>
                                <p class="dia-vazio">Sem atendimento.</p>
                            <?php else: ?>
                                <?php foreach ($horariosPorDia[$dia] as $faixa): ?>
                                    <div class="faixa-horario <?= $faixa['status'] === 'inativo' ? 'inativa' : '' ?>">
                                        <div class="faixa-info">
                                            <strong><?= formatarHora($faixa['hora_inicio']) ?> às <?= formatarHora($faixa['hora_fim']) ?></strong>
                                            <span>Intervalos de <?= (int) $faixa['intervalo_minutos'] ?> min</span>
                                        </div>
                                        <div class="grupo-botoes">
                                            <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" style="display:inline">
                                                <?= campoCsrf() ?>
                                                <input type="hidden" name="acao" value="status_horario">
                                                <input type="hidden" name="id_profissional" value="<?= $idProfissional ?>">
                                                <input type="hidden" name="id_horario" value="<?= (int) $faixa['id_horario'] ?>">
                                                <input type="hidden" name="status" value="<?= $faixa['status'] === 'ativo' ? 'inativo' : 'ativo' ?>">
                                                <button type="submit" class="btn btn-contorno btn-pequeno">
                                                    <?= $faixa['status'] === 'ativo' ? 'Pausar' : 'Ativar' ?>
                                                </button>
                                            </form>

                                            <button type="button" class="btn btn-contorno btn-pequeno" data-modal="modalEditarHorario"
                                                data-campo-id_horario="<?= (int) $faixa['id_horario'] ?>"
                                                data-campo-dia="<?= e(diaSemanaNome($dia)) ?>"
                                                data-campo-hora_inicio="<?= e(substr($faixa['hora_inicio'], 0, 5)) ?>"
                                                data-campo-hora_fim="<?= e(substr($faixa['hora_fim'], 0, 5)) ?>"
                                                data-campo-intervalo_minutos="<?= (int) $faixa['intervalo_minutos'] ?>">
                                                Editar
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>

        <div class="cartao-rodape">
            <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" class="barra-filtros" style="padding:0;border:0;background:none">
                <?= campoCsrf() ?>
                <input type="hidden" name="acao" value="replicar">
                <input type="hidden" name="id_profissional" value="<?= $idProfissional ?>">

                <div class="campo">
                    <label for="dia_origem">Copiar os horários de</label>
                    <select id="dia_origem" name="dia_origem">
                        <?php for ($dia = 0; $dia <= 6; $dia++): ?>
                            <option value="<?= $dia ?>" <?= $dia === 1 ? 'selected' : '' ?>><?= e(diaSemanaNome($dia)) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="campo">
                    <span class="rotulo">Para os dias</span>
                    <div class="grupo-botoes">
                        <?php for ($dia = 0; $dia <= 6; $dia++): ?>
                            <span class="campo-checkbox" style="margin:0">
                                <input type="checkbox" id="destino<?= $dia ?>" name="dias_destino[]" value="<?= $dia ?>">
                                <label for="destino<?= $dia ?>"><?= e(diaSemanaNome($dia, true)) ?></label>
                            </span>
                        <?php endfor; ?>
                    </div>
                </div>

                <div class="campo">
                    <button type="submit" class="btn btn-contorno"
                        data-confirmar="Os dias marcados ficam com os mesmos horários do dia de origem. Os horários que eles tinham são substituídos."
                        data-confirmar-titulo="Copiar horários"
                        data-confirmar-rotulo="Copiar">Copiar horários</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: nova faixa de expediente -->
    <?php /* Janela controlada pelo JavaScript para detalhes ou ações da página. */ ?><div class="modal" id="modalHorario">
        <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" class="modal-caixa">
            <?= campoCsrf() ?>
            <input type="hidden" name="acao" value="adicionar_horario">
            <input type="hidden" name="id_profissional" value="<?= $idProfissional ?>">

            <div class="modal-cabecalho">
                <h3>Novo horário de atendimento</h3>
                <button type="button" class="modal-fechar" data-fechar-modal aria-label="Fechar">&times;</button>
            </div>

            <div class="modal-corpo">
                <div class="campo">
                    <label for="dia_semana">Dia da semana</label>
                    <select id="dia_semana" name="dia_semana" required>
                        <?php for ($dia = 0; $dia <= 6; $dia++): ?>
                            <option value="<?= $dia ?>" <?= $dia === 1 ? 'selected' : '' ?>><?= e(diaSemanaNome($dia)) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="linha-campos">
                    <div class="campo">
                        <label for="hora_inicio">Início</label>
                        <input type="time" id="hora_inicio" name="hora_inicio" value="09:00" required>
                    </div>
                    <div class="campo">
                        <label for="hora_fim">Fim</label>
                        <input type="time" id="hora_fim" name="hora_fim" value="12:00" required>
                    </div>
                </div>

                <div class="campo">
                    <label for="intervalo_minutos">Intervalo entre horários (minutos)</label>
                    <input type="number" id="intervalo_minutos" name="intervalo_minutos" value="30" min="5" max="240" step="5" required>
                    <span class="ajuda-campo">Define de quanto em quanto tempo os horários são oferecidos ao cliente.</span>
                </div>
            </div>

            <div class="modal-rodape">
                <button type="button" class="btn btn-contorno" data-fechar-modal>Cancelar</button>
                <button type="submit" class="btn">Adicionar horário</button>
            </div>
        </form>
    </div>

    <!-- Modal: editar horario -->
    <div class="modal" id="modalEditarHorario">
        <form method="post" class="modal-caixa">
            <?= campoCsrf() ?>
            <input type="hidden" name="acao" value="editar_horario">
            <input type="hidden" name="id_profissional" value="<?= $idProfissional ?>">
            <input type="hidden" name="id_horario" value="">

            <div class="modal-cabecalho">
                <h3>Editar horário de <span data-preenche="dia"></span></h3>
                <button type="button" class="modal-fechar" data-fechar-modal aria-label="Fechar">&times;</button>
            </div>

            <div class="modal-corpo">
                <div class="linha-campos">
                    <div class="campo">
                        <label for="editar_inicio">Início</label>
                        <input type="time" id="editar_inicio" name="hora_inicio" required>
                    </div>
                    <div class="campo">
                        <label for="editar_fim">Fim</label>
                        <input type="time" id="editar_fim" name="hora_fim" required>
                    </div>
                </div>

                <div class="campo">
                    <label for="editar_intervalo">Intervalo entre horários (minutos)</label>
                    <input type="number" id="editar_intervalo" name="intervalo_minutos" min="5" max="240" step="5" required>
                </div>
            </div>

            <div class="modal-rodape">
                <button type="button" class="btn btn-contorno" data-fechar-modal>Cancelar</button>
                <button type="submit" class="btn">Salvar</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>