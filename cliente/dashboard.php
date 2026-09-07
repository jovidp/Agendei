<?php

/**
 * Painel inicial do cliente.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/../config/config.php';

// Restringe esta página a clientes autenticados.
exigirLogin('cliente');

// Usa o perfil da sessão para consultar e alterar os dados do próprio cliente.
$idCliente = perfilId();

// Prepara as próximas reservas e destaca a primeira no resumo da conta.
$proximos = Agendamento::proximosDoCliente($idCliente, 5);
$proximo  = $proximos[0] ?? null;

$ultimos = Agendamento::listar([
    'id_cliente' => $idCliente,
    'ate_hoje'   => true,
    'ordem'      => 'desc',
    'limite'     => 5,
]);

$totais = [
    'total'      => Agendamento::totalPorCliente($idCliente),
    'proximos'   => Agendamento::contar([
        'id_cliente'       => $idCliente,
        'status_em'        => ['agendado', 'confirmado'],
        'a_partir_de_hoje' => true,
    ]),
    'concluidos' => Agendamento::totalPorCliente($idCliente, 'concluido'),
    'cancelados' => Agendamento::totalPorCliente($idCliente, 'cancelado'),
];

// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina  = 'Dashboard';
$subtituloTopo = 'Resumo da sua conta';
$acoesTopo     = '<a href="' . url('cliente/agendar.php') . '" class="btn btn-pequeno">Novo agendamento</a>';

// Renderiza a estrutura comum do painel após preparar os dados desta tela.
require_once RAIZ . '/includes/painel_header.php';
?>

<div class="cabecalho-pagina">
    <div>
        <h2>Ola, <?= e(explode(' ', usuarioNome())[0]) ?></h2>
        <p>Acompanhe seus agendamentos e marque novos atendimentos.</p>
    </div>
</div>

<?php if ($proximo !== null): ?>
    <div class="resumo-destaque">
        <div class="resumo-data">
            <strong><?= date('d', strtotime($proximo['data_agendamento'])) ?></strong>
            <span><?= e(mesNome((int) date('n', strtotime($proximo['data_agendamento'])), true)) ?></span>
        </div>
        <div class="resumo-info">
            <strong><?= e($proximo['nome_servico']) ?></strong>
            <span>
                <?= e($proximo['nome_profissional']) ?> &middot;
                <?= formatarHora($proximo['hora_inicio']) ?> as <?= formatarHora($proximo['hora_fim']) ?> &middot;
                <?= formatarMoeda($proximo['valor']) ?>
            </span>
        </div>
        <div>
            <?= badgeStatus($proximo['status']) ?>
        </div>
        <div>
            <a href="<?= url('cliente/agendamentos.php') ?>" class="btn btn-contorno btn-pequeno">Ver detalhes</a>
        </div>
    </div>
<?php else: ?>
    <div class="cartao">
        <div class="estado-vazio">
            <strong>Voce nao tem agendamentos futuros</strong>
            <p>Escolha um servico e marque seu proximo atendimento.</p>
            <a href="<?= url('cliente/agendar.php') ?>" class="btn margem-topo">Agendar agora</a>
        </div>
    </div>
<?php endif; ?>

<div class="grade-indicadores">
    <div class="indicador indicador-destaque">
        <span class="indicador-rotulo">Agendamentos futuros</span>
        <span class="indicador-valor"><?= (int) $totais['proximos'] ?></span>
        <span class="indicador-nota">Agendados ou confirmados</span>
    </div>
    <div class="indicador">
        <span class="indicador-rotulo">Total de agendamentos</span>
        <span class="indicador-valor"><?= (int) $totais['total'] ?></span>
        <span class="indicador-nota">Desde o cadastro</span>
    </div>
    <div class="indicador indicador-positivo">
        <span class="indicador-rotulo">Atendimentos concluidos</span>
        <span class="indicador-valor"><?= (int) $totais['concluidos'] ?></span>
        <span class="indicador-nota">Servicos ja realizados</span>
    </div>
    <div class="indicador">
        <span class="indicador-rotulo">Cancelamentos</span>
        <span class="indicador-valor"><?= (int) $totais['cancelados'] ?></span>
        <span class="indicador-nota">Agendamentos cancelados</span>
    </div>
</div>

<div class="grade-painel">
    <div class="cartao">
        <div class="cartao-cabecalho">
            <h3>Proximos agendamentos</h3>
            <a href="<?= url('cliente/agendamentos.php') ?>" class="btn-texto">Ver todos</a>
        </div>

        <?php if ($proximos === []): ?>
            <div class="estado-vazio">
                <strong>Nenhum agendamento futuro</strong>
                <p>Seus proximos atendimentos aparecerao aqui.</p>
            </div>
        <?php else: ?>
            <div class="tabela-area">
                <?php /* Tabela de apresentação dos registros retornados pela consulta. */ ?><table class="tabela">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Servico</th>
                            <th>Profissional</th>
                            <th>Status</th>
                            <th class="coluna-acoes">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($proximos as $agendamento): ?>
                            <tr>
                                <td>
                                    <span class="celula-principal"><?= formatarData($agendamento['data_agendamento']) ?></span>
                                    <span class="celula-secundaria"><?= formatarHora($agendamento['hora_inicio']) ?></span>
                                </td>
                                <td><?= e($agendamento['nome_servico']) ?></td>
                                <td><?= e($agendamento['nome_profissional']) ?></td>
                                <td><?= badgeStatus($agendamento['status']) ?></td>
                                <td class="coluna-acoes"><?= formatarMoeda($agendamento['valor']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="cartao">
        <div class="cartao-cabecalho">
            <h3>Ultimos atendimentos</h3>
            <a href="<?= url('cliente/historico.php') ?>" class="btn-texto">Historico</a>
        </div>

        <?php if ($ultimos === []): ?>
            <div class="estado-vazio">
                <strong>Nenhum atendimento anterior</strong>
                <p>Seu historico comeca apos o primeiro atendimento.</p>
            </div>
        <?php else: ?>
            <ul class="lista-simples">
                <?php foreach ($ultimos as $agendamento): ?>
                    <li>
                        <div class="conteudo">
                            <strong><?= e($agendamento['nome_servico']) ?></strong>
                            <span><?= formatarData($agendamento['data_agendamento']) ?> &middot; <?= e($agendamento['nome_profissional']) ?></span>
                        </div>
                        <span class="valor"><?= badgeStatus($agendamento['status']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>