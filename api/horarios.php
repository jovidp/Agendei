<?php
/**
 * API: horarios livres de um profissional para um servico em uma data.
 * GET /api/horarios.php?id_profissional=1&id_servico=2&data=2026-09-10
 *
 * A lista aqui e apenas para a interface. O PHP valida tudo novamente
 * no momento de gravar o agendamento.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/../config/config.php';

// Exige uma sessão autenticada para consultar os dados desta API.
exigirLogin();

// Lê o profissional, serviço, data e eventual reserva a desconsiderar no cálculo.
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

// Centraliza o cálculo de vagas na mesma classe usada pelas regras de agendamento.
$horarios = Disponibilidade::slots($idProfissional, $idServico, $data, $opcoes);

jsonResposta([
    'sucesso'  => true,
    'data'     => $data,
    'horarios' => $horarios,
]);
