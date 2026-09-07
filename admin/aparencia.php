<?php

/** Identidade visual da empresa vinculada ao administrador autenticado. */
require_once __DIR__ . '/../config/config.php';
exigirLogin('admin');
$estabelecimento = Estabelecimento::dados();
$valores = Tema::valores($estabelecimento);
$nome = $estabelecimento['nome'];
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
    $logo = $removerLogo ? null : ($estabelecimento['logo'] ?? null);
    try {
        if (!$removerLogo && isset($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) $logo = Tema::receberLogo($_FILES['logo']);
        if ($erros === []) {
            Estabelecimento::personalizar($nome, $valores, $logo);
            definirFlash('sucesso', 'Identidade visual atualizada. Seus clientes verão as alterações ao abrir ou atualizar as páginas.');
            redirecionar('admin/aparencia.php');
        }
    } catch (InvalidArgumentException $erro) {
        $erros[] = $erro->getMessage();
    } catch (Throwable $erro) {
        error_log('Falha ao salvar identidade visual: ' . $erro->getMessage());
        $erros[] = 'Não foi possível salvar. Tente novamente.';
    }
}
$tituloPagina = 'Aparência';
$subtituloTopo = 'A identidade do seu estabelecimento em todas as telas';
$jsExtra = ['aparencia.js'];
require_once RAIZ . '/includes/painel_header.php';
$logoPrevia = !$removerLogo && Tema::logoValida($estabelecimento['logo'] ?? null) ? $estabelecimento['logo'] : '';
?>
<?php if ($erros): ?>
    <div class="alerta alerta-erro" role="alert">
        <ul><?php foreach ($erros as $mensagem): ?><li><?= e($mensagem) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>
<div class="cartao">
    <div class="cartao-cabecalho">
        <h3>Compartilhe com seus clientes</h3>
    </div>
    <div class="cartao-corpo">
        <p>O cadastro por este link vincula o cliente ao seu estabelecimento.</p>
        <a class="link-estabelecimento" href="<?= e(url('index.php')) ?>" target="_blank" rel="noopener"><?= e(url('index.php')) ?></a>
    </div>
</div>
<div class="cartao">
    <div class="cartao-cabecalho">
        <h3>Personalizar identidade visual</h3>
    </div>
    <div class="cartao-corpo personalizacao-grade">
        <form method="post" enctype="multipart/form-data" id="formAparencia">
            <?= campoCsrf() ?>
            <div class="campo"><label for="nome">Nome do estabelecimento</label><input type="text" id="nome" name="nome" value="<?= e($nome) ?>" minlength="2" maxlength="120" required></div>
            <div class="linha-campos">
                <div class="campo"><label for="cor_primaria">Cor principal</label><input type="color" id="cor_primaria" name="cor_primaria" value="<?= e($valores['cor_primaria']) ?>"></div>
                <div class="campo"><label for="cor_secundaria">Cor de destaque</label><input type="color" id="cor_secundaria" name="cor_secundaria" value="<?= e($valores['cor_secundaria']) ?>"></div>
            </div>
            <div class="linha-campos">
                <div class="campo"><label for="cor_fundo">Cor de fundo</label><input type="color" id="cor_fundo" name="cor_fundo" value="<?= e($valores['cor_fundo']) ?>"></div>
                <div class="campo"><label for="fonte">Fonte</label><select id="fonte" name="fonte"><?php foreach (Tema::FONTES as $chave => [$rotulo, $familia]): ?><option value="<?= e($chave) ?>" data-familia="<?= e($familia) ?>" <?= $valores['fonte'] === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="campo"><label for="logo">Logo do estabelecimento</label><input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp"><span class="ajuda-campo">PNG, JPG ou WebP. Até 512 KB e 2048 × 2048 pixels. A imagem se ajustará ao espaço da marca.</span></div>
            <div class="campo-checkbox"><input type="checkbox" name="remover_logo" id="remover_logo" value="1" <?= $removerLogo ? 'checked' : '' ?>><label for="remover_logo">Usar a inicial do nome no lugar da logo</label></div>
            <div class="grupo-botoes"><button class="btn" type="submit">Salvar aparência</button><button class="btn btn-contorno" type="button" id="restaurarTema">Usar visual padrão</button></div>
            <p class="ajuda-campo">A prévia só é aplicada ao sistema depois de salvar. O layout permanece igual para todos os estabelecimentos.</p>
        </form>
        <div>
            <h3>Prévia</h3>
            <div class="tema-previa" id="previaTema" style="<?= e(Tema::estilo($valores)) ?>">
                <div class="tema-previa-topo"><img class="marca-logo" id="previaLogo" <?= $logoPrevia === '' ? 'hidden' : 'src="' . e($logoPrevia) . '"' ?> alt=""><span class="marca-simbolo" id="previaInicial" <?= $logoPrevia !== '' ? 'hidden' : '' ?>><?= e(mb_substr($nome, 0, 1)) ?></span><strong id="previaNome"><?= e($nome) ?></strong></div>
                <div class="tema-previa-corpo">
                    <div class="cartao"><small>Agendamento online</small>
                        <h3>Seu próximo atendimento</h3>
                        <p>Escolha o serviço e encontre o melhor horário.</p><span class="etiqueta">Horários disponíveis</span>
                        <div><button class="btn" type="button" tabindex="-1">Agendar agora</button></div>
                    </div>
                </div>
            </div>
            <p class="ajuda-campo" id="avisoLogo" role="status">Prévia ilustrativa da identidade visual.</p>
        </div>
    </div>
</div>
<?php require_once RAIZ . '/includes/painel_footer.php'; ?>