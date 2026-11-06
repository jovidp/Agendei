<?php

/**
 * API: dias de um mes com pelo menos um horario livre.
 * GET /api/dias.php?id_profissional=1&id_servico=2&mes=2026-09
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/../config/config.php';

// Exige uma sessão autenticada para consultar os dados desta API.
exigirLogin();

// Lê os IDs e o mês da URL antes de validar os parâmetros da consulta.
$idProfissional = (int) get('id_profissional');
$idServico      = (int) get('id_servico');
$mes            = get('mes', date('Y-m'));

if ($idProfissional <= 0 || $idServico <= 0 || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
    jsonResposta(['sucesso' => false, 'mensagem' => 'Parâmetros inválidos.', 'dias' => []], 400);
}

// Permite a consulta interna da equipe sem os limites de antecedência aplicados ao cliente.
$ignorarAntecedencia = ehAdmin() || ehProfissional();

$primeiroDoMes = $mes . '-01';
$ultimoDoMes   = date('Y-m-t', strtotime($primeiroDoMes));

// Nunca antes de hoje nem depois do limite configurado.
$inicio = max($primeiroDoMes, date('Y-m-d'));

$maximoDias = Configuracao::obterInteiro('antecedencia_maxima_dias', 60);
$limite     = $maximoDias > 0 && !$ignorarAntecedencia
    ? date('Y-m-d', strtotime("+{$maximoDias} days"))
    : $ultimoDoMes;

$fim = min($ultimoDoMes, $limite);

// O mes inteiro sai de poucas consultas (ver Disponibilidade::diasComVagas).
$dias = $inicio <= $fim
    ? Disponibilidade::diasComVagas($idProfissional, $idServico, $inicio, $fim, [
        'ignorar_antecedencia' => $ignorarAntecedencia,
    ])
    : [];

jsonResposta(['sucesso' => true, 'mes' => $mes, 'dias' => $dias]);
