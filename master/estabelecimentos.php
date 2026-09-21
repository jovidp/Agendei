<?php
/**
 * Estabelecimentos contratantes.
 *
 * Alem de criar a empresa e a primeira conta administrativa, esta tela mantem
 * o acesso dos administradores locais: ligar, desligar e redefinir senha. Sem
 * isso, um admin que esquece a senha ou deixa a empresa trava o painel inteiro
 * do estabelecimento, porque nao existe outro caminho para destravar.
 *
 * Toda acao daqui vai para a auditoria: sao operacoes sobre dados de terceiros.
 */
define('AREA_MASTER', true);
require_once __DIR__ . '/../config/config.php';
exigirLogin('master');

$idSessao = (int) $_SESSION['master_id'];
$erros = [];
$acaoTela = get('acao');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $acao = post('acao');
    try {
        if ($acao === 'criar') {
            $dados = ['estabelecimento' => post('estabelecimento'), 'slug' => strtolower(post('slug')), 'nome' => post('nome'), 'email' => mb_strtolower(post('email')), 'senha' => post('senha')];
            if (mb_strlen($dados['estabelecimento']) < 2 || mb_strlen($dados['estabelecimento']) > 120) $erros[] = 'Informe o nome do estabelecimento.';
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{1,78}[a-z0-9])$/D', $dados['slug'])) $erros[] = 'Use um endereço de 3 a 80 letras minúsculas, números ou hífens.';
            if (mb_strlen($dados['nome']) < 3) $erros[] = 'Informe o nome do administrador local.';
            if (!validarEmail($dados['email'])) $erros[] = 'Informe um e-mail válido.';
            if (!validarSenha($dados['senha'])) $erros[] = 'A senha deve ter pelo menos 6 caracteres.';
            if (!$erros) {
                $id = Estabelecimento::contratar($dados);
                LogMaster::registrar('estabelecimento_criado', [
                    'estabelecimento'      => $id,
                    'estabelecimento_nome' => $dados['estabelecimento'],
                    'alvo'                 => $dados['email'],
                    'detalhe'              => 'endereço ' . $dados['slug'],
                ]);
                definirFlash('sucesso', 'Estabelecimento e sua primeira conta administrativa criados.');
                redirecionar('master/estabelecimentos.php?acao=ver&id=' . $id);
            }
        }
        if ($acao === 'novo_admin') {
            $id = (int) post('id_estabelecimento');
            $empresa = Estabelecimento::porIdGlobal($id);
            $dados = ['nome' => post('nome'), 'email' => mb_strtolower(post('email')), 'senha' => post('senha')];
            if (!$empresa) $erros[] = 'Estabelecimento não encontrado.';
            if (mb_strlen($dados['nome']) < 3 || !validarEmail($dados['email']) || !validarSenha($dados['senha'])) $erros[] = 'Preencha nome, e-mail válido e senha de pelo menos 6 caracteres.';
            if (!$erros) {
                Estabelecimento::criarAdministrador($id, $dados);
                LogMaster::registrar('admin_criado', [
                    'estabelecimento'      => $id,
                    'estabelecimento_nome' => $empresa['nome'],
                    'alvo'                 => $dados['email'],
                ]);
                definirFlash('sucesso', 'Nova conta administrativa adicionada ao estabelecimento.');
                redirecionar('master/estabelecimentos.php?acao=ver&id=' . $id);
            }
        }
        if ($acao === 'status') {
            $id = (int) post('id_estabelecimento');
            $empresa = Estabelecimento::porIdGlobal($id);
            $novoStatus = post('status') === 'ativo' ? 'ativo' : 'inativo';
            if (!$empresa) {
                $erros[] = 'Estabelecimento não encontrado.';
            } else {
                Estabelecimento::alterarStatusGlobal($id, $novoStatus);
                LogMaster::registrar('estabelecimento_status', [
                    'estabelecimento'      => $id,
                    'estabelecimento_nome' => $empresa['nome'],
                    'alvo'                 => $empresa['slug'],
                    'detalhe'              => 'de ' . $empresa['status'] . ' para ' . $novoStatus,
                ]);
                definirFlash('sucesso', 'Status do estabelecimento atualizado.');
                redirecionar('master/estabelecimentos.php');
            }
        }
        if ($acao === 'admin_status') {
            $id = (int) post('id_estabelecimento');
            $idUsuario = (int) post('id_usuario');
            $empresa = Estabelecimento::porIdGlobal($id);
            $admin = $empresa ? Estabelecimento::administrador($id, $idUsuario) : null;
            $novoStatus = post('status') === 'ativo' ? 'ativo' : 'inativo';
            if (!$admin) {
                $erros[] = 'Conta administrativa não encontrada neste estabelecimento.';
            } elseif (!Estabelecimento::alterarStatusAdministrador($id, $idUsuario, $novoStatus)) {
                $erros[] = 'Este é o último administrador ativo da empresa. Crie ou ative outro antes de desativá-lo.';
            } else {
                LogMaster::registrar('admin_status', [
                    'estabelecimento'      => $id,
                    'estabelecimento_nome' => $empresa['nome'],
                    'alvo'                 => $admin['email'],
                    'detalhe'              => 'de ' . $admin['status'] . ' para ' . $novoStatus,
                ]);
                definirFlash('sucesso', 'Acesso da conta administrativa atualizado.');
                redirecionar('master/estabelecimentos.php?acao=ver&id=' . $id);
            }
        }
        if ($acao === 'admin_senha') {
            $id = (int) post('id_estabelecimento');
            $idUsuario = (int) post('id_usuario');
            $empresa = Estabelecimento::porIdGlobal($id);
            $admin = $empresa ? Estabelecimento::administrador($id, $idUsuario) : null;
            $nova = post('nova_senha');
            if (!$admin) $erros[] = 'Selecione uma conta administrativa deste estabelecimento.';
            // Redefinir esta senha dá acesso aos dados dos clientes da empresa.
            // Confirmar a própria senha impede que uma sessão master deixada
            // aberta se transforme em acesso permanente ao painel de terceiros.
            if (!Master::senhaConfere($idSessao, post('senha_master'))) $erros[] = 'Confirme a sua senha master para redefinir.';
            if (!validarSenha($nova)) $erros[] = 'A nova senha deve ter pelo menos 6 caracteres.';
            if ($nova !== post('confirmar_senha')) $erros[] = 'As novas senhas não conferem.';
            if (!$erros) {
                Estabelecimento::definirSenhaAdministrador($id, $idUsuario, $nova);
                LogMaster::registrar('admin_senha', [
                    'estabelecimento'      => $id,
                    'estabelecimento_nome' => $empresa['nome'],
                    'alvo'                 => $admin['email'],
                    'detalhe'              => 'senha temporária definida pelo master',
                ]);
                definirFlash('sucesso', 'Senha redefinida. Combine a troca no primeiro acesso com o responsável.');
                redirecionar('master/estabelecimentos.php?acao=ver&id=' . $id);
            }
        }
    } catch (PDOException $erro) {
        $erros[] = $erro->getCode() === '23000' ? 'O endereço ou e-mail já está em uso neste estabelecimento.' : 'Não foi possível concluir a operação.';
        if ($erro->getCode() !== '23000') error_log($erro->getMessage());
    }
}
// Um POST recusado mantém o formulário do estabelecimento aberto na tela.
$idSelecionado = $acaoTela === 'ver' ? (int) get('id') : (int) post('id_estabelecimento');
$selecionado = $idSelecionado > 0 ? Estabelecimento::porIdGlobal($idSelecionado) : null;
$administradores = $selecionado ? Estabelecimento::administradores((int) $selecionado['id_estabelecimento']) : [];
$empresas = Estabelecimento::listarTodos();
$tituloPagina = 'Estabelecimentos';
$subtituloTopo = 'Cada empresa possui seus próprios administradores, clientes, equipe e serviços';
$acoesTopo = $acaoTela === 'novo' ? '<a class="btn btn-contorno btn-pequeno" href="' . url('master/estabelecimentos.php') . '">Voltar</a>' : '<a class="btn btn-pequeno" href="' . url('master/estabelecimentos.php?acao=novo') . '">Novo estabelecimento</a>';
require RAIZ . '/includes/painel_header.php';
?>
<?php if ($erros): ?><div class="alerta alerta-erro" role="alert"><ul><?php foreach ($erros as $mensagem): ?><li><?= e($mensagem) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($acaoTela === 'novo' || ($erros && post('acao') === 'criar')): ?>
<div class="cartao"><div class="cartao-cabecalho"><h3>Novo estabelecimento e administrador</h3></div><div class="cartao-corpo">
    <form method="post"><?= campoCsrf() ?><input type="hidden" name="acao" value="criar">
        <div class="linha-campos"><div class="campo"><label for="estabelecimento">Nome do estabelecimento</label><input id="estabelecimento" name="estabelecimento" maxlength="120" required></div><div class="campo"><label for="slug">Endereço exclusivo</label><input id="slug" name="slug" placeholder="studio-da-ana" maxlength="80" pattern="[a-z0-9][a-z0-9-]{1,78}[a-z0-9]" required><span class="ajuda-campo">Sem espaços nem acentos.</span></div></div>
        <h4>Primeira conta administrativa</h4>
        <div class="linha-campos"><div class="campo"><label for="nome">Nome do responsável</label><input id="nome" name="nome" maxlength="120" required></div><div class="campo"><label for="email">E-mail</label><input type="email" id="email" name="email" maxlength="150" required></div></div>
        <div class="campo"><label for="senha">Senha temporária</label><input type="password" id="senha" name="senha" minlength="6" autocomplete="new-password" required></div>
        <button class="btn" type="submit">Criar estabelecimento</button>
    </form>
</div></div>
<?php elseif ($selecionado): ?>
<div class="cartao"><div class="cartao-cabecalho"><div><h3><?= e($selecionado['nome']) ?></h3><small><?= e($selecionado['slug']) ?></small></div><a target="_blank" rel="noopener" href="<?= e(BASE_URL . '/index.php?estabelecimento=' . rawurlencode($selecionado['slug'])) ?>">Abrir página pública</a></div>
<div class="cartao-corpo"><p><strong>Status:</strong> <?= badgeStatus($selecionado['status']) ?></p><p>Clientes, profissionais, serviços e agendamentos ficam vinculados ao ID <?= (int) $selecionado['id_estabelecimento'] ?>.</p></div></div>
<div class="grade-painel grade-painel-igual">
<div class="cartao"><div class="cartao-cabecalho"><h3>Contas administrativas</h3></div><div class="tabela-area"><table class="tabela"><thead><tr><th>Nome</th><th>Último acesso</th><th>Status</th><th class="coluna-acoes">Acesso</th></tr></thead><tbody>
<?php foreach ($administradores as $admin): ?>
<tr>
    <td class="celula-principal"><?= e($admin['nome']) ?><span class="celula-secundaria"><?= e($admin['email']) ?></span></td>
    <td><?= $admin['ultimo_acesso'] ? e(formatarData(substr((string) $admin['ultimo_acesso'], 0, 10))) : 'nunca entrou' ?></td>
    <td><?= badgeStatus($admin['status']) ?></td>
    <td class="coluna-acoes">
        <form method="post" style="display:inline"><?= campoCsrf() ?>
            <input type="hidden" name="acao" value="admin_status">
            <input type="hidden" name="id_estabelecimento" value="<?= (int) $selecionado['id_estabelecimento'] ?>">
            <input type="hidden" name="id_usuario" value="<?= (int) $admin['id_usuario'] ?>">
            <input type="hidden" name="status" value="<?= $admin['status'] === 'ativo' ? 'inativo' : 'ativo' ?>">
            <button class="btn btn-pequeno <?= $admin['status'] === 'ativo' ? 'btn-perigo' : 'btn-secundario' ?>" type="submit" data-confirmar="Alterar o acesso desta conta administrativa?"><?= $admin['status'] === 'ativo' ? 'Desativar' : 'Ativar' ?></button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>
<div class="cartao"><div class="cartao-cabecalho"><h3>Adicionar administrador</h3></div><div class="cartao-corpo"><form method="post"><?= campoCsrf() ?><input type="hidden" name="acao" value="novo_admin"><input type="hidden" name="id_estabelecimento" value="<?= (int) $selecionado['id_estabelecimento'] ?>"><div class="campo"><label for="nome_admin">Nome</label><input id="nome_admin" name="nome" required></div><div class="campo"><label for="email_admin">E-mail</label><input type="email" id="email_admin" name="email" required></div><div class="campo"><label for="senha_admin">Senha temporária</label><input type="password" id="senha_admin" name="senha" minlength="6" autocomplete="new-password" required></div><button class="btn" type="submit">Adicionar administrador</button></form></div></div>
</div>
<div class="cartao">
    <div class="cartao-cabecalho"><h3>Entrar no painel do estabelecimento</h3><small>Para atender um chamado vendo a mesma tela do cliente</small></div>
    <div class="cartao-corpo">
        <?php if ($selecionado['status'] !== 'ativo'): ?>
            <p class="ajuda-campo">O estabelecimento está inativo. Ative o acesso dele antes de abrir o painel.</p>
        <?php else: ?>
            <form method="post" action="<?= url('master/simular.php') ?>"><?= campoCsrf() ?>
                <input type="hidden" name="acao" value="entrar">
                <input type="hidden" name="id_estabelecimento" value="<?= (int) $selecionado['id_estabelecimento'] ?>">
                <div class="linha-campos">
                    <div class="campo">
                        <label for="id_usuario_simular">Entrar como</label>
                        <select id="id_usuario_simular" name="id_usuario" required>
                            <?php foreach ($administradores as $admin): ?>
                                <?php if ($admin['status'] === 'ativo'): ?>
                                    <option value="<?= (int) $admin['id_usuario'] ?>"><?= e($admin['nome']) ?> — <?= e($admin['email']) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="campo"><label for="senha_simular">Sua senha master</label><input type="password" id="senha_simular" name="senha_master" autocomplete="current-password" required></div>
                </div>
                <p class="ajuda-campo">A sessão passa a ser a do administrador, com um aviso fixo no topo até você voltar. A entrada e a saída ficam na auditoria, e o "último acesso" da conta não é alterado.</p>
                <button class="btn btn-secundario" type="submit" data-confirmar="Abrir o painel deste estabelecimento em seu nome?">Entrar no painel</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<div class="cartao">
    <div class="cartao-cabecalho"><h3>Redefinir senha de administrador</h3><small>Use quando o responsável perder o acesso ao painel</small></div>
    <div class="cartao-corpo">
        <form method="post"><?= campoCsrf() ?>
            <input type="hidden" name="acao" value="admin_senha">
            <input type="hidden" name="id_estabelecimento" value="<?= (int) $selecionado['id_estabelecimento'] ?>">
            <div class="linha-campos">
                <div class="campo">
                    <label for="id_usuario">Conta</label>
                    <select id="id_usuario" name="id_usuario" required>
                        <?php foreach ($administradores as $admin): ?>
                            <option value="<?= (int) $admin['id_usuario'] ?>"><?= e($admin['nome']) ?> — <?= e($admin['email']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo"><label for="senha_master">Sua senha master</label><input type="password" id="senha_master" name="senha_master" autocomplete="current-password" required><span class="ajuda-campo">Confirma que é você quem está no teclado.</span></div>
            </div>
            <div class="linha-campos">
                <div class="campo"><label for="nova_senha">Nova senha</label><input type="password" id="nova_senha" name="nova_senha" minlength="6" autocomplete="new-password" required></div>
                <div class="campo"><label for="confirmar_senha">Confirmar nova senha</label><input type="password" id="confirmar_senha" name="confirmar_senha" minlength="6" autocomplete="new-password" required></div>
            </div>
            <button class="btn" type="submit">Redefinir senha</button>
        </form>
    </div>
</div>
<?php else: ?>
<div class="cartao"><div class="tabela-area"><table class="tabela"><thead><tr><th>Estabelecimento</th><th>Administrador</th><th>Clientes</th><th>Profissionais</th><th>Serviços</th><th>Status</th><th>Ações</th></tr></thead><tbody>
<?php foreach ($empresas as $empresa): ?><tr><td><strong><?= e($empresa['nome']) ?></strong><br><small><?= e($empresa['slug']) ?></small></td><td><?= (int) $empresa['admins_ativos'] ?> ativo(s)</td><td><?= (int) $empresa['total_clientes'] ?></td><td><?= (int) $empresa['total_profissionais'] ?></td><td><?= (int) $empresa['total_servicos'] ?></td><td><?= badgeStatus($empresa['status']) ?></td><td><div class="acoes-tabela"><a class="btn btn-contorno btn-pequeno" href="<?= url('master/estabelecimentos.php?acao=ver&id=' . $empresa['id_estabelecimento']) ?>">Detalhes</a><form method="post"><?= campoCsrf() ?><input type="hidden" name="acao" value="status"><input type="hidden" name="id_estabelecimento" value="<?= (int) $empresa['id_estabelecimento'] ?>"><input type="hidden" name="status" value="<?= $empresa['status'] === 'ativo' ? 'inativo' : 'ativo' ?>"><button class="btn btn-pequeno <?= $empresa['status'] === 'ativo' ? 'btn-perigo' : 'btn-secundario' ?>" type="submit" data-confirmar="Alterar o acesso deste estabelecimento?"><?= $empresa['status'] === 'ativo' ? 'Desativar' : 'Ativar' ?></button></form></div></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<?php require RAIZ . '/includes/painel_footer.php'; ?>
