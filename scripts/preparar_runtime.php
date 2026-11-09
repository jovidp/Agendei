<?php
/** Prepara tabelas auxiliares uma vez na inicializacao do container. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/config/database.php';
spl_autoload_register(static function (string $classe): void {
    $arquivo = dirname(__DIR__) . '/models/' . $classe . '.php';
    if (is_file($arquivo)) require_once $arquivo;
});
try {
    $db = bd();
    foreach ([Tentativa::class, LogMaster::class, Plano::class, Solicitacao::class, Bot::class] as $classe) {
        foreach ($classe::ddl() as $comando) $db->exec($comando);
    }
    Assinatura::garantirEstrutura();
    // Se falhar, o site sobe mesmo assim: o sinal, o aceite e os planos novos ficam sem atualizar (o erro vai ao log).
    try {
        Diferencial::garantirStatusSinal();
        Legal::garantirEstrutura();
        Plano::garantirEstrutura();
        VerificacaoEmail::garantirEstrutura();
    } catch (Throwable $erroSinal) {
        fwrite(STDERR, 'Aviso: estrutura do sinal e do aceite não atualizada: ' . $erroSinal->getMessage() . "\n");
    }
    echo "Estrutura auxiliar pronta para atender requisições.\n";
} catch (Throwable $erro) {
    fwrite(STDERR, 'Falha ao preparar estrutura: ' . $erro->getMessage() . "\n");
    exit(1);
}
