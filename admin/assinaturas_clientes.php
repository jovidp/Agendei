<?php

/**
 * Assinatura mensal que o estabelecimento vende aos clientes (todos os planos
 * do Agendei): um plano unico por empresa, que cobre todos os atendimentos, e
 * os assinantes, com a confirmacao das mensalidades.
 */
require_once __DIR__ . '/../config/config.php';
exigirLogin('admin');

$erros = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $acao = post('acao');
    try {
        if ($acao === 'salvar_plano') {
            $dadosPlano = ['nome' => post('nome'), 'descricao' => post('descricao'), 'preco_mensal' => post('preco_mensal'), 'servicos' => []];
            $existente = AssinaturaCliente::planoUnico();
            if ($existente !== null) {
                // Mantem a lista antiga de servicos do plano: nao muda o preco (tudo sai a zero), so o historico.
                $dadosPlano['servicos'] = array_column($existente['servicos'], 'quantidade_mes', 'id_servico');
                AssinaturaCliente::atualizarPlano((int) $existente['id_plano_cliente'], $dadosPlano);
                definirFlash('sucesso', 'Plano atualizado. O preço novo vale a partir da próxima mensalidade.');
            } else {
                AssinaturaCliente::criarPlano($dadosPlano);
                definirFlash('sucesso', 'Plano criado. Seus clientes já podem assinar em "Meus benefícios".');
            }
        } elseif ($acao === 'status_plano' && ($existente = AssinaturaCliente::planoUnico()) !== null) {
            AssinaturaCliente::alterarStatusPlano((int) $existente['id_plano_cliente'], post('status') === 'ativo' ? 'ativo' : 'inativo');
            definirFlash('sucesso', post('status') === 'ativo' ? 'Plano ativado: os clientes já podem assinar.' : 'Plano pausado. Ninguém novo assina; quem já assina continua.');
        } elseif ($acao === 'inscrever' && ($existente = AssinaturaCliente::planoUnico()) !== null) {
            AssinaturaCliente::assinar((int) post('id_cliente'), (int) $existente['id_plano_cliente']);
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

$plano = AssinaturaCliente::planoUnico();
$planoAtivo = $plano !== null && $plano['status'] === 'ativo';
$planosAntigos = max(0, count(AssinaturaCliente::planos()) - 1);
$assinantes = AssinaturaCliente::assinantes();
$clientes = Cliente::listar(['status' => 'ativo']);
$semPix = Configuracao::obter('pix_chave') === '';
// Depois de um erro, o formulario mostra o que foi digitado.
$valor = fn(string $campo, string $doPlano) => $erros !== [] && post('acao') === 'salvar_plano' ? post($campo) : $doPlano;

$tituloPagina  = 'Assinaturas de clientes';
$subtituloTopo = 'O plano mensal que você vende aos seus clientes';
require_once RAIZ . '/includes/painel_header.php';
?>

<?php if ($erros !== []): ?>
    <div class="alerta alerta-erro"><div><span class="alerta-texto"><?= e(implode(' ', $erros)) ?></span></div></div>
<?php endif; ?>

<?php if ($semPix): ?>
    <div class="alerta alerta-aviso"><div><span class="alerta-texto">Cadastre a chave Pix em <a href="<?= url('admin/diferenciais.php') ?>">Diferenciais</a> para receber as mensalidades. Sem ela, ninguém consegue assinar.</span></div></div>
<?php endif; ?>

<div class="cartao">
    <div class="cartao-cabecalho">
        <h3>Seu plano de assinatura</h3>
        <?php if ($plano !== null): ?><?= badgeStatus($plano['status']) ?><?php endif; ?>
    </div>
    <div class="cartao-corpo">
        <p>O assinante paga só a mensalidade: <strong>todos os atendimentos saem de graça</strong> enquanto o plano estiver em dia.</p>
        <form method="post">
            <?= campoCsrf() ?>
            <input type="hidden" name="acao" value="salvar_plano">
            <div class="linha-campos">
                <div class="campo">
                    <label for="nome">Nome do plano <span class="obrigatorio">*</span></label>
                    <input type="text" id="nome" name="nome" maxlength="120" required value="<?= e($valor('nome', (string) ($plano['nome'] ?? ''))) ?>" placeholder="Ex.: Clube da Barba">
                </div>
                <div class="campo">
                    <label for="preco_mensal">Preço por mês (R$) <span class="obrigatorio">*</span></label>
                    <input type="text" id="preco_mensal" name="preco_mensal" data-mascara="moeda" inputmode="numeric" required
                        value="<?= e($valor('preco_mensal', $plano ? number_format((float) $plano['preco_mensal'], 2, ',', '') : '')) ?>">
                </div>
            </div>
            <div class="campo">
                <label for="descricao">Benefícios e regras <span class="opcional">(o cliente vê este texto)</span></label>
                <textarea id="descricao" name="descricao" maxlength="500" placeholder="Ex.: Corte e barba à vontade, com hora marcada."><?= e($valor('descricao', (string) ($plano['descricao'] ?? ''))) ?></textarea>
            </div>
            <div class="grupo-botoes">
                <button class="btn"><?= $plano ? 'Salvar plano' : 'Criar plano' ?></button>
            </div>
        </form>
        <?php if ($plano !== null): ?>
            <form method="post" class="margem-topo"><?= campoCsrf() ?>
                <input type="hidden" name="acao" value="status_plano">
                <input type="hidden" name="status" value="<?= $planoAtivo ? 'inativo' : 'ativo' ?>">
                <button class="btn btn-contorno btn-pequeno"><?= $planoAtivo ? 'Pausar novas assinaturas' : 'Voltar a vender o plano' ?></button>
            </form>
        <?php endif; ?>
        <?php if ($planosAntigos > 0): ?>
            <p class="ajuda-campo margem-topo">Agora cada estabelecimento tem um plano só. <?= $planosAntigos ?> plano(s) antigo(s) não aceitam mais assinaturas novas; quem já assina continua até cancelar.</p>
        <?php endif; ?>
    </div>
</div>

<div class="cartao">
    <div class="cartao-cabecalho"><h3>Assinantes</h3></div>
    <?php if ($planoAtivo && $clientes !== []): ?>
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
