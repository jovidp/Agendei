<?php

/**
 * Gestao de agendamentos: listagem, criacao manual, mudanca de status e cancelamento.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/../config/config.php';

// Restringe esta página a administradores autenticados.
exigirLogin('admin');

$erros = [];

// Processa o formulário enviado antes de montar o HTML da página.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Confere o token da sessão antes de aceitar alterações enviadas pelo formulário.
    exigirCsrf();

    $acao = post('acao');

    // Valida os dados recebidos e solicita a criação do registro.
    if ($acao === 'criar') {
        $resultado = Agendamento::criar([
            'id_cliente'      => (int) post('id_cliente'),
            'id_profissional' => (int) post('id_profissional'),
            'id_servico'      => (int) post('id_servico'),
            'data'            => post('data'),
            'hora_inicio'     => post('hora_inicio'),
            'observacao'      => limitarTexto(post('observacao'), 500),
            'origem'          => 'admin',
        ], ['ignorar_antecedencia' => true]);

        if ($resultado['sucesso']) {
            definirFlash('sucesso', 'Agendamento criado com sucesso.');
            redirecionar('admin/agendamentos.php');
        }

        $erros = $resultado['erros'];
    }

    // Trata a mudança de status solicitada pelo formulário.
    if ($acao === 'status') {
        $idAgendamento = (int) post('id_agendamento');
        $novoStatus    = post('status');
        $agendamento   = Agendamento::porId($idAgendamento);

        if (!in_array($novoStatus, ['agendado', 'confirmado', 'concluido'], true)) {
            definirFlash('erro', 'Status inválido.');
        } elseif (!$agendamento) {
            definirFlash('erro', 'Agendamento não encontrado.');
        } elseif ($novoStatus === 'concluido' && !Agendamento::jaComecou($agendamento)) {
            // Concluir antes da hora daria como atendido algo que ainda nao aconteceu
            // (e creditaria pontos de fidelidade). A mesma regra vale no painel do profissional.
            definirFlash('erro', 'O atendimento só pode ser concluído a partir de ' . formatarData($agendamento['data_agendamento']) . ' às ' . formatarHora($agendamento['hora_inicio']) . '.');
        } elseif (Agendamento::alterarStatus($idAgendamento, $novoStatus)) {
            definirFlash('sucesso', 'Status atualizado para ' . $novoStatus . '.');
        } else {
            definirFlash('erro', 'Não foi possível atualizar o status.');
        }

        redirecionar('admin/agendamentos.php');
    }

    // Trata o cancelamento do agendamento e o motivo informado.
    if ($acao === 'cancelar') {
        $idAgendamento = (int) post('id_agendamento');

        if (Agendamento::cancelar($idAgendamento, usuarioId(), limitarTexto(post('motivo'), 200))) {
            definirFlash('sucesso', 'Agendamento cancelado.');
        } else {
            definirFlash('erro', 'Nao foi possivel cancelar este agendamento.');
        }

        redirecionar('admin/agendamentos.php');
    }
}

$acaoTela = get('acao');

// ---------------------------------------------------------------------
// Formulario de agendamento manual
// ---------------------------------------------------------------------
if ($acaoTela === 'novo' || $erros !== []) {
    $clientes = Cliente::listar(['status' => 'ativo']);
    $servicos = Servico::ativosComProfissional();

    // Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
    $tituloPagina  = 'Novo agendamento';
    $subtituloTopo = 'Criacao manual pelo balcao';
    $acoesTopo     = '<a href="' . url('admin/agendamentos.php') . '" class="btn btn-contorno btn-pequeno">Voltar para a lista</a>';
    $jsExtra       = ['admin-agendamento.js'];

    // Renderiza a estrutura comum do painel após preparar os dados desta tela.
    require_once RAIZ . '/includes/painel_header.php';
?>

    <?php if ($erros !== []): ?>
        <div class="alerta alerta-erro">
            <div>
                <span class="alerta-texto">Nao foi possivel criar o agendamento:</span>
                <ul><?php foreach ($erros as $mensagem): ?><li><?= e($mensagem) ?></li><?php endforeach; ?></ul>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($clientes === [] || $servicos === []): ?>
        <div class="cartao">
            <div class="estado-vazio">
                <strong>Cadastros insuficientes</strong>
                <p>E necessario ter ao menos um cliente ativo e um servico com profissional vinculado.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="cartao">
            <div class="cartao-cabecalho">
                <h3>Dados do agendamento</h3>
            </div>
            <div class="cartao-corpo">
                <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" id="formAgendamentoManual" data-base="<?= e(url('')) ?>">
                    <?= campoCsrf() ?>
                    <input type="hidden" name="acao" value="criar">

                    <div class="campo">
                        <label for="id_cliente">Cliente <span class="obrigatorio">*</span></label>
                        <select id="id_cliente" name="id_cliente" required>
                            <option value="">Selecione o cliente</option>
                            <?php foreach ($clientes as $cliente): ?>
                                <option value="<?= (int) $cliente['id_cliente'] ?>"
                                    <?= (int) post('id_cliente') === (int) $cliente['id_cliente'] ? 'selected' : '' ?>>
                                    <?= e($cliente['nome']) ?> - <?= e(formatarTelefone($cliente['telefone'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="linha-campos">
                        <div class="campo">
                            <label for="id_servico">Servico <span class="obrigatorio">*</span></label>
                            <select id="id_servico" name="id_servico" required>
                                <option value="">Selecione o servico</option>
                                <?php foreach ($servicos as $servico): ?>
                                    <option value="<?= (int) $servico['id_servico'] ?>"
                                        <?= (int) post('id_servico') === (int) $servico['id_servico'] ? 'selected' : '' ?>>
                                        <?= e($servico['nome']) ?> - <?= formatarMoeda($servico['preco']) ?> (<?= e(duracaoTexto((int) $servico['duracao_minutos'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="campo">
                            <label for="id_profissional">Profissional <span class="obrigatorio">*</span></label>
                            <select id="id_profissional" name="id_profissional" required>
                                <option value="">Selecione o servico primeiro</option>
                            </select>
                        </div>
                    </div>

                    <div class="linha-campos">
                        <div class="campo">
                            <label for="data">Data <span class="obrigatorio">*</span></label>
                            <input type="date" id="data" name="data" min="<?= date('Y-m-d') ?>"
                                value="<?= e(post('data')) ?>" required>
                        </div>

                        <div class="campo">
                            <label for="hora_inicio">Horario <span class="obrigatorio">*</span></label>
                            <select id="hora_inicio" name="hora_inicio" required>
                                <option value="">Selecione servico, profissional e data</option>
                            </select>
                            <span class="mensagem-campo oculto" id="avisoHorarios"></span>
                        </div>
                    </div>

                    <div class="campo">
                        <label for="observacao">Observacao</label>
                        <textarea id="observacao" name="observacao" maxlength="500"></textarea>
                    </div>

                    <div class="grupo-botoes">
                        <button type="submit" class="btn">Criar agendamento</button>
                        <a href="<?= url('admin/agendamentos.php') ?>" class="btn btn-contorno">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

<?php
    require_once RAIZ . '/includes/painel_footer.php';
    exit;
}

// ---------------------------------------------------------------------
// Listagem
// ---------------------------------------------------------------------
$filtros = array_filter([
    'status'          => in_array(get('status'), Agendamento::STATUS, true) ? get('status') : '',
    'id_profissional' => (int) get('id_profissional') ?: '',
    'id_servico'      => (int) get('id_servico') ?: '',
    'data_inicial'    => validarData(get('data_inicial')) ? get('data_inicial') : '',
    'data_final'      => validarData(get('data_final')) ? get('data_final') : '',
    'busca'           => get('busca'),
]);

$porPagina = 15;
// Mantém a página como inteiro positivo para calcular a listagem e sua navegação.
$pagina    = max(1, (int) get('pagina', '1'));

$total = Agendamento::contar($filtros);
$lista = Agendamento::listar($filtros + [
    'ordem'        => 'desc',
    'limite'       => $porPagina,
    'deslocamento' => ($pagina - 1) * $porPagina,
]);

$profissionais = Profissional::listar();
$servicos      = Servico::listar();

// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina  = 'Agendamentos';
$subtituloTopo = $total . ' registro(s) encontrado(s)';
$acoesTopo     = '<a href="' . url('admin/agendamentos.php?acao=novo') . '" class="btn btn-pequeno">Novo agendamento</a>';

// Renderiza a estrutura comum do painel após preparar os dados desta tela.
require_once RAIZ . '/includes/painel_header.php';
?>

<div class="cartao">
    <?php /* Filtros enviados na URL para permitir atualizar e compartilhar a consulta. */ ?><form method="get" class="barra-filtros">
        <input type="hidden" name="estabelecimento" value="<?= e(Contexto::slug()) ?>">
        <div class="campo campo-busca">
            <label for="busca">Buscar</label>
            <input type="search" id="busca" name="busca" value="<?= e(get('busca')) ?>" placeholder="Cliente, profissional ou servico">
        </div>

        <div class="campo">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">Todos</option>
                <?php foreach (Agendamento::STATUS as $opcao): ?>
                    <option value="<?= e($opcao) ?>" <?= get('status') === $opcao ? 'selected' : '' ?>>
                        <?= e(ucfirst($opcao)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="campo">
            <label for="id_profissional">Profissional</label>
            <select id="id_profissional" name="id_profissional">
                <option value="">Todos</option>
                <?php foreach ($profissionais as $profissional): ?>
                    <option value="<?= (int) $profissional['id_profissional'] ?>"
                        <?= (int) get('id_profissional') === (int) $profissional['id_profissional'] ? 'selected' : '' ?>>
                        <?= e($profissional['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="campo">
            <label for="id_servico">Servico</label>
            <select id="id_servico" name="id_servico">
                <option value="">Todos</option>
                <?php foreach ($servicos as $servico): ?>
                    <option value="<?= (int) $servico['id_servico'] ?>"
                        <?= (int) get('id_servico') === (int) $servico['id_servico'] ? 'selected' : '' ?>>
                        <?= e($servico['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="campo">
            <label for="data_inicial">De</label>
            <input type="date" id="data_inicial" name="data_inicial" value="<?= e(get('data_inicial')) ?>">
        </div>

        <div class="campo">
            <label for="data_final">Ate</label>
            <input type="date" id="data_final" name="data_final" value="<?= e(get('data_final')) ?>">
        </div>

        <div class="campo">
            <button type="submit" class="btn">Filtrar</button>
        </div>

        <?php if ($filtros !== []): ?>
            <div class="campo">
                <a href="<?= url('admin/agendamentos.php') ?>" class="btn btn-contorno">Limpar</a>
            </div>
        <?php endif; ?>
    </form>

    <?php if ($lista === []): ?>
        <div class="estado-vazio">
            <strong>Nenhum agendamento encontrado.</strong>
            <p>Ajuste os filtros ou crie um agendamento manualmente.</p>
            <a href="<?= url('admin/agendamentos.php?acao=novo') ?>" class="btn margem-topo">Novo agendamento</a>
        </div>
    <?php else: ?>
        <div class="tabela-area">
            <?php /* Tabela de apresentação dos registros retornados pela consulta. */ ?><table class="tabela">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Data / horario</th>
                        <th>Cliente</th>
                        <th>Servico</th>
                        <th>Profissional</th>
                        <th>Valor</th>
                        <th>Status</th>
                        <th class="coluna-acoes">Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista as $agendamento): ?>
                        <tr>
                            <td><?= (int) $agendamento['id_agendamento'] ?></td>
                            <td>
                                <span class="celula-principal"><?= formatarData($agendamento['data_agendamento']) ?></span>
                                <span class="celula-secundaria">
                                    <?= formatarHora($agendamento['hora_inicio']) ?> as <?= formatarHora($agendamento['hora_fim']) ?>
                                </span>
                            </td>
                            <td>
                                <?= e($agendamento['nome_cliente']) ?>
                                <span class="celula-secundaria"><?= e(formatarTelefone($agendamento['telefone_cliente'])) ?></span>
                            </td>
                            <td><?= e($agendamento['nome_servico']) ?></td>
                            <td><?= e($agendamento['nome_profissional']) ?></td>
                            <td><?= formatarMoeda($agendamento['valor']) ?></td>
                            <td><?= badgeStatus($agendamento['status']) ?></td>
                            <td class="coluna-acoes">
                                <button type="button" class="btn btn-contorno btn-pequeno"
                                    data-modal="modalDetalhes"
                                    data-campo-cliente="<?= e($agendamento['nome_cliente']) ?>"
                                    data-campo-contato="<?= e(formatarTelefone($agendamento['telefone_cliente'])) ?> / <?= e($agendamento['email_cliente']) ?>"
                                    data-campo-servico="<?= e($agendamento['nome_servico']) ?>"
                                    data-campo-profissional="<?= e($agendamento['nome_profissional']) ?>"
                                    data-campo-data="<?= e(dataExtenso($agendamento['data_agendamento'])) ?>"
                                    data-campo-horario="<?= formatarHora($agendamento['hora_inicio']) ?> as <?= formatarHora($agendamento['hora_fim']) ?>"
                                    data-campo-valor="<?= formatarMoeda($agendamento['valor']) ?>"
                                    data-campo-status="<?= e(ucfirst($agendamento['status'])) ?>"
                                    data-campo-origem="<?= e(ucfirst($agendamento['origem'])) ?>"
                                    data-campo-observacao="<?= e($agendamento['observacao'] ?: 'Sem observacoes.') ?>">
                                    Detalhes
                                </button>

                                <?php if ($agendamento['status'] === 'agendado'): ?>
                                    <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" style="display:inline">
                                        <?= campoCsrf() ?>
                                        <input type="hidden" name="acao" value="status">
                                        <input type="hidden" name="id_agendamento" value="<?= (int) $agendamento['id_agendamento'] ?>">
                                        <input type="hidden" name="status" value="confirmado">
                                        <button type="submit" class="btn btn-contorno btn-pequeno">Confirmar</button>
                                    </form>
                                <?php endif; ?>

                                <?php if (in_array($agendamento['status'], ['agendado', 'confirmado'], true)): ?>
                                    <?php /* Concluir so aparece depois do horario de inicio; o servidor confere de novo. */ ?>
                                    <?php if (Agendamento::jaComecou($agendamento)): ?>
                                    <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" style="display:inline">
                                        <?= campoCsrf() ?>
                                        <input type="hidden" name="acao" value="status">
                                        <input type="hidden" name="id_agendamento" value="<?= (int) $agendamento['id_agendamento'] ?>">
                                        <input type="hidden" name="status" value="concluido">
                                        <button type="submit" class="btn btn-secundario btn-pequeno">Concluir</button>
                                    </form>
                                    <?php endif; ?>

                                    <button type="button" class="btn btn-perigo btn-pequeno"
                                        data-modal="modalCancelar"
                                        data-campo-id_agendamento="<?= (int) $agendamento['id_agendamento'] ?>"
                                        data-campo-resumo="<?= e($agendamento['nome_cliente']) ?> - <?= e($agendamento['nome_servico']) ?> em <?= formatarData($agendamento['data_agendamento']) ?>">
                                        Cancelar
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= renderizarPaginacao($total, $pagina, $porPagina, $filtros) ?>
    <?php endif; ?>
</div>

<?php /* Janela controlada pelo JavaScript para detalhes ou ações da página. */ ?><div class="modal" id="modalDetalhes">
    <div class="modal-caixa">
        <div class="modal-cabecalho">
            <h3>Detalhes do agendamento</h3>
            <button type="button" class="modal-fechar" data-fechar-modal aria-label="Fechar">&times;</button>
        </div>
        <div class="modal-corpo">
            <dl class="lista-detalhes">
                <div>
                    <dt>Cliente</dt>
                    <dd data-preenche="cliente">-</dd>
                </div>
                <div>
                    <dt>Contato</dt>
                    <dd data-preenche="contato">-</dd>
                </div>
                <div>
                    <dt>Servico</dt>
                    <dd data-preenche="servico">-</dd>
                </div>
                <div>
                    <dt>Profissional</dt>
                    <dd data-preenche="profissional">-</dd>
                </div>
                <div>
                    <dt>Data</dt>
                    <dd data-preenche="data">-</dd>
                </div>
                <div>
                    <dt>Horario</dt>
                    <dd data-preenche="horario">-</dd>
                </div>
                <div>
                    <dt>Valor</dt>
                    <dd data-preenche="valor">-</dd>
                </div>
                <div>
                    <dt>Status</dt>
                    <dd data-preenche="status">-</dd>
                </div>
                <div>
                    <dt>Origem</dt>
                    <dd data-preenche="origem">-</dd>
                </div>
                <div>
                    <dt>Observacao</dt>
                    <dd data-preenche="observacao">-</dd>
                </div>
            </dl>
        </div>
        <div class="modal-rodape">
            <button type="button" class="btn btn-contorno" data-fechar-modal>Fechar</button>
        </div>
    </div>
</div>

<?php /* Janela controlada pelo JavaScript para detalhes ou ações da página. */ ?><div class="modal" id="modalCancelar">
    <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" class="modal-caixa">
        <?= campoCsrf() ?>
        <input type="hidden" name="acao" value="cancelar">
        <input type="hidden" name="id_agendamento" value="">

        <div class="modal-cabecalho">
            <h3>Cancelar agendamento</h3>
            <button type="button" class="modal-fechar" data-fechar-modal aria-label="Fechar">&times;</button>
        </div>

        <div class="modal-corpo">
            <p>Confirma o cancelamento de <strong data-preenche="resumo"></strong>?</p>

            <div class="campo">
                <label for="motivo">Motivo do cancelamento</label>
                <input type="text" id="motivo" name="motivo" maxlength="200" placeholder="Ex.: solicitacao do cliente">
            </div>
        </div>

        <div class="modal-rodape">
            <button type="button" class="btn btn-contorno" data-fechar-modal>Voltar</button>
            <button type="submit" class="btn btn-perigo">Confirmar cancelamento</button>
        </div>
    </form>
</div>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>