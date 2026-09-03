<?php
/**
 * API: profissionais ativos que executam um servico.
 * GET /api/profissionais.php?id_servico=1
 */
require_once __DIR__ . '/../config/config.php';

exigirLogin();

$idServico = (int) get('id_servico');

if ($idServico <= 0 || !Servico::estaAtivo($idServico)) {
    jsonResposta(['sucesso' => false, 'mensagem' => 'Servico invalido ou indisponivel.', 'profissionais' => []], 400);
}

$lista = [];

foreach (Profissional::porServico($idServico) as $profissional) {
    $lista[] = [
        'id_profissional' => (int) $profissional['id_profissional'],
        'nome'            => $profissional['nome'],
        'especialidade'   => $profissional['especialidade'],
        'iniciais'        => iniciais($profissional['nome']),
    ];
}

jsonResposta(['sucesso' => true, 'profissionais' => $lista]);
