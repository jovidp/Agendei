<?php
/**
 * Bloqueios da propria agenda, quando o administrador permite.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/../config/config.php';

// Restringe esta página a profissionais autenticados.
exigirLogin('profissional');

// Obtém o profissional da sessão para restringir os dados à sua própria agenda.
$idProfissional = perfilId();

// Combina a configuração geral com a permissão individual para liberar bloqueios da própria agenda.
$permitido = Configuracao::ativa('permitir_bloqueio_profissional', true)
    && Profissional::podeBloquearAgenda($idProfissional);

// Processa o formulário enviado antes de montar o HTML da página.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Confere o token da sessão antes de aceitar alterações enviadas pelo formulário.
    exigirCsrf();

    if (!$permitido) {
        definirFlash('erro', 'Voce nao tem permissao para bloquear a agenda. Fale com o administrador.');
        redirecionar('profissional/bloqueios.php');
    }

    $acao = post('acao');

    // Valida os dados recebidos e solicita a criação do registro.
    if ($acao === 'criar') {
        $data       = post('data_bloqueio');
        $horaInicio = post('hora_inicio') ?: '00:00';
        $horaFim    = post('hora_fim') ?: '23:59';

        if (!validarData($data)) {
            definirFlash('erro', 'Informe uma data valida.');
        } elseif (strtotime($data) < strtotime('today')) {
            definirFlash('erro', 'Nao e possivel bloquear uma data que ja passou.');
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

            definirFlash('sucesso', 'Bloqueio criado. Este horario deixa de aparecer para os clientes.');

            if ($conflitos !== []) {
                definirFlash('aviso', count($conflitos) . ' atendimento(s) ja agendado(s) continuam neste intervalo. Combine o cancelamento com o estabelecimento.');
            }
        }
    }

    // Confere o registro e as regras aplicáveis antes da exclusão.
    if ($acao === 'excluir') {
        $bloqueio = Bloqueio::porId((int) post('id_bloqueio'));

        if ($bloqueio && (int) $bloqueio['id_profissional'] === $idProfissional) {
            Bloqueio::excluir((int) $bloqueio['id_bloqueio']);
            definirFlash('sucesso', 'Bloqueio removido.');
        } else {
            definirFlash('erro', 'Bloqueio nao encontrado.');
        }
    }

    redirecionar('profissional/bloqueios.php');
}

$bloqueios = Bloqueio::listar(['id_profissional' => $idProfissional]);

// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina  = 'Bloqueios';
$subtituloTopo = 'Folgas e indisponibilidades da sua agenda';
$acoesTopo     = $permitido
    ? '<button type="button" class="btn btn-pequeno" data-modal="modalBloqueio">Novo bloqueio</button>'
    : '';

// Renderiza a estrutura comum do painel após preparar os dados desta tela.
require_once RAIZ . '/includes/painel_header.php';
?>

<?php if (!$permitido): ?>
    <div class="alerta alerta-info">
        <span class="alerta-texto">
            O bloqueio da propria agenda esta desativado para o seu perfil.
            Solicite ao administrador o bloqueio dos horarios necessarios.
        </span>
    </div>
<?php endif; ?>

<div class="cartao">
    <div class="cartao-cabecalho"><h3>Bloqueios cadastrados</h3></div>

    <?php if ($bloqueios === []): ?>
        <div class="estado-vazio">
            <strong>Nenhum bloqueio cadastrado.</strong>
            <p>Os bloqueios impedem novos agendamentos no periodo informado.</p>
        </div>
    <?php else: ?>
        <div class="tabela-area">
            <?php /* Tabela de apresentação dos registros retornados pela consulta. */ ?><table class="tabela">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Horario</th>
                        <th>Motivo</th>
                        <th>Situacao</th>
                        <th class="coluna-acoes">Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bloqueios as $bloqueio): ?>
                        <?php $futuro = strtotime($bloqueio['data_bloqueio']) >= strtotime('today'); ?>
                        <tr>
                            <td class="celula-principal"><?= formatarData($bloqueio['data_bloqueio']) ?></td>
                            <td><?= formatarHora($bloqueio['hora_inicio']) ?> as <?= formatarHora($bloqueio['hora_fim']) ?></td>
                            <td><?= e($bloqueio['motivo'] ?: '-') ?></td>
                            <td><?= $futuro ? '<span class="badge badge-agendado">Ativo</span>' : '<span class="badge badge-concluido">Encerrado</span>' ?></td>
                            <td class="coluna-acoes">
                                <?php if ($permitido && $futuro): ?>
                                    <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" style="display:inline">
                                        <?= campoCsrf() ?>
                                        <input type="hidden" name="acao" value="excluir">
                                        <input type="hidden" name="id_bloqueio" value="<?= (int) $bloqueio['id_bloqueio'] ?>">
                                        <button type="submit" class="btn btn-perigo btn-pequeno"
                                                data-confirmar="Remover o bloqueio do dia <?= formatarData($bloqueio['data_bloqueio']) ?>?"
                                                data-confirmar-titulo="Remover bloqueio"
                                                data-confirmar-rotulo="Remover">
                                            Remover
                                        </button>
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

<?php if ($permitido): ?>
    <?php /* Janela controlada pelo JavaScript para detalhes ou ações da página. */ ?><div class="modal" id="modalBloqueio">
        <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" class="modal-caixa">
            <?= campoCsrf() ?>
            <input type="hidden" name="acao" value="criar">

            <div class="modal-cabecalho">
                <h3>Novo bloqueio</h3>
                <button type="button" class="modal-fechar" data-fechar-modal aria-label="Fechar">&times;</button>
            </div>

            <div class="modal-corpo">
                <div class="campo">
                    <label for="data_bloqueio">Data</label>
                    <input type="date" id="data_bloqueio" name="data_bloqueio" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="linha-campos">
                    <div class="campo">
                        <label for="hora_inicio">Das</label>
                        <input type="time" id="hora_inicio" name="hora_inicio" value="08:00" required>
                    </div>
                    <div class="campo">
                        <label for="hora_fim">Ate</label>
                        <input type="time" id="hora_fim" name="hora_fim" value="18:00" required>
                    </div>
                </div>

                <div class="campo">
                    <label for="motivo">Motivo</label>
                    <input type="text" id="motivo" name="motivo" maxlength="255" placeholder="Ex.: folga, curso, consulta">
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
