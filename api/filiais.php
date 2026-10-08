<?php

/**
 * API: filiais ativas que oferecem um servico.
 * GET /api/filiais.php?id_servico=1
 *
 * E a etapa "onde voce quer ir?" do agendamento: depois de escolher o servico,
 * o cliente ve so as unidades com um profissional ativo que o executa.
 */
// Carrega as configuracoes, a sessao e as funcoes compartilhadas antes de processar a pagina.
require_once __DIR__ . '/../config/config.php';

// Exige uma sessao autenticada para consultar os dados desta API.
exigirLogin();

$idServico = (int) get('id_servico');

if ($idServico <= 0 || !Servico::estaAtivo($idServico)) {
    jsonResposta(['sucesso' => false, 'mensagem' => 'Servico invalido ou indisponivel.', 'filiais' => []], 400);
}

$lista = [];

// Devolve so o que a escolha da unidade precisa mostrar (a foto ja vem como data URI).
foreach (Filial::porServico($idServico) as $filial) {
    $lista[] = [
        'id_filial' => (int) $filial['id_filial'],
        'nome'      => $filial['nome'],
        'endereco'  => Filial::enderecoResumido($filial),
        'telefone'  => $filial['telefone'],
        'foto'      => $filial['foto'],
    ];
}

jsonResposta(['sucesso' => true, 'filiais' => $lista]);
