<?php
/**
 * Auditoria da administracao master.
 *
 * Mostra o que cada conta global fez: empresas criadas, acessos ligados e
 * desligados, senhas redefinidas e bloqueios liberados. A tela e so de leitura
 * de proposito — nenhuma acao aqui apaga ou edita o historico.
 */
define('AREA_MASTER', true);
require_once __DIR__ . '/../config/config.php';
exigirLogin('master');

$acao  = get('acao');
$busca = get('busca');
$empresa = (int) get('estabelecimento_id');

if (!isset(LogMaster::ACOES[$acao])) {
    $acao = '';
}

$porPagina = 25;
$pagina = max(1, (int) get('pagina', '1'));

$filtros = array_filter([
    'acao'            => $acao,
    'busca'           => $busca,
    'estabelecimento' => $empresa,
]);

// A paginacao remonta a URL, entao recebe os nomes dos campos do formulario,
// que nem sempre sao os nomes usados pelo filtro do model.
$parametrosUrl = array_filter([
    'acao'               => $acao,
    'busca'              => $busca,
    'estabelecimento_id' => $empresa,
]);

$total = LogMaster::contar($filtros);
$lista = LogMaster::listar($filtros + [
    'limite'       => $porPagina,
    'deslocamento' => ($pagina - 1) * $porPagina,
]);

$empresas = Estabelecimento::listarTodos();

$tituloPagina  = 'Auditoria';
$subtituloTopo = $total . ' acao(oes) registrada(s) na administracao master';
require RAIZ . '/includes/painel_header.php';
?>
<div class="cartao">
    <form method="get" class="barra-filtros">
        <div class="campo">
            <label for="acao">Acao</label>
            <select id="acao" name="acao" data-envia-ao-mudar>
                <option value="">Todas as acoes</option>
                <?php foreach (LogMaster::ACOES as $chave => $rotulo): ?>
                    <option value="<?= e($chave) ?>" <?= $acao === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
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

        <div class="campo campo-busca">
            <label for="busca">Termo</label>
            <input type="search" id="busca" name="busca" value="<?= e($busca) ?>" placeholder="Master, alvo ou empresa">
        </div>

        <div class="campo"><button type="submit" class="btn btn-contorno">Filtrar</button></div>

        <?php if ($acao !== '' || $busca !== '' || $empresa > 0): ?>
            <div class="campo"><a class="btn btn-contorno" href="<?= url('master/auditoria.php') ?>">Limpar</a></div>
        <?php endif; ?>
    </form>

    <?php if ($lista === []): ?>
        <div class="estado-vazio">
            <strong>Nenhuma acao registrada.</strong>
            <p>Entradas na area master, criacao de empresas e alteracoes de acesso aparecem aqui.</p>
        </div>
    <?php else: ?>
        <div class="tabela-area">
            <table class="tabela">
                <thead>
                    <tr>
                        <th>Dia e hora</th>
                        <th>Master</th>
                        <th>Acao</th>
                        <th>Estabelecimento</th>
                        <th>Alvo</th>
                        <th>Detalhe</th>
                        <th>Origem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista as $registro): ?>
                        <tr>
                            <td class="celula-principal">
                                <?= formatarData(substr((string) $registro['data_hora'], 0, 10)) ?>
                                <span class="celula-secundaria"><?= e(substr((string) $registro['data_hora'], 11, 8)) ?></span>
                            </td>
                            <td class="celula-principal">
                                <?= e($registro['master_nome'] !== '' ? $registro['master_nome'] : '-') ?>
                                <span class="celula-secundaria"><?= e((string) $registro['master_email']) ?></span>
                            </td>
                            <td><?= e(LogMaster::acaoTexto((string) $registro['acao'])) ?></td>
                            <td><?= e((string) ($registro['estabelecimento_nome'] ?? '') ?: '-') ?></td>
                            <td><?= e((string) ($registro['alvo'] ?? '') ?: '-') ?></td>
                            <td><?= e((string) ($registro['detalhe'] ?? '') ?: '-') ?></td>
                            <td><?= e((string) ($registro['ip'] ?? '') ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= renderizarPaginacao($total, $pagina, $porPagina, $parametrosUrl) ?>
    <?php endif; ?>
</div>
<?php require RAIZ . '/includes/painel_footer.php'; ?>
