<?php

/**
 * Cadastro de filiais (unidades) do estabelecimento.
 *
 * Nao ha exclusao: uma filial inativa some do agendamento, mas o historico
 * de profissionais e atendimentos dela permanece.
 */
// Carrega as configuracoes, a sessao e as funcoes compartilhadas antes de processar a pagina.
require_once __DIR__ . '/../config/config.php';

// Restringe esta pagina a administradores autenticados.
exigirLogin('admin');

$erros    = [];
$dados    = [];
$idFilial = 0;

// Processa o formulario enviado antes de montar o HTML da pagina.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Confere o token da sessao antes de aceitar alteracoes enviadas pelo formulario.
    exigirCsrf();

    $acao     = post('acao');
    $idFilial = (int) post('id_filial');

    if ($acao === 'salvar') {
        $dados = [
            'nome'        => post('nome'),
            'telefone'    => post('telefone'),
            'cep'         => post('cep'),
            'logradouro'  => post('logradouro'),
            'numero'      => post('numero'),
            'complemento' => post('complemento'),
            'bairro'      => post('bairro'),
            'cidade'      => post('cidade'),
            'uf'          => post('uf'),
            'ordem'       => (int) post('ordem'),
            'status'      => post('status') === 'inativo' ? 'inativo' : 'ativo',
        ];

        try {
            // Foto: "remover" apaga a atual; um arquivo novo substitui; sem os dois,
            // a chave nao entra no array e Filial::atualizar mantem a que existe.
            if (post('remover_foto') === '1') {
                $dados['foto'] = null;
            } elseif (isset($_FILES['foto']) && ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $dados['foto'] = Tema::receberLogo($_FILES['foto']);
            }

            if ($idFilial > 0) {
                Filial::atualizar($idFilial, $dados);
                definirFlash('sucesso', 'Filial atualizada com sucesso.');
            } else {
                Filial::criar($dados);
                definirFlash('sucesso', 'Filial cadastrada com sucesso.');
            }
            redirecionar('admin/filiais.php');
        } catch (InvalidArgumentException $erro) {
            // A mensagem ja vem pronta para o usuario (validacao da filial ou da imagem).
            $erros[] = $erro->getMessage();
        }
    }

    // Trata a mudanca de status solicitada pelo botao da lista.
    if ($acao === 'status' && $idFilial > 0) {
        $novoStatus = post('status') === 'ativo' ? 'ativo' : 'inativo';

        Filial::alterarStatus($idFilial, $novoStatus);
        definirFlash('sucesso', $novoStatus === 'ativo' ? 'Filial ativada.' : 'Filial inativada.');

        redirecionar('admin/filiais.php');
    }
}

$acaoTela = get('acao');
$edicao   = null;

if ($acaoTela === 'editar') {
    $edicao = Filial::porId((int) get('id'));
    if (!$edicao) {
        definirFlash('erro', 'Filial nao encontrada.');
        redirecionar('admin/filiais.php');
    }
}

// Quando o envio de uma edicao volta com erros, recarrega a filial para manter
// o id, o titulo e a foto atual; os campos mostram o que foi digitado.
if ($erros !== [] && $idFilial > 0) {
    $edicao = Filial::porId($idFilial);
}

$formularioAberto = in_array($acaoTela, ['novo', 'editar'], true) || $erros !== [];

// Valores do formulario: o que foi digitado (apos erro) ou o que esta gravado.
$valores = [];
foreach (['nome', 'telefone', 'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf', 'ordem', 'status'] as $campo) {
    $valores[$campo] = (string) ($erros !== [] ? ($dados[$campo] ?? '') : ($edicao[$campo] ?? ''));
}
$valores['status'] = $valores['status'] === 'inativo' ? 'inativo' : 'ativo';
$valores['ordem']  = (int) $valores['ordem'];

$fotoAtual   = $edicao && Tema::logoValida($edicao['foto'] ?? null) ? $edicao['foto'] : '';
$removerFoto = $erros !== [] && post('remover_foto') === '1';

$status       = get('status');
$lista        = Filial::listar(array_filter(['status' => $status]));
$totalFiliais = Filial::total();
$totalAtivas  = count(Filial::ativas());

// Quantos profissionais ha em cada filial: uma consulta so, agrupada por unidade.
$profissionaisPorFilial = [];
$consulta = bd()->query(
    'SELECT id_filial, COUNT(*) AS total
     FROM profissionais
     WHERE id_estabelecimento = ' . Contexto::id() . ' AND id_filial IS NOT NULL
     GROUP BY id_filial'
);
foreach ($consulta->fetchAll() as $linha) {
    $profissionaisPorFilial[(int) $linha['id_filial']] = (int) $linha['total'];
}

// Define o titulo e os demais dados de apresentacao utilizados pelo cabecalho.
$tituloPagina  = 'Filiais';
$subtituloTopo = 'Unidades do estabelecimento: endereco, contato e foto';
$acoesTopo     = $formularioAberto
    ? '<a href="' . url('admin/filiais.php') . '" class="btn btn-contorno btn-pequeno">Voltar para a lista</a>'
    : '<a href="' . url('admin/filiais.php?acao=novo') . '" class="btn btn-pequeno">Nova filial</a>';

// Renderiza a estrutura comum do painel apos preparar os dados desta tela.
require_once RAIZ . '/includes/painel_header.php';
?>

<?php if ($totalFiliais === 0): ?>
    <div class="alerta alerta-aviso" role="alert">
        <span class="alerta-texto">
            <strong>Nenhuma filial cadastrada.</strong>
            Cadastre a primeira filial: cada profissional precisa pertencer a uma unidade
            e o agendamento só funciona com ao menos uma filial ativa.
        </span>
    </div>
<?php elseif ($totalAtivas === 0): ?>
    <div class="alerta alerta-aviso" role="alert">
        <span class="alerta-texto">Todas as filiais estão inativas: o agendamento fica indisponível até que uma seja ativada.</span>
    </div>
<?php endif; ?>

<?php if ($erros !== []): ?>
    <div class="alerta alerta-erro" role="alert">
        <div>
            <span class="alerta-texto">Corrija os itens abaixo:</span>
            <ul>
                <?php foreach ($erros as $mensagem): ?><li><?= e($mensagem) ?></li><?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<?php if ($formularioAberto): ?>

    <div class="cartao">
        <div class="cartao-cabecalho">
            <h3><?= $edicao ? 'Editar filial' : 'Nova filial' ?></h3>
        </div>
        <div class="cartao-corpo">
            <?php /* Formulario de alteracao: os dados serao validados novamente pelo servidor. */ ?><form method="post" enctype="multipart/form-data">
                <?= campoCsrf() ?>
                <input type="hidden" name="acao" value="salvar">
                <input type="hidden" name="id_filial" value="<?= (int) ($edicao['id_filial'] ?? 0) ?>">

                <div class="linha-campos">
                    <div class="campo">
                        <label for="nome">Nome da filial <span class="obrigatorio">*</span></label>
                        <input type="text" id="nome" name="nome" maxlength="120" value="<?= e($valores['nome']) ?>" required>
                    </div>

                    <div class="campo">
                        <label for="telefone">Telefone</label>
                        <input type="tel" id="telefone" name="telefone" data-mascara="telefone" inputmode="numeric" maxlength="20"
                            value="<?= e($valores['telefone']) ?>">
                    </div>
                </div>

                <div class="linha-campos-3">
                    <div class="campo">
                        <label for="cep">CEP</label>
                        <input type="text" id="cep" name="cep" data-mascara="cep" data-busca-cep inputmode="numeric" maxlength="9"
                            value="<?= e($valores['cep']) ?>">
                        <span class="ajuda-campo" data-cep-situacao>Preenche o endereço automaticamente.</span>
                    </div>

                    <div class="campo">
                        <label for="logradouro">Logradouro</label>
                        <input type="text" id="logradouro" name="logradouro" maxlength="150" value="<?= e($valores['logradouro']) ?>">
                    </div>

                    <div class="campo">
                        <label for="numero">Numero</label>
                        <input type="text" id="numero" name="numero" maxlength="20" value="<?= e($valores['numero']) ?>">
                    </div>
                </div>

                <div class="linha-campos">
                    <div class="campo">
                        <label for="complemento">Complemento</label>
                        <input type="text" id="complemento" name="complemento" maxlength="60" value="<?= e($valores['complemento']) ?>">
                    </div>

                    <div class="campo">
                        <label for="bairro">Bairro</label>
                        <input type="text" id="bairro" name="bairro" maxlength="100" value="<?= e($valores['bairro']) ?>">
                    </div>
                </div>

                <div class="linha-campos">
                    <div class="campo">
                        <label for="cidade">Cidade</label>
                        <input type="text" id="cidade" name="cidade" maxlength="100" value="<?= e($valores['cidade']) ?>">
                    </div>

                    <div class="campo">
                        <label for="uf">UF</label>
                        <select id="uf" name="uf">
                            <option value="">Selecione</option>
                            <?php foreach (unidadesFederacao() as $uf): ?>
                                <option value="<?= e($uf) ?>" <?= $valores['uf'] === $uf ? 'selected' : '' ?>><?= e($uf) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="linha-campos">
                    <div class="campo">
                        <label for="ordem">Ordem</label>
                        <input type="number" id="ordem" name="ordem" min="0" max="999" value="<?= (int) $valores['ordem'] ?>">
                        <span class="ajuda-campo">O menor numero aparece primeiro nas listas e na escolha do cliente.</span>
                    </div>

                    <div class="campo">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="ativo" <?= $valores['status'] === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                            <option value="inativo" <?= $valores['status'] === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                        </select>
                    </div>
                </div>

                <div class="campo">
                    <label for="foto">Foto da filial</label>
                    <?php if ($fotoAtual !== ''): ?>
                        <img src="<?= e($fotoAtual) ?>" alt="Foto atual da filial"
                            style="display:block;max-width:180px;max-height:120px;margin-bottom:8px;border-radius:var(--raio-pequeno);object-fit:cover">
                    <?php endif; ?>
                    <input type="file" id="foto" name="foto" accept="image/*">
                    <span class="ajuda-campo">PNG, JPG ou WebP. Até 512 KB e 2048 × 2048 pixels. Aparece para o cliente na escolha da unidade.</span>
                </div>

                <?php if ($fotoAtual !== ''): ?>
                    <div class="campo-checkbox">
                        <input type="checkbox" id="remover_foto" name="remover_foto" value="1" <?= $removerFoto ? 'checked' : '' ?>>
                        <label for="remover_foto">Remover a foto atual</label>
                    </div>
                <?php endif; ?>

                <div class="grupo-botoes">
                    <button type="submit" class="btn"><?= $edicao ? 'Salvar alteracoes' : 'Cadastrar filial' ?></button>
                    <a href="<?= url('admin/filiais.php') ?>" class="btn btn-contorno">Cancelar</a>
                </div>
            </form>
        </div>
    </div>

<?php endif; ?>

<div class="cartao">
    <?php /* Filtros enviados na URL para permitir atualizar e compartilhar a consulta. */ ?><form method="get" class="barra-filtros">
        <input type="hidden" name="estabelecimento" value="<?= e(Contexto::slug()) ?>">
        <div class="campo">
            <label for="filtroStatus">Status</label>
            <select id="filtroStatus" name="status" data-envia-ao-mudar>
                <option value="">Todas</option>
                <option value="ativo" <?= $status === 'ativo' ? 'selected' : '' ?>>Ativas</option>
                <option value="inativo" <?= $status === 'inativo' ? 'selected' : '' ?>>Inativas</option>
            </select>
        </div>

        <div class="campo">
            <button type="submit" class="btn btn-contorno">Filtrar</button>
        </div>
    </form>

    <?php if ($lista === []): ?>
        <div class="estado-vazio">
            <?php if ($totalFiliais === 0): ?>
                <strong>Nenhuma filial cadastrada.</strong>
                <p>Cadastre a primeira filial para vincular os profissionais e liberar o agendamento.</p>
                <a href="<?= url('admin/filiais.php?acao=novo') ?>" class="btn margem-topo">Nova filial</a>
            <?php else: ?>
                <strong>Nenhuma filial encontrada.</strong>
                <p>Ajuste o filtro de status para ver as demais unidades.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="tabela-area">
            <?php /* Tabela de apresentacao dos registros retornados pela consulta. */ ?><table class="tabela">
                <thead>
                    <tr>
                        <th>Foto</th>
                        <th>Filial</th>
                        <th>Endereco</th>
                        <th>Telefone</th>
                        <th>Profissionais</th>
                        <th>Status</th>
                        <th class="coluna-acoes">Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista as $filial): ?>
                        <?php
                        $endereco = Filial::enderecoResumido($filial);
                        $ativa    = $filial['status'] === 'ativo';
                        ?>
                        <tr>
                            <td>
                                <?php if (Tema::logoValida($filial['foto'] ?? null)): ?>
                                    <img class="avatar avatar-pequeno" src="<?= e($filial['foto']) ?>" alt="" style="object-fit:cover">
                                <?php else: ?>
                                    <span class="avatar avatar-pequeno" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($filial['nome'], 0, 1))) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="celula-principal"><?= e($filial['nome']) ?></span>
                            </td>
                            <td>
                                <?= $endereco !== '' ? e($endereco) : '-' ?>
                                <?php if (!empty($filial['complemento'])): ?>
                                    <span class="celula-secundaria"><?= e($filial['complemento']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= !empty($filial['telefone']) ? e(formatarTelefone($filial['telefone'])) : '-' ?></td>
                            <td><?= $profissionaisPorFilial[(int) $filial['id_filial']] ?? 0 ?></td>
                            <td><?= badgeStatus($filial['status']) ?></td>
                            <td class="coluna-acoes">
                                <a href="<?= url('admin/filiais.php?acao=editar&id=' . (int) $filial['id_filial']) ?>"
                                    class="btn btn-contorno btn-pequeno">Editar</a>

                                <?php /* Formulario de alteracao: os dados serao validados novamente pelo servidor. */ ?><form method="post" style="display:inline">
                                    <?= campoCsrf() ?>
                                    <input type="hidden" name="acao" value="status">
                                    <input type="hidden" name="id_filial" value="<?= (int) $filial['id_filial'] ?>">
                                    <input type="hidden" name="status" value="<?= $ativa ? 'inativo' : 'ativo' ?>">
                                    <?php if ($ativa): ?>
                                        <button type="submit" class="btn btn-contorno btn-pequeno"
                                            data-confirmar="Inativar a filial <?= e($filial['nome']) ?>? Ela deixa de aparecer no agendamento; o historico e preservado."
                                            data-confirmar-titulo="Inativar filial"
                                            data-confirmar-rotulo="Inativar">
                                            Inativar
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-contorno btn-pequeno">Ativar</button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>