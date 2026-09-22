<?php
/**
 * Pagina inicial.
 *
 * Sem sessao e sem o link de uma empresa, apresenta o produto: marca, slogan
 * e o que o Agendei faz por quem atende e por quem marca. E a unica pagina
 * publica assinada pelo produto; as demais levam a marca da empresa atendida.
 *
 * Quem ja esta autenticado segue para o seu painel. Quem chega pelo link de
 * uma empresa (?estabelecimento=slug) segue para o login dela, que e a porta
 * de entrada com a marca daquela empresa.
 *
 * PAGINA_PRODUTO e definida antes da configuracao para que o Contexto abra com
 * a marca do produto em vez de escolher uma empresa (models/Contexto.php).
 */
if (trim((string) ($_GET['estabelecimento'] ?? '')) === '') {
    define('PAGINA_PRODUTO', true);
}
require_once __DIR__ . '/config/config.php';

if (estaLogado()) {
    redirecionar(painelDe(perfil()));
}
if (!defined('PAGINA_PRODUTO')) {
    redirecionar('login.php');
}

$tituloPagina = NOME_SISTEMA . ' | ' . MARCA_SLOGAN;

/** Recursos opcionais, ligados por empresa em Admin > Diferenciais. */
$recursos = [
    ['Lembretes prontos para WhatsApp', 'A fila monta a mensagem; a empresa so envia.'],
    ['Lista de espera', 'Um cancelamento avisa quem esta esperando uma vaga compativel.'],
    ['Sinal por Pix', 'Cobra o sinal na reserva e confirma o pagamento pelo painel.'],
    ['Horarios recorrentes', 'O cliente fixo tem a semana marcada de uma vez.'],
    ['Fidelidade e pacotes', 'Pontos por atendimento concluido e creditos com validade.'],
    ['Avaliacoes', 'So quem foi atendido avalia.'],
    ['Comissao por profissional', 'Resumo mensal de quem atendeu o que.'],
    ['Agenda no celular', 'Feed privado para Google Agenda, Outlook e Apple Calendar.'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Agendamento online para quem atende e para quem marca. Marcou, confirmou.">
    <title><?= e($tituloPagina) ?></title>
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/inicio.css') ?>">
    <?php require RAIZ . '/includes/tema.php'; ?>
</head>
<body class="pagina-produto">

<?php /* Cabecalho do produto: a marca do sistema, e nao a de uma empresa. */ ?>
<header class="cabecalho-site">
    <div class="container cabecalho-conteudo">
        <a href="<?= url('index.php') ?>" class="marca-link" aria-label="<?= e(NOME_SISTEMA) ?>">
            <?= marcaSistema(32) ?>
        </a>

        <nav class="menu-site" aria-label="Menu principal">
            <a href="#para-quem-atende">Para quem atende</a>
            <a href="#como-funciona">Como funciona</a>
            <a href="#para-quem-marca">Para quem marca</a>
            <a href="#recursos">Recursos</a>
        </nav>

        <div class="acoes-cabecalho">
            <a href="<?= url('entrar.php') ?>" class="btn btn-pequeno">Entrar</a>
            <button type="button" class="botao-menu" aria-label="Abrir menu"><span></span></button>
        </div>
    </div>
</header>

<main>

<?php /* Abertura: slogan, frase de apoio e um agendamento confirmado como o cliente ve. */ ?>
<section class="heroi-produto">
    <div class="container heroi-grade">
        <div class="heroi-texto">
            <span class="heroi-etiqueta">Agendamento online</span>
            <h1><?= e(MARCA_SLOGAN) ?></h1>
            <p>Agendamento online para quem atende e para quem marca. O cliente escolhe o horario, o sistema confirma e lembra, e a agenda da empresa fica sempre em dia.</p>
            <div class="heroi-acoes">
                <a href="<?= url('cadastro_empresa.php') ?>" class="btn btn-grande btn-claro">Cadastrar minha empresa</a>
                <a href="#como-funciona" class="btn btn-grande btn-vazado">Ver como funciona</a>
            </div>
        </div>

        <div class="cartao-confirmacao" aria-label="Exemplo de agendamento confirmado">
            <div class="confirmacao-topo">
                <span class="confirmacao-status">Confirmado</span>
                <span class="confirmacao-empresa">Studio Vila Nova</span>
            </div>
            <div class="confirmacao-servico">Corte e barba</div>
            <dl class="confirmacao-dados">
                <div><dt>Quando</dt><dd>Ter, 29 de set, 15:30</dd></div>
                <div><dt>Com</dt><dd>Rafael</dd></div>
                <div><dt>Duracao</dt><dd>45 min</dd></div>
                <div><dt>Valor</dt><dd>R$ 70,00</dd></div>
            </dl>
            <div class="confirmacao-rodape">Lembrete enviado 24 h antes.</div>
        </div>
    </div>
</section>

<?php /* Argumentos para o estabelecimento. */ ?>
<section class="secao secao-clara" id="para-quem-atende">
    <div class="container">
        <div class="secao-titulo">
            <h2>Para quem atende</h2>
            <p>Menos tempo confirmando horario, mais tempo atendendo.</p>
        </div>
        <div class="grade-argumentos">
            <article class="argumento">
                <span class="argumento-marca" aria-hidden="true"></span>
                <h3>Zero confirmacao manual</h3>
                <p>O cliente marca, o sistema confirma e lembra. Ninguem precisa responder mensagem para fechar um horario.</p>
            </article>
            <article class="argumento">
                <span class="argumento-marca" aria-hidden="true"></span>
                <h3>Cada empresa com a sua cara</h3>
                <p>Logo, cores e link proprio. Para o seu cliente, a agenda e da sua empresa, nao de um sistema de terceiros.</p>
            </article>
            <article class="argumento">
                <span class="argumento-marca" aria-hidden="true"></span>
                <h3>Cresce com o negocio</h3>
                <p>Uma unidade ou varias, um profissional ou uma equipe. Os recursos extras entram so quando fizerem sentido.</p>
            </article>
        </div>
    </div>
</section>

<?php /* As quatro etapas, da configuracao ao horario confirmado. */ ?>
<section class="secao" id="como-funciona">
    <div class="container">
        <div class="secao-titulo">
            <h2>Como funciona</h2>
            <p>Da configuracao ao primeiro horario confirmado no mesmo dia.</p>
        </div>
        <div class="grade-passos">
            <div class="passo">
                <span class="passo-numero">1</span>
                <h3>A empresa monta a agenda</h3>
                <p>Servicos com preco e duracao, equipe e horario de cada profissional.</p>
            </div>
            <div class="passo">
                <span class="passo-numero">2</span>
                <h3>O cliente escolhe</h3>
                <p>Servico, profissional e horario. So aparecem horarios realmente livres.</p>
            </div>
            <div class="passo">
                <span class="passo-numero">3</span>
                <h3>Marcou, confirmou</h3>
                <p>A confirmacao sai na hora e o lembrete vai antes do atendimento.</p>
            </div>
            <div class="passo">
                <span class="passo-numero">4</span>
                <h3>Todo mundo acompanha</h3>
                <p>Cliente, profissional e administrador veem a mesma agenda, cada um no seu painel.</p>
            </div>
        </div>
    </div>
</section>

<?php /* Argumentos para o cliente final. */ ?>
<section class="secao secao-clara" id="para-quem-marca">
    <div class="container">
        <div class="secao-titulo">
            <h2>Para quem marca</h2>
            <p>Sem ligar, sem esperar resposta, sem esquecer.</p>
        </div>
        <div class="grade-argumentos">
            <article class="argumento">
                <span class="argumento-marca" aria-hidden="true"></span>
                <h3>Marca em um minuto</h3>
                <p>Escolhe o servico, ve os horarios livres e pronto. Funciona no celular.</p>
            </article>
            <article class="argumento">
                <span class="argumento-marca" aria-hidden="true"></span>
                <h3>Confirmacao na hora</h3>
                <p>O horario confirmado aparece no painel e o lembrete chega antes.</p>
            </article>
            <article class="argumento">
                <span class="argumento-marca" aria-hidden="true"></span>
                <h3>Um e-mail, todas as contas</h3>
                <p>Entra com o mesmo e-mail em qualquer empresa que use o Agendei.</p>
            </article>
        </div>
    </div>
</section>

<?php /* Recursos opcionais, para mostrar ate onde o sistema vai. */ ?>
<section class="secao" id="recursos">
    <div class="container">
        <div class="secao-titulo">
            <h2>Recursos que entram quando fizer sentido</h2>
            <p>Cada empresa liga o que precisa. O que nao esta ligado nao aparece para o cliente.</p>
        </div>
        <ul class="grade-recursos">
            <?php foreach ($recursos as [$nome, $descricao]): ?>
                <li class="recurso">
                    <strong><?= e($nome) ?></strong>
                    <span><?= e($descricao) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="chamada-final margem-topo">
            <div>
                <h2><?= e(MARCA_SLOGAN) ?></h2>
                <p>Cadastre sua empresa em dois minutos. A gente confere e libera o acesso.</p>
            </div>
            <div class="chamada-acoes">
                <a href="<?= url('cadastro_empresa.php') ?>" class="btn btn-grande">Cadastrar minha empresa</a>
                <a href="<?= url('entrar.php') ?>" class="btn btn-grande btn-vazado">Ja tenho conta</a>
            </div>
        </div>
    </div>
</section>

</main>

<?php /* Rodape do produto. */ ?>
<footer class="rodape-site rodape-produto">
    <div class="container">
        <div class="rodape-colunas">
            <div>
                <?= marcaSistema(28, true) ?>
                <p class="rodape-slogan"><?= e(MARCA_SLOGAN) ?></p>
                <p>Agendamento online para quem atende e para quem marca.</p>
            </div>
            <div>
                <h4>Acesso</h4>
                <ul class="rodape-lista">
                    <li><a href="<?= url('cadastro_empresa.php') ?>">Cadastrar empresa</a></li>
                    <li><a href="<?= url('entrar.php') ?>">Entrar</a></li>
                    <li><a href="<?= url('master/login.php') ?>">Area master</a></li>
                </ul>
            </div>
            <div>
                <h4>Conheca</h4>
                <ul class="rodape-lista">
                    <li><a href="#para-quem-atende">Para quem atende</a></li>
                    <li><a href="#como-funciona">Como funciona</a></li>
                    <li><a href="#para-quem-marca">Para quem marca</a></li>
                    <li><a href="#recursos">Recursos</a></li>
                </ul>
            </div>
        </div>
        <div class="rodape-base">
            <span>&copy; <?= date('Y') ?> <?= e(NOME_SISTEMA) ?>. Todos os direitos reservados.</span>
            <span class="rodape-credito">Sistema de agendamento <?= marcaSistema(18, true, 'marca-sistema-discreta') ?></span>
        </div>
    </div>
</footer>

<div id="notificacoes"></div>
<script src="<?= url('assets/js/main.js') ?>"></script>
</body>
</html>
