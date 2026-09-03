<?php
/**
 * Dados pessoais e senha do cliente.
 */
require_once __DIR__ . '/../config/config.php';

exigirLogin('cliente');

$idCliente = perfilId();
$idUsuario = usuarioId();
$cliente   = Cliente::porId($idCliente);

if (!$cliente) {
    definirFlash('erro', 'Perfil nao encontrado.');
    redirecionar('cliente/dashboard.php');
}

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $acao = post('acao');

    if ($acao === 'dados') {
        $nome       = post('nome');
        $email      = mb_strtolower(post('email'));
        $telefone   = apenasNumeros(post('telefone'));
        $cpf        = apenasNumeros(post('cpf'));
        $nascimento = post('data_nascimento');

        if (mb_strlen($nome) < 5 || !str_contains($nome, ' ')) {
            $erros[] = 'Informe seu nome completo.';
        }
        if (!validarEmail($email)) {
            $erros[] = 'Informe um e-mail valido.';
        } elseif (Usuario::emailEmUso($email, $idUsuario)) {
            $erros[] = 'Este e-mail ja esta em uso por outra conta.';
        }
        if (!in_array(strlen($telefone), [10, 11], true)) {
            $erros[] = 'Informe um telefone valido com DDD.';
        }
        if ($cpf !== '') {
            if (!validarCpf($cpf)) {
                $erros[] = 'Informe um CPF valido.';
            } elseif (Cliente::cpfEmUso($cpf, $idCliente)) {
                $erros[] = 'Este CPF ja esta cadastrado em outra conta.';
            }
        }
        if ($nascimento !== '' && (!validarData($nascimento) || strtotime($nascimento) > time())) {
            $erros[] = 'Informe uma data de nascimento valida.';
        }

        if ($erros === []) {
            Usuario::atualizar($idUsuario, ['nome' => $nome, 'email' => $email, 'telefone' => $telefone]);
            Cliente::atualizar($idCliente, [
                'cpf'             => $cpf,
                'data_nascimento' => $nascimento,
                'observacoes'     => $cliente['observacoes'],
            ]);

            $_SESSION['usuario_nome']  = $nome;
            $_SESSION['usuario_email'] = $email;

            definirFlash('sucesso', 'Dados atualizados com sucesso.');
            redirecionar('cliente/perfil.php');
        }
    }

    if ($acao === 'senha') {
        $erros = alterarSenhaUsuario($idUsuario, post('senha_atual'), post('nova_senha'), post('confirmar_senha'));

        if ($erros === []) {
            definirFlash('sucesso', 'Senha alterada com sucesso.');
            redirecionar('cliente/perfil.php');
        }
    }
}

$tituloPagina  = 'Meu perfil';
$subtituloTopo = 'Seus dados de cadastro e acesso';
$jsExtra       = ['login.js'];

require_once RAIZ . '/includes/painel_header.php';
?>

<?php if ($erros !== []): ?>
    <div class="alerta alerta-erro">
        <div>
            <span class="alerta-texto">Corrija os itens abaixo:</span>
            <ul>
                <?php foreach ($erros as $mensagem): ?>
                    <li><?= e($mensagem) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<div class="grade-painel-igual">
    <div class="cartao">
        <div class="cartao-cabecalho"><h3>Dados pessoais</h3></div>
        <div class="cartao-corpo">
            <form method="post" id="formPerfil" novalidate>
                <?= campoCsrf() ?>
                <input type="hidden" name="acao" value="dados">

                <div class="campo">
                    <label for="nome">Nome completo</label>
                    <input type="text" id="nome" name="nome" value="<?= e($cliente['nome']) ?>" required>
                    <span class="mensagem-campo"></span>
                </div>

                <div class="linha-campos">
                    <div class="campo">
                        <label for="cpf">CPF</label>
                        <input type="text" id="cpf" name="cpf" value="<?= e(formatarCpf($cliente['cpf'])) ?>" data-mascara="cpf" inputmode="numeric">
                        <span class="mensagem-campo"></span>
                    </div>

                    <div class="campo">
                        <label for="data_nascimento">Data de nascimento</label>
                        <input type="date" id="data_nascimento" name="data_nascimento"
                               value="<?= e($cliente['data_nascimento']) ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                </div>

                <div class="linha-campos">
                    <div class="campo">
                        <label for="telefone">Telefone</label>
                        <input type="tel" id="telefone" name="telefone" value="<?= e(formatarTelefone($cliente['telefone'])) ?>" data-mascara="telefone" inputmode="numeric" required>
                        <span class="mensagem-campo"></span>
                    </div>

                    <div class="campo">
                        <label for="email">E-mail</label>
                        <input type="email" id="email" name="email" value="<?= e($cliente['email']) ?>" required>
                        <span class="mensagem-campo"></span>
                    </div>
                </div>

                <button type="submit" class="btn">Salvar alteracoes</button>
            </form>
        </div>
    </div>

    <div>
        <div class="cartao">
            <div class="cartao-cabecalho"><h3>Alterar senha</h3></div>
            <div class="cartao-corpo">
                <form method="post" id="formSenha" novalidate>
                    <?= campoCsrf() ?>
                    <input type="hidden" name="acao" value="senha">

                    <div class="campo">
                        <label for="senha_atual">Senha atual</label>
                        <input type="password" id="senha_atual" name="senha_atual" autocomplete="current-password" required>
                        <span class="mensagem-campo"></span>
                    </div>

                    <div class="campo">
                        <label for="nova_senha">Nova senha</label>
                        <input type="password" id="nova_senha" name="nova_senha" autocomplete="new-password" required>
                        <span class="mensagem-campo"></span>
                        <span class="ajuda-campo">Minimo de 6 caracteres.</span>
                    </div>

                    <div class="campo">
                        <label for="confirmar_senha">Confirmar nova senha</label>
                        <input type="password" id="confirmar_senha" name="confirmar_senha" autocomplete="new-password" required>
                        <span class="mensagem-campo"></span>
                    </div>

                    <button type="submit" class="btn">Alterar senha</button>
                </form>
            </div>
        </div>

        <div class="cartao">
            <div class="cartao-cabecalho"><h3>Informacoes da conta</h3></div>
            <div class="cartao-corpo">
                <dl class="lista-detalhes">
                    <div><dt>Cliente desde</dt><dd><?= formatarData($cliente['data_cadastro']) ?></dd></div>
                    <div><dt>Situacao</dt><dd><?= badgeStatus($cliente['status']) ?></dd></div>
                    <div><dt>Total de agendamentos</dt><dd><?= Agendamento::totalPorCliente($idCliente) ?></dd></div>
                </dl>
            </div>
        </div>
    </div>
</div>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>
