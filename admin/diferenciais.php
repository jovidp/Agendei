<?php
/**
 * Central administrativa dos diferenciais comerciais e de relacionamento.
 */
require_once __DIR__ . '/../config/config.php';
exigirLogin('admin');

$erros = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $acao = post('acao');
    try {
        if ($acao === 'configurar') {
            Configuracao::definir('pix_chave', post('pix_chave'), 'Chave Pix usada no sinal');
            Configuracao::definir('sinal_percentual', (string) max(0, min(100, (int) post('sinal_percentual'))));
            Configuracao::definir('pontos_por_real', (string) max(0, min(100, (int) post('pontos_por_real'))));
            Configuracao::definir('lembrete_horas', (string) max(1, min(168, (int) post('lembrete_horas'))));
            definirFlash('sucesso', 'Configurações dos diferenciais atualizadas.');
            redirecionar('admin/diferenciais.php');
        }

        if ($acao === 'pacote') {
            $nome = post('nome');
            $quantidade = max(1, min(100, (int) post('quantidade')));
            $validade = max(1, min(730, (int) post('validade_dias')));
            $preco = moedaParaDecimal(post('preco'));
            if (mb_strlen($nome) < 3 || $preco <= 0) {
                throw new InvalidArgumentException('Informe o nome e um preço válido para o pacote.');
            }
            Diferencial::criarPacote([
                'id_servico' => (int) post('id_servico'), 'nome' => $nome,
                'quantidade' => $quantidade, 'validade_dias' => $validade, 'preco' => $preco,
            ]);
            definirFlash('sucesso', 'Pacote criado.');
            redirecionar('admin/diferenciais.php#pacotes');
        }

        if ($acao === 'comissoes') {
            foreach ((array) ($_POST['comissao'] ?? []) as $id => $percentual) {
                Diferencial::atualizarComissao((int) $id, moedaParaDecimal((string) $percentual));
            }
            definirFlash('sucesso', 'Comissões atualizadas.');
            redirecionar('admin/diferenciais.php#comissoes');
        }

        if ($acao === 'lembretes') {
            $total = Diferencial::gerarLembretes();
            definirFlash('sucesso', $total . ' novo(s) lembrete(s) colocado(s) na fila.');
            redirecionar('admin/diferenciais.php#mensagens');
        }

        if ($acao === 'notificacao') {
            Diferencial::marcarNotificacao((int) post('id_notificacao'));
            redirecionar('admin/diferenciais.php#mensagens');
        }

        if ($acao === 'pagamento') {
            Diferencial::atualizarPagamento((int) post('id_pagamento'), post('status'));
            definirFlash('sucesso', 'Pagamento atualizado.');
            redirecionar('admin/diferenciais.php#pagamentos');
        }

        if ($acao === 'compra_pacote') {
            Diferencial::atualizarCompraPacote((int) post('id_cliente_pacote'), post('status'));
            definirFlash('sucesso', 'Compra de pacote atualizada.');
            redirecionar('admin/diferenciais.php#pacotes');
        }
    } catch (Throwable $erro) {
        $erros[] = $erro->getMessage();
    }
}

$servicos = Servico::ativos();
$espera = Diferencial::listaAdministrativa();
$notificacoes = Diferencial::notificacoesPendentes();
$pagamentos = Diferencial::pagamentos();
$pacotes = Diferencial::pacotes();
$compras = Diferencial::comprasPacotes();
$avaliacoes = Diferencial::avaliacoes();
$inicioMes = date('Y-m-01');
$fimMes = date('Y-m-t');
$comissoes = Diferencial::comissoes($inicioMes, $fimMes);

$tituloPagina = 'Diferenciais';
$subtituloTopo = 'Relacionamento, fidelidade, pagamentos e produtividade';
require RAIZ . '/includes/painel_header.php';
?>
<?php if ($erros): ?><div class="alerta alerta-erro"><ul><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="grade-indicadores">
    <div class="indicador indicador-destaque"><span class="indicador-rotulo">Lista de espera</span><span class="indicador-valor"><?= count(array_filter($espera, fn($i) => $i['status'] === 'aguardando')) ?></span><span class="indicador-nota">Aguardando encaixe</span></div>
    <div class="indicador"><span class="indicador-rotulo">Mensagens</span><span class="indicador-valor"><?= count($notificacoes) ?></span><span class="indicador-nota">Pendentes</span></div>
    <div class="indicador indicador-positivo"><span class="indicador-rotulo">Sinais pendentes</span><span class="indicador-valor"><?= count(array_filter($pagamentos, fn($i) => $i['status'] === 'pendente')) ?></span><span class="indicador-nota">Via Pix</span></div>
    <div class="indicador"><span class="indicador-rotulo">Avaliação média</span><span class="indicador-valor"><?= $avaliacoes ? number_format(array_sum(array_column($avaliacoes, 'nota')) / count($avaliacoes), 1, ',', '') : '-' ?></span><span class="indicador-nota"><?= count($avaliacoes) ?> avaliação(ões)</span></div>
</div>

<div class="grade-painel-igual">
<div class="cartao"><div class="cartao-cabecalho"><h3>Automação e fidelidade</h3></div><div class="cartao-corpo">
<form method="post"><?= campoCsrf() ?><input type="hidden" name="acao" value="configurar">
<div class="linha-campos"><div class="campo"><label for="pix_chave">Chave Pix</label><input id="pix_chave" name="pix_chave" maxlength="150" value="<?= e(Configuracao::obter('pix_chave')) ?>"><span class="ajuda-campo">Deixe vazio para não cobrar sinal.</span></div><div class="campo"><label for="sinal_percentual">Sinal sobre o serviço (%)</label><input type="number" id="sinal_percentual" name="sinal_percentual" min="0" max="100" value="<?= Configuracao::obterInteiro('sinal_percentual', 0) ?>"></div></div>
<div class="linha-campos"><div class="campo"><label for="pontos_por_real">Pontos por real concluído</label><input type="number" id="pontos_por_real" name="pontos_por_real" min="0" max="100" value="<?= Configuracao::obterInteiro('pontos_por_real', 1) ?>"></div><div class="campo"><label for="lembrete_horas">Gerar lembrete até (horas)</label><input type="number" id="lembrete_horas" name="lembrete_horas" min="1" max="168" value="<?= Configuracao::obterInteiro('lembrete_horas', 24) ?>"></div></div>
<button class="btn" type="submit">Salvar configurações</button>
</form></div></div>

<div class="cartao" id="mensagens"><div class="cartao-cabecalho"><h3>Lembretes por WhatsApp</h3><form method="post"><?= campoCsrf() ?><input type="hidden" name="acao" value="lembretes"><button class="btn btn-pequeno" type="submit">Atualizar fila</button></form></div>
<?php if (!$notificacoes): ?><div class="estado-vazio"><strong>Fila vazia</strong><p>Atualize a fila quando desejar preparar os próximos lembretes.</p></div><?php else: ?><div class="tabela-area"><table class="tabela"><thead><tr><th>Destino</th><th>Mensagem</th><th>Ação</th></tr></thead><tbody><?php foreach ($notificacoes as $n): ?><tr><td><?= e(formatarTelefone($n['destinatario'])) ?></td><td><?= e($n['mensagem']) ?></td><td><form method="post"><?= campoCsrf() ?><input type="hidden" name="acao" value="notificacao"><input type="hidden" name="id_notificacao" value="<?= (int)$n['id_notificacao'] ?>"><a class="btn btn-pequeno" target="_blank" rel="noopener" href="<?= e(Diferencial::linkWhatsapp($n)) ?>">Abrir WhatsApp</a><button class="btn btn-contorno btn-pequeno" type="submit">Marcar enviada</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div></div>

<div class="cartao" id="espera"><div class="cartao-cabecalho"><h3>Lista de espera inteligente</h3></div>
<?php if (!$espera): ?><div class="estado-vazio"><strong>Ninguém aguardando</strong><p>Os pedidos de encaixe dos clientes aparecerão aqui.</p></div><?php else: ?><div class="tabela-area"><table class="tabela"><thead><tr><th>Cliente</th><th>Preferência</th><th>Data</th><th>Status</th></tr></thead><tbody><?php foreach ($espera as $item): ?><tr><td><strong><?= e($item['nome_cliente']) ?></strong><br><small><?= e(formatarTelefone($item['telefone'])) ?></small></td><td><?= e($item['nome_servico']) ?><br><small><?= e($item['nome_profissional'] ?: 'Qualquer profissional') ?> · <?= e(ucfirst($item['periodo'])) ?></small></td><td><?= formatarData($item['data_desejada']) ?></td><td><?= badgeStatus($item['status']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>

<div class="grade-painel-igual" id="pacotes">
<div class="cartao"><div class="cartao-cabecalho"><h3>Novo pacote</h3></div><div class="cartao-corpo"><form method="post"><?= campoCsrf() ?><input type="hidden" name="acao" value="pacote">
<div class="campo"><label for="nome_pacote">Nome</label><input id="nome_pacote" name="nome" placeholder="Pacote mensal" required></div>
<div class="campo"><label for="servico_pacote">Serviço</label><select id="servico_pacote" name="id_servico" required><option value="">Selecione</option><?php foreach($servicos as $s): ?><option value="<?= (int)$s['id_servico'] ?>"><?= e($s['nome']) ?></option><?php endforeach; ?></select></div>
<div class="linha-campos"><div class="campo"><label>Quantidade</label><input type="number" name="quantidade" min="1" max="100" value="4" required></div><div class="campo"><label>Validade em dias</label><input type="number" name="validade_dias" min="1" max="730" value="30" required></div></div>
<div class="campo"><label>Preço</label><input name="preco" inputmode="decimal" placeholder="150,00" required></div><button class="btn" type="submit">Criar pacote</button></form></div></div>
<div class="cartao"><div class="cartao-cabecalho"><h3>Pacotes disponíveis</h3></div><?php if(!$pacotes): ?><div class="estado-vazio"><strong>Nenhum pacote</strong></div><?php else: ?><div class="tabela-area"><table class="tabela"><thead><tr><th>Pacote</th><th>Créditos</th><th>Preço</th></tr></thead><tbody><?php foreach($pacotes as $p): ?><tr><td><strong><?= e($p['nome']) ?></strong><br><small><?= e($p['nome_servico']) ?></small></td><td><?= (int)$p['quantidade'] ?> / <?= (int)$p['validade_dias'] ?> dias</td><td><?= formatarMoeda($p['preco']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
</div>

<?php if($compras): ?><div class="cartao"><div class="cartao-cabecalho"><h3>Solicitações de pacotes</h3></div><div class="tabela-area"><table class="tabela"><thead><tr><th>Cliente</th><th>Pacote</th><th>Créditos</th><th>Status</th><th>Ação</th></tr></thead><tbody><?php foreach($compras as $c): ?><tr><td><?= e($c['nome_cliente']) ?></td><td><?= e($c['pacote']) ?></td><td><?= (int)$c['creditos_restantes'] ?>/<?= (int)$c['creditos_total'] ?></td><td><?= badgeStatus($c['status_pagamento']) ?></td><td><form method="post"><?= campoCsrf() ?><input type="hidden" name="acao" value="compra_pacote"><input type="hidden" name="id_cliente_pacote" value="<?= (int)$c['id_cliente_pacote'] ?>"><button class="btn btn-pequeno" name="status" value="pago">Confirmar pagamento</button></form></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>

<div class="cartao" id="pagamentos"><div class="cartao-cabecalho"><h3>Pagamentos de sinal</h3></div>
<?php if(!$pagamentos): ?><div class="estado-vazio"><strong>Nenhum sinal gerado</strong><p>Configure uma chave Pix e um percentual para gerar cobranças nas novas reservas.</p></div><?php else: ?><div class="tabela-area"><table class="tabela"><thead><tr><th>Cliente</th><th>Atendimento</th><th>Valor</th><th>Status</th><th>Ação</th></tr></thead><tbody><?php foreach($pagamentos as $p): ?><tr><td><?= e($p['nome_cliente']) ?></td><td><?= e($p['nome_servico']) ?><br><small><?= formatarData($p['data_agendamento']) ?> <?= formatarHora($p['hora_inicio']) ?></small></td><td><?= formatarMoeda($p['valor']) ?></td><td><?= badgeStatus($p['status']) ?></td><td><form method="post"><?= campoCsrf() ?><input type="hidden" name="acao" value="pagamento"><input type="hidden" name="id_pagamento" value="<?= (int)$p['id_pagamento'] ?>"><button class="btn btn-pequeno" name="status" value="pago">Marcar pago</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>

<div class="cartao" id="comissoes"><div class="cartao-cabecalho"><h3>Comissões — <?= e(mesNome((int)date('n'))) ?></h3></div><div class="cartao-corpo"><form method="post"><?= campoCsrf() ?><input type="hidden" name="acao" value="comissoes"><div class="tabela-area"><table class="tabela"><thead><tr><th>Profissional</th><th>Percentual</th><th>Atendimentos</th><th>Faturamento</th><th>Comissão</th></tr></thead><tbody><?php foreach($comissoes as $c): ?><tr><td><?= e($c['nome']) ?></td><td><input style="max-width:90px" name="comissao[<?= (int)$c['id_profissional'] ?>]" value="<?= e(number_format((float)$c['comissao_percentual'],2,',','')) ?>">%</td><td><?= (int)$c['atendimentos'] ?></td><td><?= formatarMoeda($c['faturamento']) ?></td><td><strong><?= formatarMoeda($c['comissao']) ?></strong></td></tr><?php endforeach; ?></tbody></table></div><button class="btn" type="submit">Salvar percentuais</button></form></div></div>

<div class="cartao"><div class="cartao-cabecalho"><h3>Avaliações recentes</h3></div><?php if(!$avaliacoes): ?><div class="estado-vazio"><strong>Ainda não há avaliações</strong></div><?php else: ?><div class="tabela-area"><table class="tabela"><thead><tr><th>Cliente</th><th>Atendimento</th><th>Nota</th><th>Comentário</th></tr></thead><tbody><?php foreach(array_slice($avaliacoes,0,20) as $a): ?><tr><td><?= e($a['nome_cliente']) ?></td><td><?= e($a['nome_servico']) ?><br><small><?= e($a['nome_profissional']) ?></small></td><td><?= str_repeat('★',(int)$a['nota']) ?></td><td><?= e($a['comentario'] ?: '-') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<?php require RAIZ . '/includes/painel_footer.php'; ?>