<?php

/**
 * Camada de seguranca da aplicacao.
 *
 * Reune o que precisa valer em toda requisicao, sem depender de cada pagina
 * lembrar de chamar: cabecalhos de defesa do navegador, validade da sessao,
 * identificacao da origem e o freio contra forca bruta nos fluxos de acesso.
 *
 * O arquivo e carregado por config/config.php antes da sessao abrir, porque
 * parte das protecoes (modo estrito de sessao, cookies) so tem efeito se for
 * configurada antes de session_start().
 */

// ---------------------------------------------------------------------
// Politica de limites
// ---------------------------------------------------------------------

/**
 * Regras de cada fluxo protegido.
 *
 * 'limite'  falhas toleradas dentro da janela
 * 'janela'  segundos observados para tras
 * 'espera'  segundos de bloqueio contados a partir da primeira falha da janela
 *
 * As contas do sistema usam senha de oito letras (regra da especificacao do
 * projeto), o que da um espaco de busca pequeno. Por isso os limites por conta
 * sao mais apertados do que o usual: sem eles, um dicionario simples derruba
 * uma senha dessas em minutos.
 */
function politicaLimites(): array
{
    return [
        // Por conta: segura o ataque de dicionario contra um alvo especifico.
        'login_conta'    => ['limite' => 8,  'janela' => 900,  'espera' => 900],
        // Por origem: segura a varredura que testa uma senha em muitas contas.
        'login_ip'       => ['limite' => 25, 'janela' => 900,  'espera' => 1800],
        // Area master: uma unica conta, poder total, tolerancia minima.
        'master_ip'      => ['limite' => 5,  'janela' => 900,  'espera' => 1800],
        // Segundo fator: o desafio ja limita a 3 respostas; isto impede reiniciar sem fim.
        'fator_ip'       => ['limite' => 15, 'janela' => 900,  'espera' => 900],
        // Recuperacao de senha: evita usar o formulario para varrer e-mails ou inundar caixas.
        'recuperar_ip'   => ['limite' => 10, 'janela' => 3600, 'espera' => 1800],
        // Cadastro publico: freia a criacao automatizada de contas.
        'cadastro_ip'    => ['limite' => 10, 'janela' => 3600, 'espera' => 1800],
        // Cadastro de empresa pela pagina inicial: cada envio cria uma empresa
        // inativa que o master precisa avaliar, por isso a tolerancia e menor.
        'cadastro_empresa_ip' => ['limite' => 5, 'janela' => 3600, 'espera' => 3600],
        // Chave do instalador: balde proprio, para que tentativas aqui nao
        // tranquem o login master (nem o contrario).
        'instalador_ip'  => ['limite' => 5,  'janela' => 3600, 'espera' => 3600],
        // Chave do gatilho das tarefas (tarefas.php): mesma logica do instalador.
        'tarefas_ip'     => ['limite' => 5,  'janela' => 3600, 'espera' => 3600],
    ];
}

// ---------------------------------------------------------------------
// Origem da requisicao
// ---------------------------------------------------------------------

/**
 * IP do visitante, ja considerando o proxy da hospedagem.
 *
 * Render, Koyeb e Fly entregam a requisicao por um proxy, e ali o REMOTE_ADDR
 * e sempre o mesmo endereco interno: usar esse valor bloquearia todo mundo de
 * uma vez. Com AGENDEI_PROXY_CONFIAVEL ligado, vale a ultima entrada do
 * X-Forwarded-For, que e a unica que o proprio proxy escreveu (as anteriores
 * podem ter vindo forjadas pelo cliente). Sem a variavel, o cabecalho e
 * ignorado, porque ai nao ha proxy nenhum em quem confiar.
 */
function ipCliente(): string
{
    $remoto = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    if (!filter_var(getenv('AGENDEI_PROXY_CONFIAVEL') ?: '', FILTER_VALIDATE_BOOLEAN)) {
        return $remoto;
    }

    $encaminhado = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($encaminhado === '') {
        return $remoto;
    }

    $enderecos = array_map('trim', explode(',', $encaminhado));
    $ultimo = (string) end($enderecos);

    return filter_var($ultimo, FILTER_VALIDATE_IP) ? $ultimo : $remoto;
}

/** Assinatura do navegador, usada para perceber uma sessao migrando de dispositivo. */
function assinaturaCliente(): string
{
    return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

// ---------------------------------------------------------------------
// Controle de forca bruta
// ---------------------------------------------------------------------

/**
 * Segundos que ainda faltam para a chave sair do bloqueio.
 * Zero significa que a tentativa pode seguir.
 */
function tempoBloqueado(string $escopo, string $valor): int
{
    $regra = politicaLimites()[$escopo] ?? null;
    if ($regra === null) {
        return 0;
    }

    $chave = Tentativa::chave($escopo, $valor);

    if (Tentativa::contar($escopo, $chave, $regra['janela']) < $regra['limite']) {
        return 0;
    }

    $primeira = Tentativa::maisAntiga($escopo, $chave, $regra['janela']);
    if ($primeira === null) {
        return 0;
    }

    return max(0, ($primeira + $regra['espera']) - time());
}

/** Anota uma falha no escopo informado. */
function anotarFalha(string $escopo, string $valor): void
{
    Tentativa::registrar($escopo, Tentativa::chave($escopo, $valor));
}

/** Descarta o historico de falhas apos um acesso legitimo. */
function limparFalhas(string $escopo, string $valor): void
{
    Tentativa::limpar($escopo, Tentativa::chave($escopo, $valor));
}

/** Mensagem apresentada ao usuario enquanto o bloqueio nao vence. */
function mensagemBloqueio(int $segundos): string
{
    $minutos = (int) ceil($segundos / 60);

    return 'Muitas tentativas seguidas. Aguarde '
        . ($minutos <= 1 ? 'um minuto' : $minutos . ' minutos')
        . ' antes de tentar novamente.';
}

/**
 * Verifica os escopos informados e interrompe a pagina se algum estiver bloqueado.
 * Recebe pares escopo => valor observado, ex.: ['login_ip' => ipCliente()].
 *
 * Devolve a mensagem de bloqueio para a tela exibir; quando nada esta bloqueado
 * devolve string vazia e a pagina segue normalmente.
 */
function conferirBloqueio(array $escopos): string
{
    foreach ($escopos as $escopo => $valor) {
        if ((string) $valor === '') {
            continue;
        }

        $restante = tempoBloqueado((string) $escopo, (string) $valor);
        if ($restante > 0) {
            registrarEventoSeguranca('bloqueio_ativo', ['escopo' => $escopo, 'restante' => $restante]);
            return mensagemBloqueio($restante);
        }
    }

    return '';
}

/**
 * Atraso curto e aleatorio depois de uma credencial errada.
 *
 * Nao impede um ataque distribuido, mas encarece cada tentativa e embaralha a
 * medicao de tempo que revelaria se o login existe ou nao.
 */
function atrasarResposta(): void
{
    usleep(random_int(180000, 420000));
}

// ---------------------------------------------------------------------
// Registro de eventos de seguranca
// ---------------------------------------------------------------------

/**
 * Anota no log do servidor um evento que interessa a investigacao.
 *
 * Vai para o error_log de proposito: e o unico destino que existe em qualquer
 * hospedagem, sobrevive a falha do banco e nao depende de tabela criada. O IP
 * entra mascarado no ultimo octeto, que basta para correlacionar sem guardar o
 * endereco completo de quem so errou a senha.
 */
function registrarEventoSeguranca(string $evento, array $contexto = []): void
{
    $partes = [];
    foreach ($contexto as $chave => $valor) {
        $partes[] = $chave . '=' . (is_scalar($valor) ? (string) $valor : json_encode($valor));
    }

    error_log(sprintf(
        '[seguranca] %s ip=%s rota=%s %s',
        $evento,
        mascararIp(ipCliente()),
        basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')),
        implode(' ', $partes)
    ));
}

/** Esconde a parte final do endereco antes de registrar no log. */
function mascararIp(string $ip): string
{
    if (str_contains($ip, ':')) {
        $blocos = explode(':', $ip);
        return implode(':', array_slice($blocos, 0, 3)) . ':...';
    }

    $octetos = explode('.', $ip);
    return count($octetos) === 4 ? $octetos[0] . '.' . $octetos[1] . '.' . $octetos[2] . '.x' : 'desconhecido';
}

// ---------------------------------------------------------------------
// Cabecalhos de defesa
// ---------------------------------------------------------------------

/**
 * Cabecalhos aplicados a toda resposta HTML do sistema.
 *
 * A politica de conteudo e restritiva de proposito: o projeto nao tem nenhum
 * <script> embutido nem atributo onclick, entao script-src 'self' bloqueia
 * qualquer codigo injetado sem quebrar tela nenhuma. Os estilos ainda precisam
 * de 'unsafe-inline' porque varias telas usam atributo style, e a barra de
 * tema imprime um bloco <style> com as cores da empresa.
 */
function aplicarCabecalhosSeguranca(): void
{
    if (headers_sent()) {
        return;
    }

    // Assinatura do servidor nao ajuda o usuario e ajuda quem procura versao vulneravel.
    header_remove('X-Powered-By');

    $politica = [
        "default-src 'self'",
        "script-src 'self'",
        "style-src 'self' 'unsafe-inline'",
        // A logo da empresa e guardada como data: URI pela tela de aparencia.
        "img-src 'self' data:",
        "font-src 'self'",
        // A tela de cadastro consulta o ViaCEP para preencher o endereco
        // (assets/js/cadastro.js). E o unico destino externo do sistema: qualquer
        // outro endereco que apareca numa requisicao e sinal de codigo injetado.
        "connect-src 'self' https://viacep.com.br",
        // Nada de Flash, applet ou plugin: o sistema nao usa nenhum.
        "object-src 'none'",
        // Impede que uma injecao mude a base das URLs relativas da pagina.
        "base-uri 'self'",
        // Formularios so podem postar para o proprio sistema.
        "form-action 'self'",
        // Substitui o X-Frame-Options nos navegadores atuais.
        "frame-ancestors 'none'",
        "frame-src 'none'",
        "upgrade-insecure-requests",
    ];

    header('Content-Security-Policy: ' . implode('; ', $politica));

    // Mantido para navegadores antigos que ignoram frame-ancestors.
    header('X-Frame-Options: DENY');
    // Impede o navegador de adivinhar um tipo diferente do declarado.
    header('X-Content-Type-Options: nosniff');
    // O endereco completo nao vaza para sites externos; o token de recuperacao anda na URL.
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // O sistema nao usa camera, microfone nem localizacao.
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
    // Isola a janela de conteudo de outras origens abertas pelo usuario.
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');

    // O HSTS so vale sob HTTPS; anunciar em HTTP nao faz efeito e atrapalha o ambiente local.
    if (requisicaoSegura() && AMBIENTE === 'producao') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * Impede que a pagina autenticada fique no cache do navegador ou de um proxy.
 *
 * Sem isto, o botao voltar depois do logout ainda mostra a agenda e os dados
 * pessoais do ultimo usuario — problema real em computador compartilhado e
 * exposicao desnecessaria de dado pessoal.
 */
function impedirCacheAutenticado(): void
{
    if (headers_sent()) {
        return;
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// ---------------------------------------------------------------------
// Validade da sessao
// ---------------------------------------------------------------------

/** Minutos de inatividade tolerados por perfil antes de derrubar a sessao. */
function limiteInatividade(?string $tipo): int
{
    return match ($tipo) {
        // Quem administra ve dados de todos os clientes: janela mais curta.
        'master', 'admin' => 20,
        'profissional'    => 30,
        default           => 45,
    };
}

/** Horas que uma sessao pode durar, por mais ativo que o usuario esteja. */
const SESSAO_DURACAO_MAXIMA_HORAS = 12;

/** Minutos entre duas renovacoes do identificador da sessao. */
const SESSAO_RENOVAR_ID_MINUTOS = 15;

/**
 * Confere se a sessao aberta ainda pode ser usada.
 *
 * Tres regras, cada uma cobrindo um risco diferente:
 * inatividade   -> estacao abandonada sem logout;
 * duracao total -> cookie copiado continua valendo para sempre;
 * assinatura    -> cookie usado em outro navegador que nao o de origem.
 */
function validarSessao(): void
{
    if (!estaLogado()) {
        return;
    }

    $agora = time();
    $motivo = null;

    $inicio = (int) ($_SESSION['sessao_inicio'] ?? 0);
    $visto  = (int) ($_SESSION['sessao_visto'] ?? 0);

    if ($inicio === 0 || $visto === 0) {
        // Sessao aberta antes desta protecao existir: adota os marcos agora.
        $_SESSION['sessao_inicio'] = $agora;
        $_SESSION['sessao_visto']  = $agora;
        $_SESSION['sessao_assinatura'] = assinaturaCliente();
        $_SESSION['sessao_renovada'] = $agora;
        return;
    }

    if ($agora - $visto > limiteInatividade(perfil()) * 60) {
        $motivo = 'inatividade';
    } elseif ($agora - $inicio > SESSAO_DURACAO_MAXIMA_HORAS * 3600) {
        $motivo = 'duracao_maxima';
    } elseif (!hash_equals((string) ($_SESSION['sessao_assinatura'] ?? ''), assinaturaCliente())) {
        $motivo = 'assinatura_diferente';
    }

    if ($motivo !== null) {
        registrarEventoSeguranca('sessao_encerrada', ['motivo' => $motivo, 'perfil' => (string) perfil()]);

        $master = ehMaster();
        encerrarSessao();
        iniciarSessao();

        if (ehRequisicaoAjax()) {
            jsonResposta(['sucesso' => false, 'mensagem' => 'Sessao expirada. Faca login novamente.'], 401);
        }

        definirFlash('aviso', $motivo === 'inatividade'
            ? 'Sua sessao expirou por inatividade. Entre novamente.'
            : 'Sua sessao foi encerrada por seguranca. Entre novamente.');

        redirecionar($master ? 'master/login.php' : 'login.php');
    }

    $_SESSION['sessao_visto'] = $agora;

    // Trocar o identificador de tempos em tempos reduz a janela de um cookie roubado.
    if ($agora - (int) ($_SESSION['sessao_renovada'] ?? 0) > SESSAO_RENOVAR_ID_MINUTOS * 60) {
        session_regenerate_id(true);
        $_SESSION['sessao_renovada'] = $agora;
    }
}

/** Marca os instantes de controle no momento em que a sessao autenticada nasce. */
function marcarInicioSessao(): void
{
    $agora = time();
    $_SESSION['sessao_inicio']     = $agora;
    $_SESSION['sessao_visto']      = $agora;
    $_SESSION['sessao_renovada']   = $agora;
    $_SESSION['sessao_assinatura'] = assinaturaCliente();

    // Token novo a cada login: o formulario aberto antes da autenticacao nao serve mais.
    unset($_SESSION['csrf_token']);
}
