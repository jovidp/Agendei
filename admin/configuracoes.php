<?php

/**
 * Dados do estabelecimento e regras de funcionamento do sistema.
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

    $acao = post('acao');

    // Valida os dados institucionais exibidos nas páginas públicas.
    if ($acao === 'estabelecimento') {
        $nome  = post('nome');
        $email = post('email');

        if (mb_strlen($nome) < 2) {
            $erros[] = 'Informe o nome do estabelecimento.';
        }
        if ($email !== '' && !validarEmail($email)) {
            $erros[] = 'Informe um e-mail valido.';
        }

        if ($erros === []) {
            Estabelecimento::atualizar([
                'nome'                  => $nome,
                'slogan'                => post('slogan'),
                'descricao'             => post('descricao'),
                'telefone'              => post('telefone'),
                'whatsapp'              => post('whatsapp'),
                'email'                 => $email,
                'endereco'              => post('endereco'),
                'bairro'                => post('bairro'),
                'cidade'                => post('cidade'),
                'uf'                    => mb_strtoupper(mb_substr(post('uf'), 0, 2)),
                'cep'                   => post('cep'),
                'horario_funcionamento' => post('horario_funcionamento'),
                'instagram'             => post('instagram'),
                'facebook'              => post('facebook'),
            ]);

            definirFlash('sucesso', 'Dados do estabelecimento atualizados.');
            redirecionar('admin/configuracoes.php');
        }
    }

    // Atualiza as configurações que controlam agendamentos e cancelamentos.
    if ($acao === 'regras') {
        $regras = [
            'antecedencia_minima_horas' => max(0, min(72, (int) post('antecedencia_minima_horas'))),
            'antecedencia_maxima_dias'  => max(1, min(365, (int) post('antecedencia_maxima_dias'))),
            'cancelamento_limite_horas' => max(0, min(72, (int) post('cancelamento_limite_horas'))),
            'intervalo_slots_minutos'   => max(5, min(120, (int) post('intervalo_slots_minutos'))),
        ];

        foreach ($regras as $chave => $valor) {
            Configuracao::definir($chave, (string) $valor);
        }

        Configuracao::definir('permitir_bloqueio_profissional', post('permitir_bloqueio_profissional') === '1' ? '1' : '0');
        Configuracao::definir('confirmar_automaticamente', post('confirmar_automaticamente') === '1' ? '1' : '0');

        definirFlash('sucesso', 'Regras de agendamento atualizadas.');
        redirecionar('admin/configuracoes.php');
    }
}

$estabelecimento = Estabelecimento::dados();

// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina  = 'Configuracoes';
$subtituloTopo = 'Dados do estabelecimento e regras de agendamento';

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

<div class="cartao">
    <div class="cartao-corpo"><a href="<?= e(url('admin/aparencia.php')) ?>">Personalizar cores, fonte e logo do estabelecimento</a></div>
</div>

<div class="cartao">
    <div class="cartao-cabecalho">
        <h3>Dados do estabelecimento</h3>
    </div>
    <div class="cartao-corpo">
        <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post">
            <?= campoCsrf() ?>
            <input type="hidden" name="acao" value="estabelecimento">

            <div class="linha-campos">
                <div class="campo">
                    <label for="nome">Nome <span class="obrigatorio">*</span></label>
                    <input type="text" id="nome" name="nome" maxlength="120" value="<?= e($estabelecimento['nome']) ?>" required>
                </div>

                <div class="campo">
                    <label for="slogan">Slogan</label>
                    <input type="text" id="slogan" name="slogan" maxlength="180" value="<?= e($estabelecimento['slogan'] ?? '') ?>">
                </div>
            </div>

            <div class="campo">
                <label for="descricao">Descricao</label>
                <textarea id="descricao" name="descricao" maxlength="600"><?= e($estabelecimento['descricao'] ?? '') ?></textarea>
                <span class="ajuda-campo">Texto exibido na pagina inicial.</span>
            </div>

            <div class="linha-campos-3">
                <div class="campo">
                    <label for="telefone">Telefone</label>
                    <input type="tel" id="telefone" name="telefone" data-mascara="telefone" value="<?= e($estabelecimento['telefone'] ?? '') ?>">
                </div>

                <div class="campo">
                    <label for="whatsapp">WhatsApp</label>
                    <input type="tel" id="whatsapp" name="whatsapp" data-mascara="telefone" value="<?= e($estabelecimento['whatsapp'] ?? '') ?>">
                </div>

                <div class="campo">
                    <label for="email">E-mail</label>
                    <input type="email" id="email" name="email" maxlength="150" value="<?= e($estabelecimento['email'] ?? '') ?>">
                </div>
            </div>

            <div class="linha-campos">
                <div class="campo">
                    <label for="endereco">Endereco</label>
                    <input type="text" id="endereco" name="endereco" maxlength="180" value="<?= e($estabelecimento['endereco'] ?? '') ?>">
                </div>

                <div class="campo">
                    <label for="bairro">Bairro</label>
                    <input type="text" id="bairro" name="bairro" maxlength="100" value="<?= e($estabelecimento['bairro'] ?? '') ?>">
                </div>
            </div>

            <div class="linha-campos-3">
                <div class="campo">
                    <label for="cidade">Cidade</label>
                    <input type="text" id="cidade" name="cidade" maxlength="100" value="<?= e($estabelecimento['cidade'] ?? '') ?>">
                </div>

                <div class="campo">
                    <label for="uf">UF</label>
                    <input type="text" id="uf" name="uf" maxlength="2" value="<?= e($estabelecimento['uf'] ?? '') ?>">
                </div>

                <div class="campo">
                    <label for="cep">CEP</label>
                    <input type="text" id="cep" name="cep" data-mascara="cep" data-busca-cep inputmode="numeric" maxlength="9" value="<?= e($estabelecimento['cep'] ?? '') ?>">
                    <span class="ajuda-campo" data-cep-situacao>Preenche o endereço automaticamente.</span>
                </div>
            </div>

            <div class="campo">
                <label for="horario_funcionamento">Horario de funcionamento</label>
                <textarea id="horario_funcionamento" name="horario_funcionamento" maxlength="400"><?= e($estabelecimento['horario_funcionamento'] ?? '') ?></textarea>
                <span class="ajuda-campo">Uma linha por periodo. Ex.: "Segunda a sexta: 08:00 as 19:00".</span>
            </div>

            <div class="linha-campos">
                <div class="campo">
                    <label for="instagram">Instagram</label>
                    <input type="text" id="instagram" name="instagram" maxlength="120" value="<?= e($estabelecimento['instagram'] ?? '') ?>">
                </div>

                <div class="campo">
                    <label for="facebook">Facebook</label>
                    <input type="text" id="facebook" name="facebook" maxlength="120" value="<?= e($estabelecimento['facebook'] ?? '') ?>">
                </div>
            </div>

            <button type="submit" class="btn">Salvar dados</button>
        </form>
    </div>
</div>

<div class="cartao">
    <div class="cartao-cabecalho">
        <h3>Regras de agendamento</h3>
    </div>
    <div class="cartao-corpo">
        <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post">
            <?= campoCsrf() ?>
            <input type="hidden" name="acao" value="regras">

            <div class="linha-campos">
                <div class="campo">
                    <label for="antecedencia_minima_horas">Antecedencia minima (horas)</label>
                    <input type="number" id="antecedencia_minima_horas" name="antecedencia_minima_horas"
                        min="0" max="72" value="<?= Configuracao::obterInteiro('antecedencia_minima_horas', 2) ?>">
                    <span class="ajuda-campo">Tempo minimo entre o momento do agendamento e o atendimento.</span>
                </div>

                <div class="campo">
                    <label for="antecedencia_maxima_dias">Antecedencia maxima (dias)</label>
                    <input type="number" id="antecedencia_maxima_dias" name="antecedencia_maxima_dias"
                        min="1" max="365" value="<?= Configuracao::obterInteiro('antecedencia_maxima_dias', 60) ?>">
                    <span class="ajuda-campo">Ate quantos dias no futuro o cliente pode agendar.</span>
                </div>
            </div>

            <div class="linha-campos">
                <div class="campo">
                    <label for="cancelamento_limite_horas">Limite para cancelamento (horas)</label>
                    <input type="number" id="cancelamento_limite_horas" name="cancelamento_limite_horas"
                        min="0" max="72" value="<?= Configuracao::obterInteiro('cancelamento_limite_horas', 4) ?>">
                    <span class="ajuda-campo">Ate quantas horas antes o cliente pode cancelar sozinho.</span>
                </div>

                <div class="campo">
                    <label for="intervalo_slots_minutos">Intervalo padrao entre horarios (minutos)</label>
                    <input type="number" id="intervalo_slots_minutos" name="intervalo_slots_minutos"
                        min="5" max="120" step="5" value="<?= Configuracao::obterInteiro('intervalo_slots_minutos', 30) ?>">
                    <span class="ajuda-campo">Valor sugerido ao cadastrar novas faixas de expediente.</span>
                </div>
            </div>

            <div class="campo-checkbox">
                <input type="checkbox" id="permitir_bloqueio_profissional" name="permitir_bloqueio_profissional" value="1"
                    <?= Configuracao::ativa('permitir_bloqueio_profissional', true) ? 'checked' : '' ?>>
                <label for="permitir_bloqueio_profissional">Permitir que profissionais bloqueiem a propria agenda</label>
            </div>

            <div class="campo-checkbox">
                <input type="checkbox" id="confirmar_automaticamente" name="confirmar_automaticamente" value="1"
                    <?= Configuracao::ativa('confirmar_automaticamente') ? 'checked' : '' ?>>
                <label for="confirmar_automaticamente">Confirmar automaticamente os agendamentos criados pelo cliente</label>
            </div>

            <button type="submit" class="btn">Salvar regras</button>
        </form>
    </div>
</div>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>