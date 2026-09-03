<?php
/**
 * API: horarios livres de um profissional para um servico em uma data.
 * GET /api/horarios.php?id_profissional=1&id_servico=2&data=2026-09-10
 *
 * A lista aqui e apenas para a interface. O PHP valida tudo novamente
 * no momento de gravar o agendamento.
 */
require_once __DIR__ . '/../config/config.php';

exigirLogin();

$idProfissional = (int) get('id_profissional');
$idServico      = (int) get('id_servico');
$data           = get('data');
$ignorar        = (int) get('ignorar_agendamento');

if ($idProfissional <= 0 || $idServico <= 0 || !validarData($data)) {
    jsonResposta(['sucesso' => false, 'mensagem' => 'Parametros invalidos.', 'horarios' => []], 400);
}

// Administradores e profissionais podem encaixar horarios sem a antecedencia minima.
$opcoes = [
    'ignorar_antecedencia' => ehAdmin() || ehProfissional(),
    'ignorar_agendamento'  => $ignorar > 0 ? $ignorar : null,
];

$horarios = Disponibilidade::slots($idProfissional, $idServico, $data, $opcoes);

jsonResposta([
    'sucesso'  => true,
    'data'     => $data,
    'horarios' => $horarios,
]);
