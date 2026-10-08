<?php
/** Cadastro publico de estabelecimentos com demonstracao imediata. */
require_once __DIR__ . '/config/config.php';

if (estaLogado()) redirecionar(painelDe(perfil()));

$erros = [];
$dados = ['estabelecimento' => '', 'slug' => '', 'nome' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $bloqueio = conferirBloqueio(['cadastro_empresa_ip' => ipCliente()]);
    if ($bloqueio !== '') $erros[] = $bloqueio;
    anotarFalha('cadastro_empresa_ip', ipCliente());

    foreach (array_keys($dados) as $campo) $dados[$campo] = trim(post($campo));
    $dados['slug'] = mb_strtolower($dados['slug']);
    $dados['email'] = mb_strtolower($dados['email']);
    $senha = post('senha');

    if (mb_strlen($dados['estabelecimento']) < 2 || mb_strlen($dados['estabelecimento']) > 120) $erros[] = 'Informe o nome da empresa.';
    if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{1,78}[a-z0-9])$/D', $dados['slug'])) $erros[] = 'Use um endereco de 3 a 80 caracteres com letras minusculas, numeros ou hifens.';
    if (mb_strlen($dados['nome']) < 3 || mb_strlen($dados['nome']) > 120) $erros[] = 'Informe o nome do responsavel.';
    if (!validarEmail($dados['email'])) $erros[] = 'Informe um e-mail valido.';
    if (!validarSenha($senha)) $erros[] = 'A senha deve ter ao menos 6 caracteres.';
    if ($senha !== post('confirmar_senha')) $erros[] = 'As senhas nao conferem.';

    if ($erros === []) {
        $q = bd()->prepare('SELECT 1 FROM estabelecimento WHERE slug = ? LIMIT 1');
        $q->execute([$dados['slug']]);
        if ($q->fetchColumn()) $erros[] = 'Este endereco exclusivo ja esta em uso.';
    }

    if ($erros === []) {
        try {
            Estabelecimento::contratar($dados + ['senha' => $senha, 'iniciar_demo' => true]);
            limparFalhas('cadastro_empresa_ip', ipCliente());
            definirFlash('sucesso', 'Sua empresa foi criada. Voce tem 7 dias de demonstracao gratis.');
            redirecionar('login.php?estabelecimento=' . rawurlencode($dados['slug']));
        } catch (Throwable $erro) {
            error_log('Falha no cadastro de empresa: ' . $erro->getMessage());
            $erros[] = 'Nao foi possivel criar a empresa agora. Tente novamente.';
        }
    }
}

$estabelecimento = Estabelecimento::dados();
$tituloPagina = 'Criar empresa | ' . NOME_SISTEMA;
?>
<!DOCTYPE html>
<html lang="pt-BR"><head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($tituloPagina) ?></title>
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>"><link rel="stylesheet" href="<?= url('assets/css/login.css') ?>">
    <?php require RAIZ . '/includes/tema.php'; ?>
</head><body class="pagina-autenticacao">
<div class="autenticacao-area"><div class="autenticacao-caixa">
    <div class="autenticacao-apresentacao">
        <span class="marca"><?= marcaSistema(28) ?> <?= e(NOME_SISTEMA) ?></span>
        <h2>Comece sem esperar</h2>
        <p>Crie o ambiente da sua empresa e use todas as ferramentas por 7 dias.</p>
        <ul class="lista-beneficios"><li>Demonstracao liberada na hora</li><li>R$ 149 por mes depois do teste</li><li>Pix, boleto ou cartao</li></ul>
    </div>
    <div class="autenticacao-formulario">
        <a href="<?= url('login.php') ?>" class="voltar-site">&larr; Ja tenho uma conta</a>
        <h1>Crie sua empresa</h1><p class="subtitulo">7 dias gratis, sem aprovacao manual.</p>
        <?php if ($erros): ?><div class="alerta alerta-erro"><ul><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <form method="post" novalidate><?= campoCsrf() ?>
            <div class="campo"><label for="estabelecimento">Nome da empresa</label><input id="estabelecimento" name="estabelecimento" maxlength="120" value="<?= e($dados['estabelecimento']) ?>" required></div>
            <div class="campo"><label for="slug">Endereco exclusivo</label><input id="slug" name="slug" maxlength="80" pattern="[a-z0-9][a-z0-9-]{1,78}[a-z0-9]" value="<?= e($dados['slug']) ?>" placeholder="salao-da-ana" required><span class="ajuda-campo">Seu link: ?estabelecimento=nome-da-empresa</span></div>
            <div class="campo"><label for="nome">Seu nome</label><input id="nome" name="nome" maxlength="120" autocomplete="name" value="<?= e($dados['nome']) ?>" required></div>
            <div class="campo"><label for="email">E-mail</label><input id="email" name="email" type="email" maxlength="150" autocomplete="email" value="<?= e($dados['email']) ?>" required></div>
            <div class="linha-campos"><div class="campo"><label for="senha">Senha</label><input id="senha" name="senha" type="password" minlength="6" autocomplete="new-password" required></div><div class="campo"><label for="confirmar_senha">Confirmar senha</label><input id="confirmar_senha" name="confirmar_senha" type="password" minlength="6" autocomplete="new-password" required></div></div>
            <button class="btn btn-bloco btn-grande" type="submit">Comecar demonstracao gratis</button>
        </form>
    </div>
</div></div>
<?php require RAIZ . '/includes/assinatura_sistema.php'; ?>
</body></html>
