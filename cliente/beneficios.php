<?php

/**
 * Benefícios do cliente: lista de espera, fidelidade, pacotes, Pix e avaliações.
 */
require_once __DIR__ . '/../config/config.php';
exigirLogin('cliente');

if (!Plano::temRecurso('diferenciais')) {
    $recursoFaltante = 'diferenciais';
    $tituloPagina = 'Meus benefícios';
    require RAIZ . '/includes/fora_do_plano.php';
}

$idCliente = (int) perfilId();
$erros = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    try {
        $acao = post('acao');
        if ($acao === 'lista') {
            Diferencial::entrarLista(
                $idCliente,
                (int) post('id_servico'),
                (int) post('id_profissional') ?: null,
                post('data_desejada'),
                post('periodo')
            );
            definirFlash('sucesso', 'Você entrou na lista de espera. Avisaremos quando surgir uma vaga.');
        } elseif ($acao === 'cancelar_lista') {
            Diferencial::cancelarLista((int) post('id_lista'), $idCliente);
            definirFlash('sucesso', 'Pedido de encaixe cancelado.');
        } elseif ($acao === 'assinar') {
            AssinaturaCliente::assinar($idCliente, (int) post('id_plano_cliente'));
            definirFlash('sucesso', 'Assinatura solicitada. Pague a primeira mensalidade pelo Pix abaixo; ela começa quando o estabelecimento confirmar.');
        } elseif ($acao === 'cancelar_assinatura') {
            AssinaturaCliente::cancelar((int) post('id_cliente_assinatura'), $idCliente)
                ? definirFlash('sucesso', 'Assinatura cancelada. Os benefícios valem até o fim do período pago.')
                : definirFlash('erro', 'Não foi possível cancelar esta assinatura.');
        } elseif ($acao === 'pacote') {
            Diferencial::adquirirPacote($idCliente, (int) post('id_pacote'));
            definirFlash('sucesso', 'Pacote solicitado. Aguarde a confirmação do estabelecimento.');
        } elseif ($acao === 'avaliar') {
            Diferencial::avaliar($idCliente, (int) post('id_agendamento'), (int) post('nota'), post('comentario'));
            definirFlash('sucesso', 'Obrigado pela sua avaliação.');
        }
        redirecionar('cliente/beneficios.php');
    } catch (Throwable $erro) {
        $erros[] = $erro->getMessage();
    }
}

$cliente = Cliente::porId($idCliente);
$servicos = Servico::ativosComProfissional();
$profissionais = Profissional::ativos();
$lista = Diferencial::listaDoCliente($idCliente);
$pacotes = Diferencial::pacotes(true);
$meusPacotes = Diferencial::pacotesDoCliente($idCliente);
$pagamentos = Diferencial::pagamentos($idCliente);
$movimentos = Diferencial::movimentos($idCliente);
$paraAvaliar = Diferencial::atendimentosParaAvaliar($idCliente);
$avaliacoes = Diferencial::avaliacoes($idCliente);
$pix = Configuracao::obter('pix_chave');
// A pagina tambem gera a mensalidade que vence, para nao depender so da tarefa diaria.
AssinaturaCliente::gerarRenovacoes();
$minhaAssinatura = AssinaturaCliente::daCliente($idCliente);
$planosAssinatura = AssinaturaCliente::planos(true);
$nomeEmpresa = (string) (Contexto::dados()['nome'] ?? '');

$tituloPagina = 'Meus benefícios';
$subtituloTopo = 'Assinaturas, encaixes, pontos, pacotes, pagamentos e avaliações';
require RAIZ . '/includes/painel_header.php';
?>
<?php if ($erros): ?><div class="alerta alerta-erro">
        <ul><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul>
    </div><?php endif; ?>

<div class="grade-indicadores">
    <div class="indicador indicador-destaque"><span class="indicador-rotulo">Pontos disponíveis</span><span class="indicador-valor"><?= (int)($cliente['pontos_fidelidade'] ?? 0) ?></span><span class="indicador-nota">Ganhos em atendimentos concluídos</span></div>
    <div class="indicador"><span class="indicador-rotulo">Pedidos de encaixe</span><span class="indicador-valor"><?= count(array_filter($lista, fn($i) => in_array($i['status'], ['aguardando', 'avisado'], true))) ?></span><span class="indicador-nota">Ativos</span></div>
    <div class="indicador indicador-positivo"><span class="indicador-rotulo">Pacotes ativos</span><span class="indicador-valor"><?= count(array_filter($meusPacotes, fn($i) => $i['status_pagamento'] === 'pago' && $i['creditos_restantes'] > 0)) ?></span><span class="indicador-nota">Com créditos</span></div>
    <div class="indicador"><span class="indicador-rotulo">Avaliações</span><span class="indicador-valor"><?= count($avaliacoes) ?></span><span class="indicador-nota">Enviadas</span></div>
</div>

<div class="grade-painel-igual">
    <div class="cartao">
        <div class="cartao-cabecalho">
            <h3>Quero um encaixe</h3>
        </div>
        <div class="cartao-corpo">
            <form method="post"><?= campoCsrf() ?><input type="hidden" name="acao" value="lista">
                <div class="campo"><label for="lista_servico">Serviço</label><select id="lista_servico" name="id_servico" required>
                        <option value="">Selecione</option><?php foreach ($servicos as $s): ?><option value="<?= (int)$s['id_servico'] ?>"><?= e($s['nome']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="campo"><label for="lista_profissional">Profissional</label><select id="lista_profissional" name="id_profissional">
                        <option value="">Qualquer profissional</option><?php foreach ($profissionais as $p): ?><option value="<?= (int)$p['id_profissional'] ?>"><?= e($p['nome']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="linha-campos">
                    <div class="campo"><label for="data_desejada">Data desejada</label><input type="date" id="data_desejada" name="data_desejada" min="<?= date('Y-m-d') ?>" required></div>
                    <div class="campo"><label for="periodo">Período</label><select id="periodo" name="periodo">
                            <option value="qualquer">Qualquer</option>
                            <option value="manha">Manhã</option>
                            <option value="tarde">Tarde</option>
                            <option value="noite">Noite</option>
                        </select></div>
                </div>
                <button class="btn" type="submit">Entrar na lista</button>
            </form>
        </div>
    </div>

    <div class="cartao">
        <div class="cartao-cabecalho">
            <h3>Minha lista de espera</h3>
        </div><?php if (!$lista): ?><div class="estado-vazio"><strong>Nenhum pedido</strong>
                <p>Cadastre uma preferência para receber aviso de encaixe.</p>
            </div><?php else: ?><div class="tabela-area">
                <table class="tabela">
                    <thead>
                        <tr>
                            <th>Preferência</th>
                            <th>Data</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody><?php foreach ($lista as $i): ?><tr>
                                <td><strong><?= e($i['nome_servico']) ?></strong><br><small><?= e($i['nome_profissional'] ?: 'Qualquer profissional') ?></small></td>
                                <td><?= formatarData($i['data_desejada']) ?></td>
                                <td><?= badgeStatus($i['status']) ?></td>
                                <td><?php if (in_array($i['status'], ['aguardando', 'avisado'], true)): ?><form method="post"><?= campoCsrf() ?><input type="hidden" name="acao" value="cancelar_lista"><input type="hidden" name="id_lista" value="<?= (int)$i['id_lista'] ?>"><button class="btn btn-contorno btn-pequeno">Cancelar</button></form><?php endif; ?></td>
                            </tr><?php endforeach; ?></tbody>
                </table>
            </div><?php endif; ?>
    </div>
</div>

<?php $mostrarVitrine = $planosAssinatura && (!$minhaAssinatura || $minhaAssinatura['situacao'] === 'encerrada'); ?>
<?php if ($minhaAssinatura && $minhaAssinatura['situacao'] !== 'encerrada'): $a = $minhaAssinatura; $cob = $a['cobranca_pendente']; ?>
    <div class="cartao">
        <div class="cartao-cabecalho"><h3>Minha assinatura: <?= e($a['plano']['nome'] ?? '') ?></h3><?= AssinaturaCliente::badgeSituacao($a['situacao']) ?></div>
        <div class="cartao-corpo">
            <?php if ($a['situacao'] === 'pendente'): ?>
                <p>Sua assinatura começa quando o estabelecimento confirmar o pagamento da primeira mensalidade.</p>
            <?php elseif ($a['situacao'] === 'suspensa'): ?>
                <p>O período pago terminou em <?= formatarData($a['periodo_fim']) ?>. Pague a mensalidade para voltar a usar os benefícios.</p>
            <?php elseif ($a['situacao'] === 'cancelada'): ?>
                <p>Assinatura cancelada. Os benefícios valem até <strong><?= formatarData($a['periodo_fim']) ?></strong>.</p>
            <?php else: ?>
                <p>Mês atual: <?= formatarData($a['mes_atual'][0]) ?> a <?= formatarData($a['mes_atual'][1]) ?>. Pago até <?= formatarData($a['periodo_fim']) ?>.</p>
            <?php endif; ?>
            <?php if (!empty($a['plano'])): ?>
                <ul>
                    <?php foreach ($a['plano']['servicos'] as $s): $usado = $a['uso'][$s['id_servico']] ?? 0; ?>
                        <li><?= e($s['nome']) ?>: <?= $s['quantidade_mes'] === null ? 'ilimitado' : (int) $usado . ' de ' . (int) $s['quantidade_mes'] . ' usados neste mês' ?></li>
                    <?php endforeach; ?>
                    <?php if ((int) $a['plano']['desconto_percentual'] > 0): ?><li><?= (int) $a['plano']['desconto_percentual'] ?>% de desconto nos demais serviços</li><?php endif; ?>
                </ul>
            <?php endif; ?>
            <?php if ($cob): ?>
                <h4 class="margem-topo">Mensalidade de <?= formatarMoeda($cob['valor']) ?></h4>
                <p><?= $cob['metodo'] ? 'Forma escolhida: ' . e(Cobranca::rotuloPagamento($cob['metodo'], $cob['cartao_final'])) . '.' : 'Pague por Pix, boleto ou cartão.' ?></p>
                <a class="btn btn-pequeno" href="<?= url('cliente/pagar.php?mensalidade=' . (int) $cob['id_cobranca']) ?>"><?= $cob['metodo'] ? 'Ver pagamento' : 'Pagar mensalidade' ?></a>
            <?php endif; ?>
            <?php if ($a['situacao'] !== 'cancelada'): ?>
                <form method="post" class="margem-topo"><?= campoCsrf() ?>
                    <input type="hidden" name="acao" value="cancelar_assinatura"><input type="hidden" name="id_cliente_assinatura" value="<?= (int) $a['id_cliente_assinatura'] ?>">
                    <button class="btn btn-contorno btn-pequeno" data-confirmar="Cancelar sua assinatura? Os benefícios valem até o fim do período já pago." data-confirmar-titulo="Cancelar assinatura" data-confirmar-rotulo="Cancelar assinatura">Cancelar assinatura</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($mostrarVitrine): ?>
    <div class="cartao">
        <div class="cartao-cabecalho"><h3>Assinaturas</h3></div>
        <div class="grade-painel-igual cartao-corpo"><?php foreach ($planosAssinatura as $p): ?><div class="cartao">
            <div class="cartao-corpo">
                <h3><?= e($p['nome']) ?></h3>
                <?php if ($p['descricao']): ?><p><?= e($p['descricao']) ?></p><?php endif; ?>
                <ul>
                    <?php foreach ($p['servicos'] as $s): ?><li><?= e($s['nome']) ?>: <?= $s['quantidade_mes'] === null ? 'ilimitado' : (int) $s['quantidade_mes'] . 'x por mês' ?></li><?php endforeach; ?>
                    <?php if ((int) $p['desconto_percentual'] > 0): ?><li><?= (int) $p['desconto_percentual'] ?>% de desconto nos demais serviços</li><?php endif; ?>
                </ul>
                <strong><?= formatarMoeda($p['preco_mensal']) ?> por mês</strong>
                <form method="post" class="margem-topo"><?= campoCsrf() ?><input type="hidden" name="acao" value="assinar"><input type="hidden" name="id_plano_cliente" value="<?= (int) $p['id_plano_cliente'] ?>"><button class="btn btn-pequeno">Assinar</button></form>
            </div>
        </div><?php endforeach; ?>
        <div class="cartao"><div class="cartao-corpo">
            <h3>Sem plano</h3>
            <p>Pague só quando usar: cada atendimento pelo valor normal, no local ou online por Pix, boleto ou cartão.</p>
            <a class="btn btn-contorno btn-pequeno margem-topo" href="<?= url('cliente/agendar.php') ?>">Marcar horário</a>
        </div></div></div>
    </div>
<?php endif; ?>

<div class="cartao">
    <div class="cartao-cabecalho">
        <h3>Pacotes disponíveis</h3>
    </div>
    <?php if (!$pacotes): ?><div class="estado-vazio"><strong>Nenhum pacote disponível</strong></div><?php else: ?><div class="grade-painel-igual cartao-corpo"><?php foreach ($pacotes as $p): ?><div class="cartao">
                    <div class="cartao-corpo"><small><?= e($p['nome_servico']) ?></small>
                        <h3><?= e($p['nome']) ?></h3>
                        <p><?= (int)$p['quantidade'] ?> atendimento(s), válidos por <?= (int)$p['validade_dias'] ?> dias.</p><strong><?= formatarMoeda($p['preco']) ?></strong>
                        <form method="post" class="margem-topo"><?= campoCsrf() ?><input type="hidden" name="acao" value="pacote"><input type="hidden" name="id_pacote" value="<?= (int)$p['id_pacote'] ?>"><button class="btn btn-pequeno">Solicitar pacote</button></form>
                    </div>
                </div><?php endforeach; ?></div><?php endif; ?>
</div>

<?php if ($meusPacotes): ?><div class="cartao">
        <div class="cartao-cabecalho">
            <h3>Meus pacotes</h3>
        </div>
        <div class="tabela-area">
            <table class="tabela">
                <thead>
                    <tr>
                        <th>Pacote</th>
                        <th>Créditos</th>
                        <th>Validade</th>
                        <th>Pagamento</th>
                    </tr>
                </thead>
                <tbody><?php foreach ($meusPacotes as $p): ?><tr>
                            <td><?= e($p['nome']) ?><br><small><?= e($p['nome_servico']) ?></small></td>
                            <td><?= (int)$p['creditos_restantes'] ?>/<?= (int)$p['creditos_total'] ?></td>
                            <td><?= formatarData($p['data_expiracao']) ?></td>
                            <td><?= badgeStatus($p['status_pagamento']) ?></td>
                        </tr><?php endforeach; ?></tbody>
            </table>
        </div>
    </div><?php endif; ?>

<?php if ($pagamentos): ?><div class="cartao">
        <div class="cartao-cabecalho">
            <h3>Sinais de agendamento</h3>
        </div>
        <div class="tabela-area">
            <table class="tabela">
                <thead>
                    <tr>
                        <th>Atendimento</th>
                        <th>Valor</th>
                        <th>Status</th>
                        <th>Pix</th>
                    </tr>
                </thead>
                <tbody><?php foreach ($pagamentos as $p): ?><tr>
                            <td><?= e($p['nome_servico']) ?><br><small><?= formatarData($p['data_agendamento']) ?> <?= formatarHora($p['hora_inicio']) ?></small></td>
                            <td><?= formatarMoeda($p['valor']) ?></td>
                            <td><?= badgeStatus($p['status']) ?></td>
                            <td><?php if ($p['status'] === 'pendente' && $pix !== ''): ?><code><?= e($pix) ?></code><?php else: ?>-<?php endif; ?></td>
                        </tr><?php endforeach; ?></tbody>
            </table>
        </div>
    </div><?php endif; ?>

<?php if ($paraAvaliar): ?><div class="cartao">
        <div class="cartao-cabecalho">
            <h3>Avalie seus atendimentos</h3>
        </div>
        <div class="cartao-corpo"><?php foreach ($paraAvaliar as $a): ?><form method="post" class="cartao" style="padding:18px"><?= campoCsrf() ?><input type="hidden" name="acao" value="avaliar"><input type="hidden" name="id_agendamento" value="<?= (int)$a['id_agendamento'] ?>"><strong><?= e($a['nome_servico']) ?> — <?= e($a['nome_profissional']) ?></strong>
                    <div class="linha-campos margem-topo">
                        <div class="campo"><label>Nota</label><select name="nota" required>
                                <option value="5">5 — Excelente</option>
                                <option value="4">4 — Muito bom</option>
                                <option value="3">3 — Bom</option>
                                <option value="2">2 — Regular</option>
                                <option value="1">1 — Ruim</option>
                            </select></div>
                        <div class="campo"><label>Comentário</label><input name="comentario" maxlength="500"></div>
                    </div><button class="btn btn-pequeno">Enviar avaliação</button>
                </form><?php endforeach; ?></div>
    </div><?php endif; ?>

<?php if ($movimentos): ?><div class="cartao">
        <div class="cartao-cabecalho">
            <h3>Extrato de pontos</h3>
        </div>
        <div class="tabela-area">
            <table class="tabela">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Descrição</th>
                        <th>Pontos</th>
                    </tr>
                </thead>
                <tbody><?php foreach ($movimentos as $m): ?><tr>
                            <td><?= formatarData(substr($m['data_criacao'], 0, 10)) ?></td>
                            <td><?= e($m['descricao']) ?></td>
                            <td><strong><?= $m['pontos'] > 0 ? '+' : '' ?><?= (int)$m['pontos'] ?></strong></td>
                        </tr><?php endforeach; ?></tbody>
            </table>
        </div>
    </div><?php endif; ?>

<div class="cartao">
    <div class="cartao-corpo"><a href="<?= url('cliente/privacidade.php') ?>">Privacidade: exportar meus dados ou encerrar minha conta</a></div>
</div>
<?php require RAIZ . '/includes/painel_footer.php'; ?>
