<?php
/**
 * Gestao de clientes e consulta do historico individual.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/../config/config.php';

// Restringe esta página a administradores autenticados.
exigirLogin('admin');

// Processa o formulário enviado antes de montar o HTML da página.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Confere o token da sessão antes de aceitar alterações enviadas pelo formulário.
    exigirCsrf();

    $idCliente = (int) post('id_cliente');
    $cliente   = Cliente::porId($idCliente);

    if (!$cliente) {
        definirFlash('erro', 'Cliente nao encontrado.');
    } elseif (post('acao') === 'status') {
        $novoStatus = post('status') === 'ativo' ? 'ativo' : 'inativo';
        Usuario::alterarStatus((int) $cliente['id_usuario'], $novoStatus);
        definirFlash('sucesso', $novoStatus === 'ativo' ? 'Cliente ativado.' : 'Cliente desativado.');
    } elseif (post('acao') === 'observacoes') {
        Cliente::atualizar($idCliente, [
            'cpf'             => $cliente['cpf'],
            'data_nascimento' => $cliente['data_nascimento'],
            'observacoes'     => limitarTexto(post('observacoes'), 500),
        ]);
        definirFlash('sucesso', 'Observacoes atualizadas.');
        redirecionar('admin/clientes.php?acao=ver&id=' . $idCliente);
    }

    redirecionar('admin/clientes.php');
}

$acaoTela = get('acao');

// ---------------------------------------------------------------------
// Detalhe do cliente
// ---------------------------------------------------------------------
if ($acaoTela === 'ver') {
    $cliente = Cliente::porId((int) get('id'));

    if (!$cliente) {
        definirFlash('erro', 'Cliente nao encontrado.');
        redirecionar('admin/clientes.php');
    }

    $idCliente    = (int) $cliente['id_cliente'];
    $agendamentos = Agendamento::listar(['id_cliente' => $idCliente, 'ordem' => 'desc', 'limite' => 50]);

    $resumo = [
        'total'      => Agendamento::totalPorCliente($idCliente),
        'concluidos' => Agendamento::totalPorCliente($idCliente, 'concluido'),
        'cancelados' => Agendamento::totalPorCliente($idCliente, 'cancelado'),
    ];

    $valorTotal = 0.0;
    foreach ($agendamentos as $registro) {
        if ($registro['status'] === 'concluido') {
            $valorTotal += (float) $registro['valor'];
        }
    }

    // Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
    $tituloPagina  = 'Cliente';
    $subtituloTopo = $cliente['nome'];
    $acoesTopo     = '<a href="' . url('admin/clientes.php') . '" class="btn btn-contorno btn-pequeno">Voltar para a lista</a>';

    // Renderiza a estrutura comum do painel após preparar os dados desta tela.
    require_once RAIZ . '/includes/painel_header.php';
    ?>

    <div class="grade-indicadores">
        <div class="indicador indicador-destaque">
            <span class="indicador-rotulo">Agendamentos</span>
            <span class="indicador-valor"><?= (int) $resumo['total'] ?></span>
            <span class="indicador-nota">Total historico</span>
        </div>
        <div class="indicador indicador-positivo">
            <span class="indicador-rotulo">Concluidos</span>
            <span class="indicador-valor"><?= (int) $resumo['concluidos'] ?></span>
            <span class="indicador-nota">Atendimentos realizados</span>
        </div>
        <div class="indicador indicador-negativo">
            <span class="indicador-rotulo">Cancelados</span>
            <span class="indicador-valor"><?= (int) $resumo['cancelados'] ?></span>
            <span class="indicador-nota">Historico completo</span>
        </div>
        <div class="indicador">
            <span class="indicador-rotulo">Valor gerado</span>
            <span class="indicador-valor"><?= formatarMoeda($valorTotal) ?></span>
            <span class="indicador-nota">Somente concluidos</span>
        </div>
    </div>

    <div class="grade-painel">
        <div class="cartao">
            <div class="cartao-cabecalho"><h3>Historico de agendamentos</h3></div>

            <?php if ($agendamentos === []): ?>
                <div class="estado-vazio">
                    <strong>Nenhum agendamento registrado</strong>
                    <p>Este cliente ainda nao realizou agendamentos.</p>
                </div>
            <?php else: ?>
                <div class="tabela-area">
                    <?php /* Tabela de apresentação dos registros retornados pela consulta. */ ?><table class="tabela">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Servico</th>
                                <th>Profissional</th>
                                <th>Horario</th>
                                <th>Status</th>
                                <th class="coluna-acoes">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($agendamentos as $agendamento): ?>
                                <tr>
                                    <td class="celula-principal"><?= formatarData($agendamento['data_agendamento']) ?></td>
                                    <td><?= e($agendamento['nome_servico']) ?></td>
                                    <td><?= e($agendamento['nome_profissional']) ?></td>
                                    <td><?= formatarHora($agendamento['hora_inicio']) ?></td>
                                    <td><?= badgeStatus($agendamento['status']) ?></td>
                                    <td class="coluna-acoes"><?= formatarMoeda($agendamento['valor']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div>
            <div class="cartao">
                <div class="cartao-cabecalho"><h3>Dados do cliente</h3></div>
                <div class="cartao-corpo">
                    <dl class="lista-detalhes">
                        <div><dt>Nome</dt><dd><?= e($cliente['nome']) ?></dd></div>
                        <div><dt>CPF</dt><dd><?= e(formatarCpf($cliente['cpf'])) ?></dd></div>
                        <div><dt>Telefone</dt><dd><?= e(formatarTelefone($cliente['telefone'])) ?></dd></div>
                        <div><dt>E-mail</dt><dd><?= e($cliente['email']) ?></dd></div>
                        <div><dt>Nascimento</dt><dd><?= formatarData($cliente['data_nascimento']) ?></dd></div>
                        <div><dt>Cadastro</dt><dd><?= formatarData($cliente['data_cadastro']) ?></dd></div>
                        <div><dt>Situacao</dt><dd><?= badgeStatus($cliente['status']) ?></dd></div>
                    </dl>
                </div>
            </div>

            <div class="cartao">
                <div class="cartao-cabecalho"><h3>Observacoes internas</h3></div>
                <div class="cartao-corpo">
                    <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post">
                        <?= campoCsrf() ?>
                        <input type="hidden" name="acao" value="observacoes">
                        <input type="hidden" name="id_cliente" value="<?= (int) $cliente['id_cliente'] ?>">

                        <div class="campo">
                            <label for="observacoes">Anotacoes visiveis apenas para a equipe</label>
                            <textarea id="observacoes" name="observacoes" maxlength="500"><?= e($cliente['observacoes']) ?></textarea>
                        </div>

                        <button type="submit" class="btn">Salvar observacoes</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php
    require_once RAIZ . '/includes/painel_footer.php';
    exit;
}

// ---------------------------------------------------------------------
// Lista de clientes
// ---------------------------------------------------------------------
$busca     = get('busca');
$status    = get('status');
$porPagina = 15;
// Mantém a página como inteiro positivo para calcular a listagem e sua navegação.
$pagina    = max(1, (int) get('pagina', '1'));

$filtros = array_filter(['busca' => $busca, 'status' => $status]);
$total   = Cliente::contar($filtros);
$lista   = Cliente::listar($filtros + [
    'limite'       => $porPagina,
    'deslocamento' => ($pagina - 1) * $porPagina,
]);

// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina  = 'Clientes';
$subtituloTopo = $total . ' cliente(s) cadastrado(s)';

// Renderiza a estrutura comum do painel após preparar os dados desta tela.
require_once RAIZ . '/includes/painel_header.php';
?>

<div class="cartao">
    <?php /* Filtros enviados na URL para permitir atualizar e compartilhar a consulta. */ ?><form method="get" class="barra-filtros">
        <input type="hidden" name="estabelecimento" value="<?= e(Contexto::slug()) ?>">
        <div class="campo campo-busca">
            <label for="busca">Buscar cliente</label>
            <input type="search" id="busca" name="busca" value="<?= e($busca) ?>" placeholder="Nome, e-mail ou CPF">
        </div>

        <div class="campo">
            <label for="filtroStatus">Status</label>
            <select id="filtroStatus" name="status" data-envia-ao-mudar>
                <option value="">Todos</option>
                <option value="ativo" <?= $status === 'ativo' ? 'selected' : '' ?>>Ativos</option>
                <option value="inativo" <?= $status === 'inativo' ? 'selected' : '' ?>>Inativos</option>
            </select>
        </div>

        <div class="campo">
            <button type="submit" class="btn btn-contorno">Filtrar</button>
        </div>
    </form>

    <?php if ($lista === []): ?>
        <div class="estado-vazio">
            <strong>Nenhum cliente encontrado.</strong>
            <p>Os clientes aparecem aqui assim que criam a conta pelo site.</p>
        </div>
    <?php else: ?>
        <div class="tabela-area">
            <?php /* Tabela de apresentação dos registros retornados pela consulta. */ ?><table class="tabela">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Cliente</th>
                        <th>CPF</th>
                        <th>Contato</th>
                        <th>Cadastro</th>
                        <th>Agendamentos</th>
                        <th>Status</th>
                        <th class="coluna-acoes">Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista as $cliente): ?>
                        <tr>
                            <td><?= (int) $cliente['id_cliente'] ?></td>
                            <td class="celula-principal"><?= e($cliente['nome']) ?></td>
                            <td><?= e(formatarCpf($cliente['cpf'])) ?></td>
                            <td>
                                <?= e(formatarTelefone($cliente['telefone'])) ?>
                                <span class="celula-secundaria"><?= e($cliente['email']) ?></span>
                            </td>
                            <td><?= formatarData($cliente['data_cadastro']) ?></td>
                            <td><?= (int) $cliente['total_agendamentos'] ?></td>
                            <td><?= badgeStatus($cliente['status']) ?></td>
                            <td class="coluna-acoes">
                                <a href="<?= url('admin/clientes.php?acao=ver&id=' . (int) $cliente['id_cliente']) ?>"
                                   class="btn btn-contorno btn-pequeno">Historico</a>

                                <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" style="display:inline">
                                    <?= campoCsrf() ?>
                                    <input type="hidden" name="acao" value="status">
                                    <input type="hidden" name="id_cliente" value="<?= (int) $cliente['id_cliente'] ?>">
                                    <input type="hidden" name="status" value="<?= $cliente['status'] === 'ativo' ? 'inativo' : 'ativo' ?>">
                                    <button type="submit" class="btn btn-contorno btn-pequeno"
                                            <?= $cliente['status'] === 'ativo'
                                                ? 'data-confirmar="Desativar ' . e($cliente['nome']) . '? O cliente perde o acesso ao sistema." data-confirmar-titulo="Desativar cliente" data-confirmar-rotulo="Desativar"'
                                                : '' ?>>
                                        <?= $cliente['status'] === 'ativo' ? 'Desativar' : 'Ativar' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= renderizarPaginacao($total, $pagina, $porPagina, $filtros) ?>
    <?php endif; ?>
</div>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>
