<?php
/**
 * Planos de contratacao e o que cada um permite.
 *
 * O plano descreve tetos, nao cobranca: equipe, catalogo e volume de
 * agendamentos no mes. Campo em branco significa ilimitado, e empresa sem
 * plano continua sem teto — quem ja usa o sistema nao sente nada ate receber
 * um plano.
 */
define('AREA_MASTER', true);
require_once __DIR__ . '/../config/config.php';
exigirLogin('master');

$erros = [];
$acaoTela = get('acao');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $acao = post('acao');

    try {
        if ($acao === 'criar' || $acao === 'editar') {
            $id = (int) post('id_plano');
            $nome = trim(post('nome'));
            $dados = [
                'nome'                    => $nome,
                'descricao'               => post('descricao'),
                'limite_profissionais'    => post('limite_profissionais'),
                'limite_servicos'         => post('limite_servicos'),
                'limite_agendamentos_mes' => post('limite_agendamentos_mes'),
            ];

            if (mb_strlen($nome) < 2 || mb_strlen($nome) > 60) {
                $erros[] = 'Informe um nome de 2 a 60 caracteres para o plano.';
            }

            $existente = Plano::porNome($nome);
            if ($existente && (int) $existente['id_plano'] !== $id) {
                $erros[] = 'Ja existe um plano com esse nome.';
            }

            foreach (['limite_profissionais', 'limite_servicos', 'limite_agendamentos_mes'] as $campo) {
                if (post($campo) !== '' && (int) post($campo) < 0) {
                    $erros[] = 'Os limites nao podem ser negativos. Deixe em branco para ilimitado.';
                    break;
                }
            }

            if (!$erros && $acao === 'criar') {
                $novo = Plano::criar($dados);
                LogMaster::registrar('plano_criado', ['alvo' => $nome, 'detalhe' => 'plano ' . $novo]);
                definirFlash('sucesso', 'Plano criado.');
                redirecionar('master/planos.php');
            }

            if (!$erros && $acao === 'editar') {
                $antigo = Plano::porId($id);
                if (!$antigo) {
                    $erros[] = 'Plano nao encontrado.';
                } else {
                    Plano::atualizar($id, $dados);
                    LogMaster::registrar('plano_alterado', ['alvo' => $nome, 'detalhe' => 'limites revisados']);
                    definirFlash('sucesso', 'Plano atualizado. Os novos tetos passam a valer nas proximas criacoes.');
                    redirecionar('master/planos.php');
                }
            }
        }

        if ($acao === 'status') {
            $id = (int) post('id_plano');
            $plano = Plano::porId($id);
            $novoStatus = post('status') === 'ativo' ? 'ativo' : 'inativo';

            if (!$plano) {
                $erros[] = 'Plano nao encontrado.';
            } else {
                Plano::alterarStatus($id, $novoStatus);
                LogMaster::registrar('plano_alterado', [
                    'alvo'    => $plano['nome'],
                    'detalhe' => 'de ' . $plano['status'] . ' para ' . $novoStatus,
                ]);
                definirFlash('sucesso', 'Plano atualizado.');
                redirecionar('master/planos.php');
            }
        }

        if ($acao === 'atribuir') {
            $idEmpresa = (int) post('id_estabelecimento');
            $idPlano = post('id_plano') === '' ? null : (int) post('id_plano');
            $empresa = Estabelecimento::porIdGlobal($idEmpresa);
            $plano = $idPlano === null ? null : Plano::porId($idPlano);

            if (!$empresa) {
                $erros[] = 'Estabelecimento nao encontrado.';
            } elseif ($idPlano !== null && !$plano) {
                $erros[] = 'Plano nao encontrado.';
            } else {
                Plano::atribuir($idEmpresa, $idPlano, post('observacao'));
                LogMaster::registrar('plano_atribuido', [
                    'estabelecimento'      => $idEmpresa,
                    'estabelecimento_nome' => $empresa['nome'],
                    'alvo'                 => $plano['nome'] ?? 'sem plano',
                    'detalhe'              => $plano === null ? 'plano removido: volta a ser ilimitado' : 'limites do plano passam a valer',
                ]);
                definirFlash('sucesso', 'Plano do estabelecimento atualizado.');
                redirecionar('master/planos.php');
            }
        }
    } catch (Throwable $erro) {
        $erros[] = 'Nao foi possivel concluir a operacao.';
        error_log('Falha na tela de planos: ' . $erro->getMessage());
    }
}

$disponivel = Plano::disponivel();
$planos = Plano::listar();
$empresas = Estabelecimento::listarTodos();
$vinculos = Plano::porEstabelecimento();
$emEdicao = $acaoTela === 'editar' ? Plano::porId((int) get('id')) : null;

// Uma edicao recusada volta pelo POST, sem o id na URL. Sem isto o formulario
// reabriria em modo de criacao e a correcao viraria um plano novo.
if ($emEdicao === null && $erros && post('acao') === 'editar') {
    $emEdicao = Plano::porId((int) post('id_plano'));
}

$tituloPagina  = 'Planos e limites';
$subtituloTopo = count($planos) . ' plano(s) cadastrado(s)';
$acoesTopo = $acaoTela === 'novo'
    ? '<a class="btn btn-contorno btn-pequeno" href="' . url('master/planos.php') . '">Voltar</a>'
    : '<a class="btn btn-pequeno" href="' . url('master/planos.php?acao=novo') . '">Novo plano</a>';
require RAIZ . '/includes/painel_header.php';
?>
<?php if ($erros): ?>
    <div class="alerta alerta-erro" role="alert"><ul><?php foreach ($erros as $mensagem): ?><li><?= e($mensagem) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if (!$disponivel): ?>
    <div class="alerta alerta-erro" role="alert">
        As tabelas de planos nao estao disponiveis neste banco. Rode <code>php scripts/migrar_seguranca.php</code> no servidor.
    </div>
<?php endif; ?>

<?php if ($acaoTela === 'novo' || $emEdicao || ($erros && in_array(post('acao'), ['criar', 'editar'], true))): ?>
    <?php $plano = $emEdicao; ?>
    <div class="cartao">
        <div class="cartao-cabecalho"><h3><?= $plano ? 'Editar plano' : 'Novo plano' ?></h3><small>Campo em branco = sem limite</small></div>
        <div class="cartao-corpo">
            <form method="post">
                <?= campoCsrf() ?>
                <input type="hidden" name="acao" value="<?= $plano ? 'editar' : 'criar' ?>">
                <input type="hidden" name="id_plano" value="<?= (int) ($plano['id_plano'] ?? 0) ?>">
                <div class="linha-campos">
                    <div class="campo"><label for="nome">Nome</label><input id="nome" name="nome" maxlength="60" value="<?= e($plano['nome'] ?? '') ?>" required></div>
                    <div class="campo"><label for="descricao">Descricao</label><input id="descricao" name="descricao" maxlength="255" value="<?= e($plano['descricao'] ?? '') ?>"></div>
                </div>
                <div class="linha-campos">
                    <div class="campo">
                        <label for="limite_profissionais">Profissionais ativos</label>
                        <input type="number" min="0" id="limite_profissionais" name="limite_profissionais" value="<?= e((string) ($plano['limite_profissionais'] ?? '')) ?>" placeholder="ilimitado">
                    </div>
                    <div class="campo">
                        <label for="limite_servicos">Servicos ativos</label>
                        <input type="number" min="0" id="limite_servicos" name="limite_servicos" value="<?= e((string) ($plano['limite_servicos'] ?? '')) ?>" placeholder="ilimitado">
                    </div>
                    <div class="campo">
                        <label for="limite_agendamentos_mes">Agendamentos por mes</label>
                        <input type="number" min="0" id="limite_agendamentos_mes" name="limite_agendamentos_mes" value="<?= e((string) ($plano['limite_agendamentos_mes'] ?? '')) ?>" placeholder="ilimitado">
                    </div>
                </div>
                <p class="ajuda-campo">
                    O teto e conferido na hora de criar. Baixar um plano nao apaga o que a empresa ja tem: ela apenas para de crescer ate voltar para dentro do limite.
                </p>
                <button class="btn" type="submit"><?= $plano ? 'Salvar plano' : 'Criar plano' ?></button>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="cartao">
    <div class="cartao-cabecalho"><h3>Planos</h3></div>
    <?php if ($planos === []): ?>
        <div class="estado-vazio">
            <strong>Nenhum plano cadastrado.</strong>
            <p>Sem plano, todo estabelecimento fica sem teto — que e exatamente como o sistema funcionava ate aqui.</p>
        </div>
    <?php else: ?>
        <div class="tabela-area">
            <table class="tabela">
                <thead><tr><th>Plano</th><th>Profissionais</th><th>Servicos</th><th>Agendamentos/mes</th><th>Empresas</th><th>Status</th><th class="coluna-acoes">Acoes</th></tr></thead>
                <tbody>
                <?php foreach ($planos as $plano): ?>
                    <tr>
                        <td class="celula-principal"><?= e($plano['nome']) ?><span class="celula-secundaria"><?= e((string) ($plano['descricao'] ?? '')) ?></span></td>
                        <td><?= $plano['limite_profissionais'] === null ? 'ilimitado' : (int) $plano['limite_profissionais'] ?></td>
                        <td><?= $plano['limite_servicos'] === null ? 'ilimitado' : (int) $plano['limite_servicos'] ?></td>
                        <td><?= $plano['limite_agendamentos_mes'] === null ? 'ilimitado' : (int) $plano['limite_agendamentos_mes'] ?></td>
                        <td><?= (int) $plano['contratantes'] ?></td>
                        <td><?= badgeStatus($plano['status']) ?></td>
                        <td class="coluna-acoes">
                            <div class="acoes-tabela">
                                <a class="btn btn-contorno btn-pequeno" href="<?= url('master/planos.php?acao=editar&id=' . (int) $plano['id_plano']) ?>">Editar</a>
                                <form method="post" style="display:inline">
                                    <?= campoCsrf() ?>
                                    <input type="hidden" name="acao" value="status">
                                    <input type="hidden" name="id_plano" value="<?= (int) $plano['id_plano'] ?>">
                                    <input type="hidden" name="status" value="<?= $plano['status'] === 'ativo' ? 'inativo' : 'ativo' ?>">
                                    <button class="btn btn-pequeno <?= $plano['status'] === 'ativo' ? 'btn-perigo' : 'btn-secundario' ?>" type="submit"
                                            data-confirmar="Alterar a disponibilidade deste plano?">
                                        <?= $plano['status'] === 'ativo' ? 'Desativar' : 'Ativar' ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="cartao">
    <div class="cartao-cabecalho"><h3>Plano de cada estabelecimento</h3><small>Consumo do mes corrente</small></div>
    <?php if ($empresas === []): ?>
        <div class="estado-vazio"><strong>Nenhum estabelecimento cadastrado.</strong></div>
    <?php else: ?>
        <div class="tabela-area">
            <table class="tabela">
                <thead><tr><th>Estabelecimento</th><th>Plano</th><th>Consumo</th><th class="coluna-acoes">Alterar</th></tr></thead>
                <tbody>
                <?php foreach ($empresas as $empresa): ?>
                    <?php
                    $idEmpresa = (int) $empresa['id_estabelecimento'];
                    $vinculo = $vinculos[$idEmpresa] ?? null;
                    $situacao = Plano::situacao($idEmpresa);
                    ?>
                    <tr>
                        <td class="celula-principal"><?= e($empresa['nome']) ?><span class="celula-secundaria"><?= e($empresa['slug']) ?></span></td>
                        <td><?= $vinculo ? e($vinculo['nome']) : '<span class="celula-secundaria">sem plano</span>' ?></td>
                        <td>
                            <?php foreach ($situacao as $item): ?>
                                <div<?= $item['excedido'] ? ' class="texto-aviso"' : '' ?>>
                                    <?= e($item['rotulo']) ?>: <?= (int) $item['usado'] ?><?= $item['limite'] === null ? '' : ' / ' . (int) $item['limite'] ?>
                                </div>
                            <?php endforeach; ?>
                        </td>
                        <td class="coluna-acoes">
                            <form method="post" class="grupo-botoes">
                                <?= campoCsrf() ?>
                                <input type="hidden" name="acao" value="atribuir">
                                <input type="hidden" name="id_estabelecimento" value="<?= $idEmpresa ?>">
                                <select name="id_plano" aria-label="Plano de <?= e($empresa['nome']) ?>">
                                    <option value="">Sem plano (ilimitado)</option>
                                    <?php foreach ($planos as $plano): ?>
                                        <?php if ($plano['status'] === 'ativo' || (int) ($vinculo['id_plano'] ?? 0) === (int) $plano['id_plano']): ?>
                                            <option value="<?= (int) $plano['id_plano'] ?>" <?= (int) ($vinculo['id_plano'] ?? 0) === (int) $plano['id_plano'] ? 'selected' : '' ?>>
                                                <?= e($plano['nome']) ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-contorno btn-pequeno" type="submit">Aplicar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require RAIZ . '/includes/painel_footer.php'; ?>
