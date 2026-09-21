<?php
/**
 * Logs de autenticacao.
 * Exclusiva do perfil master: mostra as entradas no sistema com data, hora,
 * nome e qual pergunta foi usada no segundo fator, da mais recente para a mais antiga.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/../config/config.php';

// Somente o perfil master abre esta tela.
exigirLogin('admin');

$campo  = get('campo', 'todos');
$busca  = get('busca');
$evento = get('evento');

// O seletor aceita apenas os campos previstos na especificacao.
if (!in_array($campo, ['todos', 'nome', 'cpf'], true)) {
    $campo = 'todos';
}

$porPagina = 20;
// Mantém a página como inteiro positivo para calcular a listagem e sua navegação.
$pagina = max(1, (int) get('pagina', '1'));

$filtros = array_filter([
    'campo'  => $campo,
    'busca'  => $busca,
    'evento' => $evento,
]);

$total = LogAutenticacao::contar($filtros);
$lista = LogAutenticacao::listar($filtros + [
    'limite'       => $porPagina,
    'deslocamento' => ($pagina - 1) * $porPagina,
]);

// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina  = 'Logs de autenticacao';
$subtituloTopo = $total . ' registro(s) encontrado(s)';

// Renderiza a estrutura comum do painel após preparar os dados desta tela.
require_once RAIZ . '/includes/painel_header.php';
?>

<div class="cartao">
    <?php /* Filtros enviados na URL para permitir atualizar e compartilhar a consulta. */ ?><form method="get" class="barra-filtros">
        <input type="hidden" name="estabelecimento" value="<?= e(Contexto::slug()) ?>">

        <div class="campo">
            <label for="campo">Buscar por</label>
            <select id="campo" name="campo">
                <option value="todos" <?= $campo === 'todos' ? 'selected' : '' ?>>Todos</option>
                <option value="nome" <?= $campo === 'nome' ? 'selected' : '' ?>>Nome do usuario</option>
                <option value="cpf" <?= $campo === 'cpf' ? 'selected' : '' ?>>CPF</option>
            </select>
        </div>

        <div class="campo campo-busca">
            <label for="busca">Termo</label>
            <input type="search" id="busca" name="busca" value="<?= e($busca) ?>"
                   placeholder="<?= $campo === 'cpf' ? '000.000.000-00' : 'Parte do nome' ?>">
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

        <div class="campo">
            <button type="submit" class="btn btn-contorno">Filtrar</button>
        </div>

        <?php if ($busca !== '' || $evento !== '' || $campo !== 'todos'): ?>
            <div class="campo">
                <a class="btn btn-contorno" href="<?= url('admin/logs.php') ?>">Limpar</a>
            </div>
        <?php endif; ?>
    </form>

    <?php if ($lista === []): ?>
        <div class="estado-vazio">
            <strong>Nenhum registro encontrado.</strong>
            <p>As entradas aparecem aqui conforme os usuarios acessam o sistema.</p>
        </div>
    <?php else: ?>
        <div class="tabela-area">
            <?php /* Tabela de apresentação dos registros retornados pela consulta. */ ?><table class="tabela">
                <thead>
                    <tr>
                        <th>Dia e hora</th>
                        <th>Nome</th>
                        <th>CPF</th>
                        <th>Login informado</th>
                        <th>Evento</th>
                        <th>2FA</th>
                        <th>Origem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista as $registro): ?>
                        <tr>
                            <td class="celula-principal">
                                <?= formatarData(substr($registro['data_hora'], 0, 10)) ?>
                                <span class="celula-secundaria"><?= e(substr($registro['data_hora'], 11, 8)) ?></span>
                            </td>
                            <td><?= e($registro['nome'] !== '' ? $registro['nome'] : '-') ?></td>
                            <td><?= e(formatarCpf($registro['cpf'])) ?></td>
                            <td><?= e($registro['login_informado']) ?></td>
                            <td><?= e(LogAutenticacao::eventoTexto($registro['evento'])) ?></td>
                            <td><?= e(LogAutenticacao::fatorTexto($registro['fator_2fa'])) ?></td>
                            <td><?= e($registro['ip'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= renderizarPaginacao($total, $pagina, $porPagina, $filtros) ?>
    <?php endif; ?>
</div>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>
