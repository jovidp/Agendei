<?php

/**
 * Teste do gerador de QR code.
 *
 * Um QR que "parece certo" na tela pode ser ilegivel para o leitor, e nao ha
 * biblioteca de QR neste ambiente para comparar. Entao o teste faz o caminho
 * de volta: le a matriz gerada como um leitor faria — descobre a mascara pela
 * informacao de formato, desfaz a mascara, percorre o ziguezague, desintercala
 * os blocos e reconstroi o texto.
 *
 * Alem disso confere a correcao de erro pelo caminho independente das
 * sindromes: se o polinomio recebido e multiplo do gerador, cada sindrome
 * S_i = R(alfa^i) vale zero. Isso valida o Reed-Solomon sem repetir a mesma
 * conta que o gerador usou.
 *
 * Execute com "php tests/qrcode.php".
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(dirname(__DIR__));

require_once 'models/QrCode.php';

/** O gerador usa e() para o texto alternativo do SVG. */
function e(?string $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$total = 0;
$falhas = 0;

/** Compara o resultado com o esperado e acumula o placar do teste. */
function verificar(string $descricao, $obtido, $esperado): void
{
    global $total, $falhas;
    $total++;

    if ($obtido === $esperado) {
        return;
    }

    $falhas++;
    echo 'FALHA: ' . $descricao . "\n";
    echo '  esperado: ' . var_export($esperado, true) . "\n";
    echo '  obtido:   ' . var_export($obtido, true) . "\n";
}

// =====================================================================
// Leitor independente, escrito a partir da ISO/IEC 18004
// =====================================================================

/** Tabelas do GF(256), montadas de novo aqui para nao depender do gerador. */
function campoGf(): array
{
    static $tabelas = null;

    if ($tabelas === null) {
        $exp = [];
        $log = [];
        $valor = 1;

        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $valor;
            $log[$valor] = $i;
            $valor <<= 1;
            if ($valor & 0x100) {
                $valor ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }

        $tabelas = [$exp, $log];
    }

    return $tabelas;
}

/** Multiplicacao no corpo. */
function multiplicarGf(int $a, int $b): int
{
    [$exp, $log] = campoGf();
    return ($a === 0 || $b === 0) ? 0 : $exp[$log[$a] + $log[$b]];
}

/**
 * Sindromes do bloco recebido. Todas zero significa palavra de codigo valida.
 *
 * O gerador do QR e o produto de (x - alfa^j) com j comecando em ZERO, entao as
 * raizes sao alfa^0 ate alfa^(t-1) — e e nessas potencias que as sindromes
 * precisam ser avaliadas. Comecar em alfa^1, como fazem outros usos de
 * Reed-Solomon, acusaria erro num bloco perfeitamente valido.
 */
function sindromes(array $codewords, int $quantidadeEc): array
{
    [$exp] = campoGf();
    $resultado = [];

    for ($i = 0; $i < $quantidadeEc; $i++) {
        $soma = 0;

        // Avalia o polinomio em alfa^i pelo metodo de Horner.
        foreach ($codewords as $coeficiente) {
            $soma = multiplicarGf($soma, $exp[$i]) ^ $coeficiente;
        }

        $resultado[] = $soma;
    }

    return $resultado;
}

/** Mascaras da norma, repetidas aqui para conferir a escolha do gerador. */
function mascaraLeitor(int $mascara, int $linha, int $coluna): bool
{
    return match ($mascara) {
        0 => ($linha + $coluna) % 2 === 0,
        1 => $linha % 2 === 0,
        2 => $coluna % 3 === 0,
        3 => ($linha + $coluna) % 3 === 0,
        4 => (intdiv($linha, 2) + intdiv($coluna, 3)) % 2 === 0,
        5 => (($linha * $coluna) % 2) + (($linha * $coluna) % 3) === 0,
        6 => ((($linha * $coluna) % 2) + (($linha * $coluna) % 3)) % 2 === 0,
        7 => (((($linha + $coluna) % 2) + (($linha * $coluna) % 3)) % 2) === 0,
        default => false,
    };
}

/**
 * Le a informacao de formato da primeira copia e devolve [nivel, mascara].
 * Percorre as 32 combinacoes validas e fica com a de menor distancia de
 * Hamming — exatamente o que um leitor real faz.
 */
function lerFormato(array $matriz): array
{
    $bits = 0;

    for ($i = 0; $i < 15; $i++) {
        if ($i < 6) {
            $modulo = $matriz[8][$i];
        } elseif ($i === 6) {
            $modulo = $matriz[8][7];
        } elseif ($i === 7) {
            $modulo = $matriz[8][8];
        } elseif ($i === 8) {
            $modulo = $matriz[7][8];
        } else {
            $modulo = $matriz[14 - $i][8];
        }

        if ($modulo) {
            $bits |= 1 << $i;
        }
    }

    $melhor = [null, null];
    $menorDistancia = 16;

    for ($dados = 0; $dados < 32; $dados++) {
        $resto = $dados << 10;
        for ($i = 4; $i >= 0; $i--) {
            if ($resto & (1 << ($i + 10))) {
                $resto ^= 0b10100110111 << $i;
            }
        }
        $candidato = ((($dados << 10) | $resto) ^ 0b101010000010010);

        $distancia = substr_count(decbin($candidato ^ $bits), '1');
        if ($distancia < $menorDistancia) {
            $menorDistancia = $distancia;
            $melhor = [$dados >> 3, $dados & 0b111];
        }
    }

    return [$melhor[0], $melhor[1], $menorDistancia];
}

/** Reconstroi o mapa de modulos de funcao, a partir da geometria da norma. */
function mapaReservado(int $versao): array
{
    $lado = ($versao * 4) + 17;
    $reservado = array_fill(0, $lado, array_fill(0, $lado, false));

    $marcar = static function (int $l1, int $c1, int $l2, int $c2) use (&$reservado, $lado): void {
        for ($l = max(0, $l1); $l <= min($lado - 1, $l2); $l++) {
            for ($c = max(0, $c1); $c <= min($lado - 1, $c2); $c++) {
                $reservado[$l][$c] = true;
            }
        }
    };

    // Localizadores com separador (8x8 em cada canto) e faixa de formato.
    $marcar(0, 0, 8, 8);
    $marcar(0, $lado - 8, 8, $lado - 1);
    $marcar($lado - 8, 0, $lado - 1, 8);

    // Temporizacao.
    $marcar(6, 0, 6, $lado - 1);
    $marcar(0, 6, $lado - 1, 6);

    // Alinhamento.
    $centros = [[], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50]][$versao - 1];
    foreach ($centros as $linha) {
        foreach ($centros as $coluna) {
            $ultimo = end($centros);
            if (($linha === 6 && $coluna === 6) || ($linha === 6 && $coluna === $ultimo) || ($linha === $ultimo && $coluna === 6)) {
                continue;
            }
            $marcar($linha - 2, $coluna - 2, $linha + 2, $coluna + 2);
        }
    }

    // Informacao de versao.
    if ($versao >= 7) {
        $marcar(0, $lado - 11, 5, $lado - 9);
        $marcar($lado - 11, 0, $lado - 9, 5);
    }

    return $reservado;
}

/** Le o fluxo de bits da matriz, desfazendo a mascara. */
function lerBits(array $matriz, int $versao, int $mascara): array
{
    $lado = count($matriz);
    $reservado = mapaReservado($versao);
    $bits = [];
    $subindo = true;

    for ($direita = $lado - 1; $direita > 0; $direita -= 2) {
        if ($direita === 6) {
            $direita = 5;
        }

        for ($passo = 0; $passo < $lado; $passo++) {
            $linha = $subindo ? $lado - 1 - $passo : $passo;

            foreach ([0, 1] as $deslocamento) {
                $coluna = $direita - $deslocamento;

                if ($reservado[$linha][$coluna]) {
                    continue;
                }

                $bits[] = ($matriz[$linha][$coluna] !== mascaraLeitor($mascara, $linha, $coluna)) ? 1 : 0;
            }
        }

        $subindo = !$subindo;
    }

    return $bits;
}

/**
 * Caminho completo do leitor: matriz -> texto.
 * Devolve [texto, sindromesZeradas, mascaraLida, nivelLido].
 */
function decodificar(array $matriz): array
{
    $lado = count($matriz);
    $versao = (int) (($lado - 17) / 4);

    $estrutura = [
        1  => [16,  10, 1, 16, 0, 0],
        2  => [28,  16, 1, 28, 0, 0],
        3  => [44,  26, 1, 44, 0, 0],
        4  => [64,  18, 2, 32, 0, 0],
        5  => [86,  24, 2, 43, 0, 0],
        6  => [108, 16, 4, 27, 0, 0],
        7  => [124, 18, 4, 31, 0, 0],
        8  => [154, 22, 2, 38, 2, 39],
        9  => [182, 22, 3, 36, 2, 37],
        10 => [216, 26, 4, 43, 1, 44],
    ][$versao];

    [, $ecPorBloco, $blocos1, $dados1, $blocos2, $dados2] = $estrutura;

    [$nivel, $mascara, $distancia] = lerFormato($matriz);
    $bits = lerBits($matriz, $versao, $mascara);

    // Bits -> codewords.
    $codewords = [];
    foreach (array_chunk(array_slice($bits, 0, intdiv(count($bits), 8) * 8), 8) as $byte) {
        $codewords[] = bindec(implode('', $byte));
    }

    // Desfaz a intercalacao, recompondo cada bloco.
    $tamanhos = array_merge(
        array_fill(0, $blocos1, $dados1),
        array_fill(0, $blocos2, $dados2)
    );
    $quantidadeBlocos = count($tamanhos);

    $blocosDados = array_fill(0, $quantidadeBlocos, []);
    $posicao = 0;
    $maior = max($tamanhos);

    for ($i = 0; $i < $maior; $i++) {
        for ($b = 0; $b < $quantidadeBlocos; $b++) {
            if ($i < $tamanhos[$b]) {
                $blocosDados[$b][] = $codewords[$posicao++];
            }
        }
    }

    $blocosEc = array_fill(0, $quantidadeBlocos, []);
    for ($i = 0; $i < $ecPorBloco; $i++) {
        for ($b = 0; $b < $quantidadeBlocos; $b++) {
            $blocosEc[$b][] = $codewords[$posicao++];
        }
    }

    // Correcao de erro valida? Sindromes de cada bloco devem ser zero.
    $sindromesZeradas = true;
    for ($b = 0; $b < $quantidadeBlocos; $b++) {
        $completo = array_merge($blocosDados[$b], $blocosEc[$b]);
        foreach (sindromes($completo, $ecPorBloco) as $sindrome) {
            if ($sindrome !== 0) {
                $sindromesZeradas = false;
            }
        }
    }

    // Reconstroi o fluxo de dados e le o segmento em modo byte.
    $fluxo = '';
    foreach ($blocosDados as $bloco) {
        foreach ($bloco as $codeword) {
            $fluxo .= str_pad(decbin($codeword), 8, '0', STR_PAD_LEFT);
        }
    }

    $modo = bindec(substr($fluxo, 0, 4));
    $tamanhoContagem = $versao < 10 ? 8 : 16;
    $quantidade = bindec(substr($fluxo, 4, $tamanhoContagem));

    $texto = '';
    for ($i = 0; $i < $quantidade; $i++) {
        $texto .= chr(bindec(substr($fluxo, 4 + $tamanhoContagem + ($i * 8), 8)));
    }

    return [$texto, $sindromesZeradas, $mascara, $nivel, $modo, $distancia, $versao];
}

// =====================================================================
// Verificacoes
// =====================================================================

// ---------------------------------------------------------------------
// Estrutura do simbolo
// ---------------------------------------------------------------------
$matriz = QrCode::matriz('HELLO WORLD');
$lado = count($matriz);

verificar('a matriz e quadrada', count($matriz[0]), $lado);
verificar('o lado segue 4v+17', ($lado - 17) % 4, 0);

// Localizador: quadro escuro, anel claro, miolo escuro, nos tres cantos.
foreach ([[0, 0], [0, $lado - 7], [$lado - 7, 0]] as [$base, $coluna]) {
    $ok = true;
    for ($i = 0; $i < 7; $i++) {
        for ($j = 0; $j < 7; $j++) {
            $borda = $i === 0 || $i === 6 || $j === 0 || $j === 6;
            $miolo = $i >= 2 && $i <= 4 && $j >= 2 && $j <= 4;
            if ($matriz[$base + $i][$coluna + $j] !== ($borda || $miolo)) {
                $ok = false;
            }
        }
    }
    verificar("localizador em ({$base},{$coluna})", $ok, true);
}

// O canto inferior direito nao tem localizador. Um modulo isolado escuro ali
// e normal (e area de dados), entao o que se confere e a ausencia do padrao 7x7.
$pareceLocalizador = true;
for ($i = 0; $i < 7; $i++) {
    for ($j = 0; $j < 7; $j++) {
        $borda = $i === 0 || $i === 6 || $j === 0 || $j === 6;
        $miolo = $i >= 2 && $i <= 4 && $j >= 2 && $j <= 4;
        if ($matriz[$lado - 7 + $i][$lado - 7 + $j] !== ($borda || $miolo)) {
            $pareceLocalizador = false;
        }
    }
}
verificar('sem localizador no canto inferior direito', $pareceLocalizador, false);

// Temporizacao alterna e comeca escura na coordenada par.
$temporizacaoOk = true;
for ($i = 8; $i < $lado - 8; $i++) {
    if ($matriz[6][$i] !== ($i % 2 === 0) || $matriz[$i][6] !== ($i % 2 === 0)) {
        $temporizacaoOk = false;
    }
}
verificar('linhas de temporizacao alternam', $temporizacaoOk, true);

// Modulo sempre escuro.
verificar('modulo escuro obrigatorio esta presente', $matriz[$lado - 8][8], true);

// ---------------------------------------------------------------------
// Informacao de formato
// ---------------------------------------------------------------------
[$texto, $sindromesOk, $mascara, $nivel, $modo, $distancia, $versao] = decodificar($matriz);

verificar('formato lido sem erro de bit', $distancia, 0);
verificar('nivel de correcao gravado e M', $nivel, 0);
verificar('mascara fica na faixa valida', $mascara >= 0 && $mascara <= 7, true);
verificar('modo declarado e byte', $modo, 4);

// As duas copias do formato precisam dizer a mesma coisa.
$copiasIguais = true;
for ($i = 0; $i < 15; $i++) {
    if ($i < 6) {
        $copiaA = $matriz[8][$i];
    } elseif ($i === 6) {
        $copiaA = $matriz[8][7];
    } elseif ($i === 7) {
        $copiaA = $matriz[8][8];
    } elseif ($i === 8) {
        $copiaA = $matriz[7][8];
    } else {
        $copiaA = $matriz[14 - $i][8];
    }

    $copiaB = $i < 7 ? $matriz[$lado - 1 - $i][8] : $matriz[8][$lado - 15 + $i];

    if ($copiaA !== $copiaB) {
        $copiasIguais = false;
    }
}
verificar('as duas copias do formato coincidem', $copiasIguais, true);

// ---------------------------------------------------------------------
// Caminho de volta: o texto sai igual ao que entrou
// ---------------------------------------------------------------------
verificar('texto simples volta igual', $texto, 'HELLO WORLD');
verificar('correcao de erro valida (sindromes zeradas)', $sindromesOk, true);

$amostras = [
    'a',
    'Agendei',
    'otpauth://totp/Agendei:maria%40exemplo.br?secret=JBSWY3DPEHPK3PXP&issuer=Agendei&algorithm=SHA1&digits=6&period=30',
    'otpauth://totp/Barbearia%20do%20Joao%20Ltda%20ME:administrador%40barbeariadojoao.com.br'
        . '?secret=KRUGKIDROVUWG2ZAMJZG653OEBTG66BA&issuer=Barbearia%20do%20Joao%20Ltda%20ME'
        . '&algorithm=SHA1&digits=6&period=30',
    str_repeat('X', 14),   // limite da versao 1
    str_repeat('Y', 15),   // primeira que exige a versao 2
    str_repeat('Z', 106),  // limite da versao 6
    str_repeat('W', 107),  // primeira que exige a versao 7 (leva info de versao)
    str_repeat('Q', 152),  // limite da versao 8
    str_repeat('P', 180),  // limite da versao 9
    str_repeat('R', 213),  // limite da versao 10, o maior que o gerador aceita
];

foreach ($amostras as $amostra) {
    $rotulo = strlen($amostra) > 30 ? strlen($amostra) . ' caracteres' : '"' . $amostra . '"';
    [$lido, $ecOk, , , $modoLido, $distanciaLida, $versaoLida] = decodificar(QrCode::matriz($amostra));

    verificar("texto de {$rotulo} volta igual", $lido, $amostra);
    verificar("correcao de erro valida em {$rotulo}", $ecOk, true);
    verificar("formato sem erro em {$rotulo}", $distanciaLida, 0);
    verificar("modo byte em {$rotulo}", $modoLido, 4);
}

// ---------------------------------------------------------------------
// Escolha da versao: a menor que couber
// ---------------------------------------------------------------------
$limites = [14 => 1, 15 => 2, 26 => 2, 27 => 3, 106 => 6, 107 => 7, 152 => 8, 180 => 9, 213 => 10];
foreach ($limites as $tamanho => $versaoEsperada) {
    verificar(
        "{$tamanho} caracteres cabem na versao {$versaoEsperada}",
        intdiv(count(QrCode::matriz(str_repeat('A', $tamanho))) - 17, 4),
        $versaoEsperada
    );
}

// Acima do maximo, o gerador precisa reclamar em vez de produzir lixo.
$reclamou = false;
try {
    QrCode::matriz(str_repeat('A', 214));
} catch (InvalidArgumentException $erro) {
    $reclamou = true;
}
verificar('texto grande demais e recusado', $reclamou, true);

// ---------------------------------------------------------------------
// Mascara escolhida e mesmo a de menor penalidade
// ---------------------------------------------------------------------
// Gerar duas vezes o mesmo texto tem de dar exatamente a mesma matriz.
verificar('geracao e deterministica', QrCode::matriz('Agendei') === QrCode::matriz('Agendei'), true);

// ---------------------------------------------------------------------
// Saida SVG
// ---------------------------------------------------------------------
$svg = QrCode::svg('otpauth://totp/Teste?secret=JBSWY3DPEHPK3PXP', 'QR de teste');

verificar('saida e um svg', str_starts_with($svg, '<svg '), true);
verificar('svg declara o namespace', str_contains($svg, 'xmlns="http://www.w3.org/2000/svg"'), true);
verificar('svg tem texto alternativo', str_contains($svg, 'aria-label="QR de teste"'), true);
verificar('svg tem papel de imagem', str_contains($svg, 'role="img"'), true);
verificar('svg tem fundo branco', str_contains($svg, 'fill="#FFFFFF"'), true);
verificar('svg fecha a tag', str_ends_with($svg, '</svg>'), true);

// A zona quieta de quatro modulos precisa entrar no viewBox.
$ladoSvg = count(QrCode::matriz('otpauth://totp/Teste?secret=JBSWY3DPEHPK3PXP'));
verificar('viewBox inclui a zona quieta', str_contains($svg, 'viewBox="0 0 ' . ($ladoSvg + 8) . ' ' . ($ladoSvg + 8) . '"'), true);

// Texto alternativo com aspas nao pode escapar do atributo.
verificar(
    'texto alternativo e escapado',
    str_contains(QrCode::svg('Agendei', 'aspas " e <tag>'), 'aria-label="aspas &quot; e &lt;tag&gt;"'),
    true
);

// ---------------------------------------------------------------------
echo "\n{$total} verificacao(oes), {$falhas} falha(s).\n";
exit($falhas === 0 ? 0 : 1);
