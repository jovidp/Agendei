<?php
/**
 * Contas por estabelecimento.
 *
 * Responde a pergunta "este e-mail (ou nome, ou login) pertence a qual
 * empresa?". O vinculo e uma coluna so, usuarios.id_estabelecimento, e esta
 * tela le direto dela, sem passar pela sessao de nenhuma empresa. O e-mail e
 * unico em toda a plataforma, entao cada e-mail aparece em uma linha; so uma
 * base antiga, ainda nao migrada (scripts/migrar_email_unico.php), pode
 * mostrar o mesmo e-mail em mais de uma empresa.
 *
 * Tela de consulta: nada aqui altera dados. Ajustes na conta continuam sendo
 * feitos pela tela do estabelecimento, que registra auditoria.
 */
define('AREA_MASTER', true);
require_once __DIR__ . '/../config/config.php';
exigirLogin('master');

$busca   = get('busca');
$tipo    = get('tipo');
$empresa = (int) get('estabelecimento_id');
$porPagina = 20;
$pagina    = max(1, (int) get('pagina', '1'));

$filtros = array_filter([
    'busca'          => $busca,
    'tipo'           => isset(Usuario::TIPOS[$tipo]) ? $tipo : '',
    'estabelecimento' => $empresa,
]);

$total = Usuario::contarGlobal($filtros);
$lista = Usuario::buscarGlobal($filtros + [
    'limite'       => $porPagina,
    'deslocamento' => ($pagina - 1) * $porPagina,
]);
$empresas = Estabelecimento::listarTodos();

// Parametros que a paginacao precisa repetir na URL.
$parametrosUrl = array_filter(['busca' => $busca, 'tipo' => $filtros['tipo'] ?? '', 'estabelecimento_id' => $empresa ?: '']);

$tituloPagina  = 'Contas por estabelecimento';
$subtituloTopo = $total . ' vinculo(s) encontrado(s)';
require RAIZ . '/includes/painel_header.php';
?>
<div class="cartao">
    <form method="get" class="barra-filtros">
        <div class="campo campo-busca">
            <label for="busca">E-mail, nome ou login</label>
            <input type="search" id="busca" name="busca" value="<?= e($busca) ?>" placeholder="pessoa@exemplo.com" autofocus>
            <span class="ajuda-campo">Procura em todas as empresas. Cada e-mail tem uma unica conta na plataforma.</span>
        </div>

        <div class="campo">
            <label for="tipo">Tipo de conta</label>
            <select id="tipo" name="tipo" data-envia-ao-mudar>
                <option value="">Todos</option>
                <?php foreach (Usuario::TIPOS as $chave => $rotulo): ?>
                    <option value="<?= e($chave) ?>" <?= ($filtros['tipo'] ?? '') === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="campo">
            <label for="estabelecimento_id">Estabelecimento</label>
            <select id="estabelecimento_id" name="estabelecimento_id" data-envia-ao-mudar>
                <option value="">Todos</option>
                <?php foreach ($empresas as $item): ?>
                    <option value="<?= (int) $item['id_estabelecimento'] ?>" <?= $empresa === (int) $item['id_estabelecimento'] ? 'selected' : '' ?>>
                        <?= e($item['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="campo"><button type="submit" class="btn btn-contorno">Pesquisar</button></div>

        <?php if ($parametrosUrl): ?>
            <div class="campo"><a class="btn btn-contorno" href="<?= url('master/usuarios.php') ?>">Limpar</a></div>
        <?php endif; ?>
    </form>

    <?php if ($lista === []): ?>
        <div class="estado-vazio">
            <strong>Nenhuma conta encontrada.</strong>
            <p><?= $parametrosUrl ? 'Nenhum vinculo atende aos filtros informados.' : 'As contas aparecem aqui assim que sao criadas em algum estabelecimento.' ?></p>
        </div>
    <?php else: ?>
        <div class="tabela-area">
            <table class="tabela">
                <thead>
                    <tr>
                        <th>Conta</th>
                        <th>Login</th>
                        <th>Tipo</th>
                        <th>Estabelecimento</th>
                        <th>Ultimo acesso</th>
                        <th>Status</th>
                        <th class="coluna-acoes">Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista as $conta): ?>
                        <tr>
                            <td class="celula-principal">
                                <?= e($conta['nome']) ?>
                                <span class="celula-secundaria"><?= e($conta['email']) ?></span>
                            </td>
                            <td><?= e($conta['login'] ?: '-') ?></td>
                            <td><?= e(Usuario::TIPOS[$conta['tipo']] ?? $conta['tipo']) ?></td>
                            <td class="celula-principal">
                                <?= e($conta['estabelecimento_nome']) ?>
                                <span class="celula-secundaria">
                                    #<?= (int) $conta['id_estabelecimento'] ?> · <?= e($conta['estabelecimento_slug']) ?>
                                    <?= $conta['estabelecimento_status'] !== 'ativo' ? ' · empresa inativa' : '' ?>
                                </span>
                            </td>
                            <td><?= $conta['ultimo_acesso'] ? e(formatarData(substr((string) $conta['ultimo_acesso'], 0, 10))) : 'nunca entrou' ?></td>
                            <td><?= badgeStatus($conta['status']) ?></td>
                            <td class="coluna-acoes">
                                <a class="btn btn-contorno btn-pequeno" href="<?= url('master/estabelecimentos.php?acao=ver&id=' . (int) $conta['id_estabelecimento']) ?>">Ver estabelecimento</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= renderizarPaginacao($total, $pagina, $porPagina, $parametrosUrl) ?>
    <?php endif; ?>
</div>
<?php require RAIZ . '/includes/painel_footer.php'; ?>
