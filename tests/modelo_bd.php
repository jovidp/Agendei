<?php
/** Confere cobertura do DER e as cardinalidades que costumam ser confundidas. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/models/ModeloBanco.php';
$total = 0;
function conferir(bool $condicao, string $mensagem): void
{
    global $total;
    $total++;
    if (!$condicao) throw new RuntimeException($mensagem);
}
$modelos = [];
foreach (['mysql', 'pgsql'] as $dialeto) {
    $modelo = ModeloBanco::carregar($dialeto);
    $modelos[$dialeto] = $modelo;
    $sql = file_get_contents(dirname(__DIR__) . '/' . $modelo['arquivo']);
    preg_match_all('/^CREATE TABLE `?(\w+)`?/m', $sql, $nomes);
    conferir(array_keys($modelo['tabelas']) === $nomes[1], "$dialeto: todas as tabelas do SQL");
    conferir(count($nomes[1]) === 25, "$dialeto: inventário atual de 25 tabelas");
    conferir(count(ModeloBanco::relacoes($modelo)) === substr_count($sql, 'FOREIGN KEY'), "$dialeto: nenhuma FK perdida");
    $mermaid = ModeloBanco::mermaid($modelo);
    foreach ($modelo['tabelas'] as $nome => $tabela) {
        // Conta declarações de tipos no DDL por uma expressão independente do leitor.
        preg_match_all('/^\s+`?\w+`?\s+(?:int|integer|bigint|smallint|tinyint|varchar|char|text|mediumtext|date|datetime|timestamp|time|decimal|numeric|enum)\b/im', $tabela['ddl'], $colunas);
        conferir(count($tabela['colunas']) === count($colunas[0]), "$dialeto/$nome: todas as colunas");
        conferir(count($tabela['uk']) === preg_match_all('/\bUNIQUE(?: KEY)?\b/i', $tabela['ddl']), "$dialeto/$nome: todas as UKs");
        conferir(str_contains($mermaid, "    $nome {"), "$dialeto/$nome: entidade no Mermaid");
        foreach ($tabela['pk'] as $pk) conferir(!$tabela['colunas'][$pk]['nulo'], "$dialeto/$nome: PK obrigatória");
        foreach ($tabela['fks'] as $fk) {
            conferir(isset($modelo['tabelas'][$fk['pai']]), "$dialeto: destino de FK existe");
            foreach ($fk['campos'] as $i => $campo) {
                conferir(isset($tabela['colunas'][$campo], $modelo['tabelas'][$fk['pai']]['colunas'][$fk['referencias'][$i]]), "$dialeto: colunas de FK existem");
            }
        }
    }
    $tabelas = $modelo['tabelas'];
    foreach (['administradores_master', 'logs_master', 'tentativas_acesso', 'planos'] as $nome) conferir($tabelas[$nome]['fks'] === [], "$dialeto/$nome: sem FKs de saída");
    foreach (['id_cliente_pacote' => 'agendamentos', 'id_usuario' => 'logs_autenticacao', 'id_master' => 'logs_master'] as $campo => $nome) {
        conferir(!in_array('FK', $tabelas[$nome]['colunas'][$campo]['chaves'], true), "$dialeto/$nome/$campo: referência sem FK");
    }
    $esperadas = [
        'clientes:usuarios' => ['1', '0..1'],
        'assinaturas:estabelecimento' => ['1', '0..1'],
        'estabelecimento_plano:estabelecimento' => ['1', '0..1'],
        'estabelecimento_plano:planos' => ['1', '0..N'],
        'agendamentos:servicos' => ['1', '0..N'],
        'agendamentos:profissionais' => ['1', '0..N'],
        'agendamentos:usuarios' => ['0..1', '0..N'],
        'lista_espera:profissionais' => ['0..1', '0..N'],
        'avaliacoes:agendamentos' => ['1', '0..1'],
        'fidelidade_movimentos:agendamentos' => ['0..1', '0..1'],
    ];
    foreach (ModeloBanco::relacoes($modelo, true) as $fk) {
        $chave = $fk['filha'] . ':' . $fk['pai'];
        if (isset($esperadas[$chave])) {
            conferir([$fk['por_filha'], $fk['por_pai']] === $esperadas[$chave], "$dialeto/$chave: cardinalidade");
            unset($esperadas[$chave]);
        }
    }
    conferir($esperadas === [], "$dialeto: relações esperadas presentes");
    $arquivo = dirname(__DIR__) . '/docs/der_' . ($dialeto === 'pgsql' ? 'postgres' : 'mysql') . '.mmd';
    conferir(file_get_contents($arquivo) === $mermaid, "$dialeto: documento sincronizado");
}
foreach ($modelos['mysql']['tabelas'] as $nome => $tabela) {
    conferir(array_keys($tabela['colunas']) === array_keys($modelos['pgsql']['tabelas'][$nome]['colunas']), "$nome: colunas equivalentes entre dialetos");
}
echo "$total verificações do DER passaram, sem acessar ou alterar o banco.\n";
