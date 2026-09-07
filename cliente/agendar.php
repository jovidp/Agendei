<?php
/**
 * Fluxo de agendamento do cliente (servico -> profissional -> data -> horario -> confirmacao).
 * Toda a disponibilidade e validada novamente aqui, no servidor.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/../config/config.php';

// Restringe esta página a clientes autenticados.
exigirLogin('cliente');

// Usa o perfil da sessão para consultar e alterar os dados do próprio cliente.
$idCliente = perfilId();
$erros     = [];
$servicoSelecionado = 0;

// Processa o formulário enviado antes de montar o HTML da página.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Confere o token da sessão antes de aceitar alterações enviadas pelo formulário.
    exigirCsrf();

    $servicoSelecionado = (int) post('id_servico');

    // Envia a escolha para as regras do servidor; disponibilidade e preço não dependem do navegador.
    $resultado = Agendamento::criar([
        'id_cliente'      => $idCliente,
        'id_profissional' => (int) post('id_profissional'),
        'id_servico'      => $servicoSelecionado,
        'data'            => post('data'),
        'hora_inicio'     => post('hora_inicio'),
        'observacao'      => limitarTexto(post('observacao'), 500),
        'origem'          => 'cliente',
    ]);

    if ($resultado['sucesso']) {
        definirFlash('sucesso', 'Agendamento realizado com sucesso.');
        redirecionar('cliente/agendar.php?sucesso=' . $resultado['id_agendamento']);
    }

    $erros = $resultado['erros'];
}

$agendamentoConfirmado = null;
if (get('sucesso') !== '') {
    $possivel = Agendamento::porId((int) get('sucesso'));
    // Só exibe a confirmação se a reserva pertence ao cliente da sessão.
    if ($possivel && (int) $possivel['id_cliente'] === $idCliente) {
        $agendamentoConfirmado = $possivel;
    }
}

$servicos       = Servico::ativosComProfissional();
$maximoDias     = Configuracao::obterInteiro('antecedencia_maxima_dias', 60);
// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina   = 'Novo agendamento';
$subtituloTopo  = 'Escolha o servico, o profissional e o melhor horario';
$cssExtra       = ['agendamento.css'];
$jsExtra        = ['agendamento.js'];

// Renderiza a estrutura comum do painel após preparar os dados desta tela.
require_once RAIZ . '/includes/painel_header.php';
?>

<?php if ($agendamentoConfirmado !== null): ?>

    <div class="confirmacao">
        <div class="confirmacao-icone">&#10003;</div>
        <h2>Agendamento realizado</h2>
        <p>Guarde os detalhes abaixo. Voce pode acompanhar ou cancelar pelo painel.</p>

        <dl class="lista-detalhes">
            <div><dt>Servico</dt><dd><?= e($agendamentoConfirmado['nome_servico']) ?></dd></div>
            <div><dt>Profissional</dt><dd><?= e($agendamentoConfirmado['nome_profissional']) ?></dd></div>
            <div><dt>Data</dt><dd><?= e(dataExtenso($agendamentoConfirmado['data_agendamento'])) ?></dd></div>
            <div><dt>Horario</dt><dd><?= formatarHora($agendamentoConfirmado['hora_inicio']) ?> as <?= formatarHora($agendamentoConfirmado['hora_fim']) ?></dd></div>
            <div><dt>Duracao</dt><dd><?= e(duracaoTexto((int) $agendamentoConfirmado['duracao_minutos'])) ?></dd></div>
            <div><dt>Valor</dt><dd><?= formatarMoeda($agendamentoConfirmado['valor']) ?></dd></div>
            <div><dt>Status</dt><dd><?= badgeStatus($agendamentoConfirmado['status']) ?></dd></div>
        </dl>

        <div class="grupo-botoes" style="justify-content:center">
            <a href="<?= url('cliente/agendamentos.php') ?>" class="btn">Ver meus agendamentos</a>
            <a href="<?= url('cliente/agendar.php') ?>" class="btn btn-contorno">Fazer novo agendamento</a>
        </div>
    </div>

<?php elseif ($servicos === []): ?>

    <div class="cartao">
        <div class="estado-vazio">
            <strong>Nenhum servico disponivel no momento</strong>
            <p>Assim que o estabelecimento cadastrar servicos e profissionais, o agendamento sera liberado.</p>
        </div>
    </div>

<?php else: ?>

    <?php if ($erros !== []): ?>
        <div class="alerta alerta-erro">
            <div>
                <span class="alerta-texto">Nao foi possivel concluir o agendamento:</span>
                <ul>
                    <?php foreach ($erros as $mensagem): ?>
                        <li><?= e($mensagem) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="fluxo-agendamento" id="fluxoAgendamento"
         data-base="<?= e(url('')) ?>"
         data-max-dias="<?= (int) $maximoDias ?>">

        <div>
            <div class="etapas">
                <div class="etapa ativa" data-etapa="1"><span class="etapa-numero">1</span><span class="etapa-texto">Servico</span></div>
                <div class="etapa" data-etapa="2"><span class="etapa-numero">2</span><span class="etapa-texto">Profissional</span></div>
                <div class="etapa" data-etapa="3"><span class="etapa-numero">3</span><span class="etapa-texto">Data</span></div>
                <div class="etapa" data-etapa="4"><span class="etapa-numero">4</span><span class="etapa-texto">Horario</span></div>
                <div class="etapa" data-etapa="5"><span class="etapa-numero">5</span><span class="etapa-texto">Confirmacao</span></div>
            </div>

            <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" id="formAgendamento">
                <?= campoCsrf() ?>
                <input type="hidden" name="id_servico" id="campoServico">
                <input type="hidden" name="id_profissional" id="campoProfissional">
                <input type="hidden" name="data" id="campoData">
                <input type="hidden" name="hora_inicio" id="campoHora">

                <!-- Etapa 1 -->
                <section class="cartao" data-painel="1">
                    <div class="cartao-cabecalho"><h2>Escolha o servico</h2></div>
                    <div class="cartao-corpo">
                        <div class="lista-opcoes">
                            <?php foreach ($servicos as $servico): ?>
                                <div class="opcao">
                                    <input type="radio" name="servico_opcao"
                                           id="servico<?= (int) $servico['id_servico'] ?>"
                                           value="<?= (int) $servico['id_servico'] ?>"
                                           data-nome="<?= e($servico['nome']) ?>"
                                           data-preco="<?= e($servico['preco']) ?>"
                                           data-duracao="<?= (int) $servico['duracao_minutos'] ?>"
                                           <?= $servicoSelecionado === (int) $servico['id_servico'] ? 'checked' : '' ?>>
                                    <label for="servico<?= (int) $servico['id_servico'] ?>">
                                        <span class="opcao-conteudo">
                                            <strong><?= e($servico['nome']) ?></strong>
                                            <p><?= e(limitarTexto($servico['descricao'] ?? '', 90)) ?></p>
                                            <span class="opcao-meta">
                                                <span class="preco"><?= formatarMoeda($servico['preco']) ?></span>
                                                <span class="duracao"><?= e(duracaoTexto((int) $servico['duracao_minutos'])) ?></span>
                                            </span>
                                        </span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="navegacao-etapa">
                            <span></span>
                            <button type="button" class="btn" data-proxima>Continuar</button>
                        </div>
                    </div>
                </section>

                <!-- Etapa 2 -->
                <section class="cartao oculto" data-painel="2">
                    <div class="cartao-cabecalho"><h2>Escolha o profissional</h2></div>
                    <div class="cartao-corpo">
                        <div id="listaProfissionais"></div>

                        <div class="navegacao-etapa">
                            <button type="button" class="btn btn-contorno" data-voltar>Voltar</button>
                            <button type="button" class="btn" data-proxima>Continuar</button>
                        </div>
                    </div>
                </section>

                <!-- Etapa 3 -->
                <section class="cartao oculto" data-painel="3">
                    <div class="cartao-cabecalho">
                        <h2>Escolha a data</h2>
                        <span class="texto-pequeno texto-secundario">Somente dias com horarios livres ficam habilitados</span>
                    </div>
                    <div class="cartao-corpo">
                        <div class="calendario" id="calendario"></div>

                        <div class="navegacao-etapa">
                            <button type="button" class="btn btn-contorno" data-voltar>Voltar</button>
                            <button type="button" class="btn" data-proxima>Continuar</button>
                        </div>
                    </div>
                </section>

                <!-- Etapa 4 -->
                <section class="cartao oculto" data-painel="4">
                    <div class="cartao-cabecalho"><h2>Escolha o horario</h2></div>
                    <div class="cartao-corpo">
                        <div id="listaHorarios"></div>

                        <div class="navegacao-etapa">
                            <button type="button" class="btn btn-contorno" data-voltar>Voltar</button>
                            <button type="button" class="btn" data-proxima>Continuar</button>
                        </div>
                    </div>
                </section>

                <!-- Etapa 5 -->
                <section class="cartao oculto" data-painel="5">
                    <div class="cartao-cabecalho"><h2>Confirme os dados</h2></div>
                    <div class="cartao-corpo">
                        <dl class="lista-detalhes">
                            <div><dt>Servico</dt><dd data-resumo="servico">-</dd></div>
                            <div><dt>Profissional</dt><dd data-resumo="profissional">-</dd></div>
                            <div><dt>Data</dt><dd data-resumo="data">-</dd></div>
                            <div><dt>Horario</dt><dd data-resumo="hora">-</dd></div>
                            <div><dt>Duracao</dt><dd data-resumo="duracao">-</dd></div>
                            <div><dt>Valor</dt><dd data-resumo="preco">-</dd></div>
                        </dl>

                        <div class="campo margem-topo">
                            <label for="observacao">Observacao (opcional)</label>
                            <textarea id="observacao" name="observacao" maxlength="500"
                                      placeholder="Alguma preferencia ou informacao importante para o profissional?"></textarea>
                        </div>

                        <div class="navegacao-etapa">
                            <button type="button" class="btn btn-contorno" data-voltar>Voltar</button>
                            <button type="submit" class="btn btn-secundario" id="botaoConfirmar" disabled>Confirmar agendamento</button>
                        </div>
                    </div>
                </section>
            </form>
        </div>

        <aside class="resumo-agendamento">
            <div class="resumo-topo"><strong>Resumo</strong></div>
            <div class="resumo-corpo">
                <div class="resumo-linha vazia"><span>Servico</span><strong data-resumo="servico">Nao selecionado</strong></div>
                <div class="resumo-linha vazia"><span>Profissional</span><strong data-resumo="profissional">Nao selecionado</strong></div>
                <div class="resumo-linha vazia"><span>Data</span><strong data-resumo="data">Nao selecionada</strong></div>
                <div class="resumo-linha vazia"><span>Horario</span><strong data-resumo="hora">Nao selecionado</strong></div>
                <div class="resumo-linha vazia"><span>Duracao</span><strong data-resumo="duracao">-</strong></div>
            </div>
            <div class="resumo-total">
                <span>Valor total</span>
                <strong data-resumo="preco">R$ 0,00</strong>
            </div>
        </aside>
    </div>

<?php endif; ?>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>
