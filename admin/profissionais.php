<?php
/**
 * CRUD de profissionais e vinculo com os servicos executados.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/../config/config.php';

// Restringe esta página a administradores autenticados.
exigirLogin('admin');

$erros = [];

// Processa o formulário enviado antes de montar o HTML da página.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Confere o token da sessão antes de aceitar alterações enviadas pelo formulário.
    exigirCsrf();

    $acao           = post('acao');
    $idProfissional = (int) post('id_profissional');

    // Valida os campos antes de criar ou atualizar o cadastro.
    if ($acao === 'salvar') {
        $profissional = $idProfissional > 0 ? Profissional::porId($idProfissional) : null;

        $nome          = post('nome');
        $email         = mb_strtolower(post('email'));
        $telefone      = apenasNumeros(post('telefone'));
        $especialidade = post('especialidade');
        $senha         = post('senha');
        $status        = post('status') === 'inativo' ? 'inativo' : 'ativo';
        $servicos      = array_map('intval', (array) ($_POST['servicos'] ?? []));
        $podeBloquear  = post('pode_bloquear_agenda') === '1';

        if (mb_strlen($nome) < 5 || !str_contains($nome, ' ')) {
            $erros[] = 'Informe o nome completo do profissional.';
        }
        if (!validarEmail($email)) {
            $erros[] = 'Informe um e-mail valido.';
        } elseif (Usuario::emailEmUso($email, $profissional ? (int) $profissional['id_usuario'] : null)) {
            $erros[] = 'Este e-mail ja esta cadastrado em outra conta.';
        }
        if ($telefone !== '' && !in_array(strlen($telefone), [10, 11], true)) {
            $erros[] = 'Informe um telefone valido com DDD.';
        }
        if (!$profissional && !validarSenha($senha)) {
            $erros[] = 'Defina uma senha de acesso com no minimo 6 caracteres.';
        }
        if ($profissional && $senha !== '' && !validarSenha($senha)) {
            $erros[] = 'A nova senha deve ter no minimo 6 caracteres.';
        }
        if ($servicos === []) {
            $erros[] = 'Selecione ao menos um servico executado pelo profissional.';
        }

        if ($erros === []) {
            try {
                if ($profissional) {
                    Usuario::atualizar((int) $profissional['id_usuario'], [
                        'nome'     => $nome,
                        'email'    => $email,
                        'telefone' => $telefone,
                    ]);
                    Usuario::alterarStatus((int) $profissional['id_usuario'], $status);

                    if ($senha !== '') {
                        Usuario::atualizarSenha((int) $profissional['id_usuario'], $senha);
                    }

                    Profissional::atualizar($idProfissional, [
                        'especialidade'        => $especialidade,
                        'bio'                  => post('bio'),
                        'pode_bloquear_agenda' => $podeBloquear,
                    ]);
                    Profissional::definirServicos($idProfissional, $servicos);

                    definirFlash('sucesso', 'Profissional atualizado com sucesso.');
                } else {
                    Profissional::criar([
                        'nome'                 => $nome,
                        'email'                => $email,
                        'senha'                => $senha,
                        'telefone'             => $telefone,
                        'especialidade'        => $especialidade,
                        'bio'                  => post('bio'),
                        'status'               => $status,
                        'pode_bloquear_agenda' => $podeBloquear,
                        'servicos'             => $servicos,
                    ]);

                    definirFlash('sucesso', 'Profissional cadastrado com sucesso. Configure o expediente em Horarios.');
                }

                redirecionar('admin/profissionais.php');
            } catch (Throwable $erro) {
                error_log('Falha ao salvar profissional: ' . $erro->getMessage());
                $erros[] = 'Nao foi possivel salvar o profissional. Tente novamente.';
            }
        }
    }

    // Trata a mudança de status solicitada pelo formulário.
    if ($acao === 'status' && $idProfissional > 0) {
        $profissional = Profissional::porId($idProfissional);
        if ($profissional) {
            $novoStatus = post('status') === 'ativo' ? 'ativo' : 'inativo';
            Usuario::alterarStatus((int) $profissional['id_usuario'], $novoStatus);
            definirFlash('sucesso', $novoStatus === 'ativo' ? 'Profissional ativado.' : 'Profissional desativado.');
        }
        redirecionar('admin/profissionais.php');
    }

    // Confere o registro e as regras aplicáveis antes da exclusão.
    if ($acao === 'excluir' && $idProfissional > 0) {
        $profissional = Profissional::porId($idProfissional);
        $totalAgendamentos = Agendamento::contar(['id_profissional' => $idProfissional]);

        if (!$profissional) {
            definirFlash('erro', 'Profissional nao encontrado.');
        } elseif ($totalAgendamentos > 0) {
            definirFlash('erro', 'Este profissional possui agendamentos registrados e nao pode ser excluido. Desative o cadastro.');
        } else {
            Usuario::excluir((int) $profissional['id_usuario']);
            definirFlash('sucesso', 'Profissional excluido.');
        }

        redirecionar('admin/profissionais.php');
    }
}

$acaoTela = get('acao');
$edicao   = null;
$servicosVinculados = [];

if ($acaoTela === 'editar') {
    $edicao = Profissional::porId((int) get('id'));
    if (!$edicao) {
        definirFlash('erro', 'Profissional nao encontrado.');
        redirecionar('admin/profissionais.php');
    }
    $servicosVinculados = Profissional::idsServicos((int) $edicao['id_profissional']);
}

$formularioAberto = in_array($acaoTela, ['novo', 'editar'], true) || $erros !== [];

if ($erros !== [] && isset($_POST['servicos'])) {
    $servicosVinculados = array_map('intval', (array) $_POST['servicos']);
}

$busca  = get('busca');
$status = get('status');
$lista  = Profissional::listar(array_filter(['busca' => $busca, 'status' => $status]));
$servicosDisponiveis = Servico::listar();

// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina  = 'Profissionais';
$subtituloTopo = 'Equipe, servicos executados e acesso ao sistema';
$acoesTopo     = $formularioAberto
    ? '<a href="' . url('admin/profissionais.php') . '" class="btn btn-contorno btn-pequeno">Voltar para a lista</a>'
    : '<a href="' . url('admin/profissionais.php?acao=novo') . '" class="btn btn-pequeno">Novo profissional</a>';

// Renderiza a estrutura comum do painel após preparar os dados desta tela.
require_once RAIZ . '/includes/painel_header.php';
?>

<?php if ($erros !== []): ?>
    <div class="alerta alerta-erro">
        <div>
            <span class="alerta-texto">Corrija os itens abaixo:</span>
            <ul><?php foreach ($erros as $mensagem): ?><li><?= e($mensagem) ?></li><?php endforeach; ?></ul>
        </div>
    </div>
<?php endif; ?>

<?php if ($formularioAberto): ?>

    <div class="cartao">
        <div class="cartao-cabecalho">
            <h3><?= $edicao ? 'Editar profissional' : 'Novo profissional' ?></h3>
        </div>
        <div class="cartao-corpo">
            <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post">
                <?= campoCsrf() ?>
                <input type="hidden" name="acao" value="salvar">
                <input type="hidden" name="id_profissional" value="<?= (int) ($edicao['id_profissional'] ?? 0) ?>">

                <div class="linha-campos">
                    <div class="campo">
                        <label for="nome">Nome completo <span class="obrigatorio">*</span></label>
                        <input type="text" id="nome" name="nome" maxlength="120"
                               value="<?= e($edicao['nome'] ?? post('nome')) ?>" required>
                    </div>

                    <div class="campo">
                        <label for="especialidade">Especialidade</label>
                        <input type="text" id="especialidade" name="especialidade" maxlength="120"
                               value="<?= e($edicao['especialidade'] ?? post('especialidade')) ?>"
                               placeholder="Ex.: Barbeiro, Cabeleireira">
                    </div>
                </div>

                <div class="linha-campos">
                    <div class="campo">
                        <label for="email">E-mail de acesso <span class="obrigatorio">*</span></label>
                        <input type="email" id="email" name="email" maxlength="150"
                               value="<?= e($edicao['email'] ?? post('email')) ?>" required>
                    </div>

                    <div class="campo">
                        <label for="telefone">Telefone</label>
                        <input type="tel" id="telefone" name="telefone" data-mascara="telefone" inputmode="numeric"
                               value="<?= e($edicao ? formatarTelefone($edicao['telefone']) : post('telefone')) ?>">
                    </div>
                </div>

                <div class="linha-campos">
                    <div class="campo">
                        <label for="senha"><?= $edicao ? 'Nova senha (opcional)' : 'Senha de acesso' ?>
                            <?= $edicao ? '' : '<span class="obrigatorio">*</span>' ?>
                        </label>
                        <input type="password" id="senha" name="senha" autocomplete="new-password" <?= $edicao ? '' : 'required' ?>>
                        <span class="ajuda-campo"><?= $edicao ? 'Deixe em branco para manter a senha atual.' : 'Minimo de 6 caracteres.' ?></span>
                    </div>

                    <div class="campo">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="ativo" <?= ($edicao['status'] ?? 'ativo') === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                            <option value="inativo" <?= ($edicao['status'] ?? '') === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                        </select>
                        <span class="ajuda-campo">Profissionais inativos nao aparecem para os clientes.</span>
                    </div>
                </div>

                <div class="campo">
                    <label for="bio">Apresentacao</label>
                    <textarea id="bio" name="bio" maxlength="400"><?= e($edicao['bio'] ?? post('bio')) ?></textarea>
                </div>

                <fieldset>
                    <legend>Servicos executados <span class="obrigatorio">*</span></legend>

                    <?php if ($servicosDisponiveis === []): ?>
                        <p class="texto-secundario sem-margem">
                            Nenhum servico cadastrado.
                            <a href="<?= url('admin/servicos.php?acao=novo') ?>">Cadastre um servico</a> antes de continuar.
                        </p>
                    <?php else: ?>
                        <div class="linha-campos-3">
                            <?php foreach ($servicosDisponiveis as $servico): ?>
                                <div class="campo-checkbox">
                                    <input type="checkbox" id="servico<?= (int) $servico['id_servico'] ?>"
                                           name="servicos[]" value="<?= (int) $servico['id_servico'] ?>"
                                           <?= in_array((int) $servico['id_servico'], $servicosVinculados, true) ? 'checked' : '' ?>>
                                    <label for="servico<?= (int) $servico['id_servico'] ?>">
                                        <?= e($servico['nome']) ?>
                                        <?= $servico['status'] === 'inativo' ? ' (inativo)' : '' ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </fieldset>

                <div class="campo-checkbox">
                    <input type="checkbox" id="pode_bloquear_agenda" name="pode_bloquear_agenda" value="1"
                           <?= (!$edicao || !empty($edicao['pode_bloquear_agenda'])) ? 'checked' : '' ?>>
                    <label for="pode_bloquear_agenda">Permitir que o profissional bloqueie a propria agenda</label>
                </div>

                <div class="grupo-botoes">
                    <button type="submit" class="btn"><?= $edicao ? 'Salvar alteracoes' : 'Cadastrar profissional' ?></button>
                    <a href="<?= url('admin/profissionais.php') ?>" class="btn btn-contorno">Cancelar</a>
                </div>
            </form>
        </div>
    </div>

<?php endif; ?>

<div class="cartao">
    <?php /* Filtros enviados na URL para permitir atualizar e compartilhar a consulta. */ ?><form method="get" class="barra-filtros">
        <input type="hidden" name="estabelecimento" value="<?= e(Contexto::slug()) ?>">
        <div class="campo campo-busca">
            <label for="busca">Buscar profissional</label>
            <input type="search" id="busca" name="busca" value="<?= e($busca) ?>" placeholder="Nome, e-mail ou especialidade">
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
            <strong>Nenhum profissional cadastrado.</strong>
            <p>Cadastre a equipe para liberar os agendamentos.</p>
            <a href="<?= url('admin/profissionais.php?acao=novo') ?>" class="btn margem-topo">Novo profissional</a>
        </div>
    <?php else: ?>
        <div class="tabela-area">
            <?php /* Tabela de apresentação dos registros retornados pela consulta. */ ?><table class="tabela">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Profissional</th>
                        <th>Contato</th>
                        <th>Especialidade</th>
                        <th>Servicos</th>
                        <th>Status</th>
                        <th class="coluna-acoes">Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista as $profissional): ?>
                        <tr>
                            <td><?= (int) $profissional['id_profissional'] ?></td>
                            <td>
                                <span class="celula-principal"><?= e($profissional['nome']) ?></span>
                                <span class="celula-secundaria">Desde <?= formatarData($profissional['data_cadastro']) ?></span>
                            </td>
                            <td>
                                <?= e($profissional['email']) ?>
                                <span class="celula-secundaria"><?= e(formatarTelefone($profissional['telefone'])) ?></span>
                            </td>
                            <td><?= e($profissional['especialidade'] ?: '-') ?></td>
                            <td><?= (int) $profissional['total_servicos'] ?></td>
                            <td><?= badgeStatus($profissional['status']) ?></td>
                            <td class="coluna-acoes">
                                <a href="<?= url('admin/horarios.php?id_profissional=' . (int) $profissional['id_profissional']) ?>"
                                   class="btn btn-contorno btn-pequeno">Horarios</a>
                                <a href="<?= url('admin/profissionais.php?acao=editar&id=' . (int) $profissional['id_profissional']) ?>"
                                   class="btn btn-contorno btn-pequeno">Editar</a>

                                <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" style="display:inline">
                                    <?= campoCsrf() ?>
                                    <input type="hidden" name="acao" value="status">
                                    <input type="hidden" name="id_profissional" value="<?= (int) $profissional['id_profissional'] ?>">
                                    <input type="hidden" name="status" value="<?= $profissional['status'] === 'ativo' ? 'inativo' : 'ativo' ?>">
                                    <button type="submit" class="btn btn-contorno btn-pequeno">
                                        <?= $profissional['status'] === 'ativo' ? 'Desativar' : 'Ativar' ?>
                                    </button>
                                </form>

                                <?php if (Agendamento::contar(['id_profissional' => (int) $profissional['id_profissional']]) === 0): ?>
                                    <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post" style="display:inline">
                                        <?= campoCsrf() ?>
                                        <input type="hidden" name="acao" value="excluir">
                                        <input type="hidden" name="id_profissional" value="<?= (int) $profissional['id_profissional'] ?>">
                                        <button type="submit" class="btn btn-perigo btn-pequeno"
                                                data-confirmar="Excluir o cadastro de <?= e($profissional['nome']) ?>? O acesso ao sistema sera removido."
                                                data-confirmar-titulo="Excluir profissional"
                                                data-confirmar-rotulo="Excluir">
                                            Excluir
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>
