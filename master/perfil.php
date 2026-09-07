<?php
define('AREA_MASTER', true);
require_once __DIR__ . '/../config/config.php';
exigirLogin('master');
$id = (int) $_SESSION['master_id'];
$master = Master::porId($id);
$erros = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $acao = post('acao');
    if ($acao === 'dados') {
        $nome = post('nome');
        $email = mb_strtolower(post('email'));
        if (mb_strlen($nome) < 3 || !validarEmail($email)) $erros[] = 'Informe um nome e um e-mail válidos.';
        $outro = Master::porEmail($email);
        if ($outro && (int) $outro['id_master'] !== $id) $erros[] = 'Este e-mail já pertence a outra conta master.';
        if (!$erros) {
            Master::atualizarPerfil($id, $nome, $email);
            $_SESSION['usuario_nome'] = $nome;
            $_SESSION['usuario_email'] = $email;
            definirFlash('sucesso', 'Dados da conta master atualizados.');
            redirecionar('master/perfil.php');
        }
    }
    if ($acao === 'senha') {
        $nova = post('nova_senha');
        if (!Master::senhaConfere($id, post('senha_atual'))) $erros[] = 'A senha atual está incorreta.';
        if (!validarSenha($nova)) $erros[] = 'A nova senha deve ter pelo menos 6 caracteres.';
        if ($nova !== post('confirmar_senha')) $erros[] = 'As novas senhas não conferem.';
        if (!$erros) {
            Master::atualizarSenha($id, $nova);
            definirFlash('sucesso', 'Senha master alterada com sucesso.');
            redirecionar('master/perfil.php');
        }
    }
}
$master = Master::porId($id);
$tituloPagina = 'Meu perfil';
$subtituloTopo = 'Dados e senha da administração master';
$jsExtra = ['login.js'];
require RAIZ . '/includes/painel_header.php';
?>
<?php if ($erros): ?><div class="alerta alerta-erro" role="alert"><ul><?php foreach ($erros as $mensagem): ?><li><?= e($mensagem) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="grade-painel grade-painel-igual">
<div class="cartao"><div class="cartao-cabecalho"><h3>Dados da conta</h3></div><div class="cartao-corpo"><form method="post"><?= campoCsrf() ?><input type="hidden" name="acao" value="dados"><div class="campo"><label for="nome">Nome</label><input id="nome" name="nome" value="<?= e($master['nome']) ?>" required></div><div class="campo"><label for="email">E-mail</label><input type="email" id="email" name="email" value="<?= e($master['email']) ?>" required></div><button class="btn" type="submit">Salvar dados</button></form></div></div>
<div class="cartao"><div class="cartao-cabecalho"><h3>Alterar senha</h3></div><div class="cartao-corpo"><form method="post" id="formSenha"><?= campoCsrf() ?><input type="hidden" name="acao" value="senha"><div class="campo"><label for="senha_atual">Senha atual</label><input type="password" id="senha_atual" name="senha_atual" required></div><div class="campo"><label for="nova_senha">Nova senha</label><input type="password" id="nova_senha" name="nova_senha" minlength="6" required></div><div class="campo"><label for="confirmar_senha">Confirmar nova senha</label><input type="password" id="confirmar_senha" name="confirmar_senha" minlength="6" required></div><button class="btn" type="submit">Alterar senha</button></form></div></div>
</div>
<?php require RAIZ . '/includes/painel_footer.php'; ?>
