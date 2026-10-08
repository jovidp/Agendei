<?php
/**
 * Cadastro de novos usuarios comuns.
 * Os campos e as regras seguem a especificacao do projeto e sao conferidos
 * novamente no servidor, mesmo quando o JavaScript ja validou a tela.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
// Pagina de entrada local: nunca roda sob a identidade master (ver config.php).
define('ENTRADA_LOCAL', true);
require_once __DIR__ . '/config/config.php';

// Encaminha quem já está autenticado ao painel, evitando repetir o fluxo de acesso.
bloquearSeLogado();

// Acumula falhas de validação para reapresentar o formulário sem criar um cadastro incompleto.
$erros = [];
// E-mail que ja tem conta no Agendei: em vez de cadastrar de novo, a pessoa
// usa a conta que tem (vincular.php cria so o vinculo com este estabelecimento).
$emailConhecido = null;
$dados = [
    'nome'            => '',
    'data_nascimento' => '',
    'sexo'            => '',
    'nome_materno'    => '',
    'cpf'             => '',
    'email'           => '',
    'telefone'        => '',
    'telefone_fixo'   => '',
    'cep'             => '',
    'logradouro'      => '',
    'numero'          => '',
    'complemento'     => '',
    'bairro'          => '',
    'cidade'          => '',
    'uf'              => '',
    'login'           => '',
];

// Cadastro iniciado pelo Google (google_login.php): nome e e-mail ja vem
// confirmados e preenchidos; a pessoa completa o restante. Ela pode desistir
// do Google e preencher tudo a mao. A confirmacao vale so para esta tela e
// por pouco tempo (Google::cadastroPendente).
if (get('google') === 'cancelar') {
    Google::limparCadastro();
    redirecionar('cadastro.php');
}
$googleCadastro = Google::cadastroPendente('cadastro');
if (is_array($googleCadastro) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $dados['nome']  = (string) ($googleCadastro['nome'] ?? '');
    $dados['email'] = (string) ($googleCadastro['email'] ?? '');
}

// Processa o formulário enviado antes de montar o HTML da página.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Confere o token da sessão antes de aceitar alterações enviadas pelo formulário.
    exigirCsrf();

    // Cadastro aberto ao publico: o freio por origem evita que um script encha
    // a base de contas falsas e, de quebra, use o CPF ja cadastrado como oraculo.
    $bloqueioCadastro = conferirBloqueio(['cadastro_ip' => ipCliente()]);
    if ($bloqueioCadastro !== '') {
        $erros[] = $bloqueioCadastro;
    }
    anotarFalha('cadastro_ip', ipCliente());

    foreach (array_keys($dados) as $campo) {
        $dados[$campo] = post($campo);
    }
    $dados['email'] = mb_strtolower($dados['email']);
    $dados['login'] = mb_strtolower($dados['login']);
    $dados['uf']    = mb_strtoupper($dados['uf']);

    $senha       = post('senha');
    $confirmacao = post('confirmar_senha');

    $cpf     = apenasNumeros($dados['cpf']);
    $celular = apenasNumeros($dados['telefone']);
    $fixo    = apenasNumeros($dados['telefone_fixo']);
    $cep     = apenasNumeros($dados['cep']);

    if (!validarNomeCompleto($dados['nome'])) {
        $erros[] = 'O nome deve ter de 15 a 80 caracteres, apenas letras e espacos.';
    }
    if (!validarData($dados['data_nascimento']) || strtotime($dados['data_nascimento']) > time()) {
        $erros[] = 'Informe uma data de nascimento valida.';
    }
    if (!in_array($dados['sexo'], ['F', 'M', 'O'], true)) {
        $erros[] = 'Selecione o sexo.';
    }
    if (!validarNomeMaterno($dados['nome_materno'])) {
        $erros[] = 'Informe o nome materno com no minimo 5 caracteres alfabeticos.';
    }
    if (!validarCpf($cpf)) {
        $erros[] = 'Informe um CPF valido.';
    }
    if (!validarEmail($dados['email'])) {
        $erros[] = 'Informe um e-mail valido.';
    }
    if (!validarTelefoneBr($celular, true)) {
        $erros[] = 'Informe o telefone celular com DDD e 9 digitos.';
    }
    if (!validarTelefoneBr($fixo)) {
        $erros[] = 'Informe o telefone fixo com DDD e 8 digitos.';
    }
    if (!validarCep($cep)) {
        $erros[] = 'Informe um CEP valido com oito digitos.';
    }
    if ($dados['logradouro'] === '' || $dados['numero'] === '' || $dados['bairro'] === '' || $dados['cidade'] === '') {
        $erros[] = 'Preencha o endereco completo.';
    }
    if (!validarUf($dados['uf'])) {
        $erros[] = 'Informe a UF com duas letras.';
    }
    if (!validarLogin($dados['login'])) {
        $erros[] = 'O login deve ter exatamente 6 caracteres alfabeticos.';
    }
    if (!validarSenhaProjeto($senha)) {
        $erros[] = 'A senha deve ter exatamente 8 caracteres alfabeticos.';
    }
    if ($senha !== $confirmacao) {
        $erros[] = 'As senhas nao conferem.';
    }
    if ($erros === [] && Usuario::loginEmUso($dados['login'])) {
        $erros[] = 'Este login ja esta em uso. Escolha outro.';
    }
    if ($erros === [] && Usuario::emailEmUso($dados['email'])) {
        $emailConhecido = $dados['email'];
        $erros[] = 'Este e-mail ja tem conta no Agendei. Nao precisa cadastrar de novo: confirme a sua senha e a mesma conta passa a valer aqui.';
    }
    if ($erros === [] && Cliente::cpfEmUso($cpf)) {
        $erros[] = 'Ja existe uma conta cadastrada com este CPF.';
    }

    // Só cria a conta quando todos os dados obrigatórios passam pela validação do servidor.
    if ($erros === []) {
        try {
            Cliente::criar([
                'nome'            => $dados['nome'],
                'email'           => $dados['email'],
                'senha'           => $senha,
                'login'           => $dados['login'],
                'telefone'        => $celular,
                'telefone_fixo'   => $fixo,
                'cpf'             => $cpf,
                'data_nascimento' => $dados['data_nascimento'],
                'sexo'            => $dados['sexo'],
                'nome_materno'    => $dados['nome_materno'],
                'cep'             => $cep,
                'logradouro'      => $dados['logradouro'],
                'numero'          => $dados['numero'],
                'complemento'     => $dados['complemento'],
                'bairro'          => $dados['bairro'],
                'cidade'          => $dados['cidade'],
                'uf'              => $dados['uf'],
            ]);

            Google::limparCadastro();
            // A especificacao encerra o cadastro na tela de login.
            definirFlash('sucesso', 'Cadastro realizado com sucesso. Faça login para continuar.');
            redirecionar('login.php');
        } catch (Throwable $erro) {
            error_log('Falha no cadastro de cliente: ' . $erro->getMessage());
            $erros[] = 'Nao foi possivel concluir o cadastro. Tente novamente.';
        }
    }
}

$estabelecimento = Estabelecimento::dados();
// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
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
    <?php require RAIZ . '/includes/tema.php'; ?>
</head>
<body class="pagina-autenticacao">

<div class="autenticacao-area">
    <div class="autenticacao-caixa caixa-larga">
        <div class="autenticacao-apresentacao">
            <span class="marca">
                <?= Tema::marca($estabelecimento) ?>
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
            <p class="subtitulo">Preencha seus dados para começar.</p>

            <?php exibirFlash(); ?>

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

            <?php if ($emailConhecido !== null): ?>
                <a class="btn btn-contorno btn-bloco btn-grande" href="<?= url('vincular.php?email=' . rawurlencode($emailConhecido)) ?>">Usar minha conta neste estabelecimento</a>
                <div class="separador-ou"><span>ou corrija o e-mail abaixo</span></div>
            <?php endif; ?>

            <?php if (is_array($googleCadastro)): ?>
                <div class="alerta alerta-info">
                    <span class="alerta-texto">E-mail <strong><?= e((string) $googleCadastro['email']) ?></strong> confirmado pelo Google. Complete os dados abaixo.
                        <a href="<?= url('cadastro.php?google=cancelar') ?>">Cadastrar sem o Google</a></span>
                </div>
            <?php elseif (Google::configurado()): ?>
                <?php /* Formulario proprio, fora do cadastro. Dentro dele o botao do Google seria o primeiro
                         botao de envio, e o Enter em qualquer campo levaria ao Google em vez de enviar o
                         cadastro. O Google confirma o e-mail e devolve nome e e-mail preenchidos. */ ?>
                <form method="post" action="<?= url('google_login.php') ?>" id="formCadastroGoogle">
                    <?= campoCsrf() ?>
                    <input type="hidden" name="estabelecimento" value="<?= e(Contexto::slug()) ?>">
                    <input type="hidden" name="origem" value="cadastro">
                    <button type="submit" class="btn btn-contorno btn-bloco btn-grande btn-google" name="acao" value="google">
                        <?= iconeGoogle() ?>
                        Cadastrar com o Google
                    </button>
                    <span class="ajuda-campo">Confirma seu e-mail e já preenche nome e e-mail. O restante você completa abaixo.</span>
                </form>
                <div class="separador-ou"><span>ou preencha tudo</span></div>
            <?php endif; ?>

            <?php /* Formulário de cadastro: os dados serão validados novamente pelo servidor. */ ?><form method="post" id="formCadastro" novalidate>
                <?= campoCsrf() ?>

                <fieldset class="grupo-campos">
                    <legend>Dados pessoais</legend>

                    <div class="campo">
                        <label for="nome">Nome completo <span class="obrigatorio">*</span></label>
                        <input type="text" id="nome" name="nome" value="<?= e($dados['nome']) ?>"
                               autocomplete="name" minlength="15" maxlength="80" required>
                        <span class="mensagem-campo"></span>
                        <span class="ajuda-campo">De 15 a 80 caracteres, apenas letras.</span>
                    </div>

                    <div class="linha-campos">
                        <div class="campo">
                            <label for="data_nascimento">Data de nascimento <span class="obrigatorio">*</span></label>
                            <input type="date" id="data_nascimento" name="data_nascimento"
                                   value="<?= e($dados['data_nascimento']) ?>" max="<?= date('Y-m-d') ?>" required>
                            <span class="mensagem-campo"></span>
                        </div>

                        <div class="campo">
                            <label for="sexo">Sexo <span class="obrigatorio">*</span></label>
                            <select id="sexo" name="sexo" required>
                                <option value="">Selecione</option>
                                <option value="F" <?= $dados['sexo'] === 'F' ? 'selected' : '' ?>>Feminino</option>
                                <option value="M" <?= $dados['sexo'] === 'M' ? 'selected' : '' ?>>Masculino</option>
                                <option value="O" <?= $dados['sexo'] === 'O' ? 'selected' : '' ?>>Outro</option>
                            </select>
                            <span class="mensagem-campo"></span>
                        </div>
                    </div>

                    <div class="linha-campos">
                        <div class="campo">
                            <label for="nome_materno">Nome materno <span class="obrigatorio">*</span></label>
                            <input type="text" id="nome_materno" name="nome_materno"
                                   value="<?= e($dados['nome_materno']) ?>" maxlength="120" required>
                            <span class="mensagem-campo"></span>
                        </div>

                        <div class="campo">
                            <label for="cpf">CPF <span class="obrigatorio">*</span></label>
                            <input type="text" id="cpf" name="cpf" value="<?= e($dados['cpf']) ?>"
                                   data-mascara="cpf" inputmode="numeric" placeholder="000.000.000-00" required>
                            <span class="mensagem-campo"></span>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="grupo-campos">
                    <legend>Contato</legend>

                    <div class="campo">
                        <label for="email">E-mail <span class="obrigatorio">*</span></label>
                        <input type="email" id="email" name="email" value="<?= e($dados['email']) ?>"
                               autocomplete="email" required>
                        <span class="mensagem-campo"></span>
                    </div>

                    <div class="linha-campos">
                        <div class="campo">
                            <label for="telefone">Telefone celular <span class="obrigatorio">*</span></label>
                            <input type="tel" id="telefone" name="telefone" value="<?= e($dados['telefone']) ?>"
                                   data-mascara="telefone" inputmode="numeric" placeholder="(00) 00000-0000" required>
                            <span class="mensagem-campo"></span>
                            <span class="ajuda-campo">Gravado como (+55)XX-XXXXXXXXX.</span>
                        </div>

                        <div class="campo">
                            <label for="telefone_fixo">Telefone fixo <span class="obrigatorio">*</span></label>
                            <input type="tel" id="telefone_fixo" name="telefone_fixo" value="<?= e($dados['telefone_fixo']) ?>"
                                   data-mascara="telefone" inputmode="numeric" placeholder="(00) 0000-0000" required>
                            <span class="mensagem-campo"></span>
                            <span class="ajuda-campo">Gravado como (+55)XX-XXXXXXXX.</span>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="grupo-campos">
                    <legend>Endereco</legend>

                    <div class="linha-campos">
                        <div class="campo">
                            <label for="cep">CEP <span class="obrigatorio">*</span></label>
                            <input type="text" id="cep" name="cep" value="<?= e($dados['cep']) ?>"
                                   data-mascara="cep" data-busca-cep inputmode="numeric" placeholder="00000-000" required>
                            <span class="mensagem-campo"></span>
                            <span class="ajuda-campo" data-cep-situacao>Preenche o endereço automaticamente.</span>
                        </div>

                        <div class="campo">
                            <label for="numero">Numero <span class="obrigatorio">*</span></label>
                            <input type="text" id="numero" name="numero" value="<?= e($dados['numero']) ?>" maxlength="20" required>
                            <span class="mensagem-campo"></span>
                        </div>
                    </div>

                    <div class="campo">
                        <label for="logradouro">Logradouro <span class="obrigatorio">*</span></label>
                        <input type="text" id="logradouro" name="logradouro" value="<?= e($dados['logradouro']) ?>"
                               autocomplete="street-address" maxlength="150" required>
                        <span class="mensagem-campo"></span>
                    </div>

                    <div class="linha-campos">
                        <div class="campo">
                            <label for="complemento">Complemento</label>
                            <input type="text" id="complemento" name="complemento" value="<?= e($dados['complemento']) ?>" maxlength="60">
                            <span class="mensagem-campo"></span>
                        </div>

                        <div class="campo">
                            <label for="bairro">Bairro <span class="obrigatorio">*</span></label>
                            <input type="text" id="bairro" name="bairro" value="<?= e($dados['bairro']) ?>" maxlength="100" required>
                            <span class="mensagem-campo"></span>
                        </div>
                    </div>

                    <div class="linha-campos">
                        <div class="campo">
                            <label for="cidade">Cidade <span class="obrigatorio">*</span></label>
                            <input type="text" id="cidade" name="cidade" value="<?= e($dados['cidade']) ?>" maxlength="100" required>
                            <span class="mensagem-campo"></span>
                        </div>

                        <div class="campo">
                            <label for="uf">UF <span class="obrigatorio">*</span></label>
                            <select id="uf" name="uf" required>
                                <option value="">--</option>
                                <?php foreach (unidadesFederacao() as $sigla): ?>
                                    <option value="<?= e($sigla) ?>" <?= $dados['uf'] === $sigla ? 'selected' : '' ?>><?= e($sigla) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="mensagem-campo"></span>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="grupo-campos">
                    <legend>Acesso</legend>

                    <div class="campo">
                        <label for="login">Login <span class="obrigatorio">*</span></label>
                        <input type="text" id="login" name="login" value="<?= e($dados['login']) ?>"
                               autocomplete="username" minlength="6" maxlength="6" required>
                        <span class="mensagem-campo"></span>
                        <span class="ajuda-campo">Exatamente 6 letras, sem numeros ou simbolos.</span>
                    </div>

                    <div class="linha-campos">
                        <div class="campo">
                            <label for="senha">Senha <span class="obrigatorio">*</span></label>
                            <input type="password" id="senha" name="senha" autocomplete="new-password"
                                   minlength="8" maxlength="8" required>
                            <span class="mensagem-campo"></span>
                            <span class="ajuda-campo">Exatamente 8 letras.</span>
                        </div>

                        <div class="campo">
                            <label for="confirmar_senha">Confirmar senha <span class="obrigatorio">*</span></label>
                            <input type="password" id="confirmar_senha" name="confirmar_senha" autocomplete="new-password"
                                   minlength="8" maxlength="8" required>
                            <span class="mensagem-campo"></span>
                        </div>
                    </div>
                </fieldset>

                <div class="acoes-formulario">
                    <button type="submit" class="btn btn-bloco btn-grande">Enviar</button>
                    <button type="reset" class="btn btn-contorno btn-bloco btn-grande">Limpar tela</button>
                </div>
            </form>

            <p class="autenticacao-rodape">
                Ja tem uma conta? <a href="<?= url('login.php') ?>">Entrar</a>
                &middot; Tem conta em outro estabelecimento? <a href="<?= url('vincular.php') ?>">Usar aqui</a>
            </p>
        </div>
    </div>
</div>

<?php require RAIZ . '/includes/assinatura_sistema.php'; ?>

<div id="notificacoes"></div>
<script src="<?= url('assets/js/main.js') ?>"></script>
<script src="<?= url('assets/js/cadastro.js') ?>"></script>
</body>
</html>
