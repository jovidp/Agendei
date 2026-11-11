<?php

/**
 * Assinaturas que o estabelecimento vende aos clientes: planos (servicos
 * incluidos por mes e desconto no restante) e assinantes, com a confirmacao
 * das mensalidades pagas por Pix.
 */
require_once __DIR__ . '/../config/config.php';
exigirLogin('admin');

if (!Plano::temRecurso('diferenciais')) {
    $recursoFaltante = 'diferenciais';
    $tituloPagina = 'Assinaturas de clientes';
    require RAIZ . '/includes/fora_do_plano.php';
}

/** Monta os dados do plano a partir do formulario (quantidade vazia = ilimitado). */
function planoDoFormulario(): array
{
    $servicos = [];
    foreach ((array) ($_POST['incluir'] ?? []) as $idServico => $marcado) {
        $quantidade = trim((string) ($_POST['quantidade'][$idServico] ?? ''));
        $servicos[(int) $idServico] = $quantidade === '' ? null : $quantidade;
    }
    return [
        'nome'                => post('nome'),
        'descricao'           => post('descricao'),
        'preco_mensal'        => post('preco_mensal'),
        'desconto_percentual' => post('desconto_percentual'),
        'servicos'            => $servicos,
    ];
}

$erros = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $acao = post('acao');
    try {
        if ($acao === 'salvar_plano') {
            $idPlano = (int) post('id_plano_cliente');
            if ($idPlano > 0) {
                AssinaturaCliente::atualizarPlano($idPlano, planoDoFormulario());
                definirFlash('sucesso', 'Plano atualizado. As mudanças valem para os próximos agendamentos.');
            } else {
                AssinaturaCliente::criarPlano(planoDoFormulario());
                definirFlash('sucesso', 'Plano criado.');
            }
        } elseif ($acao === 'status_plano') {
            AssinaturaCliente::alterarStatusPlano((int) post('id_plano_cliente'), post('status') === 'ativo' ? 'ativo' : 'inativo');
            definirFlash('sucesso', post('status') === 'ativo' ? 'Plano ativado.' : 'Plano desativado. Quem já assina continua com ele.');
        } elseif ($acao === 'inscrever') {
            AssinaturaCliente::assinar((int) post('id_cliente'), (int) post('id_plano_cliente'));
            definirFlash('sucesso', 'Cliente inscrito. A assinatura começa quando a primeira mensalidade for confirmada.');
        } elseif ($acao === 'confirmar') {
            AssinaturaCliente::confirmarPagamento((int) post('id_cobranca'))
                ? definirFlash('sucesso', 'Pagamento confirmado. A assinatura está ativa.')
                : definirFlash('erro', 'Esta cobrança já foi confirmada ou cancelada.');
        } elseif ($acao === 'cancelar_cobranca') {
            AssinaturaCliente::cancelarCobranca((int) post('id_cobranca'));
            definirFlash('sucesso', 'Cobrança cancelada.');
        } elseif ($acao === 'cancelar') {
            AssinaturaCliente::cancelar((int) post('id_cliente_assinatura'));
            definirFlash('sucesso', 'Assinatura cancelada. Os benefícios valem até o fim do período pago.');
        }
        redirecionar('admin/assinaturas_clientes.php');
    } catch (InvalidArgumentException | DomainException $erro) {
        $erros[] = $erro->getMessage();
    }
}

$edicao = get('acao') === 'editar' ? AssinaturaCliente::planoPorId((int) get('id')) : null;
$formularioAberto = $edicao !== null || get('acao') === 'novo' || ($erros !== [] && post('acao') === 'salvar_plano');
$incluidos = $edicao ? array_column($edicao['servicos'], 'quantidade_mes', 'id_servico') : [];
$planos = AssinaturaCliente::planos();
$planosAtivos = array_filter($planos, fn($p) => $p['status'] === 'ativo');
$assinantes = AssinaturaCliente::assinantes();
$servicos = Servico::listar(['status' => 'ativo']);
$clientes = Cliente::listar(['status' => 'ativo']);
$semPix = Configuracao::obter('pix_chave') === '';

$tituloPagina  = 'Assinaturas de clientes';
$subtituloTopo = 'Planos mensais que você vende aos seus clientes';
$acoesTopo     = $formularioAberto
    ? '<a href="' . url('admin/assinaturas_clientes.php') . '" class="btn btn-contorno btn-pequeno">Voltar</a>'
    : '<a href="' . url('admin/assinaturas_clientes.php?acao=novo') . '" class="btn btn-pequeno">Novo plano</a>';
require_once RAIZ . '/includes/painel_header.php';
?>

<?php if ($erros !== []): ?>
    <div class="alerta alerta-erro"><div><span class="alerta-texto"><?= e(implode(' ', $erros)) ?></span></div></div>
<?php endif; ?>

<?php if ($semPix): ?>
    <div class="alerta alerta-aviso"><div><span class="alerta-texto">Cadastre a chave Pix em <a href="<?= url('admin/diferenciais.php') ?>">Diferenciais</a> para receber as mensalidades. Sem ela, ninguém consegue assinar.</span></div></div>
<?php endif; ?>

<?php if ($formularioAberto): ?>
    <div class="cartao">
        <div class="cartao-cabecalho"><h3><?= $edicao ? 'Editar plano' : 'Novo plano' ?></h3></div>
        <div class="cartao-corpo">
            <form method="post">
                <?= campoCsrf() ?>
                <input type="hidden" name="acao" value="salvar_plano">
                <input type="hidden" name="id_plano_cliente" value="<?= (int) ($edicao['id_plano_cliente'] ?? 0) ?>">
                <div class="campo">
                    <label for="nome">Nome do plano <span class="obrigatorio">*</span></label>
                    <input type="text" id="nome" name="nome" maxlength="120" required value="<?= e($edicao['nome'] ?? post('nome')) ?>" placeholder="Ex.: Clube da Barba">
                </div>
                <div class="campo">
                    <label for="descricao">Descrição</label>
                    <textarea id="descricao" name="descricao" maxlength="500"><?= e($edicao['descricao'] ?? post('descricao')) ?></textarea>
                </div>
                <div class="linha-campos">
                    <div class="campo">
                        <label for="preco_mensal">Preço por mês (R$) <span class="obrigatorio">*</span></label>
                        <input type="text" id="preco_mensal" name="preco_mensal" data-mascara="moeda" inputmode="numeric" required
                            value="<?= e($edicao ? number_format((float) $edicao['preco_mensal'], 2, ',', '') : post('preco_mensal')) ?>">
                    </div>
                    <div class="campo">
                        <label for="desconto_percentual">Desconto nos outros serviços (%)</label>
                        <input type="number" id="desconto_percentual" name="desconto_percentual" min="0" max="100"
                            value="<?= (int) ($edicao['desconto_percentual'] ?? post('desconto_percentual', '0')) ?>">
                    </div>
                </div>
                <fieldset class="grupo-campos">
                    <legend>Serviços incluídos</legend>
                    <p class="ajuda-campo">Marque o que o plano cobre e quantas vezes por mês. Deixe a quantidade vazia para ilimitado.</p>
                    <?php foreach ($servicos as $s): $id = (int) $s['id_servico']; $marcado = array_key_exists($id, $incluidos) || isset($_POST['incluir'][$id]); ?>
                        <div class="linha-campos">
                            <div class="campo-checkbox">
                                <input type="checkbox" id="incluir_<?= $id ?>" name="incluir[<?= $id ?>]" value="1" <?= $marcado ? 'checked' : '' ?>>
                                <label for="incluir_<?= $id ?>"><?= e($s['nome']) ?> <span class="celula-secundaria"><?= formatarMoeda($s['preco']) ?></span></label>
                            </div>
                            <div class="campo">
                                <label for="quantidade_<?= $id ?>">Vezes por mês</label>
                                <input type="number" id="quantidade_<?= $id ?>" name="quantidade[<?= $id ?>]" min="1" placeholder="Ilimitado"
                                    value="<?= e((string) ($incluidos[$id] ?? ($_POST['quantidade'][$id] ?? ''))) ?>">
                            </div>
                        </div>
                    <?php endforeach; ?>
                </fieldset>
                <div class="grupo-botoes">
                    <button class="btn"><?= $edicao ? 'Salvar alterações' : 'Criar plano' ?></button>
                    <a href="<?= url('admin/assinaturas_clientes.php') ?>" class="btn btn-contorno">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="cartao">
    <div class="cartao-cabecalho"><h3>Planos</h3></div>
    <?php if ($planos === []): ?>
        <div class="estado-vazio">
            <strong>Nenhum plano criado</strong>
            <p>Crie um plano mensal com serviços incluídos e desconto para os assinantes.</p>
            <a href="<?= url('admin/assinaturas_clientes.php?acao=novo') ?>" class="btn margem-topo">Novo plano</a>
        </div>
    <?php else: ?>
        <div class="tabela-area"><table class="tabela">
            <thead><tr><th>Plano</th><th>Preço</th><th>Incluídos por mês</th><th>Desconto</th><th>Status</th><th class="coluna-acoes">Ações</th></tr></thead>
            <tbody>
            <?php foreach ($planos as $p): ?>
                <tr>
                    <td><span class="celula-principal"><?= e($p['nome']) ?></span><?php if ($p['descricao']): ?><span class="celula-secundaria"><?= e(limitarTexto($p['descricao'], 60)) ?></span><?php endif; ?></td>
                    <td><?= formatarMoeda($p['preco_mensal']) ?></td>
                    <td><?= e(implode(', ', array_map(fn($s) => $s['nome'] . ' (' . ($s['quantidade_mes'] ?? 'ilimitado') . ')', $p['servicos']))) ?></td>
                    <td><?= (int) $p['desconto_percentual'] ?>%</td>
                    <td><?= badgeStatus($p['status']) ?></td>
                    <td class="coluna-acoes">
                        <a class="btn btn-contorno btn-pequeno" href="<?= url('admin/assinaturas_clientes.php?acao=editar&id=' . (int) $p['id_plano_cliente']) ?>">Editar</a>
                        <form method="post" style="display:inline"><?= campoCsrf() ?>
                            <input type="hidden" name="acao" value="status_plano">
                            <input type="hidden" name="id_plano_cliente" value="<?= (int) $p['id_plano_cliente'] ?>">
                            <input type="hidden" name="status" value="<?= $p['status'] === 'ativo' ? 'inativo' : 'ativo' ?>">
                            <button class="btn btn-contorno btn-pequeno"><?= $p['status'] === 'ativo' ? 'Desativar' : 'Ativar' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>

<div class="cartao">
    <div class="cartao-cabecalho"><h3>Assinantes</h3></div>
    <?php if ($planosAtivos !== [] && $clientes !== []): ?>
        <div class="cartao-corpo">
            <form method="post" class="barra-filtros">
                <?= campoCsrf() ?>
                <input type="hidden" name="acao" value="inscrever">
                <div class="campo">
                    <label for="id_cliente">Cliente</label>
                    <select id="id_cliente" name="id_cliente" required>
                        <?php foreach ($clientes as $c): ?><option value="<?= (int) $c['id_cliente'] ?>"><?= e($c['nome']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="campo">
                    <label for="plano_inscrever">Plano</label>
                    <select id="plano_inscrever" name="id_plano_cliente" required>
                        <?php foreach ($planosAtivos as $p): ?><option value="<?= (int) $p['id_plano_cliente'] ?>"><?= e($p['nome']) ?> — <?= formatarMoeda($p['preco_mensal']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="campo"><button class="btn btn-contorno">Inscrever cliente</button></div>
            </form>
        </div>
    <?php endif; ?>
    <?php if ($assinantes === []): ?>
        <div class="estado-vazio"><strong>Nenhum assinante ainda</strong><p>Os clientes assinam em "Meus benefícios", ou você inscreve um aqui.</p></div>
    <?php else: ?>
        <div class="tabela-area"><table class="tabela">
            <thead><tr><th>Cliente</th><th>Plano</th><th>Situação</th><th>Período pago</th><th>Mensalidade pendente</th><th class="coluna-acoes">Ações</th></tr></thead>
            <tbody>
            <?php foreach ($assinantes as $a): $cob = $a['cobranca_pendente']; ?>
                <tr>
                    <td><?= e($a['nome_cliente']) ?></td>
                    <td><?= e($a['nome_plano']) ?></td>
                    <td><?= AssinaturaCliente::badgeSituacao($a['situacao']) ?></td>
                    <td><?= $a['periodo_inicio'] ? formatarData($a['periodo_inicio']) . ' a ' . formatarData($a['periodo_fim']) : '-' ?></td>
                    <td><?= $cob ? formatarMoeda($cob['valor']) . ' <span class="celula-secundaria">' . e($cob['referencia'] . ' · ' . Cobranca::rotuloPagamento($cob['metodo'] ?? null, $cob['cartao_final'] ?? null)) . '</span>' : '-' ?></td>
                    <td class="coluna-acoes">
                        <?php if ($cob): ?>
                            <form method="post" style="display:inline"><?= campoCsrf() ?>
                                <input type="hidden" name="acao" value="confirmar"><input type="hidden" name="id_cobranca" value="<?= (int) $cob['id_cobranca'] ?>">
                                <button class="btn btn-pequeno">Confirmar pagamento</button>
                            </form>
                            <form method="post" style="display:inline"><?= campoCsrf() ?>
                                <input type="hidden" name="acao" value="cancelar_cobranca"><input type="hidden" name="id_cobranca" value="<?= (int) $cob['id_cobranca'] ?>">
                                <button class="btn btn-contorno btn-pequeno">Cancelar cobrança</button>
                            </form>
                        <?php endif; ?>
                        <?php if (!in_array($a['situacao'], ['cancelada', 'encerrada'], true)): ?>
                            <form method="post" style="display:inline"><?= campoCsrf() ?>
                                <input type="hidden" name="acao" value="cancelar"><input type="hidden" name="id_cliente_assinatura" value="<?= (int) $a['id_cliente_assinatura'] ?>">
                                <button class="btn btn-perigo btn-pequeno" data-confirmar="Cancelar a assinatura de <?= e($a['nome_cliente']) ?>? Os benefícios valem até o fim do período pago." data-confirmar-titulo="Cancelar assinatura" data-confirmar-rotulo="Cancelar assinatura">Cancelar assinatura</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>
