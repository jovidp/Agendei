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
        definirFlash('erro', 'Profissional nao encontrado.');
        redirecionar('admin/horarios.php');
    }

    // Confere a faixa de expediente antes de cadastrá-la no dia escolhido.
    if ($acao === 'adicionar_horario') {
        $diaSemana  = (int) post('dia_semana');
        $horaInicio = post('hora_inicio');
        $horaFim    = post('hora_fim');
        $intervalo  = (int) post('intervalo_minutos', '30');

        if ($diaSemana < 0 || $diaSemana > 6) {
            definirFlash('erro', 'Dia da semana invalido.');
        } elseif (!validarHora($horaInicio) || !validarHora($horaFim)) {
            definirFlash('erro', 'Informe horarios validos.');
        } elseif (horaParaMinutos($horaFim) <= horaParaMinutos($horaInicio)) {
            definirFlash('erro', 'O horario final deve ser maior que o inicial.');
        } elseif ($intervalo < 5 || $intervalo > 240) {
            definirFlash('erro', 'O intervalo deve estar entre 5 e 240 minutos.');
        } elseif (Horario::existeSobreposicao($idProfissional, $diaSemana, $horaInicio, $horaFim)) {
            definirFlash('erro', 'Ja existe uma faixa de expediente sobrepondo este horario.');
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

    // Remove a faixa de expediente selecionada.
    if ($acao === 'excluir_horario') {
        Horario::excluir((int) post('id_horario'));
        definirFlash('sucesso', 'Faixa removida.');
        redirecionar($retorno);
    }

    // Replica o expediente do dia de origem para os dias selecionados.
    if ($acao === 'replicar') {
        $diaOrigem   = (int) post('dia_origem');
        $diasDestino = array_map('intval', (array) ($_POST['dias_destino'] ?? []));

        if ($diasDestino === []) {
            definirFlash('erro', 'Selecione ao menos um dia de destino.');
        } else {
            $criadas = Horario::replicarDia($idProfissional, $diaOrigem, $diasDestino);
            definirFlash(
                $criadas > 0 ? 'sucesso' : 'aviso',
                $criadas > 0
                    ? $criadas . ' faixa(s) copiada(s) com sucesso.'
                    : 'Nenhuma faixa foi copiada. Verifique se os dias de destino ja possuem esses horarios.'
            );
        }

        redirecionar($retorno);
    }

    // Valida a indisponibilidade e verifica os atendimentos afetados pelo intervalo.
    if ($acao === 'criar_bloqueio') {
        $data       = post('data_bloqueio');
        $horaInicio = post('hora_inicio') ?: '00:00';
        $horaFim    = post('hora_fim') ?: '23:59';

        if (!validarData($data)) {
            definirFlash('erro', 'Informe uma data valida.');
        } elseif (!validarHora($horaInicio) || !validarHora($horaFim)) {
            definirFlash('erro', 'Informe horarios validos.');
        } elseif (horaParaMinutos($horaFim) <= horaParaMinutos($horaInicio)) {
            definirFlash('erro', 'O horario final deve ser maior que o inicial.');
        } else {
            $conflitos = Bloqueio::agendamentosNoIntervalo($idProfissional, $data, $horaInicio, $horaFim);

            Bloqueio::criar([
                'id_profissional'  => $idProfissional,
                'data_bloqueio'    => $data,
                'hora_inicio'      => $horaInicio,
                'hora_fim'         => $horaFim,
                'motivo'           => post('motivo'),
                'id_usuario_criou' => usuarioId(),
            ]);

            definirFlash('sucesso', 'Bloqueio cadastrado. O horario deixa de aparecer para os clientes.');

            if ($conflitos !== []) {
                definirFlash('aviso', count($conflitos) . ' agendamento(s) ja existente(s) permanecem neste intervalo. Cancele-os se necessario.');
            }
        }

        redirecionar($retorno);
    }

    // Remove a indisponibilidade selecionada da agenda.
    if ($acao === 'excluir_bloqueio') {
        Bloqueio::excluir((int) post('id_bloqueio'));
        definirFlash('sucesso', 'Bloqueio removido.');
        redirecionar($retorno);
    }

    redirecionar($retorno);
}

$profissionais = Profissional::listar();
$profissional  = $idProfissional > 0 ? Profissional::porId($idProfissional) : null;

// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina  = 'Horarios';
$subtituloTopo = $profissional ? $profissional['nome'] : 'Expediente e bloqueios da equipe';

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
            <p>Cadastre a equipe antes de configurar os horarios.</p>
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
            <button type="button" class="btn btn-pequeno" data-modal="modalHorario">Adicionar faixa</button>
        </div>

        <div class="cartao-corpo">
            <div class="grade-dias">
                <?php for ($dia = 0; $dia <= 6; $dia++): ?>
                    <div class="cartao-dia">
                        <div class="cartao-dia-topo">
                            <span><?= e(diaSemanaNome($dia)) ?></span>
                            <span class="texto-pequeno texto-secundario"><?= count($horariosPorDia[$dia]) ?> faixa(s)</span>
                        </div>
                        <div class="cartao-dia-corpo">
                            <?php if ($horariosPorDia[$dia] === []): ?>
                                <p class="dia-vazio">Sem atendimento.</p>
                            <?php else: ?>
                                <?php foreach ($horariosPorDia[$dia] as $faixa): ?>
                                    <div class="faixa-horario <?= $faixa['status'] === 'inativo' ? 'inativa' : '' ?>">
                                        <div class="faixa-info">
                                            <strong><?= formatarHora($faixa['hora_inicio']) ?> as <?= formatarHora($faixa['hora_fim']) ?></strong>
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

                                            <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" style="display:inline">
                                                <?= campoCsrf() ?>
                                                <input type="hidden" name="acao" value="excluir_horario">
                                                <input type="hidden" name="id_profissional" value="<?= $idProfissional ?>">
                                                <input type="hidden" name="id_horario" value="<?= (int) $faixa['id_horario'] ?>">
                                                <button type="submit" class="btn btn-perigo btn-pequeno"
                                                    data-confirmar="Remover a faixa de <?= formatarHora($faixa['hora_inicio']) ?> as <?= formatarHora($faixa['hora_fim']) ?>?"
                                                    data-confirmar-titulo="Remover faixa"
                                                    data-confirmar-rotulo="Remover">
                                                    Remover
                                                </button>
                                            </form>
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
                    <label for="dia_origem">Copiar o expediente de</label>
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
                    <button type="submit" class="btn btn-contorno">Copiar faixas</button>
                </div>
            </form>
        </div>
    </div>

    <div class="cartao">
        <div class="cartao-cabecalho">
            <h3>Bloqueios de agenda</h3>
            <button type="button" class="btn btn-pequeno" data-modal="modalBloqueio">Novo bloqueio</button>
        </div>

        <?php if ($bloqueios === []): ?>
            <div class="estado-vazio">
                <strong>Nenhum bloqueio cadastrado.</strong>
                <p>Use os bloqueios para folgas, ferias e compromissos pontuais.</p>
            </div>
        <?php else: ?>
            <div class="tabela-area">
                <?php /* Tabela de apresentação dos registros retornados pela consulta. */ ?><table class="tabela">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Horario</th>
                            <th>Motivo</th>
                            <th class="coluna-acoes">Acoes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bloqueios as $bloqueio): ?>
                            <tr>
                                <td class="celula-principal"><?= formatarData($bloqueio['data_bloqueio']) ?></td>
                                <td><?= formatarHora($bloqueio['hora_inicio']) ?> as <?= formatarHora($bloqueio['hora_fim']) ?></td>
                                <td><?= e($bloqueio['motivo'] ?: '-') ?></td>
                                <td class="coluna-acoes">
                                    <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" style="display:inline">
                                        <?= campoCsrf() ?>
                                        <input type="hidden" name="acao" value="excluir_bloqueio">
                                        <input type="hidden" name="id_profissional" value="<?= $idProfissional ?>">
                                        <input type="hidden" name="id_bloqueio" value="<?= (int) $bloqueio['id_bloqueio'] ?>">
                                        <button type="submit" class="btn btn-perigo btn-pequeno"
                                            data-confirmar="Remover o bloqueio do dia <?= formatarData($bloqueio['data_bloqueio']) ?>?"
                                            data-confirmar-titulo="Remover bloqueio"
                                            data-confirmar-rotulo="Remover">
                                            Remover
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal: nova faixa de expediente -->
    <?php /* Janela controlada pelo JavaScript para detalhes ou ações da página. */ ?><div class="modal" id="modalHorario">
        <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" class="modal-caixa">
            <?= campoCsrf() ?>
            <input type="hidden" name="acao" value="adicionar_horario">
            <input type="hidden" name="id_profissional" value="<?= $idProfissional ?>">

            <div class="modal-cabecalho">
                <h3>Nova faixa de expediente</h3>
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
                        <label for="hora_inicio">Inicio</label>
                        <input type="time" id="hora_inicio" name="hora_inicio" value="09:00" required>
                    </div>
                    <div class="campo">
                        <label for="hora_fim">Fim</label>
                        <input type="time" id="hora_fim" name="hora_fim" value="12:00" required>
                    </div>
                </div>

                <div class="campo">
                    <label for="intervalo_minutos">Intervalo entre horarios (minutos)</label>
                    <input type="number" id="intervalo_minutos" name="intervalo_minutos" value="30" min="5" max="240" step="5" required>
                    <span class="ajuda-campo">Define de quanto em quanto tempo os horarios sao oferecidos ao cliente.</span>
                </div>
            </div>

            <div class="modal-rodape">
                <button type="button" class="btn btn-contorno" data-fechar-modal>Cancelar</button>
                <button type="submit" class="btn">Adicionar faixa</button>
            </div>
        </form>
    </div>

    <!-- Modal: novo bloqueio -->
    <?php /* Janela controlada pelo JavaScript para detalhes ou ações da página. */ ?><div class="modal" id="modalBloqueio">
        <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" class="modal-caixa">
            <?= campoCsrf() ?>
            <input type="hidden" name="acao" value="criar_bloqueio">
            <input type="hidden" name="id_profissional" value="<?= $idProfissional ?>">

            <div class="modal-cabecalho">
                <h3>Novo bloqueio de agenda</h3>
                <button type="button" class="modal-fechar" data-fechar-modal aria-label="Fechar">&times;</button>
            </div>

            <div class="modal-corpo">
                <div class="campo">
                    <label for="data_bloqueio">Data</label>
                    <input type="date" id="data_bloqueio" name="data_bloqueio" value="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="linha-campos">
                    <div class="campo">
                        <label for="bloqueio_inicio">Das</label>
                        <input type="time" id="bloqueio_inicio" name="hora_inicio" value="08:00" required>
                    </div>
                    <div class="campo">
                        <label for="bloqueio_fim">Ate</label>
                        <input type="time" id="bloqueio_fim" name="hora_fim" value="18:00" required>
                    </div>
                </div>

                <div class="campo">
                    <label for="motivo">Motivo</label>
                    <input type="text" id="motivo" name="motivo" maxlength="255" placeholder="Ex.: folga, consulta medica">
                </div>
            </div>

            <div class="modal-rodape">
                <button type="button" class="btn btn-contorno" data-fechar-modal>Cancelar</button>
                <button type="submit" class="btn">Criar bloqueio</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>