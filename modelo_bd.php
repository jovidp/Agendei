<?php
/** DER e dicionário derivados dos scripts usados na instalação. */
require_once __DIR__ . '/config/config.php';
exigirLogin();
$modelo = ModeloBanco::carregar(Database::driver());
$tabelas = $modelo['tabelas'];
$selecionada = is_string($_GET['tabela'] ?? null) ? $_GET['tabela'] : 'agendamentos';
if (!isset($tabelas[$selecionada])) $selecionada = 'agendamentos';
$desenho = array_values(array_filter(ModeloBanco::relacoes($modelo, true),
    static fn(array $fk): bool => $fk['pai'] === $selecionada || $fk['filha'] === $selecionada));
if (($_GET['formato'] ?? '') === 'mermaid') {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="agendei-der.mmd"');
    echo ModeloBanco::mermaid($modelo);
    exit;
}
$tituloPagina = 'Modelo do banco de dados';
$subtituloTopo = count($tabelas) . ' tabelas · DER, chaves e regras do sistema';
$cssExtra = ['modelo_bd.css'];
require_once RAIZ . '/includes/painel_header.php';
?>
<div class="cartao modelo-introducao">
    <div class="cartao-cabecalho"><h3>Diagrama entidade-relacionamento completo</h3></div>
    <div class="modelo-conteudo">
        <p>Explore as relações de cada tabela e consulte todos os campos no dicionário abaixo.
            Fonte: <code><?= e($modelo['arquivo']) ?></code>, o esquema versionado do sistema.</p>
        <p>O desenho usa as chaves estrangeiras declaradas no SQL. Relações mantidas pelo código,
            como o crédito de pacote de um agendamento, estão explicadas nas regras de funcionamento.</p>
        <a class="btn btn-contorno" href="<?= e(url('modelo_bd.php?formato=mermaid')) ?>">Baixar DER completo (Mermaid)</a>
    </div>
</div>
<div class="cartao">
    <div class="cartao-cabecalho"><h3>Relações por tabela</h3></div>
    <form class="modelo-filtro" method="get" action="<?= e(url('modelo_bd.php')) ?>">
        <?php if (Contexto::slug() !== ''): ?>
            <input type="hidden" name="estabelecimento" value="<?= e(Contexto::slug()) ?>">
        <?php endif; ?>
        <label for="tabela-modelo">Tabela</label>
        <select id="tabela-modelo" name="tabela">
            <?php foreach ($tabelas as $nome => $tabela): ?>
                <option value="<?= e($nome) ?>" <?= $nome === $selecionada ? 'selected' : '' ?>><?= e($nome) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-primario" type="submit">Exibir relações</button>
        <a href="#tabela-<?= e($selecionada) ?>">Ver campos</a>
    </form>
    <div class="modelo-conteudo">
        <p><strong>1</strong> = exatamente um; <strong>0..1</strong> = opcional, no máximo um;
            <strong>0..N</strong> = zero ou vários. As cardinalidades indicam quantos registros de cada lado
            podem corresponder a um registro do outro lado.</p>
        <p>Quando duas FKs ligam os mesmos registros, a ligação composta aparece no desenho.
            Todas as restrições, inclusive as simples, constam no dicionário.</p>
    </div>
    <?php if ($desenho): ?>
        <div class="modelo-diagrama" tabindex="0" role="region" aria-label="Diagrama com rolagem horizontal">
            <svg viewBox="0 0 1000 <?= count($desenho) * 116 ?>" role="img" aria-labelledby="titulo-der descricao-der">
                <title id="titulo-der">Relacionamentos de <?= e($selecionada) ?></title>
                <desc id="descricao-der">Cada linha mostra uma tabela referenciada à esquerda e a tabela que contém a chave estrangeira à direita. A tabela textual a seguir contém as mesmas relações.</desc>
                <?php foreach ($desenho as $indice => $fk): $y = 12 + $indice * 116; ?>
                    <g>
                        <rect class="modelo-entidade" x="10" y="<?= $y ?>" width="370" height="84" rx="6" />
                        <rect class="modelo-entidade" x="620" y="<?= $y ?>" width="370" height="84" rx="6" />
                        <text class="modelo-titulo" x="24" y="<?= $y + 24 ?>"><?= e($fk['pai']) ?></text>
                        <text class="modelo-titulo" x="634" y="<?= $y + 24 ?>"><?= e($fk['filha']) ?></text>
                        <?php foreach ($fk['referencias'] as $i => $campo): ?>
                            <text class="modelo-campo" x="24" y="<?= $y + 46 + $i * 17 ?>"><?= e($campo) ?></text>
                        <?php endforeach; ?>
                        <?php foreach ($fk['campos'] as $i => $campo): ?>
                            <text class="modelo-campo" x="634" y="<?= $y + 46 + $i * 17 ?>">FK <?= e($campo) ?></text>
                        <?php endforeach; ?>
                        <path class="modelo-ligacao" d="M380 <?= $y + 42 ?> H620" />
                        <text class="modelo-cardinalidade" x="403" y="<?= $y + 31 ?>"><?= e($fk['por_filha']) ?></text>
                        <text class="modelo-cardinalidade" x="578" y="<?= $y + 31 ?>"><?= e($fk['por_pai']) ?></text>
                    </g>
                <?php endforeach; ?>
            </svg>
        </div>
        <div class="tabela-area">
            <table class="tabela">
                <caption>Chaves estrangeiras relacionadas a <?= e($selecionada) ?></caption>
                <thead><tr><th>Tabela referenciada</th><th>Tabela com FK</th><th>Colunas da FK</th><th>Pais por registro filho</th><th>Filhos por registro pai</th></tr></thead>
                <tbody>
                    <?php foreach ($desenho as $fk): ?>
                        <tr><td><?= e($fk['pai']) ?></td><td><?= e($fk['filha']) ?></td><td><code><?= e(implode(', ', $fk['campos'])) ?></code></td><td><?= e($fk['por_filha']) ?></td><td><?= e($fk['por_pai']) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="modelo-conteudo">Esta tabela não participa de relacionamentos por chave estrangeira no esquema.</p>
    <?php endif; ?>
</div>
<div class="cartao">
    <div class="cartao-cabecalho"><h3>Referências da aplicação sem chave estrangeira</h3></div>
    <p class="modelo-conteudo">Estes identificadores são usados pelo PHP, mas não têm integridade referencial
        imposta por FK. Referências históricas podem permanecer após a exclusão da conta ou empresa.</p>
    <div class="tabela-area">
        <table class="tabela">
            <thead><tr><th>Coluna</th><th>Referência lógica</th><th>Uso no sistema</th></tr></thead>
            <tbody>
                <tr><td><code>agendamentos.id_cliente_pacote</code></td><td><code>cliente_pacotes</code></td><td>Zero ou uma compra por reserva; uma compra pode custear várias reservas.</td></tr>
                <tr><td><code>logs_autenticacao.id_usuario</code></td><td><code>usuarios</code></td><td>Identificador opcional da conta; um usuário pode originar vários logs.</td></tr>
                <tr><td><code>logs_master.id_master</code></td><td><code>administradores_master</code></td><td>Identificador opcional do autor; um master pode originar vários logs.</td></tr>
                <tr><td><code>logs_master.id_estabelecimento</code></td><td><code>estabelecimento</code></td><td>Identificador opcional da empresa citada; uma empresa pode ser citada em vários logs.</td></tr>
            </tbody>
        </table>
    </div>
</div>
<div class="cartao">
    <div class="cartao-cabecalho"><h3>Dicionário completo: <?= count($tabelas) ?> tabelas</h3></div>
    <p class="modelo-conteudo">PK = chave primária; FK = chave estrangeira; UK = participa de uma chave única.
        Em chaves compostas, a unicidade vale para o conjunto de colunas. Abra uma tabela para ver as definições completas.</p>
    <?php foreach ($tabelas as $nome => $tabela): ?>
        <details class="modelo-tabela" id="tabela-<?= e($nome) ?>" <?= $nome === $selecionada ? 'open' : '' ?>>
            <summary><?= e($nome) ?> <span>(<?= count($tabela['colunas']) ?> campos)</span></summary>
            <div class="tabela-area">
                <table class="tabela">
                    <caption>Campos de <?= e($nome) ?></caption>
                    <thead><tr><th>Coluna</th><th>Tipo SQL</th><th>Aceita nulo</th><th>Chaves</th></tr></thead>
                    <tbody>
                        <?php foreach ($tabela['colunas'] as $coluna): ?>
                            <tr><td><code><?= e($coluna['nome']) ?></code></td><td><code><?= e($coluna['tipo']) ?></code></td><td><?= $coluna['nulo'] ? 'Sim' : 'Não' ?></td><td><?= e(implode(', ', $coluna['chaves']) ?: '—') ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <details class="modelo-sql"><summary>Definição SQL: valores padrão, chaves e restrições</summary><pre><code><?= e($tabela['ddl']) ?></code></pre></details>
        </details>
    <?php endforeach; ?>
</div>
<div class="cartao">
    <div class="cartao-cabecalho"><h3>Como o modelo representa o funcionamento do sistema</h3></div>
    <dl class="modelo-regras">
        <?php foreach (ModeloBanco::regras() as $titulo => $descricao): ?>
            <dt><?= e($titulo) ?></dt><dd><?= e($descricao) ?></dd>
        <?php endforeach; ?>
    </dl>
</div>
<?php require_once RAIZ . '/includes/painel_footer.php'; ?>
