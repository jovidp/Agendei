<?php
/**
 * Seguranca da plataforma, vista de cima.
 *
 * O painel de cada empresa mostra apenas os proprios acessos. Uma varredura de
 * senhas que toca dez estabelecimentos aparece la como um punhado de erros
 * comuns e so fica visivel quando alguem olha o conjunto — que e o que esta
 * tela faz.
 *
 * Tambem e daqui que sai a unica acao corretiva possivel hoje sem abrir o
 * banco: liberar uma chave que ficou presa no controle de forca bruta.
 */
define('AREA_MASTER', true);
require_once __DIR__ . '/../config/config.php';
exigirLogin('master');

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();

    if (post('acao') === 'liberar') {
        $escopo = post('escopo');
        $chave  = post('chave');

        // A chave vem da propria listagem; conferir formato e escopo conhecido
        // impede que um POST forjado vire um DELETE com valores arbitrarios.
        if (!isset(politicaLimites()[$escopo]) || !preg_match('/^[a-f0-9]{64}$/D', $chave)) {
            $erros[] = 'Bloqueio invalido.';
        } else {
            Tentativa::limpar($escopo, $chave);
            LogMaster::registrar('bloqueio_liberado', [
                'alvo'    => Tentativa::escopoTexto($escopo),
                'detalhe' => 'chave ' . substr($chave, 0, 12),
            ]);
            definirFlash('sucesso', 'Bloqueio liberado. A proxima tentativa dessa origem sera aceita.');
            redirecionar('master/seguranca.php');
        }
    }
}

$bloqueios = Tentativa::bloqueiosAtivos();
$resumo    = LogAutenticacao::resumoGlobal(24);
$empresas  = Estabelecimento::listarTodos();

$evento  = get('evento');
$busca   = get('busca');
$empresa = (int) get('estabelecimento_id');

$porPagina = 25;
$pagina = max(1, (int) get('pagina', '1'));

$filtros = array_filter([
    'evento'          => $evento,
    'busca'           => $busca,
    'estabelecimento' => $empresa,
]);

$total = LogAutenticacao::contarGlobal($filtros);
$lista = LogAutenticacao::listarGlobal($filtros + [
    'limite'       => $porPagina,
    'deslocamento' => ($pagina - 1) * $porPagina,
]);

// A paginacao remonta a URL com os nomes dos campos do formulario.
$parametrosUrl = array_filter([
    'evento'             => $evento,
    'busca'              => $busca,
    'estabelecimento_id' => $empresa,
]);

/** Tempo restante em texto curto, para a coluna de bloqueio. */
$tempoTexto = static function (int $segundos): string {
    $minutos = (int) ceil($segundos / 60);
    return $minutos <= 1 ? 'menos de 1 min' : $minutos . ' min';
};

$tituloPagina  = 'Seguranca';
$subtituloTopo = 'Acessos e bloqueios de todos os estabelecimentos nas ultimas 24 horas';
require RAIZ . '/includes/painel_header.php';
?>
<?php if ($erros): ?>
    <div class="alerta alerta-erro" role="alert"><ul><?php foreach ($erros as $mensagem): ?><li><?= e($mensagem) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="grade-indicadores">
    <div class="indicador indicador-destaque">
        <span class="indicador-rotulo">Bloqueios ativos</span>
        <strong class="indicador-valor"><?= count($bloqueios) ?></strong>
        <span class="indicador-nota">Contas ou origens aguardando liberacao</span>
    </div>
    <div class="indicador">
        <span class="indicador-rotulo">Falhas de login (24h)</span>
        <strong class="indicador-valor"><?= (int) ($resumo['login_falha'] ?? 0) ?></strong>
    </div>
    <div class="indicador">
        <span class="indicador-rotulo">Falhas no 2FA (24h)</span>
        <strong class="indicador-valor"><?= (int) ($resumo['2fa_falha'] ?? 0) + (int) ($resumo['2fa_bloqueio'] ?? 0) ?></strong>
    </div>
    <div class="indicador">
        <span class="indicador-rotulo">Entradas validadas (24h)</span>
        <strong class="indicador-valor"><?= (int) ($resumo['login_sucesso'] ?? 0) + (int) ($resumo['2fa_sucesso'] ?? 0) ?></strong>
    </div>
</div>

<div class="cartao">
    <div class="cartao-cabecalho">
        <h3>Bloqueios de forca bruta</h3>
        <small>O sistema libera sozinho quando o prazo vence</small>
    </div>
    <?php if ($bloqueios === []): ?>
        <div class="estado-vazio">
            <strong>Nenhum bloqueio ativo.</strong>
            <p>Contas e origens que errarem a senha varias vezes seguidas aparecem aqui ate o prazo vencer.</p>
        </div>
    <?php else: ?>
        <div class="cartao-corpo">
            <p class="ajuda-campo">
                A chave e guardada como hash: o sistema conta as falhas sem manter a lista de e-mails e enderecos que erraram.
                Para atender um chamado, basta liberar a linha correspondente.
            </p>
        </div>
        <div class="tabela-area">
            <table class="tabela">
                <thead>
                    <tr><th>Fluxo</th><th>Chave</th><th>Falhas</th><th>Primeira falha</th><th>Libera em</th><th class="coluna-acoes">Acao</th></tr>
                </thead>
                <tbody>
                <?php foreach ($bloqueios as $bloqueio): ?>
                    <tr>
                        <td class="celula-principal"><?= e(Tentativa::escopoTexto($bloqueio['escopo'])) ?></td>
                        <td><code><?= e(substr($bloqueio['chave'], 0, 12)) ?></code></td>
                        <td><?= (int) $bloqueio['total'] ?></td>
                        <td><?= e(date('d/m/Y H:i', $bloqueio['primeira'])) ?></td>
                        <td><?= e($tempoTexto($bloqueio['restante'])) ?></td>
                        <td class="coluna-acoes">
                            <form method="post" style="display:inline">
                                <?= campoCsrf() ?>
                                <input type="hidden" name="acao" value="liberar">
                                <input type="hidden" name="escopo" value="<?= e($bloqueio['escopo']) ?>">
                                <input type="hidden" name="chave" value="<?= e($bloqueio['chave']) ?>">
                                <button class="btn btn-contorno btn-pequeno" type="submit"
                                        data-confirmar="Liberar este bloqueio antes do prazo?">Liberar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="cartao">
    <div class="cartao-cabecalho">
        <h3>Autenticacao em todos os estabelecimentos</h3>
        <small><?= (int) $total ?> registro(s)</small>
    </div>

    <form method="get" class="barra-filtros">
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

        <div class="campo">
            <label for="evento">Evento</label>
            <select id="evento" name="evento" data-envia-ao-mudar>
                <option value="">Todos os eventos</option>
                <option value="login_sucesso" <?= $evento === 'login_sucesso' ? 'selected' : '' ?>>Login validado</option>
                <option value="2fa_sucesso" <?= $evento === '2fa_sucesso' ? 'selected' : '' ?>>Entrada concluida</option>
                <option value="login_falha" <?= $evento === 'login_falha' ? 'selected' : '' ?>>Falha no login</option>
                <option value="2fa_falha" <?= $evento === '2fa_falha' ? 'selected' : '' ?>>Resposta 2FA incorreta</option>
                <option value="2fa_bloqueio" <?= $evento === '2fa_bloqueio' ? 'selected' : '' ?>>Bloqueio apos 3 tentativas</option>
                <option value="logout" <?= $evento === 'logout' ? 'selected' : '' ?>>Saida do sistema</option>
            </select>
        </div>

        <div class="campo campo-busca">
            <label for="busca">Termo</label>
            <input type="search" id="busca" name="busca" value="<?= e($busca) ?>" placeholder="Nome, login ou IP">
        </div>

        <div class="campo"><button type="submit" class="btn btn-contorno">Filtrar</button></div>

        <?php if ($evento !== '' || $busca !== '' || $empresa > 0): ?>
            <div class="campo"><a class="btn btn-contorno" href="<?= url('master/seguranca.php') ?>">Limpar</a></div>
        <?php endif; ?>
    </form>

    <?php if ($lista === []): ?>
        <div class="estado-vazio">
            <strong>Nenhum registro encontrado.</strong>
            <p>As entradas aparecem conforme os usuarios acessam qualquer estabelecimento da plataforma.</p>
        </div>
    <?php else: ?>
        <div class="tabela-area">
            <table class="tabela">
                <thead>
                    <tr><th>Dia e hora</th><th>Estabelecimento</th><th>Usuario</th><th>Login informado</th><th>Evento</th><th>Perfil</th><th>Origem</th></tr>
                </thead>
                <tbody>
                <?php foreach ($lista as $registro): ?>
                    <tr>
                        <td class="celula-principal">
                            <?= formatarData(substr((string) $registro['data_hora'], 0, 10)) ?>
                            <span class="celula-secundaria"><?= e(substr((string) $registro['data_hora'], 11, 8)) ?></span>
                        </td>
                        <td><?= e((string) ($registro['estabelecimento_nome'] ?? '') ?: '-') ?></td>
                        <td><?= e((string) $registro['nome'] !== '' ? (string) $registro['nome'] : '-') ?></td>
                        <td><?= e((string) $registro['login_informado']) ?></td>
                        <td><?= e(LogAutenticacao::eventoTexto((string) $registro['evento'])) ?></td>
                        <td><?= e(perfilRotulo((string) ($registro['perfil'] ?? '')) ?: '-') ?></td>
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
