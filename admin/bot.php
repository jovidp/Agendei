<?php

/**
 * Personalizacao do bot de WhatsApp: textos, opcoes do menu (ligar,
 * renomear, ordem) e opcoes proprias com resposta pronta (BotMenu).
 * Ligar o bot e o webhook continuam em Diferenciais.
 */
require_once __DIR__ . '/../config/config.php';
exigirLogin('admin');

if (!Plano::temRecurso('bot_whatsapp')) {
    $recursoFaltante = 'bot_whatsapp';
    $tituloPagina = 'Bot do WhatsApp';
    require RAIZ . '/includes/fora_do_plano.php';
}

$erros = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $acao = post('acao');
    $id = (int) post('id_bot_opcao');
    try {
        if ($acao === 'textos') {
            foreach (array_keys(BotMenu::TEXTOS) as $chave) {
                BotMenu::definirTexto($chave, (string) ($_POST['texto'][$chave] ?? ''));
            }
            definirFlash('sucesso', 'Textos do bot salvos.');
        } elseif ($acao === 'restaurar_texto') {
            BotMenu::definirTexto(post('chave'), '');
            definirFlash('sucesso', 'Texto padrão restaurado.');
        } elseif ($acao === 'criar') {
            BotMenu::criar(post('rotulo'), (string) ($_POST['resposta'] ?? ''));
            definirFlash('sucesso', 'Opção criada no fim do menu.');
        } elseif ($acao === 'salvar_opcao') {
            BotMenu::atualizar($id, post('rotulo'), isset($_POST['resposta']) ? (string) $_POST['resposta'] : null);
            definirFlash('sucesso', 'Opção atualizada.');
        } elseif ($acao === 'alternar') {
            BotMenu::alternar($id);
            definirFlash('sucesso', 'Menu atualizado.');
        } elseif ($acao === 'subir' || $acao === 'descer') {
            BotMenu::mover($id, $acao === 'subir' ? -1 : 1);
            definirFlash('sucesso', 'Ordem atualizada.');
        } elseif ($acao === 'apagar') {
            BotMenu::apagar($id);
            definirFlash('sucesso', 'Opção apagada.');
        }
        redirecionar('admin/bot.php');
    } catch (InvalidArgumentException | DomainException $erro) {
        $erros[] = $erro->getMessage();
    }
}

$opcoes = BotMenu::opcoes();
$edicao = null;
foreach ($opcoes as $o) if ((int) $o['id_bot_opcao'] === (int) get('editar')) $edicao = $o;
$numeros = array_column(BotMenu::visiveis(true), 'numero', 'id_bot_opcao');
$variaveisPrevia = ['nome' => 'Maria', 'empresa' => (string) (Contexto::dados()['nome'] ?? ''), 'link_cadastro' => urlAbsoluta('cadastro.php')];
$tiposRotulo = ['marcar' => 'Marca horário', 'meus' => 'Lista os horários', 'cancelar' => 'Cancela horário', 'servicos' => 'Lista serviços e preços', 'equipe' => 'Chama a equipe', 'resposta' => 'Resposta pronta'];
$botLigado = Configuracao::ativa('whatsapp_bot_ativo');

$tituloPagina  = 'Bot do WhatsApp';
$subtituloTopo = 'Textos e opções do menu que o cliente recebe';
$acoesTopo     = '<a href="' . url('admin/diferenciais.php#bot') . '" class="btn btn-contorno btn-pequeno">Ligar e conectar</a>';
require_once RAIZ . '/includes/painel_header.php';
?>

<?php if ($erros !== []): ?>
    <div class="alerta alerta-erro"><div><span class="alerta-texto"><?= e(implode(' ', $erros)) ?></span></div></div>
<?php endif; ?>
<?php if (!$botLigado): ?>
    <div class="alerta alerta-info"><div><span class="alerta-texto">O bot está desligado. Você pode deixar o menu pronto aqui e ligar em <a href="<?= url('admin/diferenciais.php#bot') ?>">Diferenciais</a>.</span></div></div>
<?php endif; ?>

<div class="grade-painel">
    <div>
        <div class="cartao">
            <div class="cartao-cabecalho"><h3>Opções do menu</h3></div>
            <div class="tabela-area"><table class="tabela">
                <thead><tr><th>Nº</th><th>Opção</th><th>O que faz</th><th>Situação</th><th class="coluna-acoes">Ações</th></tr></thead>
                <tbody>
                <?php foreach ($opcoes as $i => $o): $id = (int) $o['id_bot_opcao']; ?>
                    <tr>
                        <td><?= isset($numeros[$id]) ? (int) $numeros[$id] : '-' ?></td>
                        <td><span class="celula-principal"><?= e($o['rotulo']) ?></span><?php if ($o['tipo'] === 'resposta'): ?><span class="celula-secundaria"><?= e(limitarTexto((string) $o['resposta'], 60)) ?></span><?php endif; ?></td>
                        <td><?= e($tiposRotulo[$o['tipo']] ?? $o['tipo']) ?></td>
                        <td><?= badgeStatus($o['ativo'] ? 'ativo' : 'inativo') ?></td>
                        <td class="coluna-acoes">
                            <?php foreach (['subir' => '↑', 'descer' => '↓'] as $acao => $seta): $desligado = $acao === 'subir' ? $i === 0 : $i === count($opcoes) - 1; ?>
                                <form method="post" style="display:inline"><?= campoCsrf() ?><input type="hidden" name="acao" value="<?= $acao ?>"><input type="hidden" name="id_bot_opcao" value="<?= $id ?>">
                                    <button class="btn btn-contorno btn-pequeno" aria-label="<?= $acao === 'subir' ? 'Subir' : 'Descer' ?> <?= e($o['rotulo']) ?>" <?= $desligado ? 'disabled' : '' ?>><?= $seta ?></button></form>
                            <?php endforeach; ?>
                            <a class="btn btn-contorno btn-pequeno" href="<?= url('admin/bot.php?editar=' . $id) ?>#editar">Editar</a>
                            <form method="post" style="display:inline"><?= campoCsrf() ?><input type="hidden" name="acao" value="alternar"><input type="hidden" name="id_bot_opcao" value="<?= $id ?>">
                                <button class="btn btn-contorno btn-pequeno"><?= $o['ativo'] ? 'Desligar' : 'Ligar' ?></button></form>
                            <?php if ($o['tipo'] === 'resposta'): ?>
                                <form method="post" style="display:inline"><?= campoCsrf() ?><input type="hidden" name="acao" value="apagar"><input type="hidden" name="id_bot_opcao" value="<?= $id ?>">
                                    <button class="btn btn-perigo btn-pequeno" data-confirmar="Apagar a opção <?= e($o['rotulo']) ?>?" data-confirmar-titulo="Apagar opção" data-confirmar-rotulo="Apagar">Apagar</button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>

        <div class="cartao" id="editar">
            <div class="cartao-cabecalho"><h3><?= $edicao ? 'Editar "' . e($edicao['rotulo']) . '"' : 'Nova opção com resposta pronta' ?></h3></div>
            <div class="cartao-corpo">
                <form method="post">
                    <?= campoCsrf() ?>
                    <input type="hidden" name="acao" value="<?= $edicao ? 'salvar_opcao' : 'criar' ?>">
                    <input type="hidden" name="id_bot_opcao" value="<?= (int) ($edicao['id_bot_opcao'] ?? 0) ?>">
                    <div class="campo">
                        <label for="rotulo">Nome no menu <span class="obrigatorio">*</span></label>
                        <input type="text" id="rotulo" name="rotulo" maxlength="60" required value="<?= e($edicao['rotulo'] ?? post('rotulo')) ?>" placeholder="Ex.: Endereço e horário de funcionamento">
                    </div>
                    <?php if (!$edicao || $edicao['tipo'] === 'resposta'): ?>
                        <div class="campo">
                            <label for="resposta">Resposta que o bot envia <span class="obrigatorio">*</span></label>
                            <textarea id="resposta" name="resposta" maxlength="1000" rows="5" required><?= e($edicao['resposta'] ?? ($_POST['resposta'] ?? '')) ?></textarea>
                            <span class="ajuda-campo">Use *asteriscos* para negrito no WhatsApp. Depois da resposta o bot lembra o cliente de mandar *menu*.</span>
                        </div>
                    <?php else: ?>
                        <p class="ajuda-campo">Esta opção faz uma ação do sistema (<?= e(mb_strtolower($tiposRotulo[$edicao['tipo']])) ?>); só o nome muda.</p>
                    <?php endif; ?>
                    <div class="grupo-botoes">
                        <button class="btn"><?= $edicao ? 'Salvar opção' : 'Criar opção' ?></button>
                        <?php if ($edicao): ?><a class="btn btn-contorno" href="<?= url('admin/bot.php') ?>">Cancelar</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="cartao">
            <div class="cartao-cabecalho"><h3>Textos</h3></div>
            <div class="cartao-corpo">
                <p class="ajuda-campo">Variáveis: <code>{nome}</code> (primeiro nome do cliente), <code>{empresa}</code>, <code>{link_cadastro}</code>. Deixe vazio para usar o texto padrão.</p>
                <form method="post" id="form-textos">
                    <?= campoCsrf() ?>
                    <input type="hidden" name="acao" value="textos">
                    <?php foreach (BotMenu::ROTULOS_TEXTOS as $chave => $rotulo): $atual = Configuracao::obter('bot_texto_' . $chave); ?>
                        <div class="campo">
                            <label for="texto_<?= $chave ?>"><?= e($rotulo) ?></label>
                            <textarea id="texto_<?= $chave ?>" name="texto[<?= $chave ?>]" maxlength="1000" rows="3" placeholder="<?= e(BotMenu::TEXTOS[$chave]) ?>"><?= e($atual) ?></textarea>
                            <?php if (trim($atual) !== ''): ?>
                                <button type="submit" form="restaurar_<?= $chave ?>" class="btn btn-contorno btn-pequeno margem-topo">Restaurar padrão</button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <button class="btn">Salvar textos</button>
                </form>
                <?php foreach (array_keys(BotMenu::TEXTOS) as $chave): ?>
                    <form method="post" id="restaurar_<?= $chave ?>"><?= campoCsrf() ?><input type="hidden" name="acao" value="restaurar_texto"><input type="hidden" name="chave" value="<?= $chave ?>"></form>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="cartao">
        <div class="cartao-cabecalho"><h3>Prévia no WhatsApp</h3></div>
        <div class="cartao-corpo">
            <h4>Cliente com cadastro</h4>
            <pre class="previa-bot"><?= e(BotMenu::menu(true, $variaveisPrevia)) ?></pre>
            <h4 class="margem-topo">Número sem cadastro</h4>
            <pre class="previa-bot"><?= e(BotMenu::menu(false, $variaveisPrevia)) ?></pre>
        </div>
    </div>
</div>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>
