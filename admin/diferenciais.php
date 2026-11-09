<?php
/**
 * Central administrativa dos diferenciais comerciais e de relacionamento.
 */
require_once __DIR__ . '/../config/config.php';
exigirLogin('admin');

// Plano sem nenhum dos recursos desta tela (Basico): mostra o que falta e para aqui.
if (!Plano::temRecurso('diferenciais') && !Plano::temRecurso('lembretes_whatsapp') && !Plano::temRecurso('sinal_pix')) {
    $recursoFaltante = 'diferenciais';
    $tituloPagina = 'Diferenciais';
    require RAIZ . '/includes/fora_do_plano.php';
}

$erros = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $acao = post('acao');
    try {
        if ($acao === 'configurar') {
            Configuracao::definir('pix_chave', post('pix_chave'), 'Chave Pix usada no sinal');
            Configuracao::definir('sinal_percentual', (string) max(0, min(100, (int) post('sinal_percentual'))));
            Configuracao::definir('pontos_por_real', (string) max(0, min(100, (int) post('pontos_por_real'))));
            $lembreteHoras = max(1, min(168, (int) post('lembrete_horas')));
            Configuracao::definir('lembrete_horas', (string) $lembreteHoras);
            // A liberacao vem depois do lembrete, com folga para o cliente responder.
            $liberarHoras = max(0, min(72, (int) post('liberar_sem_confirmacao_horas')));
            $liberarHoras = min($liberarHoras, max(0, $lembreteHoras - Confirmacao::REACAO_MINIMA_HORAS));
            Configuracao::definir('liberar_sem_confirmacao_horas', (string) $liberarHoras, 'Libera horários sem confirmação N horas antes (0 = nunca)');
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
            if (WhatsApp::ativo()) {
                $r = Lembrete::processar();
                definirFlash('sucesso', sprintf(
                    '%d novo(s) lembrete(s) na fila, %d enviado(s), %d falha(s), %d cancelado(s), %d horario(s) liberado(s).',
                    $r['geradas'], $r['enviadas'], $r['falhas'], $r['canceladas'], $r['liberadas']
                ));
            } else {
                $total = Diferencial::gerarLembretes();
                definirFlash('sucesso', $total . ' novo(s) lembrete(s) colocado(s) na fila.');
            }
            redirecionar('admin/diferenciais.php#mensagens');
        }

        // Provedor de envio automatico. O token so e trocado quando vem preenchido:
        // o campo volta vazio na tela para o segredo nao ficar no HTML.
        if ($acao === 'whatsapp') {
            $provedor = post('whatsapp_provedor', 'plataforma');
            if (!array_key_exists($provedor, WhatsApp::PROVEDORES)) {
                throw new InvalidArgumentException('Provedor de WhatsApp desconhecido.');
            }
            $url = trim(post('whatsapp_url'));
            if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
                throw new InvalidArgumentException('A URL da Evolution API precisa começar com http:// ou https://.');
            }
            Configuracao::definir('whatsapp_provedor', $provedor, 'Provedor do envio automático de WhatsApp');
            Configuracao::definir('whatsapp_url', mb_substr($url, 0, 255));
            Configuracao::definir('whatsapp_instancia', mb_substr(trim(post('whatsapp_instancia')), 0, 100));
            Configuracao::definir('whatsapp_telefone_id', mb_substr(apenasNumeros(post('whatsapp_telefone_id')), 0, 40));
            Configuracao::definir('whatsapp_modelo', mb_substr(trim(post('whatsapp_modelo')), 0, 100));
            if (post('whatsapp_token') !== '') {
                Configuracao::definir('whatsapp_token', mb_substr(trim(post('whatsapp_token')), 0, 255));
            }
            if ($provedor === 'manual' || $provedor === 'plataforma') {
                Configuracao::definir('whatsapp_token', '');
            }
            definirFlash('sucesso', WhatsApp::ativo()
                ? 'Envio automático ligado. Mande uma mensagem de teste para conferir.'
                : 'Configuração salva. ' . WhatsApp::pendencia());
            redirecionar('admin/diferenciais.php#mensagens');
        }

        if ($acao === 'bot') {
            if (!Plano::temRecurso('bot_whatsapp')) {
                definirFlash('erro', Plano::avisoSemRecurso('bot_whatsapp'));
            } elseif (post('operacao') === 'nova_chave') {
                Bot::chave(true);
                definirFlash('sucesso', 'Chave nova gerada. Atualize a URL do webhook na Evolution: a antiga parou de funcionar.');
            } else {
                $ligar = post('operacao') === 'ligar';
                Configuracao::definir('whatsapp_bot_ativo', $ligar ? '1' : '0', 'Bot de atendimento no WhatsApp ligado');
                Bot::chave();
                definirFlash('sucesso', $ligar ? 'Bot ligado. Ele responde assim que a Evolution chamar o webhook abaixo.' : 'Bot desligado.');
            }
            redirecionar('admin/diferenciais.php#bot');
        }

        if ($acao === 'whatsapp_teste') {
            Lembrete::enviarTeste(post('telefone_teste'));
            definirFlash('sucesso', 'Mensagem de teste enviada para ' . formatarTelefone(post('telefone_teste')) . '.');
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
$recentes = Diferencial::notificacoesRecentes();
$whatsapp = WhatsApp::configuracao();
$whatsappAtivo = $whatsapp['provedor'] !== 'manual';
$whatsappEscolha = Configuracao::obter('whatsapp_provedor', 'plataforma');
$whatsappPendencia = WhatsApp::pendencia();
$ultimoEnvio = Configuracao::obter('whatsapp_ultima_execucao');
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
<div class="linha-campos"><div class="campo"><label for="liberar_sem_confirmacao_horas">Liberar horário sem confirmação (horas antes)</label><input type="number" id="liberar_sem_confirmacao_horas" name="liberar_sem_confirmacao_horas" min="0" max="72" value="<?= Configuracao::obterInteiro('liberar_sem_confirmacao_horas', 0) ?>"><span class="ajuda-campo">0 desliga. Vale para quem recebeu o lembrete e não respondeu pelo link; reserva com sinal pago nunca é liberada. Precisa ser menor que "Gerar lembrete até".</span></div></div>
<button class="btn" type="submit">Salvar configurações</button>
</form></div></div>

<?php $botNoPlano = Plano::temRecurso('bot_whatsapp'); $botLigado = Configuracao::ativa('whatsapp_bot_ativo'); ?>
<div class="cartao" id="bot"><div class="cartao-cabecalho"><h3>Bot de atendimento no WhatsApp</h3><?= $botNoPlano ? badgeStatus($botLigado && Bot::ligado() ? 'ativo' : 'inativo') : '' ?></div>
<div class="cartao-corpo">
<?php if (!$botNoPlano): ?>
    <p><?= e(Plano::avisoSemRecurso('bot_whatsapp')) ?></p>
    <a class="btn btn-contorno" href="<?= url('assinatura.php') ?>">Ver planos</a>
<?php else: ?>
    <p>O bot responde no número da empresa: o cliente marca, consulta e cancela horários pela conversa, com as mesmas regras do site (horários livres, sinal e prazo de cancelamento). Ele reconhece o cliente pelo celular do cadastro e, para "Falar com a equipe", fica em silêncio por 2 horas para alguém responder.</p>
    <?php if ($whatsapp['provedor'] !== 'evolution'): ?>
        <div class="alerta alerta-aviso"><span class="alerta-texto">O bot precisa da <strong>Evolution API</strong> configurada abaixo, em "Provedor de envio".</span></div>
    <?php endif; ?>
    <?php if ($botLigado): ?>
        <div class="campo"><label for="bot_webhook">URL do webhook (cole na Evolution, evento MESSAGES_UPSERT)</label>
            <input type="text" id="bot_webhook" readonly value="<?= e(urlAbsoluta('api/whatsapp.php?chave=' . Bot::chave())) ?>">
            <span class="ajuda-campo">Na Evolution: Webhook &rarr; ativar, colar esta URL e marcar o evento MESSAGES_UPSERT. A chave na URL é o que autoriza a Evolution; não divulgue.</span>
        </div>
    <?php endif; ?>
    <div class="grupo-botoes">
        <form method="post"><?= campoCsrf() ?><input type="hidden" name="acao" value="bot"><input type="hidden" name="operacao" value="<?= $botLigado ? 'desligar' : 'ligar' ?>">
            <button class="btn<?= $botLigado ? ' btn-contorno' : '' ?>" type="submit"><?= $botLigado ? 'Desligar bot' : 'Ligar bot' ?></button></form>
        <?php if ($botLigado): ?>
        <form method="post"><?= campoCsrf() ?><input type="hidden" name="acao" value="bot"><input type="hidden" name="operacao" value="nova_chave">
            <button class="btn btn-contorno" type="submit" data-confirmar="A URL atual para de funcionar e você precisa colar a nova na Evolution." data-confirmar-titulo="Gerar chave nova" data-confirmar-rotulo="Gerar">Gerar chave nova</button></form>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div></div>

<div class="cartao" id="mensagens"><div class="cartao-cabecalho"><h3>Lembretes por WhatsApp</h3><form method="post"><?= campoCsrf() ?><input type="hidden" name="acao" value="lembretes"><button class="btn btn-pequeno" type="submit"><?= $whatsappAtivo ? 'Enviar agora' : 'Atualizar fila' ?></button></form></div>
<div class="cartao-corpo">
<?php if ($whatsappAtivo): ?>
<p><strong>Envio automático ligado</strong> por <?= e(WhatsApp::PROVEDORES[$whatsapp['provedor']]) ?><?= $whatsapp['origem'] === 'plataforma' ? ', com o número da plataforma' : ', com o seu número' ?>.
Os lembretes saem sozinhos até <?= Configuracao::obterInteiro('lembrete_horas', 24) ?> h antes do atendimento<?= $ultimoEnvio !== '' ? '; último envio automático em ' . e(formatarData(substr($ultimoEnvio, 0, 10))) . ' as ' . e(substr($ultimoEnvio, 11, 5)) : '; a tarefa periódica ainda não rodou' ?>.</p>
<?php else: ?>
<p><strong>Envio manual.</strong> A fila abaixo monta a mensagem e você a envia pelo link "Abrir WhatsApp". <?= e($whatsappPendencia) ?></p>
<?php endif; ?>
<?php $liberarHoras = Configuracao::obterInteiro('liberar_sem_confirmacao_horas', 0); ?>
<p>Cada lembrete leva um link para o cliente confirmar a presença ou avisar que não vai.<?= $liberarHoras > 0 ? ' Quem não responde perde o horário ' . $liberarHoras . ' h antes do atendimento, e a lista de espera e avisada.' : ' A liberação automática dos horários sem resposta está desligada (veja "Automação e fidelidade").' ?></p>
<form method="post"><?= campoCsrf() ?><input type="hidden" name="acao" value="whatsapp">
<div class="campo"><label for="whatsapp_provedor">Provedor de envio</label><select id="whatsapp_provedor" name="whatsapp_provedor"><?php foreach (WhatsApp::PROVEDORES as $chave => $rotulo): ?><option value="<?= e($chave) ?>" <?= $whatsappEscolha === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option><?php endforeach; ?></select><span class="ajuda-campo">"Padrão da plataforma" usa o número de quem hospeda o sistema, quando ele existe. Os demais usam as credenciais abaixo.</span></div>
<div class="linha-campos"><div class="campo"><label for="whatsapp_url">Evolution API: URL</label><input id="whatsapp_url" name="whatsapp_url" maxlength="255" placeholder="https://evolution.seudominio.com.br" value="<?= e(Configuracao::obter('whatsapp_url')) ?>"></div><div class="campo"><label for="whatsapp_instancia">Evolution API: instancia</label><input id="whatsapp_instancia" name="whatsapp_instancia" maxlength="100" value="<?= e(Configuracao::obter('whatsapp_instancia')) ?>"></div></div>
<div class="linha-campos"><div class="campo"><label for="whatsapp_telefone_id">Meta: ID do número (phone number id)</label><input id="whatsapp_telefone_id" name="whatsapp_telefone_id" maxlength="40" inputmode="numeric" value="<?= e(Configuracao::obter('whatsapp_telefone_id')) ?>"></div><div class="campo"><label for="whatsapp_modelo">Meta: nome do modelo aprovado</label><input id="whatsapp_modelo" name="whatsapp_modelo" maxlength="100" placeholder="lembrete_agendamento" value="<?= e(Configuracao::obter('whatsapp_modelo')) ?>"><span class="ajuda-campo">Modelo em pt_BR com 4 variáveis, nesta ordem: nome, serviço, data e hora.</span></div></div>
<div class="campo"><label for="whatsapp_token">Chave de acesso (apikey da Evolution ou token da Meta)</label><input type="password" id="whatsapp_token" name="whatsapp_token" maxlength="255" autocomplete="new-password" placeholder="<?= Configuracao::obter('whatsapp_token') !== '' ? 'Chave guardada. Preencha só para trocar.' : '' ?>"><span class="ajuda-campo">A chave nunca volta para a tela. Deixe em branco para manter a atual.</span></div>
<button class="btn" type="submit">Salvar provedor</button>
</form>
<?php if ($whatsappAtivo): ?>
<form method="post" class="linha-campos" style="align-items:flex-end;margin-top:12px"><?= campoCsrf() ?><input type="hidden" name="acao" value="whatsapp_teste">
<div class="campo"><label for="telefone_teste">Enviar mensagem de teste para</label><input type="tel" id="telefone_teste" name="telefone_teste" data-mascara="telefone" placeholder="(11) 99999-9999" required></div>
<div class="campo"><button class="btn btn-contorno" type="submit">Enviar teste</button></div>
</form>
<?php endif; ?>
</div>
<?php if (!$notificacoes): ?><div class="estado-vazio"><strong>Fila vazia</strong><p><?= $whatsappAtivo ? 'Os lembretes entram aqui e saem sozinhos quando chega a hora.' : 'Atualize a fila quando desejar preparar os próximos lembretes.' ?></p></div><?php else: ?><div class="tabela-area"><table class="tabela"><thead><tr><th>Destino</th><th>Mensagem</th><th>Situação</th><th>Ação</th></tr></thead><tbody><?php foreach ($notificacoes as $n): $tentativas = (int) ($n['tentativas'] ?? 0); ?><tr><td><?= e(formatarTelefone($n['destinatario'])) ?></td><td><?= e($n['mensagem']) ?></td><td><?php if ($tentativas > 0): ?><span class="badge badge-cancelado"><?= $tentativas ?> falha(s)</span><br><small><?= e((string) ($n['erro'] ?? '')) ?><?= $tentativas >= Lembrete::MAX_TENTATIVAS ? ' O envio automático desistiu; envie pelo link.' : '' ?></small><?php elseif ($whatsappAtivo): ?><small>Aguardando envio automático</small><?php else: ?><small>Envio manual</small><?php endif; ?></td><td><form method="post"><?= campoCsrf() ?><input type="hidden" name="acao" value="notificacao"><input type="hidden" name="id_notificacao" value="<?= (int)$n['id_notificacao'] ?>"><a class="btn btn-pequeno" target="_blank" rel="noopener" href="<?= e(Diferencial::linkWhatsapp($n)) ?>">Abrir WhatsApp</a><button class="btn btn-contorno btn-pequeno" type="submit">Marcar enviada</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php if ($recentes): ?><div class="cartao-cabecalho"><h3>Ultimas mensagens</h3></div><div class="tabela-area"><table class="tabela"><thead><tr><th>Destino</th><th>Mensagem</th><th>Situação</th><th>Quando</th></tr></thead><tbody><?php foreach ($recentes as $n): ?><tr><td><?= e(formatarTelefone($n['destinatario'])) ?></td><td><?= e(limitarTexto($n['mensagem'], 90)) ?></td><td><?= badgeStatus($n['status'] === 'enviada' ? 'concluido' : 'cancelado') ?><?= !empty($n['erro']) ? '<br><small>' . e($n['erro']) . '</small>' : '' ?></td><td><?php $quando = $n['data_envio'] ?: $n['data_criacao']; ?><?= e(formatarData(substr((string) $quando, 0, 10))) ?> <?= e(substr((string) $quando, 11, 5)) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
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