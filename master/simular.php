<?php
/**
 * Entrada temporaria do master no painel de um estabelecimento.
 *
 * Existe para o suporte: sem isso, a unica forma de o master ver o que o
 * cliente esta vendo era redefinir a senha do administrador local — tirar o
 * acesso de quem pediu ajuda para poder ajudar.
 *
 * A sessao passa a ser a do administrador, com duas diferencas que ficam de pe
 * o tempo todo: o aviso no topo de todas as telas e o registro na auditoria,
 * na entrada e na saida. O "ultimo acesso" da conta nao e tocado.
 */
define('AREA_MASTER', true);
require_once __DIR__ . '/../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirecionar('master/estabelecimentos.php');
}

exigirCsrf();
$acao = post('acao');

// -------------------------------------------------------------------------
// Volta para a conta master
//
// Nao pode exigir perfil master: quem chama e a sessao do administrador
// simulado. A autorizacao vem da marca gravada na sessao, que so o servidor
// escreve, e o destino vem de la — nunca do formulario.
// -------------------------------------------------------------------------
if ($acao === 'sair') {
    if (!ehSimulacao()) {
        redirecionar(painelDe(perfil()));
    }

    $simulacao = simulacaoAtual();

    // Este arquivo declara AREA_MASTER, entao o Contexto aponta para a
    // identidade da administracao, nao para a empresa simulada: o nome dela
    // precisa vir do vinculo guardado na sessao.
    $idEmpresaSimulada = (int) ($_SESSION['estabelecimento_id'] ?? 0);
    $empresa = $idEmpresaSimulada > 0 ? Estabelecimento::porIdGlobal($idEmpresaSimulada) : null;
    $contexto = [
        'estabelecimento'      => $idEmpresaSimulada,
        'estabelecimento_nome' => (string) ($empresa['nome'] ?? ''),
        'alvo'                 => (string) ($_SESSION['usuario_email'] ?? ''),
        'detalhe'              => 'simulacao de ' . duracaoTexto((int) ceil((time() - (int) $simulacao['inicio']) / 60)),
    ];

    if (encerrarSimulacao() === null) {
        // A conta master foi desativada durante a simulacao: nao ha para onde
        // voltar, e manter a sessao do administrador aberta seria pior.
        encerrarSessao();
        iniciarSessao();
        definirFlash('aviso', 'A conta master que abriu esta simulacao nao esta mais ativa. Entre novamente.');
        redirecionar('master/login.php');
    }

    LogMaster::registrar('simulacao_fim', $contexto);
    definirFlash('sucesso', 'Voce voltou para a administracao master.');
    redirecionar('master/estabelecimentos.php');
}

// -------------------------------------------------------------------------
// Entra no painel do estabelecimento
// -------------------------------------------------------------------------
exigirLogin('master');

$idEmpresa = (int) post('id_estabelecimento');
$idUsuario = (int) post('id_usuario');

$master  = Master::porId((int) $_SESSION['master_id']);
$empresa = Estabelecimento::porIdGlobal($idEmpresa);
$usuario = $empresa ? Estabelecimento::administrador($idEmpresa, $idUsuario) : null;

// Confirmar a senha master de novo: a simulacao da acesso aos dados pessoais
// dos clientes daquela empresa, e uma sessao deixada aberta nao pode bastar.
if (!$master || !Master::senhaConfere((int) $master['id_master'], post('senha_master'))) {
    definirFlash('erro', 'Confirme a sua senha master para entrar no painel do estabelecimento.');
    redirecionar('master/estabelecimentos.php?acao=ver&id=' . $idEmpresa);
}

if (!$empresa || !$usuario) {
    definirFlash('erro', 'Conta administrativa nao encontrada neste estabelecimento.');
    redirecionar('master/estabelecimentos.php');
}

// O painel do estabelecimento so abre com a empresa e a conta ativas: o
// Contexto recusa empresa inativa, e entrar como conta desligada mostraria uma
// tela que o proprio responsavel nao consegue abrir.
if ($empresa['status'] !== 'ativo' || $usuario['status'] !== 'ativo') {
    definirFlash('erro', 'Ative o estabelecimento e a conta administrativa antes de entrar no painel.');
    redirecionar('master/estabelecimentos.php?acao=ver&id=' . $idEmpresa);
}

LogMaster::registrar('simulacao_inicio', [
    'estabelecimento'      => $idEmpresa,
    'estabelecimento_nome' => $empresa['nome'],
    'alvo'                 => $usuario['email'],
    'detalhe'              => 'suporte pelo painel do estabelecimento',
]);

iniciarSimulacao($usuario, $master, Estabelecimento::idAdministrador($idEmpresa, $idUsuario));

definirFlash('aviso', 'Voce esta no painel de ' . $empresa['nome'] . ' como ' . $usuario['nome'] . '.');
redirecionar('admin/dashboard.php');
