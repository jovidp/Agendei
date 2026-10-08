<?php

/**
 * API: profissionais ativos que executam um servico.
 * GET /api/profissionais.php?id_servico=1
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/../config/config.php';

// Exige uma sessão autenticada para consultar os dados desta API.
exigirLogin();

$idServico = (int) get('id_servico');
$idFilial  = (int) get('id_filial');

if ($idServico <= 0 || !Servico::estaAtivo($idServico)) {
    jsonResposta(['sucesso' => false, 'mensagem' => 'Servico invalido ou indisponivel.', 'profissionais' => []], 400);
}

// Com a unidade escolhida, so entram os profissionais dela; sem ela, todos que fazem o servico.
$profissionais = $idFilial > 0
    ? Profissional::porServicoEFilial($idServico, $idFilial)
    : Profissional::porServico($idServico);

$lista = [];

// Devolve somente os campos necessários à escolha do profissional na interface.
foreach ($profissionais as $profissional) {
    $lista[] = [
        'id_profissional' => (int) $profissional['id_profissional'],
        'nome'            => $profissional['nome'],
        'especialidade'   => $profissional['especialidade'],
        'iniciais'        => iniciais($profissional['nome']),
    ];
}

jsonResposta(['sucesso' => true, 'profissionais' => $lista]);
