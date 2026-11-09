<?php
/**
 * Documentos legais do Agendei: Política de Privacidade, Termos de Uso,
 * Política de Cookies e Cancelamento e reembolso.
 *
 * Página pública, aberta com ou sem login. Os prazos vêm das constantes do
 * código (Legal, Assinatura, Agendamento), para o texto nunca contradizer o
 * que o sistema faz. Os dados do fornecedor vêm das variáveis de ambiente
 * (Legal::fornecedor).
 */
define('ENTRADA_LOCAL', true);
define('PAGINA_PRODUTO', true);
require_once __DIR__ . '/config/config.php';

$documentos = [
    'privacidade' => 'Política de Privacidade',
    'termos'      => 'Termos de Uso',
    'cookies'     => 'Política de Cookies',
    'reembolso'   => 'Cancelamento e reembolso',
];
$pagina = array_key_exists(get('pagina'), $documentos) ? get('pagina') : 'privacidade';

$fornecedor = Legal::fornecedor();
$nome       = e(Legal::campo('nome'));
$documento  = e(Legal::campo('documento'));
$endereco   = e(Legal::campo('endereco'));
$contato    = e(Legal::campo('email'));
$dpoNome    = e($fornecedor['dpo_nome'] !== '' ? $fornecedor['dpo_nome'] : 'o próprio fornecedor');
$dpoEmail   = e(Legal::campo('dpo_email'));
$versao     = formatarData(Legal::VERSAO_TERMOS);
$minutos    = Agendamento::minutosSemPerda();
// Precos vigentes dos planos, como a empresa ve na tela Assinatura.
$planosTexto = implode(', ', array_map(fn($p) => $p['nome'] . ' ' . formatarMoeda($p['preco_mensal']), Plano::contrataveis())) ?: formatarMoeda(Assinatura::VALOR_MENSAL);

$tituloPagina = $documentos[$pagina] . ' | ' . NOME_SISTEMA;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= e($documentos[$pagina]) ?> do Agendei.">
    <title><?= e($tituloPagina) ?></title>
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/login.css') ?>">
    <?php require RAIZ . '/includes/tema.php'; ?>
</head>
<body class="pagina-autenticacao">

<main class="pagina-legal">
    <a href="<?= e(urlInicio()) ?>" class="voltar-site">&larr; Voltar ao início</a>
    <?= marcaSistema(28) ?>

    <nav class="abas-legais" aria-label="Documentos legais">
        <?php foreach ($documentos as $chave => $rotulo): ?>
            <a href="<?= url('legal.php?pagina=' . $chave) ?>" <?= $chave === $pagina ? 'aria-current="page"' : '' ?>><?= e($rotulo) ?></a>
        <?php endforeach; ?>
    </nav>

    <article class="texto-legal">
        <h1><?= e($documentos[$pagina]) ?></h1>
        <p class="texto-secundario">Versão de <?= e($versao) ?>.</p>

<?php if ($pagina === 'privacidade'): ?>
        <h2>1. Quem trata os seus dados</h2>
        <p>O Agendei é fornecido por <strong><?= $nome ?></strong>, <?= $documento ?>, <?= $endereco ?>. Contato: <?= $contato ?>.</p>
        <p>Os dados dos <strong>clientes</strong> são tratados pelo Agendei em nome de cada estabelecimento: o estabelecimento em que você marca horário é o controlador desses dados e o Agendei é o operador. Os dados das <strong>empresas e de quem as administra</strong> são tratados pelo Agendei como controlador.</p>

        <h2>2. Que dados coletamos e para quê</h2>
        <table class="tabela tabela-legal">
            <thead><tr><th>Dado</th><th>Para quê</th><th>Base legal (LGPD, art. 7º)</th></tr></thead>
            <tbody>
                <tr><td>Nome, e-mail, celular, CPF, usuário e senha (guardada só como hash)</td><td>Criar e identificar a sua conta e evitar contas duplicadas</td><td>Execução de contrato (V)</td></tr>
                <tr><td>Data de nascimento, nome materno e CEP</td><td>Verificação em duas etapas, quando você não usa um aplicativo autenticador</td><td>Legítimo interesse na segurança da conta (IX)</td></tr>
                <tr><td>Endereço e telefone fixo (opcional)</td><td>Cadastro pedido pelo estabelecimento</td><td>Execução de contrato (V)</td></tr>
                <tr><td>Agendamentos, observações, sinal e pagamentos</td><td>Marcar, lembrar, cancelar e cobrar os atendimentos</td><td>Execução de contrato (V)</td></tr>
                <tr><td>Endereço IP, navegador, data e resultado de cada entrada</td><td>Proteger contra acesso indevido e investigar incidentes</td><td>Legítimo interesse (IX) e Marco Civil da Internet, art. 15</td></tr>
                <tr><td>Celular e e-mail para lembretes</td><td>Lembrar e confirmar o horário pelo WhatsApp ou e-mail</td><td>Execução de contrato (V)</td></tr>
            </tbody>
        </table>
        <p>Coletamos só o que o serviço usa. Não vendemos dados, não fazemos publicidade com eles e não usamos inteligência artificial para tomar decisões sobre você.</p>

        <h2>3. Com quem compartilhamos</h2>
        <p>Só com quem precisa para o serviço funcionar, sempre como operador: a hospedagem do sistema e do banco de dados, o envio de e-mail, o envio de WhatsApp e, se você escolher entrar com o Google, o Google. O estabelecimento em que você agenda vê os seus dados de cadastro e os seus agendamentos com ele, e nada de outros estabelecimentos.</p>

        <h2>4. Por quanto tempo guardamos</h2>
        <ul>
            <li>Dados da conta: enquanto ela existir. Ao encerrar a conta, os dados de identificação são anonimizados.</li>
            <li>Registros de acesso: <?= Legal::MESES_LOG_ACESSO ?> meses.</li>
            <li>Lembretes e notificações enviados: <?= Legal::MESES_NOTIFICACOES ?> meses.</li>
            <li>Auditoria das ações administrativas: <?= Legal::ANOS_AUDITORIA ?> anos.</li>
            <li>Registros financeiros dos atendimentos: pelo prazo exigido pela legislação fiscal, sem identificar quem encerrou a conta.</li>
        </ul>
        <p>Depois desses prazos os dados são apagados automaticamente.</p>

        <h2>5. Seus direitos</h2>
        <p>Pela LGPD (art. 18) você pode confirmar que tratamos seus dados, acessá-los, corrigi-los, pedir a portabilidade, a anonimização ou a eliminação, saber com quem foram compartilhados e revogar um consentimento. No painel do cliente, em <strong>Privacidade</strong>, você baixa uma cópia dos seus dados e encerra a conta sozinho; em <strong>Meu perfil</strong> corrige o cadastro. Para o resto, fale com o encarregado.</p>

        <h2>6. Encarregado de dados (DPO)</h2>
        <p>O encarregado é <?= $dpoNome ?>, pelo e-mail <?= $dpoEmail ?>. Respondemos em até 15 dias. Você também pode reclamar à Autoridade Nacional de Proteção de Dados (ANPD).</p>

        <h2>7. Segurança</h2>
        <p>Usamos conexão criptografada (HTTPS), senhas guardadas só como hash, verificação em duas etapas, limite de tentativas de acesso, proteção contra os ataques mais comuns (como injeção de SQL, XSS e CSRF) e cópias de segurança do banco. Se houver um incidente que possa causar risco ou dano relevante, avisamos você e a ANPD.</p>

<?php elseif ($pagina === 'termos'): ?>
        <h2>1. O serviço</h2>
        <p>O Agendei é um sistema de agendamento on-line fornecido por <strong><?= $nome ?></strong>, <?= $documento ?>. Estabelecimentos o usam para organizar serviços, equipe e horários; clientes o usam para marcar, acompanhar e cancelar atendimentos.</p>

        <h2>2. A sua conta</h2>
        <p>Você responde pelos dados que informa e pela guarda da sua senha. Não compartilhe a conta. Se perceber um acesso que não foi seu, troque a senha e avise o estabelecimento ou o suporte.</p>

        <h2>3. Agendamentos, sinal e cancelamento</h2>
        <p>O horário só é confirmado se o sistema o mostrar como livre. O estabelecimento pode cobrar um sinal no agendamento. Você pode cancelar pelo painel até o horário começar:</p>
        <ul>
            <li>cancelando até <?= (int) $minutos ?> minutos antes, o sinal pago vira crédito para o seu próximo agendamento;</li>
            <li>depois disso, ou se o sinal já tiver vindo de um cancelamento anterior, o sinal fica com o estabelecimento, para cobrir o horário reservado;</li>
            <li>se quem cancelar for o estabelecimento, o sinal volta para você como crédito.</li>
        </ul>
        <p>O atendimento em si, o preço e a qualidade do serviço são de responsabilidade do estabelecimento.</p>

        <h2>4. Estabelecimentos</h2>
        <p>A empresa ganha <?= Assinatura::DIAS_DEMO ?> dias de demonstração gratuita. Depois, a assinatura é mensal, sem fidelidade, pelo preço do plano escolhido (<?= e($planosTexto) ?> por mês). Trocar de plano muda os benefícios na hora e o preço a partir do próximo pagamento. O estabelecimento é o controlador dos dados dos seus clientes e se compromete a usá-los só para os atendimentos. Veja <a href="<?= url('legal.php?pagina=reembolso') ?>">Cancelamento e reembolso</a>.</p>

        <h2>5. Uso permitido</h2>
        <p>Não é permitido usar o Agendei para atividades ilegais, enviar conteúdo ofensivo, tentar acessar contas ou dados de terceiros, ou sobrecarregar o sistema. Contas que fizerem isso podem ser suspensas.</p>

        <h2>6. Disponibilidade e responsabilidade</h2>
        <p>Trabalhamos para o sistema ficar sempre no ar, mas pode haver interrupções para manutenção ou por falhas de terceiros. Fazemos cópias de segurança do banco de dados. Não respondemos por danos causados por uso indevido da conta pelo próprio titular.</p>

        <h2>7. Privacidade</h2>
        <p>O tratamento dos dados segue a <a href="<?= url('legal.php?pagina=privacidade') ?>">Política de Privacidade</a> e a <a href="<?= url('legal.php?pagina=cookies') ?>">Política de Cookies</a>.</p>

        <h2>8. Mudanças nestes termos</h2>
        <p>Se estes termos mudarem, a data da versão muda junto e avisamos no sistema. O aceite fica registrado na sua conta com a data e a versão.</p>

        <h2>9. Contato e foro</h2>
        <p>Dúvidas: <?= $contato ?>. Fica eleito o foro do domicílio do consumidor, conforme o Código de Defesa do Consumidor.</p>

<?php elseif ($pagina === 'cookies'): ?>
        <h2>1. O que usamos</h2>
        <p>O Agendei usa só cookies <strong>essenciais</strong>, sem os quais a entrada no sistema não funciona. Não usamos cookies de publicidade, de rastreamento nem de análise de terceiros, por isso não pedimos consentimento para eles (LGPD, art. 7º, V e IX).</p>
        <table class="tabela tabela-legal">
            <thead><tr><th>Cookie</th><th>Para quê</th><th>Duração</th></tr></thead>
            <tbody>
                <tr><td><code>AGENDEI_SESSAO</code></td><td>Manter você conectado enquanto usa o sistema e proteger os formulários (CSRF)</td><td>Até sair ou a sessão expirar</td></tr>
                <tr><td><code>AGENDEI_LEMBRAR</code></td><td>"Manter conectado" neste dispositivo, só se você marcar a opção</td><td>Até sair ou o prazo do dispositivo lembrado acabar</td></tr>
            </tbody>
        </table>

        <h2>2. Armazenamento no navegador</h2>
        <p>As preferências de acessibilidade (tamanho da fonte e alto contraste) e o aviso de cookies dispensado ficam guardados no próprio navegador, e não são enviados para nós.</p>

        <h2>3. Como apagar</h2>
        <p>Você pode apagar os cookies nas configurações do navegador. Sem o cookie de sessão, será preciso entrar de novo.</p>

<?php else: ?>
        <h2>1. Assinatura dos estabelecimentos</h2>
        <ul>
            <li><strong>Demonstração:</strong> <?= Assinatura::DIAS_DEMO ?> dias grátis. Cancelar nesse período não gera cobrança.</li>
            <li><strong>Arrependimento:</strong> cancelando até <?= Assinatura::DIAS_ARREPENDIMENTO ?> dias depois do pagamento, o valor pago volta integralmente, pela mesma forma de pagamento (Código de Defesa do Consumidor, art. 49).</li>
            <li><strong>Depois disso:</strong> não há multa nem fidelidade. O acesso continua até o fim do período de <?= Assinatura::DIAS_PERIODO ?> dias já pago e a assinatura não é renovada. O mês em andamento não é reembolsado.</li>
            <li>O cancelamento é feito pelo próprio administrador, em <strong>Assinatura</strong>, no painel. Os dados da empresa continuam guardados e ela pode assinar de novo depois.</li>
        </ul>

        <h2>2. Sinal dos agendamentos</h2>
        <ul>
            <li>Cancelando até <?= (int) $minutos ?> minutos antes do horário, o sinal pago vira crédito e paga o sinal do próximo agendamento.</li>
            <li>Cancelando depois disso, não comparecendo, ou cancelando de novo um agendamento que foi pago com crédito, o sinal fica com o estabelecimento.</li>
            <li>Se o estabelecimento cancelar, o sinal volta para o cliente como crédito.</li>
            <li>A devolução do sinal em dinheiro, quando houver, é combinada diretamente com o estabelecimento, que recebeu o valor.</li>
        </ul>

        <h2>3. Preços</h2>
        <p>O preço de cada serviço e o valor do sinal aparecem antes de você confirmar o agendamento. Os planos e seus preços (<?= e($planosTexto) ?> por mês) aparecem antes da contratação, na tela Assinatura. Não há taxas escondidas.</p>

        <h2>4. Contato</h2>
        <p>Pedidos de reembolso da assinatura: <?= $contato ?>.</p>
<?php endif; ?>
    </article>
</main>

<?php require RAIZ . '/includes/assinatura_sistema.php'; ?>

<div id="notificacoes"></div>
<script src="<?= url('assets/js/main.js') ?>"></script>
</body>
</html>
