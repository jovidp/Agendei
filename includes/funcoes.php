<?php
/**
 * Funcoes utilitarias compartilhadas por todo o sistema.
 */

/** Monta uma URL absoluta da aplicacao. */
function url(string $caminho = ''): string
{
    $endereco = BASE_URL . '/' . ltrim($caminho, '/');
    // Arquivos estáticos mantêm URLs comuns; páginas carregam a identificação da empresa.
    if (preg_match('/\.php(?:[?#]|$)/', $caminho) && Contexto::slug() !== '') {
        $partes = explode('#', $endereco, 2);
        $partes[0] .= (str_contains($partes[0], '?') ? '&' : '?') . 'estabelecimento=' . rawurlencode(Contexto::slug());
        $endereco = $partes[0] . (isset($partes[1]) ? '#' . $partes[1] : '');
    }
    return $endereco;
}

/** Redireciona e encerra a execucao. */
function redirecionar(string $caminho): void
{
    header('Location: ' . url($caminho));
    exit;
}

/** Escapa saida para HTML. */
function e(?string $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Le um campo de $_POST ja com trim. */
function post(string $campo, string $padrao = ''): string
{
    return isset($_POST[$campo]) && is_scalar($_POST[$campo])
        ? trim((string) $_POST[$campo])
        : $padrao;
}

/** Le um campo de $_GET ja com trim. */
function get(string $campo, string $padrao = ''): string
{
    return isset($_GET[$campo]) && is_scalar($_GET[$campo])
        ? trim((string) $_GET[$campo])
        : $padrao;
}

// ---------------------------------------------------------------------
// Mensagens de feedback (flash)
// ---------------------------------------------------------------------

/** Guarda uma mensagem para exibir na proxima pagina. Tipos: sucesso, erro, aviso, info. */
function definirFlash(string $tipo, string $mensagem): void
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'mensagem' => $mensagem];
}

/** Retorna e limpa as mensagens pendentes. */
function obterFlash(): array
{
    $mensagens = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $mensagens;
}

/** Renderiza as mensagens pendentes. */
function exibirFlash(): void
{
    foreach (obterFlash() as $flash) {
        $tipo = in_array($flash['tipo'], ['sucesso', 'erro', 'aviso', 'info'], true) ? $flash['tipo'] : 'info';
        echo '<div class="alerta alerta-' . $tipo . '" role="alert">'
            . '<span class="alerta-texto">' . e($flash['mensagem']) . '</span>'
            . '<button type="button" class="alerta-fechar" aria-label="Fechar">&times;</button>'
            . '</div>';
    }
}

// ---------------------------------------------------------------------
// Formatacao
// ---------------------------------------------------------------------

/** Apresenta o valor em reais com separadores usados no Brasil. */
function formatarMoeda(float|string|null $valor): string
{
    return 'R$ ' . number_format((float) $valor, 2, ',', '.');
}

/** Converte 2026-09-03 em 03/09/2026. */
function formatarData(?string $data): string
{
    if (empty($data)) {
        return '-';
    }
    $objeto = date_create($data);
    return $objeto ? $objeto->format('d/m/Y') : '-';
}


/** Converte 14:30:00 em 14:30. */
function formatarHora(?string $hora): string
{
    if (empty($hora)) {
        return '-';
    }
    return substr($hora, 0, 5);
}

/** Aplica a apresentação com DDD aos telefones com 10 ou 11 dígitos. */
function formatarTelefone(?string $telefone): string
{
    $numeros = apenasNumeros((string) $telefone);
    $total   = strlen($numeros);

    if ($total === 11) {
        return sprintf('(%s) %s-%s', substr($numeros, 0, 2), substr($numeros, 2, 5), substr($numeros, 7));
    }
    if ($total === 10) {
        return sprintf('(%s) %s-%s', substr($numeros, 0, 2), substr($numeros, 2, 4), substr($numeros, 6));
    }
    return (string) $telefone;
}

/** Aplica a pontuação de CPF quando há 11 dígitos disponíveis. */
function formatarCpf(?string $cpf): string
{
    $numeros = apenasNumeros((string) $cpf);
    if (strlen($numeros) !== 11) {
        return $cpf === null || $cpf === '' ? '-' : $cpf;
    }
    return sprintf(
        '%s.%s.%s-%s',
        substr($numeros, 0, 3),
        substr($numeros, 3, 3),
        substr($numeros, 6, 3),
        substr($numeros, 9, 2)
    );
}

/** Converte "1.234,56" (entrada do usuario) em 1234.56. */
function moedaParaDecimal(?string $valor): float
{
    $valor = trim((string) $valor);
    if ($valor === '') {
        return 0.0;
    }

    return (float) str_replace(',', '.', str_replace('.', '', $valor));
}

/** Remove caracteres não numéricos para normalizar documentos e contatos. */
function apenasNumeros(?string $valor): string
{
    return preg_replace('/\D/', '', (string) $valor) ?? '';
}

/** 90 => "1h30". */
function duracaoTexto(int $minutos): string
{
    if ($minutos < 60) {
        return $minutos . ' min';
    }
    $horas  = intdiv($minutos, 60);
    $resto  = $minutos % 60;
    return $resto === 0 ? $horas . 'h' : $horas . 'h' . str_pad((string) $resto, 2, '0', STR_PAD_LEFT);
}

/** Data por extenso: "quarta-feira, 03 de setembro de 2026". */
function dataExtenso(string $data): string
{
    $objeto = date_create($data);
    if (!$objeto) {
        return $data;
    }
    return diaSemanaNome((int) $objeto->format('w')) . ', '
        . $objeto->format('d') . ' de ' . mesNome((int) $objeto->format('n'))
        . ' de ' . $objeto->format('Y');
}

/** Converte o índice do dia da semana em nome completo ou abreviado. */
function diaSemanaNome(int $dia, bool $abreviado = false): string
{
    $nomes = [
        0 => ['Domingo', 'Dom'],
        1 => ['Segunda-feira', 'Seg'],
        2 => ['Terca-feira', 'Ter'],
        3 => ['Quarta-feira', 'Qua'],
        4 => ['Quinta-feira', 'Qui'],
        5 => ['Sexta-feira', 'Sex'],
        6 => ['Sabado', 'Sab'],
    ];
    if (!isset($nomes[$dia])) {
        return '';
    }
    return $abreviado ? $nomes[$dia][1] : $nomes[$dia][0];
}

/** Converte o número do mês em nome completo ou abreviado. */
function mesNome(int $mes, bool $abreviado = false): string
{
    $nomes = [
        1 => 'janeiro', 2 => 'fevereiro', 3 => 'marco', 4 => 'abril',
        5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto',
        9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
    ];
    $nome = $nomes[$mes] ?? '';
    return $abreviado ? ucfirst(substr($nome, 0, 3)) : $nome;
}

/** Limita o texto de exibição, preservando caracteres multibyte. */
function limitarTexto(?string $texto, int $limite = 120): string
{
    $texto = trim((string) $texto);
    if (mb_strlen($texto) <= $limite) {
        return $texto;
    }
    return mb_substr($texto, 0, $limite) . '...';
}

// ---------------------------------------------------------------------
// Horarios
// ---------------------------------------------------------------------

/** Soma minutos a um horario "HH:MM" ou "HH:MM:SS" e devolve "HH:MM:SS". */
function somarMinutos(string $hora, int $minutos): string
{
    $base = strtotime('1970-01-01 ' . $hora . ' UTC');
    return gmdate('H:i:s', $base + ($minutos * 60));
}

/** Diferenca em minutos entre dois horarios do mesmo dia. */
function diferencaMinutos(string $inicio, string $fim): int
{
    $i = strtotime('1970-01-01 ' . $inicio . ' UTC');
    $f = strtotime('1970-01-01 ' . $fim . ' UTC');
    return (int) round(($f - $i) / 60);
}

/** Converte "HH:MM[:SS]" em minutos desde a meia-noite. */
function horaParaMinutos(string $hora): int
{
    $partes = explode(':', $hora);
    return ((int) ($partes[0] ?? 0)) * 60 + ((int) ($partes[1] ?? 0));
}

/** Converte minutos desde a meia-noite para o formato de horário usado no sistema. */
function minutosParaHora(int $minutos): string
{
    return sprintf('%02d:%02d:00', intdiv($minutos, 60), $minutos % 60);
}

// ---------------------------------------------------------------------
// Validacao
// ---------------------------------------------------------------------

/** Verifica se o texto tem um formato de e-mail aceito pela validação. */
function validarEmail(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

/** Rejeita sequências repetidas e confere os dois dígitos verificadores do CPF. */
function validarCpf(string $cpf): bool
{
    $cpf = apenasNumeros($cpf);

    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }

    for ($posicao = 9; $posicao < 11; $posicao++) {
        $soma = 0;
        for ($indice = 0; $indice < $posicao; $indice++) {
            $soma += (int) $cpf[$indice] * (($posicao + 1) - $indice);
        }
        $digito = ((10 * $soma) % 11) % 10;
        if ((int) $cpf[$posicao] !== $digito) {
            return false;
        }
    }

    return true;
}

/** Valida "YYYY-MM-DD". */
function validarData(string $data): bool
{
    $partes = explode('-', $data);
    if (count($partes) !== 3) {
        return false;
    }
    [$ano, $mes, $dia] = array_map('intval', $partes);
    return checkdate($mes, $dia, $ano);
}

/** Valida "HH:MM" ou "HH:MM:SS". */
function validarHora(string $hora): bool
{
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $hora);
}

/** Verifica o comprimento mínimo exigido para a senha. */
function validarSenha(string $senha): bool
{
    return mb_strlen($senha) >= 6;
}

// ---------------------------------------------------------------------
// Interface
// ---------------------------------------------------------------------

/** Badge discreta de status de agendamento. */
function badgeStatus(string $status): string
{
    $rotulos = [
        'agendado'   => 'Agendado',
        'confirmado' => 'Confirmado',
        'concluido'  => 'Concluido',
        'cancelado'  => 'Cancelado',
        'ativo'      => 'Ativo',
        'inativo'    => 'Inativo',
    ];
    $rotulo = $rotulos[$status] ?? ucfirst($status);
    return '<span class="badge badge-' . e($status) . '">' . e($rotulo) . '</span>';
}

/**
 * Rodape de paginacao das tabelas.
 * $parametros preserva os filtros aplicados na URL.
 */
function renderizarPaginacao(int $total, int $pagina, int $porPagina, array $parametros = []): string
{
    if ($total === 0) {
        return '';
    }

    // Calcula o total de páginas e a faixa de registros exibida nesta página.
    $parametros['estabelecimento'] = Contexto::slug();
    $totalPaginas = (int) ceil($total / $porPagina);
    $primeiro     = (($pagina - 1) * $porPagina) + 1;
    $ultimo       = min($pagina * $porPagina, $total);

    $html = '<div class="paginacao"><span>Exibindo ' . $primeiro . '-' . $ultimo . ' de ' . $total . '</span>';

    if ($totalPaginas > 1) {
        $html .= '<span class="paginacao-links">';

        // Limita a navegação a uma janela de até cinco páginas ao redor da página atual.
        $inicio = max(1, $pagina - 2);
        $fim    = min($totalPaginas, $inicio + 4);
        $inicio = max(1, $fim - 4);

        if ($pagina > 1) {
            $parametros['pagina'] = $pagina - 1;
            $html .= '<a href="?' . e(http_build_query($parametros)) . '" aria-label="Pagina anterior">&lsaquo;</a>';
        }

        for ($numero = $inicio; $numero <= $fim; $numero++) {
            $parametros['pagina'] = $numero;
            $html .= $numero === $pagina
                ? '<span class="atual">' . $numero . '</span>'
                : '<a href="?' . e(http_build_query($parametros)) . '">' . $numero . '</a>';
        }

        if ($pagina < $totalPaginas) {
            $parametros['pagina'] = $pagina + 1;
            $html .= '<a href="?' . e(http_build_query($parametros)) . '" aria-label="Proxima pagina">&rsaquo;</a>';
        }

        $html .= '</span>';
    }

    return $html . '</div>';
}

/** Nome do arquivo da pagina atual, usado para marcar o item ativo do menu. */
function paginaAtual(): string
{
    return basename($_SERVER['SCRIPT_NAME'] ?? '');
}

/** Devolve resposta JSON e encerra. */
function jsonResposta(array $dados, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Identifica chamadas que enviam o cabeçalho X-Requested-With de XMLHttpRequest. */
function ehRequisicaoAjax(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}


/** Iniciais do nome para os avatares da interface. */
function iniciais(string $nome): string
{
    $partes = preg_split('/\s+/', trim($nome)) ?: [];
    $primeira = mb_substr($partes[0] ?? '', 0, 1);
    $ultima   = count($partes) > 1 ? mb_substr(end($partes), 0, 1) : '';
    return mb_strtoupper($primeira . $ultima);
}
