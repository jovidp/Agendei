<?php
/**
 * Cadastro de novos clientes.
 */
require_once __DIR__ . '/config/config.php';

bloquearSeLogado();

$erros = [];
$dados = [
    'nome'            => '',
    'cpf'             => '',
    'data_nascimento' => '',
    'telefone'        => '',
    'email'           => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();

    $dados['nome']            = post('nome');
    $dados['cpf']             = post('cpf');
    $dados['data_nascimento'] = post('data_nascimento');
    $dados['telefone']        = post('telefone');
    $dados['email']           = mb_strtolower(post('email'));

    $senha       = post('senha');
    $confirmacao = post('confirmar_senha');

    $cpf      = apenasNumeros($dados['cpf']);
    $telefone = apenasNumeros($dados['telefone']);

    if (mb_strlen($dados['nome']) < 5 || !str_contains($dados['nome'], ' ')) {
        $erros[] = 'Informe seu nome completo.';
    }
    if (!validarCpf($cpf)) {
        $erros[] = 'Informe um CPF valido.';
    }
    if ($dados['data_nascimento'] !== '') {
        if (!validarData($dados['data_nascimento']) || strtotime($dados['data_nascimento']) > time()) {
            $erros[] = 'Informe uma data de nascimento valida.';
        }
    }
    if (!in_array(strlen($telefone), [10, 11], true)) {
        $erros[] = 'Informe um telefone valido com DDD.';
    }
    if (!validarEmail($dados['email'])) {
        $erros[] = 'Informe um e-mail valido.';
    }
    if (!validarSenha($senha)) {
        $erros[] = 'A senha deve ter no minimo 6 caracteres.';
    }
    if ($senha !== $confirmacao) {
        $erros[] = 'As senhas nao conferem.';
    }
    if ($erros === [] && Usuario::emailEmUso($dados['email'])) {
        $erros[] = 'Ja existe uma conta cadastrada com este e-mail.';
    }
    if ($erros === [] && Cliente::cpfEmUso($cpf)) {
        $erros[] = 'Ja existe uma conta cadastrada com este CPF.';
    }

    if ($erros === []) {
        try {
            Cliente::criar([
                'nome'            => $dados['nome'],
                'email'           => $dados['email'],
                'senha'           => $senha,
                'telefone'        => $telefone,
                'cpf'             => $cpf,
                'data_nascimento' => $dados['data_nascimento'] ?: null,
            ]);

            $usuario = autenticar($dados['email'], $senha);
            if ($usuario !== null) {
                registrarSessao($usuario);
                definirFlash('sucesso', 'Cadastro realizado com sucesso. Bem-vindo(a)!');
                redirecionar('cliente/agendar.php');
            }

            definirFlash('sucesso', 'Cadastro realizado com sucesso. Faca login para continuar.');
            redirecionar('login.php');
        } catch (Throwable $erro) {
            error_log('Falha no cadastro de cliente: ' . $erro->getMessage());
            $erros[] = 'Nao foi possivel concluir o cadastro. Tente novamente.';
        }
    }
}

$estabelecimento = Estabelecimento::dados();
$tituloPagina = 'Criar conta | ' . $estabelecimento['nome'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($tituloPagina) ?></title>
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/login.css') ?>">
</head>
<body class="pagina-autenticacao">

<div class="autenticacao-area">
    <div class="autenticacao-caixa">
        <div class="autenticacao-apresentacao">
            <span class="marca">
                <span class="marca-simbolo"><?= e(mb_substr($estabelecimento['nome'], 0, 1)) ?></span>
                <?= e($estabelecimento['nome']) ?>
            </span>
            <h2>Crie sua conta</h2>
            <p>Com a conta criada voce agenda em poucos cliques e acompanha todo o seu historico.</p>

            <ul class="lista-beneficios">
                <li>Agende quando e onde quiser</li>
                <li>Receba a confirmacao pelo painel</li>
                <li>Cancele dentro do prazo permitido</li>
            </ul>
        </div>

        <div class="autenticacao-formulario">
            <a href="<?= url('index.php') ?>" class="voltar-site">&larr; Voltar ao site</a>

            <h1>Criar conta</h1>
            <p class="subtitulo">Preencha seus dados para comecar.</p>

            <?php if ($erros !== []): ?>
                <div class="alerta alerta-erro">
                    <div>
                        <span class="alerta-texto">Corrija os itens abaixo:</span>
                        <ul>
                            <?php foreach ($erros as $erro): ?>
                                <li><?= e($erro) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" id="formCadastro" novalidate>
                <?= campoCsrf() ?>

                <div class="campo">
                    <label for="nome">Nome completo <span class="obrigatorio">*</span></label>
                    <input type="text" id="nome" name="nome" value="<?= e($dados['nome']) ?>" autocomplete="name" required>
                    <span class="mensagem-campo"></span>
                </div>

                <div class="linha-campos">
                    <div class="campo">
                        <label for="cpf">CPF <span class="obrigatorio">*</span></label>
                        <input type="text" id="cpf" name="cpf" value="<?= e($dados['cpf']) ?>" data-mascara="cpf" inputmode="numeric" placeholder="000.000.000-00" required>
                        <span class="mensagem-campo"></span>
                    </div>

                    <div class="campo">
                        <label for="data_nascimento">Data de nascimento</label>
                        <input type="date" id="data_nascimento" name="data_nascimento" value="<?= e($dados['data_nascimento']) ?>" max="<?= date('Y-m-d') ?>">
                        <span class="mensagem-campo"></span>
                    </div>
                </div>

                <div class="linha-campos">
                    <div class="campo">
                        <label for="telefone">Telefone <span class="obrigatorio">*</span></label>
                        <input type="tel" id="telefone" name="telefone" value="<?= e($dados['telefone']) ?>" data-mascara="telefone" inputmode="numeric" placeholder="(00) 00000-0000" required>
                        <span class="mensagem-campo"></span>
                    </div>

                    <div class="campo">
                        <label for="email">E-mail <span class="obrigatorio">*</span></label>
                        <input type="email" id="email" name="email" value="<?= e($dados['email']) ?>" autocomplete="email" required>
                        <span class="mensagem-campo"></span>
                    </div>
                </div>

                <div class="linha-campos">
                    <div class="campo">
                        <label for="senha">Senha <span class="obrigatorio">*</span></label>
                        <input type="password" id="senha" name="senha" autocomplete="new-password" required>
                        <span class="mensagem-campo"></span>
                        <span class="ajuda-campo">Minimo de 6 caracteres.</span>
                    </div>

                    <div class="campo">
                        <label for="confirmar_senha">Confirmar senha <span class="obrigatorio">*</span></label>
                        <input type="password" id="confirmar_senha" name="confirmar_senha" autocomplete="new-password" required>
                        <span class="mensagem-campo"></span>
                    </div>
                </div>

                <button type="submit" class="btn btn-bloco btn-grande">Criar conta</button>
            </form>

            <p class="autenticacao-rodape">
                Ja tem uma conta? <a href="<?= url('login.php') ?>">Entrar</a>
            </p>
        </div>
    </div>
</div>

<div id="notificacoes"></div>
<script src="<?= url('assets/js/main.js') ?>"></script>
<script src="<?= url('assets/js/login.js') ?>"></script>
</body>
</html>
