<?php

/**
 * Fluxo de agendamento do cliente (servico -> unidade -> profissional -> data -> horario -> confirmacao).
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
        $serie = Diferencial::criarRecorrencias((int) $resultado['id_agendamento'], (int) post('repeticoes', '1'));
        $mensagem = $serie['criadas'] > 1
            ? $serie['criadas'] . ' agendamentos recorrentes foram criados.'
            : 'Agendamento realizado com sucesso.';
        if ($serie['falhas']) {
            $mensagem .= ' Algumas datas não estavam disponíveis.';
        }
        definirFlash('sucesso', $mensagem);
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
$subtituloTopo  = 'Escolha o serviço, a unidade, o profissional e o melhor horário';
$cssExtra       = ['agendamento.css'];
$jsExtra        = ['agendamento.js'];

// Renderiza a estrutura comum do painel após preparar os dados desta tela.
require_once RAIZ . '/includes/painel_header.php';
?>

<?php if ($agendamentoConfirmado !== null): ?>

    <div class="confirmacao">
        <div class="confirmacao-icone">&#10003;</div>
        <h2>Agendamento realizado</h2>
        <p>Guarde os detalhes abaixo. Você pode acompanhar ou cancelar pelo painel.</p>

        <dl class="lista-detalhes">
            <div>
                <dt>Serviço</dt>
                <dd><?= e($agendamentoConfirmado['nome_servico']) ?></dd>
            </div>
            <div>
                <dt>Profissional</dt>
                <dd><?= e($agendamentoConfirmado['nome_profissional']) ?></dd>
            </div>
            <div>
                <dt>Data</dt>
                <dd><?= e(dataExtenso($agendamentoConfirmado['data_agendamento'])) ?></dd>
            </div>
            <div>
                <dt>Horário</dt>
                <dd><?= formatarHora($agendamentoConfirmado['hora_inicio']) ?> às <?= formatarHora($agendamentoConfirmado['hora_fim']) ?></dd>
            </div>
            <div>
                <dt>Duração</dt>
                <dd><?= e(duracaoTexto((int) $agendamentoConfirmado['duracao_minutos'])) ?></dd>
            </div>
            <div>
                <dt>Valor</dt>
                <dd><?= formatarMoeda($agendamentoConfirmado['valor']) ?></dd>
            </div>
            <div>
                <dt>Status</dt>
                <dd><?= badgeStatus($agendamentoConfirmado['status']) ?></dd>
            </div>
        </dl>

        <div class="grupo-botoes" style="justify-content:center">
            <a href="<?= url('cliente/agendamentos.php') ?>" class="btn">Ver meus agendamentos</a>
            <a href="<?= url('cliente/agendar.php') ?>" class="btn btn-contorno">Fazer novo agendamento</a>
        </div>
    </div>

<?php elseif ($servicos === []): ?>

    <div class="cartao">
        <div class="estado-vazio">
            <strong>Nenhum serviço disponível no momento</strong>
            <p>Assim que o estabelecimento cadastrar serviços e profissionais, o agendamento será liberado.</p>
        </div>
    </div>

<?php else: ?>

    <?php if ($erros !== []): ?>
        <div class="alerta alerta-erro">
            <div>
                <span class="alerta-texto">Não foi possível concluir o agendamento:</span>
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
                <div class="etapa ativa" data-etapa="1"><span class="etapa-numero">1</span><span class="etapa-texto">Serviço</span></div>
                <div class="etapa" data-etapa="2"><span class="etapa-numero">2</span><span class="etapa-texto">Unidade</span></div>
                <div class="etapa" data-etapa="3"><span class="etapa-numero">3</span><span class="etapa-texto">Profissional</span></div>
                <div class="etapa" data-etapa="4"><span class="etapa-numero">4</span><span class="etapa-texto">Data</span></div>
                <div class="etapa" data-etapa="5"><span class="etapa-numero">5</span><span class="etapa-texto">Horário</span></div>
                <div class="etapa" data-etapa="6"><span class="etapa-numero">6</span><span class="etapa-texto">Confirmação</span></div>
            </div>

            <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" id="formAgendamento">
                <?= campoCsrf() ?>
                <input type="hidden" name="id_servico" id="campoServico">
                <input type="hidden" name="id_filial" id="campoFilial">
                <input type="hidden" name="id_profissional" id="campoProfissional">
                <input type="hidden" name="data" id="campoData">
                <input type="hidden" name="hora_inicio" id="campoHora">

                <!-- Etapa 1 -->
                <section class="cartao" data-painel="1">
                    <div class="cartao-cabecalho">
                        <h2>Escolha o serviço</h2>
                    </div>
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
                    <div class="cartao-cabecalho">
                        <h2>Escolha a unidade</h2>
                        <span class="texto-pequeno texto-secundario">Onde você quer ser atendido?</span>
                    </div>
                    <div class="cartao-corpo">
                        <div id="listaFiliais"></div>

                        <div class="navegacao-etapa">
                            <button type="button" class="btn btn-contorno" data-voltar>Voltar</button>
                            <button type="button" class="btn" data-proxima>Continuar</button>
                        </div>
                    </div>
                </section>

                <!-- Etapa 3 -->
                <section class="cartao oculto" data-painel="3">
                    <div class="cartao-cabecalho">
                        <h2>Escolha o profissional</h2>
                    </div>
                    <div class="cartao-corpo">
                        <div id="listaProfissionais"></div>

                        <div class="navegacao-etapa">
                            <button type="button" class="btn btn-contorno" data-voltar>Voltar</button>
                            <button type="button" class="btn" data-proxima>Continuar</button>
                        </div>
                    </div>
                </section>

                <!-- Etapa 4 -->
                <section class="cartao oculto" data-painel="4">
                    <div class="cartao-cabecalho">
                        <h2>Escolha a data</h2>
                        <span class="texto-pequeno texto-secundario">Somente dias com horários livres ficam habilitados</span>
                    </div>
                    <div class="cartao-corpo">
                        <div class="calendario" id="calendario"></div>

                        <div class="navegacao-etapa">
                            <button type="button" class="btn btn-contorno" data-voltar>Voltar</button>
                            <button type="button" class="btn" data-proxima>Continuar</button>
                        </div>
                    </div>
                </section>

                <!-- Etapa 5 -->
                <section class="cartao oculto" data-painel="5">
                    <div class="cartao-cabecalho">
                        <h2>Escolha o horário</h2>
                    </div>
                    <div class="cartao-corpo">
                        <div id="listaHorarios"></div>

                        <div class="navegacao-etapa">
                            <button type="button" class="btn btn-contorno" data-voltar>Voltar</button>
                            <button type="button" class="btn" data-proxima>Continuar</button>
                        </div>
                    </div>
                </section>

                <!-- Etapa 6 -->
                <section class="cartao oculto" data-painel="6">
                    <div class="cartao-cabecalho">
                        <h2>Confirme os dados</h2>
                    </div>
                    <div class="cartao-corpo">
                        <dl class="lista-detalhes">
                            <div>
                                <dt>Serviço</dt>
                                <dd data-resumo="servico">-</dd>
                            </div>
                            <div>
                                <dt>Unidade</dt>
                                <dd data-resumo="unidade">-</dd>
                            </div>
                            <div>
                                <dt>Profissional</dt>
                                <dd data-resumo="profissional">-</dd>
                            </div>
                            <div>
                                <dt>Data</dt>
                                <dd data-resumo="data">-</dd>
                            </div>
                            <div>
                                <dt>Horário</dt>
                                <dd data-resumo="hora">-</dd>
                            </div>
                            <div>
                                <dt>Duração</dt>
                                <dd data-resumo="duracao">-</dd>
                            </div>
                            <div>
                                <dt>Valor</dt>
                                <dd data-resumo="preco">-</dd>
                            </div>
                        </dl>

                        <div class="linha-campos margem-topo">
                            <div class="campo">
                                <label for="repeticoes">Repetir semanalmente</label>
                                <select id="repeticoes" name="repeticoes">
                                    <option value="1">Não repetir</option>
                                    <option value="2">2 semanas</option>
                                    <option value="4">4 semanas</option>
                                    <option value="8">8 semanas</option>
                                    <option value="12">12 semanas</option>
                                </select>
                            </div>
                            <div class="campo">
                                <label for="observacao">Observação (opcional)</label>
                                <textarea id="observacao" name="observacao" maxlength="500"
                                    placeholder="Alguma preferência importante?"></textarea>
                            </div>
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
                <div class="resumo-linha vazia"><span>Serviço</span><strong data-resumo="servico">Não selecionado</strong></div>
                <div class="resumo-linha vazia"><span>Unidade</span><strong data-resumo="unidade">Não selecionada</strong></div>
                <div class="resumo-linha vazia"><span>Profissional</span><strong data-resumo="profissional">Não selecionado</strong></div>
                <div class="resumo-linha vazia"><span>Data</span><strong data-resumo="data">Não selecionada</strong></div>
                <div class="resumo-linha vazia"><span>Horário</span><strong data-resumo="hora">Não selecionado</strong></div>
                <div class="resumo-linha vazia"><span>Duração</span><strong data-resumo="duracao">-</strong></div>
            </div>
            <div class="resumo-total">
                <span>Valor total</span>
                <strong data-resumo="preco">R$ 0,00</strong>
            </div>
        </aside>
    </div>

<?php endif; ?>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>