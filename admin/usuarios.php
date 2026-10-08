<?php
/**
 * Consulta de usuarios do sistema.
 * Exclusiva do perfil master: lista os usuarios comuns, pesquisa por parte do
 * nome e permite excluir a conta selecionada.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/../config/config.php';

// Somente o perfil master abre esta tela.
exigirLogin('admin');

// Processa o formulário enviado antes de montar o HTML da página.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Confere o token da sessão antes de aceitar alterações enviadas pelo formulário.
    exigirCsrf();

    if (post('acao') === 'excluir') {
        $idCliente = (int) post('id_cliente');
        $cliente   = Cliente::porId($idCliente);

        if ($cliente === null) {
            definirFlash('erro', 'Usuario nao encontrado.');
        } else {
            try {
                // Apaga o vinculo de cliente com esta empresa (o perfil cai em
                // cascata); a pessoa so some se nao tiver vinculo em outra empresa.
                // O log de autenticacao permanece: ele guarda nome e CPF por copia.
                Vinculo::excluir((int) $cliente['id_vinculo']);
                definirFlash('sucesso', 'Usuario ' . $cliente['nome'] . ' excluido com sucesso.');
            } catch (Throwable $erro) {
                error_log('Falha ao excluir usuario: ' . $erro->getMessage());
                definirFlash('erro', 'Nao foi possivel excluir este usuario.');
            }
        }

        // Recarrega a listagem preservando a pesquisa em andamento.
        $buscaAtual = post('busca');
        redirecionar('admin/usuarios.php' . ($buscaAtual !== '' ? '?busca=' . rawurlencode($buscaAtual) : ''));
    }
}

$busca     = get('busca');
$porPagina = 15;
// Mantém a página como inteiro positivo para calcular a listagem e sua navegação.
$pagina    = max(1, (int) get('pagina', '1'));

$filtros = array_filter(['busca' => $busca]);
$total   = Cliente::contar($filtros);
$lista   = Cliente::listar($filtros + [
    'limite'       => $porPagina,
    'deslocamento' => ($pagina - 1) * $porPagina,
]);

// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina  = 'Consulta de usuarios';
$subtituloTopo = $total . ' usuario(s) comum(ns) cadastrado(s)';
$acoesTopo     = '<a class="btn btn-contorno" href="'
    . e(url('admin/usuarios_pdf.php' . ($busca !== '' ? '?busca=' . rawurlencode($busca) : '')))
    . '">Baixar PDF</a>';

// Renderiza a estrutura comum do painel após preparar os dados desta tela.
require_once RAIZ . '/includes/painel_header.php';
?>

<div class="cartao">
    <?php /* Filtros enviados na URL para permitir atualizar e compartilhar a consulta. */ ?><form method="get" class="barra-filtros">
        <input type="hidden" name="estabelecimento" value="<?= e(Contexto::slug()) ?>">
        <div class="campo campo-busca">
            <label for="busca">Pesquisar usuario</label>
            <input type="search" id="busca" name="busca" value="<?= e($busca) ?>"
                   placeholder="Digite parte do nome" autofocus>
            <span class="ajuda-campo">A lista mostra todos os usuarios cujo nome contem o texto digitado.</span>
        </div>

        <div class="campo">
            <button type="submit" class="btn btn-contorno">Pesquisar</button>
        </div>

        <?php if ($busca !== ''): ?>
            <div class="campo">
                <a class="btn btn-contorno" href="<?= url('admin/usuarios.php') ?>">Limpar</a>
            </div>
        <?php endif; ?>
    </form>

    <?php if ($lista === []): ?>
        <div class="estado-vazio">
            <strong>Nenhum usuario encontrado.</strong>
            <p>
                <?= $busca !== ''
                    ? 'Nenhum nome contem "' . e($busca) . '".'
                    : 'Os usuarios aparecem aqui assim que criam a conta pelo site.' ?>
            </p>
        </div>
    <?php else: ?>
        <div class="tabela-area">
            <?php /* Tabela de apresentação dos registros retornados pela consulta. */ ?><table class="tabela">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nome</th>
                        <th>Login</th>
                        <th>CPF</th>
                        <th>Contato</th>
                        <th>Cadastro</th>
                        <th>Status</th>
                        <th class="coluna-acoes">Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista as $usuario): ?>
                        <tr>
                            <td><?= (int) $usuario['id_cliente'] ?></td>
                            <td class="celula-principal">
                                <?= e($usuario['nome']) ?>
                                <span class="celula-secundaria"><?= e($usuario['email']) ?></span>
                            </td>
                            <td><?= e($usuario['login'] ?: '-') ?></td>
                            <td><?= e(formatarCpf($usuario['cpf'])) ?></td>
                            <td>
                                <?= e(formatarTelefoneInternacional($usuario['telefone'])) ?>
                                <span class="celula-secundaria"><?= e(formatarTelefoneInternacional($usuario['telefone_fixo'])) ?></span>
                            </td>
                            <td><?= formatarData($usuario['data_cadastro']) ?></td>
                            <td><?= badgeStatus($usuario['status']) ?></td>
                            <td class="coluna-acoes">
                                <a href="<?= url('admin/clientes.php?acao=ver&id=' . (int) $usuario['id_cliente']) ?>"
                                   class="btn btn-contorno btn-pequeno">Detalhes</a>

                                <?php /* A exclusao passa pelo modal de confirmacao do sistema. */ ?><form method="post" style="display:inline">
                                    <?= campoCsrf() ?>
                                    <input type="hidden" name="acao" value="excluir">
                                    <input type="hidden" name="busca" value="<?= e($busca) ?>">
                                    <input type="hidden" name="id_cliente" value="<?= (int) $usuario['id_cliente'] ?>">
                                    <button type="submit" class="btn btn-perigo btn-pequeno"
                                            data-confirmar="Excluir o usuario <?= e($usuario['nome']) ?>? Os agendamentos dele tambem serao removidos. Esta acao nao pode ser desfeita."
                                            data-confirmar-titulo="Excluir usuario"
                                            data-confirmar-rotulo="Excluir">
                                        Excluir
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
