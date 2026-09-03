<?php
/**
 * CRUD de servicos.
 */
require_once __DIR__ . '/../config/config.php';

exigirLogin('admin');

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();

    $acao       = post('acao');
    $idServico  = (int) post('id_servico');

    if ($acao === 'salvar') {
        $dados = [
            'nome'            => post('nome'),
            'descricao'       => post('descricao'),
            'preco'           => moedaParaDecimal(post('preco')),
            'duracao_minutos' => (int) post('duracao_minutos'),
            'status'          => post('status') === 'inativo' ? 'inativo' : 'ativo',
            'destaque'        => post('destaque') === '1',
        ];

        if (mb_strlen($dados['nome']) < 3) {
            $erros[] = 'Informe o nome do servico (minimo 3 caracteres).';
        }
        if ($dados['preco'] < 0) {
            $erros[] = 'Informe um preco valido.';
        }
        if ($dados['duracao_minutos'] < 5 || $dados['duracao_minutos'] > 600) {
            $erros[] = 'A duracao deve estar entre 5 e 600 minutos.';
        }

        if ($erros === []) {
            if ($idServico > 0) {
                Servico::atualizar($idServico, $dados);
                definirFlash('sucesso', 'Servico atualizado com sucesso.');
            } else {
                Servico::criar($dados);
                definirFlash('sucesso', 'Servico cadastrado com sucesso.');
            }
            redirecionar('admin/servicos.php');
        }
    }

    if ($acao === 'status' && $idServico > 0) {
        $novoStatus = post('status') === 'ativo' ? 'ativo' : 'inativo';
        Servico::alterarStatus($idServico, $novoStatus);
        definirFlash('sucesso', $novoStatus === 'ativo' ? 'Servico ativado.' : 'Servico desativado.');
        redirecionar('admin/servicos.php');
    }

    if ($acao === 'excluir' && $idServico > 0) {
        if (!Servico::podeExcluir($idServico)) {
            definirFlash('erro', 'Este servico ja possui agendamentos e nao pode ser excluido. Desative-o para retirar da lista.');
        } else {
            Servico::excluir($idServico);
            definirFlash('sucesso', 'Servico excluido.');
        }
        redirecionar('admin/servicos.php');
    }
}

$acaoTela = get('acao');
$edicao   = null;

if ($acaoTela === 'editar') {
    $edicao = Servico::porId((int) get('id'));
    if (!$edicao) {
        definirFlash('erro', 'Servico nao encontrado.');
        redirecionar('admin/servicos.php');
    }
}

$formularioAberto = in_array($acaoTela, ['novo', 'editar'], true) || $erros !== [];

$busca   = get('busca');
$status  = get('status');
$lista   = Servico::listar(array_filter(['busca' => $busca, 'status' => $status]));

$tituloPagina  = 'Servicos';
$subtituloTopo = 'Cadastro, precos e duracao dos servicos';
$acoesTopo     = $formularioAberto
    ? '<a href="' . url('admin/servicos.php') . '" class="btn btn-contorno btn-pequeno">Voltar para a lista</a>'
    : '<a href="' . url('admin/servicos.php?acao=novo') . '" class="btn btn-pequeno">Novo servico</a>';

require_once RAIZ . '/includes/painel_header.php';
?>

<?php if ($erros !== []): ?>
    <div class="alerta alerta-erro">
        <div>
            <span class="alerta-texto">Corrija os itens abaixo:</span>
            <ul>
                <?php foreach ($erros as $mensagem): ?><li><?= e($mensagem) ?></li><?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<?php if ($formularioAberto): ?>

    <div class="cartao">
        <div class="cartao-cabecalho">
            <h3><?= $edicao ? 'Editar servico' : 'Novo servico' ?></h3>
        </div>
        <div class="cartao-corpo">
            <form method="post">
                <?= campoCsrf() ?>
                <input type="hidden" name="acao" value="salvar">
                <input type="hidden" name="id_servico" value="<?= (int) ($edicao['id_servico'] ?? 0) ?>">

                <div class="campo">
                    <label for="nome">Nome do servico <span class="obrigatorio">*</span></label>
                    <input type="text" id="nome" name="nome" maxlength="120"
                           value="<?= e($edicao['nome'] ?? post('nome')) ?>" required>
                </div>

                <div class="campo">
                    <label for="descricao">Descricao</label>
                    <textarea id="descricao" name="descricao" maxlength="500"><?= e($edicao['descricao'] ?? post('descricao')) ?></textarea>
                    <span class="ajuda-campo">Aparece na pagina inicial e na tela de agendamento.</span>
                </div>

                <div class="linha-campos-3">
                    <div class="campo">
                        <label for="preco">Preco (R$) <span class="obrigatorio">*</span></label>
                        <input type="text" id="preco" name="preco" data-mascara="moeda" inputmode="numeric"
                               value="<?= e($edicao ? number_format((float) $edicao['preco'], 2, ',', '') : post('preco')) ?>" required>
                    </div>

                    <div class="campo">
                        <label for="duracao_minutos">Duracao (minutos) <span class="obrigatorio">*</span></label>
                        <input type="number" id="duracao_minutos" name="duracao_minutos" min="5" max="600" step="5"
                               value="<?= (int) ($edicao['duracao_minutos'] ?? post('duracao_minutos', '30')) ?>" required>
                    </div>

                    <div class="campo">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="ativo" <?= ($edicao['status'] ?? 'ativo') === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                            <option value="inativo" <?= ($edicao['status'] ?? '') === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                        </select>
                    </div>
                </div>

                <div class="campo-checkbox">
                    <input type="checkbox" id="destaque" name="destaque" value="1"
                           <?= !empty($edicao['destaque']) ? 'checked' : '' ?>>
                    <label for="destaque">Exibir em destaque na pagina inicial</label>
                </div>

                <div class="grupo-botoes">
                    <button type="submit" class="btn"><?= $edicao ? 'Salvar alteracoes' : 'Cadastrar servico' ?></button>
                    <a href="<?= url('admin/servicos.php') ?>" class="btn btn-contorno">Cancelar</a>
                </div>
            </form>
        </div>
    </div>

<?php endif; ?>

<div class="cartao">
    <form method="get" class="barra-filtros">
        <div class="campo campo-busca">
            <label for="busca">Buscar servico</label>
            <input type="search" id="busca" name="busca" value="<?= e($busca) ?>" placeholder="Nome ou descricao">
        </div>

        <div class="campo">
            <label for="filtroStatus">Status</label>
            <select id="filtroStatus" name="status" data-envia-ao-mudar>
                <option value="">Todos</option>
                <option value="ativo" <?= $status === 'ativo' ? 'selected' : '' ?>>Ativos</option>
                <option value="inativo" <?= $status === 'inativo' ? 'selected' : '' ?>>Inativos</option>
            </select>
        </div>

        <div class="campo">
            <button type="submit" class="btn btn-contorno">Filtrar</button>
        </div>
    </form>

    <?php if ($lista === []): ?>
        <div class="estado-vazio">
            <strong>Nenhum servico cadastrado.</strong>
            <p>Cadastre os servicos oferecidos para liberar os agendamentos.</p>
            <a href="<?= url('admin/servicos.php?acao=novo') ?>" class="btn margem-topo">Novo servico</a>
        </div>
    <?php else: ?>
        <div class="tabela-area">
            <table class="tabela">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Servico</th>
                        <th>Preco</th>
                        <th>Duracao</th>
                        <th>Profissionais</th>
                        <th>Status</th>
                        <th>Cadastro</th>
                        <th class="coluna-acoes">Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista as $servico): ?>
                        <?php $totalProfissionais = count(Profissional::porServico((int) $servico['id_servico'])); ?>
                        <tr>
                            <td><?= (int) $servico['id_servico'] ?></td>
                            <td>
                                <span class="celula-principal"><?= e($servico['nome']) ?></span>
                                <?php if (!empty($servico['descricao'])): ?>
                                    <span class="celula-secundaria"><?= e(limitarTexto($servico['descricao'], 60)) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= formatarMoeda($servico['preco']) ?></td>
                            <td><?= e(duracaoTexto((int) $servico['duracao_minutos'])) ?></td>
                            <td><?= $totalProfissionais ?></td>
                            <td><?= badgeStatus($servico['status']) ?></td>
                            <td><?= formatarData($servico['data_cadastro']) ?></td>
                            <td class="coluna-acoes">
                                <a href="<?= url('admin/servicos.php?acao=editar&id=' . (int) $servico['id_servico']) ?>"
                                   class="btn btn-contorno btn-pequeno">Editar</a>

                                <form method="post" style="display:inline">
                                    <?= campoCsrf() ?>
                                    <input type="hidden" name="acao" value="status">
                                    <input type="hidden" name="id_servico" value="<?= (int) $servico['id_servico'] ?>">
                                    <input type="hidden" name="status" value="<?= $servico['status'] === 'ativo' ? 'inativo' : 'ativo' ?>">
                                    <button type="submit" class="btn btn-contorno btn-pequeno">
                                        <?= $servico['status'] === 'ativo' ? 'Desativar' : 'Ativar' ?>
                                    </button>
                                </form>

                                <?php if (Servico::podeExcluir((int) $servico['id_servico'])): ?>
                                    <form method="post" style="display:inline">
                                        <?= campoCsrf() ?>
                                        <input type="hidden" name="acao" value="excluir">
                                        <input type="hidden" name="id_servico" value="<?= (int) $servico['id_servico'] ?>">
                                        <button type="submit" class="btn btn-perigo btn-pequeno"
                                                data-confirmar="Excluir o servico <?= e($servico['nome']) ?>? Esta acao nao pode ser desfeita."
                                                data-confirmar-titulo="Excluir servico"
                                                data-confirmar-rotulo="Excluir">
                                            Excluir
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

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>
