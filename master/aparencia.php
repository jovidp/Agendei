<?php
/** Aparência exclusiva do painel e da tela de login master. */
define('AREA_MASTER', true);
require_once __DIR__ . '/../config/config.php';
exigirLogin('master');
$master = Master::porId((int) $_SESSION['master_id']);
$valores = Tema::valores($master);
$nome = $master['nome'];
$erros = [];
$removerLogo = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $nome = post('nome');
    $entrada = ['cor_primaria' => post('cor_primaria'), 'cor_secundaria' => post('cor_secundaria'), 'cor_fundo' => post('cor_fundo'), 'fonte' => post('fonte')];
    $erros = Tema::validar($entrada);
    $valores = Tema::valores($entrada);
    if (mb_strlen($nome) < 2 || mb_strlen($nome) > 120) $erros[] = 'Informe um nome com 2 a 120 caracteres.';
    $removerLogo = post('remover_logo') === '1';
    $logo = $removerLogo ? null : ($master['logo'] ?? null);
    try {
        if (!$removerLogo && isset($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) $logo = Tema::receberLogo($_FILES['logo']);
        if (!$erros) {
            Master::personalizar((int) $master['id_master'], $nome, $valores, $logo);
            $_SESSION['usuario_nome'] = $nome;
            definirFlash('sucesso', 'Aparência da administração master atualizada.');
            redirecionar('master/aparencia.php');
        }
    } catch (InvalidArgumentException $erro) {
        $erros[] = $erro->getMessage();
    }
}
$tituloPagina = 'Aparência master';
$subtituloTopo = 'Identidade visual da sua área administrativa global';
$jsExtra = ['aparencia.js'];
require RAIZ . '/includes/painel_header.php';
$logoPrevia = !$removerLogo && Tema::logoValida($master['logo'] ?? null) ? $master['logo'] : '';
?>
<?php if ($erros): ?><div class="alerta alerta-erro" role="alert"><ul><?php foreach ($erros as $mensagem): ?><li><?= e($mensagem) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="cartao"><div class="cartao-cabecalho"><h3>Personalizar administração master</h3></div><div class="cartao-corpo personalizacao-grade">
<form method="post" enctype="multipart/form-data" id="formAparencia">
    <?= campoCsrf() ?>
    <div class="campo"><label for="nome">Nome exibido</label><input type="text" id="nome" name="nome" value="<?= e($nome) ?>" minlength="2" maxlength="120" required></div>
    <div class="linha-campos"><div class="campo"><label for="cor_primaria">Cor principal</label><input type="color" id="cor_primaria" name="cor_primaria" value="<?= e($valores['cor_primaria']) ?>"></div><div class="campo"><label for="cor_secundaria">Cor de destaque</label><input type="color" id="cor_secundaria" name="cor_secundaria" value="<?= e($valores['cor_secundaria']) ?>"></div></div>
    <div class="linha-campos"><div class="campo"><label for="cor_fundo">Cor de fundo</label><input type="color" id="cor_fundo" name="cor_fundo" value="<?= e($valores['cor_fundo']) ?>"></div><div class="campo"><label for="fonte">Fonte</label><select id="fonte" name="fonte"><?php foreach (Tema::FONTES as $chave => [$rotulo, $familia]): ?><option value="<?= e($chave) ?>" data-familia="<?= e($familia) ?>" <?= $valores['fonte'] === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option><?php endforeach; ?></select></div></div>
    <div class="campo"><label for="logo">Logo da área master</label><input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp"><span class="ajuda-campo">PNG, JPG ou WebP, até 512 KB e 2048 × 2048 pixels.</span></div>
    <div class="campo-checkbox"><input type="checkbox" name="remover_logo" id="remover_logo" value="1"><label for="remover_logo">Usar a inicial do nome no lugar da logo</label></div>
    <div class="grupo-botoes"><button class="btn" type="submit">Salvar aparência</button><button class="btn btn-contorno" type="button" id="restaurarTema">Usar visual padrão</button></div>
</form>
<div><h3>Prévia</h3><div class="tema-previa" id="previaTema" style="<?= e(Tema::estilo($valores)) ?>"><div class="tema-previa-topo"><img class="marca-logo" id="previaLogo" <?= $logoPrevia === '' ? 'hidden' : 'src="' . e($logoPrevia) . '"' ?> alt=""><span class="marca-simbolo" id="previaInicial" <?= $logoPrevia !== '' ? 'hidden' : '' ?>><?= e(mb_substr($nome, 0, 1)) ?></span><strong id="previaNome"><?= e($nome) ?></strong></div><div class="tema-previa-corpo"><div class="cartao"><small>Administração global</small><h3>Estabelecimentos</h3><p>Acompanhe as empresas e suas contas administrativas.</p><span class="etiqueta">Dados isolados</span><div><button class="btn" type="button" tabindex="-1">Gerenciar</button></div></div></div></div><p class="ajuda-campo" id="avisoLogo" role="status">Prévia ilustrativa da identidade visual master.</p></div>
</div></div>
<?php require RAIZ . '/includes/painel_footer.php'; ?>
