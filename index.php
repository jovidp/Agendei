<?php
/**
 * Pagina inicial publica do estabelecimento.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/config/config.php';

// Consulta os dados institucionais e os cadastros que alimentam os cartões públicos.
$estabelecimento = Estabelecimento::dados();
$servicos        = Servico::destaques(6);
$profissionais   = Profissional::ativos();
$totalServicos   = Servico::total('ativo');

// Clientes autenticados seguem para o agendamento; os demais passam pelo login.
$linkAgendar = estaLogado() && ehCliente()
    ? url('cliente/agendar.php')
    : url('login.php?destino=agendar');

// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina = $estabelecimento['nome'] . ' | Agendamento online';
$paginaAtiva  = 'inicio';

// Monta o cabeçalho público com os dados preparados nesta página.
require_once RAIZ . '/includes/header.php';
?>

<?php /* Apresentação do estabelecimento e acesso principal ao agendamento. */ ?><section class="destaque-principal">
    <div class="container destaque-grade">
        <div class="destaque-texto">
            <span class="etiqueta">Agendamento online</span>
            <h1><?= e($estabelecimento['slogan'] ?: 'Agende seu horario em poucos cliques') ?></h1>
            <p><?= e(limitarTexto($estabelecimento['descricao'] ?? '', 260)) ?></p>

            <div class="destaque-acoes">
                <a href="<?= e($linkAgendar) ?>" class="btn btn-grande">Agendar agora</a>
                <a href="#servicos" class="btn btn-contorno btn-grande">Ver servicos</a>
            </div>

            <div class="destaque-indicadores">
                <div>
                    <strong><?= (int) $totalServicos ?></strong>
                    <span>Servicos disponiveis</span>
                </div>
                <div>
                    <strong><?= count($profissionais) ?></strong>
                    <span>Profissionais</span>
                </div>
                <div>
                    <strong>100%</strong>
                    <span>Hora marcada</span>
                </div>
            </div>
        </div>

        <div class="cartao-destaque">
            <div class="cartao-destaque-topo">
                <strong>Atendimento</strong>
                <span>Confira os horarios e o contato</span>
            </div>
            <div class="cartao-destaque-lista">
                <?php foreach (array_filter(array_map('trim', explode("\n", (string) $estabelecimento['horario_funcionamento']))) as $linha): ?>
                    <?php $partes = explode(':', $linha, 2); ?>
                    <div>
                        <span><?= e($partes[0]) ?></span>
                        <strong><?= e(trim($partes[1] ?? '')) ?></strong>
                    </div>
                <?php endforeach; ?>
                <?php if (!empty($estabelecimento['telefone'])): ?>
                    <div>
                        <span>Telefone</span>
                        <strong><?= e($estabelecimento['telefone']) ?></strong>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php /* Catálogo de serviços em destaque com preço e duração. */ ?><section class="secao secao-clara" id="servicos">
    <div class="container">
        <div class="secao-titulo">
            <h2>Servicos</h2>
            <p>Escolha o servico desejado e agende com o profissional da sua preferencia.</p>
        </div>

        <?php if ($servicos === []): ?>
            <div class="estado-vazio">
                <strong>Nenhum servico cadastrado</strong>
                <p>Os servicos aparecerao aqui assim que forem cadastrados pelo estabelecimento.</p>
            </div>
        <?php else: ?>
            <div class="grade-servicos">
                <?php foreach ($servicos as $servico): ?>
                    <article class="cartao-servico">
                        <h3><?= e($servico['nome']) ?></h3>
                        <p><?= e(limitarTexto($servico['descricao'] ?? '', 120)) ?></p>
                        <div class="servico-rodape">
                            <div>
                                <span class="servico-preco"><?= formatarMoeda($servico['preco']) ?></span><br>
                                <span class="servico-duracao"><?= e(duracaoTexto((int) $servico['duracao_minutos'])) ?></span>
                            </div>
                            <a href="<?= e($linkAgendar) ?>" class="btn btn-contorno btn-pequeno">Agendar</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php /* Orientações sobre as etapas que o cliente percorre para reservar um horário. */ ?><section class="secao" id="como-funciona">
    <div class="container">
        <div class="secao-titulo">
            <h2>Como funciona</h2>
            <p>Todo o processo leva menos de um minuto.</p>
        </div>

        <div class="grade-passos">
            <div class="passo">
                <span class="passo-numero">1</span>
                <h3>Crie sua conta</h3>
                <p>Cadastro rapido com seus dados basicos de contato.</p>
            </div>
            <div class="passo">
                <span class="passo-numero">2</span>
                <h3>Escolha o servico</h3>
                <p>Veja preco e duracao de cada servico antes de agendar.</p>
            </div>
            <div class="passo">
                <span class="passo-numero">3</span>
                <h3>Selecione data e horario</h3>
                <p>Somente horarios realmente livres do profissional aparecem.</p>
            </div>
            <div class="passo">
                <span class="passo-numero">4</span>
                <h3>Pronto</h3>
                <p>Acompanhe, confirme ou cancele seus agendamentos pelo painel.</p>
            </div>
        </div>
    </div>
</section>

<?php /* Apresentação dos profissionais ativos e de seus serviços. */ ?><section class="secao secao-clara" id="profissionais">
    <div class="container">
        <div class="secao-titulo">
            <h2>Profissionais</h2>
            <p>Equipe pronta para atender voce com hora marcada.</p>
        </div>

        <?php if ($profissionais === []): ?>
            <div class="estado-vazio">
                <strong>Nenhum profissional cadastrado</strong>
                <p>A equipe sera exibida aqui assim que for cadastrada.</p>
            </div>
        <?php else: ?>
            <div class="grade-profissionais">
                <?php foreach ($profissionais as $profissional): ?>
                    <?php $servicosProfissional = array_slice(Profissional::servicos((int) $profissional['id_profissional']), 0, 3); ?>
                    <article class="cartao-profissional">
                        <span class="avatar"><?= e(iniciais($profissional['nome'])) ?></span>
                        <div>
                            <h3><?= e($profissional['nome']) ?></h3>
                            <p><?= e($profissional['especialidade'] ?: 'Profissional do estabelecimento') ?></p>
                            <div class="lista-etiquetas">
                                <?php foreach ($servicosProfissional as $servico): ?>
                                    <span class="etiqueta-servico"><?= e($servico['nome']) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php /* Informações públicas de contato, endereço e funcionamento. */ ?><section class="secao" id="contato">
    <div class="container">
        <div class="secao-titulo">
            <h2>Contato e horarios</h2>
            <p>Fale com a gente ou venha nos visitar.</p>
        </div>

        <div class="grade-contato">
            <div class="bloco-contato">
                <h3>Onde estamos</h3>
                <p><?= e(Estabelecimento::enderecoCompleto() ?: 'Endereco nao informado.') ?></p>
                <?php if (!empty($estabelecimento['telefone'])): ?>
                    <p><strong>Telefone:</strong> <?= e($estabelecimento['telefone']) ?></p>
                <?php endif; ?>
                <?php if (!empty($estabelecimento['whatsapp'])): ?>
                    <p><strong>WhatsApp:</strong> <?= e($estabelecimento['whatsapp']) ?></p>
                <?php endif; ?>
                <?php if (!empty($estabelecimento['email'])): ?>
                    <p><strong>E-mail:</strong> <?= e($estabelecimento['email']) ?></p>
                <?php endif; ?>
            </div>

            <div class="bloco-contato">
                <h3>Horario de funcionamento</h3>
                <p class="horario-funcionamento"><?= e($estabelecimento['horario_funcionamento'] ?: 'Horario nao informado.') ?></p>
            </div>
        </div>

        <div class="chamada-final margem-topo">
            <div>
                <h2>Pronto para agendar?</h2>
                <p>Escolha o servico, o profissional e o melhor horario para voce.</p>
            </div>
            <a href="<?= e($linkAgendar) ?>" class="btn btn-grande">Agendar agora</a>
        </div>
    </div>
</section>

<?php require_once RAIZ . '/includes/footer.php'; ?>
