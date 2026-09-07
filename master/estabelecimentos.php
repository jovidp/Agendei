<?php
define('AREA_MASTER', true);
require_once __DIR__ . '/../config/config.php';
exigirLogin('master');
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
                definirFlash('sucesso', 'Estabelecimento e sua primeira conta administrativa criados.');
                redirecionar('master/estabelecimentos.php?acao=ver&id=' . $id);
            }
        }
        if ($acao === 'novo_admin') {
            $id = (int) post('id_estabelecimento');
            $dados = ['nome' => post('nome'), 'email' => mb_strtolower(post('email')), 'senha' => post('senha')];
            if (mb_strlen($dados['nome']) < 3 || !validarEmail($dados['email']) || !validarSenha($dados['senha'])) $erros[] = 'Preencha nome, e-mail válido e senha de pelo menos 6 caracteres.';
            if (!$erros) {
                Estabelecimento::criarAdministrador($id, $dados);
                definirFlash('sucesso', 'Nova conta administrativa adicionada ao estabelecimento.');
                redirecionar('master/estabelecimentos.php?acao=ver&id=' . $id);
            }
        }
        if ($acao === 'status') {
            $id = (int) post('id_estabelecimento');
            Estabelecimento::alterarStatusGlobal($id, post('status'));
            definirFlash('sucesso', 'Status do estabelecimento atualizado.');
            redirecionar('master/estabelecimentos.php');
        }
    } catch (PDOException $erro) {
        $erros[] = $erro->getCode() === '23000' ? 'O endereço ou e-mail já está em uso neste estabelecimento.' : 'Não foi possível concluir a operação.';
        if ($erro->getCode() !== '23000') error_log($erro->getMessage());
    }
}
$selecionado = $acaoTela === 'ver' ? Estabelecimento::porIdGlobal((int) get('id')) : null;
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
<div class="cartao"><div class="cartao-cabecalho"><h3>Contas administrativas</h3></div><div class="tabela-area"><table class="tabela"><thead><tr><th>Nome</th><th>E-mail</th><th>Status</th></tr></thead><tbody><?php foreach ($administradores as $admin): ?><tr><td><?= e($admin['nome']) ?></td><td><?= e($admin['email']) ?></td><td><?= badgeStatus($admin['status']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="cartao"><div class="cartao-cabecalho"><h3>Adicionar administrador</h3></div><div class="cartao-corpo"><form method="post"><?= campoCsrf() ?><input type="hidden" name="acao" value="novo_admin"><input type="hidden" name="id_estabelecimento" value="<?= (int) $selecionado['id_estabelecimento'] ?>"><div class="campo"><label for="nome_admin">Nome</label><input id="nome_admin" name="nome" required></div><div class="campo"><label for="email_admin">E-mail</label><input type="email" id="email_admin" name="email" required></div><div class="campo"><label for="senha_admin">Senha temporária</label><input type="password" id="senha_admin" name="senha" minlength="6" required></div><button class="btn" type="submit">Adicionar administrador</button></form></div></div>
</div>
<?php else: ?>
<div class="cartao"><div class="tabela-area"><table class="tabela"><thead><tr><th>Estabelecimento</th><th>Administrador</th><th>Clientes</th><th>Profissionais</th><th>Serviços</th><th>Status</th><th>Ações</th></tr></thead><tbody>
<?php foreach ($empresas as $empresa): ?><tr><td><strong><?= e($empresa['nome']) ?></strong><br><small><?= e($empresa['slug']) ?></small></td><td><?= (int) $empresa['admins_ativos'] ?> ativo(s)</td><td><?= (int) $empresa['total_clientes'] ?></td><td><?= (int) $empresa['total_profissionais'] ?></td><td><?= (int) $empresa['total_servicos'] ?></td><td><?= badgeStatus($empresa['status']) ?></td><td><div class="acoes-tabela"><a class="btn btn-contorno btn-pequeno" href="<?= url('master/estabelecimentos.php?acao=ver&id=' . $empresa['id_estabelecimento']) ?>">Detalhes</a><form method="post"><?= campoCsrf() ?><input type="hidden" name="acao" value="status"><input type="hidden" name="id_estabelecimento" value="<?= (int) $empresa['id_estabelecimento'] ?>"><input type="hidden" name="status" value="<?= $empresa['status'] === 'ativo' ? 'inativo' : 'ativo' ?>"><button class="btn btn-pequeno <?= $empresa['status'] === 'ativo' ? 'btn-perigo' : 'btn-secundario' ?>" type="submit" data-confirmar="Alterar o acesso deste estabelecimento?"><?= $empresa['status'] === 'ativo' ? 'Desativar' : 'Ativar' ?></button></form></div></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<?php require RAIZ . '/includes/painel_footer.php'; ?>
