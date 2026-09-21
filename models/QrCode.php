<?php

/**
 * Gerador de QR code em PHP puro, com saida em SVG.
 *
 * Existe porque o projeto nao usa Composer e a hospedagem nao tem a extensao
 * GD. Mandar o texto para um servico externo de QR estava fora de questao: o
 * endereco otpauth:// carrega o segredo do segundo fator, e entrega-lo a um
 * terceiro anularia a protecao que ele deveria criar.
 *
 * Cobre o que o sistema precisa e nada alem: modo byte, correcao de erro nivel
 * M e versoes 1 a 10 (ate 213 bytes) — folga confortavel para o endereco do
 * autenticador, que costuma ter de 100 a 150 caracteres.
 *
 * Referencia: ISO/IEC 18004. Os nomes seguem os da norma para que quem for
 * conferir consiga acompanhar.
 */
class QrCode
{
    /** Nivel de correcao de erro usado: M recupera cerca de 15% do simbolo. */
    private const NIVEL_M = 0;

    /**
     * Estrutura de cada versao no nivel M.
     * [codewords de dados, codewords de correcao por bloco, blocos do grupo 1,
     *  dados por bloco do grupo 1, blocos do grupo 2, dados por bloco do grupo 2]
     */
    private const VERSOES = [
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
    ];

    /** Centros dos padroes de alinhamento de cada versao. */
    private const ALINHAMENTO = [
        1  => [],
        2  => [6, 18],
        3  => [6, 22],
        4  => [6, 26],
        5  => [6, 30],
        6  => [6, 34],
        7  => [6, 22, 38],
        8  => [6, 24, 42],
        9  => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** Bits de sobra depois dos codewords, por versao. */
    private const SOBRA = [1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7, 7 => 0, 8 => 0, 9 => 0, 10 => 0];

    // -----------------------------------------------------------------
    // Saida
    // -----------------------------------------------------------------

    /**
     * Desenha o QR como SVG embutido na pagina.
     *
     * SVG e nao imagem porque nao depende de GD, escala sem borrar e fica
     * nitido em qualquer tela. As cores vem de currentColor e do fundo branco
     * fixo: leitor de QR precisa de contraste alto, entao o tema escuro do
     * sistema nao deve inverter este desenho.
     */
    public static function svg(string $texto, string $descricao = 'Codigo QR'): string
    {
        $matriz = self::matriz($texto);
        $lado = count($matriz);

        // Quatro modulos de margem sao exigidos pela norma: sem a "zona quieta"
        // o leitor nao encontra as bordas do simbolo.
        $margem = 4;
        $totalLado = $lado + ($margem * 2);

        $caminho = '';
        foreach ($matriz as $linha => $colunas) {
            foreach ($colunas as $coluna => $escuro) {
                if ($escuro) {
                    $caminho .= 'M' . ($coluna + $margem) . ' ' . ($linha + $margem) . 'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $totalLado . ' ' . $totalLado . '"'
            . ' class="qrcode" role="img" aria-label="' . e($descricao) . '" shape-rendering="crispEdges">'
            . '<rect width="' . $totalLado . '" height="' . $totalLado . '" fill="#FFFFFF"/>'
            . '<path d="' . $caminho . '" fill="#000000"/>'
            . '</svg>';
    }

    // -----------------------------------------------------------------
    // Montagem do simbolo
    // -----------------------------------------------------------------

    /** Matriz de modulos: true e escuro. Indices [linha][coluna]. */
    public static function matriz(string $texto): array
    {
        $versao = self::escolherVersao($texto);
        $bits = self::montarCodewords($texto, $versao);

        $melhor = null;
        $melhorNota = null;

        // A norma manda testar as oito mascaras e ficar com a de menor
        // penalidade: e o que evita blocos uniformes que confundem o leitor.
        for ($mascara = 0; $mascara < 8; $mascara++) {
            $candidata = self::desenhar($versao, $bits, $mascara);
            $nota = self::penalidade($candidata);

            if ($melhorNota === null || $nota < $melhorNota) {
                $melhorNota = $nota;
                $melhor = $candidata;
            }
        }

        return $melhor;
    }

    /** Menor versao que comporta o texto no nivel M. */
    private static function escolherVersao(string $texto): int
    {
        $tamanho = strlen($texto);

        foreach (self::VERSOES as $versao => $dados) {
            // Cabecalho: 4 bits de modo + 8 ou 16 bits de contagem.
            $cabecalho = $versao < 10 ? 12 : 20;
            if (($dados[0] * 8) - $cabecalho >= $tamanho * 8) {
                return $versao;
            }
        }

        throw new InvalidArgumentException('Texto longo demais para o QR code gerado pelo sistema.');
    }

    /**
     * Fluxo final de bits: dados codificados, correcao de erro e intercalacao.
     */
    private static function montarCodewords(string $texto, int $versao): array
    {
        [$totalDados, $ecPorBloco, $blocos1, $dados1, $blocos2, $dados2] = self::VERSOES[$versao];

        // --- Codificacao em modo byte ---
        $bits = [];
        self::empilhar($bits, 0b0100, 4);                              // modo byte
        self::empilhar($bits, strlen($texto), $versao < 10 ? 8 : 16);  // contagem

        foreach (str_split($texto) as $caractere) {
            self::empilhar($bits, ord($caractere), 8);
        }

        // Terminador de ate quatro zeros, sem ultrapassar a capacidade.
        $capacidade = $totalDados * 8;
        for ($i = 0; $i < 4 && count($bits) < $capacidade; $i++) {
            $bits[] = 0;
        }

        // Completa o ultimo byte e enche o resto alternando 236 e 17, como manda a norma.
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $preenchimento = [236, 17];
        $indice = 0;
        while (count($bits) < $capacidade) {
            self::empilhar($bits, $preenchimento[$indice % 2], 8);
            $indice++;
        }

        // --- Separacao em blocos ---
        $codewords = [];
        foreach (array_chunk($bits, 8) as $byte) {
            $codewords[] = bindec(implode('', $byte));
        }

        $blocosDados = [];
        $blocosCorrecao = [];
        $posicao = 0;

        foreach ([[$blocos1, $dados1], [$blocos2, $dados2]] as [$quantidade, $porBloco]) {
            for ($b = 0; $b < $quantidade; $b++) {
                $bloco = array_slice($codewords, $posicao, $porBloco);
                $posicao += $porBloco;
                $blocosDados[] = $bloco;
                $blocosCorrecao[] = self::correcao($bloco, $ecPorBloco);
            }
        }

        // --- Intercalacao: um codeword de cada bloco por vez ---
        $saida = [];

        $maiorDados = max(array_map('count', $blocosDados));
        for ($i = 0; $i < $maiorDados; $i++) {
            foreach ($blocosDados as $bloco) {
                if (isset($bloco[$i])) {
                    $saida[] = $bloco[$i];
                }
            }
        }

        for ($i = 0; $i < $ecPorBloco; $i++) {
            foreach ($blocosCorrecao as $bloco) {
                $saida[] = $bloco[$i];
            }
        }

        // --- De volta a bits, com os bits de sobra da versao ---
        $fluxo = [];
        foreach ($saida as $codeword) {
            self::empilhar($fluxo, $codeword, 8);
        }
        for ($i = 0; $i < self::SOBRA[$versao]; $i++) {
            $fluxo[] = 0;
        }

        return $fluxo;
    }

    /** Acrescenta um valor ao fluxo de bits, do mais significativo ao menos. */
    private static function empilhar(array &$bits, int $valor, int $quantidade): void
    {
        for ($i = $quantidade - 1; $i >= 0; $i--) {
            $bits[] = ($valor >> $i) & 1;
        }
    }

    // -----------------------------------------------------------------
    // Reed-Solomon sobre GF(256)
    // -----------------------------------------------------------------

    /** Tabelas de logaritmo e exponencial do corpo, montadas uma vez. */
    private static ?array $exp = null;
    private static ?array $log = null;

    /** Prepara as tabelas do GF(256) com o polinomio primitivo 0x11D. */
    private static function prepararCampo(): void
    {
        if (self::$exp !== null) {
            return;
        }

        self::$exp = array_fill(0, 512, 0);
        self::$log = array_fill(0, 256, 0);

        $valor = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $valor;
            self::$log[$valor] = $i;

            $valor <<= 1;
            if ($valor & 0x100) {
                $valor ^= 0x11D;
            }
        }

        // A segunda metade repete a primeira: evita o modulo em cada multiplicacao.
        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    /** Multiplicacao no corpo: soma de logaritmos. */
    private static function multiplicar(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return self::$exp[self::$log[$a] + self::$log[$b]];
    }

    /** Polinomio gerador de grau $grau. */
    private static function gerador(int $grau): array
    {
        self::prepararCampo();

        // O indice 0 guarda o coeficiente de maior grau, e e o que correcao()
        // espera encontrar valendo 1 para dividir. Multiplicar por (x + alfa^i)
        // e portanto deslocar (o proprio coeficiente uma casa a esquerda) e
        // somar o produto pelo termo constante uma casa a direita. Trocar as
        // duas atribuicoes produz o polinomio ao contrario: o simbolo continua
        // legivel, mas com a correcao de erro invalida — falha que so aparece
        // no leitor real, ou na conferencia por sindromes do teste.
        $polinomio = [1];

        for ($i = 0; $i < $grau; $i++) {
            $novo = array_fill(0, count($polinomio) + 1, 0);

            foreach ($polinomio as $posicao => $coeficiente) {
                $novo[$posicao] ^= $coeficiente;
                $novo[$posicao + 1] ^= self::multiplicar($coeficiente, self::$exp[$i]);
            }

            $polinomio = $novo;
        }

        return $polinomio;
    }

    /** Codewords de correcao de um bloco: resto da divisao pelo gerador. */
    private static function correcao(array $bloco, int $quantidade): array
    {
        self::prepararCampo();

        $gerador = self::gerador($quantidade);
        $resto = array_merge($bloco, array_fill(0, $quantidade, 0));

        for ($i = 0; $i < count($bloco); $i++) {
            $fator = $resto[$i];
            if ($fator === 0) {
                continue;
            }

            foreach ($gerador as $posicao => $coeficiente) {
                $resto[$i + $posicao] ^= self::multiplicar($coeficiente, $fator);
            }
        }

        return array_slice($resto, count($bloco), $quantidade);
    }

    // -----------------------------------------------------------------
    // Desenho da matriz
    // -----------------------------------------------------------------

    /**
     * Monta a matriz completa de uma mascara.
     * Devolve [linha][coluna] => bool, ja com a informacao de formato gravada.
     */
    private static function desenhar(int $versao, array $bits, int $mascara): array
    {
        $lado = ($versao * 4) + 17;

        $matriz = array_fill(0, $lado, array_fill(0, $lado, false));
        // Marca os modulos reservados (funcao), que os dados nao podem ocupar.
        $reservado = array_fill(0, $lado, array_fill(0, $lado, false));

        self::padroesFixos($matriz, $reservado, $versao, $lado);
        self::gravarDados($matriz, $reservado, $bits, $lado, $mascara);
        self::gravarFormato($matriz, $mascara, $lado);

        if ($versao >= 7) {
            self::gravarVersao($matriz, $versao, $lado);
        }

        return $matriz;
    }

    /** Localizadores, separadores, temporizacao, alinhamento e modulo escuro. */
    private static function padroesFixos(array &$matriz, array &$reservado, int $versao, int $lado): void
    {
        // --- Localizadores nos tres cantos, com o separador claro em volta ---
        foreach ([[0, 0], [0, $lado - 7], [$lado - 7, 0]] as [$linha, $coluna]) {
            for ($i = -1; $i <= 7; $i++) {
                for ($j = -1; $j <= 7; $j++) {
                    $l = $linha + $i;
                    $c = $coluna + $j;

                    if ($l < 0 || $l >= $lado || $c < 0 || $c >= $lado) {
                        continue;
                    }

                    $noQuadro = $i >= 0 && $i <= 6 && $j >= 0 && $j <= 6;
                    $borda = $i === 0 || $i === 6 || $j === 0 || $j === 6;
                    $miolo = $i >= 2 && $i <= 4 && $j >= 2 && $j <= 4;

                    $matriz[$l][$c] = $noQuadro && ($borda || $miolo);
                    $reservado[$l][$c] = true;
                }
            }
        }

        // --- Linhas de temporizacao, que dao a escala ao leitor ---
        // Alternam a partir da borda do localizador: coordenada par e escura.
        for ($i = 8; $i < $lado - 8; $i++) {
            $escuro = $i % 2 === 0;
            $matriz[6][$i] = $escuro;
            $matriz[$i][6] = $escuro;
            $reservado[6][$i] = true;
            $reservado[$i][6] = true;
        }

        // --- Padroes de alinhamento, exceto onde colidem com os localizadores ---
        $centros = self::ALINHAMENTO[$versao];
        foreach ($centros as $linha) {
            foreach ($centros as $coluna) {
                $cantoSuperiorEsquerdo = $linha === 6 && $coluna === 6;
                $cantoSuperiorDireito  = $linha === 6 && $coluna === end($centros);
                $cantoInferiorEsquerdo = $linha === end($centros) && $coluna === 6;

                if ($cantoSuperiorEsquerdo || $cantoSuperiorDireito || $cantoInferiorEsquerdo) {
                    continue;
                }

                for ($i = -2; $i <= 2; $i++) {
                    for ($j = -2; $j <= 2; $j++) {
                        $matriz[$linha + $i][$coluna + $j] = max(abs($i), abs($j)) !== 1;
                        $reservado[$linha + $i][$coluna + $j] = true;
                    }
                }
            }
        }

        // --- Area da informacao de formato, preenchida depois ---
        for ($i = 0; $i < 9; $i++) {
            $reservado[8][$i] = true;
            $reservado[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $reservado[8][$lado - 1 - $i] = true;
            $reservado[$lado - 1 - $i][8] = true;
        }

        // --- Modulo sempre escuro, exigido pela norma ---
        $matriz[$lado - 8][8] = true;
        $reservado[$lado - 8][8] = true;

        // --- Area da informacao de versao (7 ou maior) ---
        if ($versao >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $reservado[$i][$lado - 11 + $j] = true;
                    $reservado[$lado - 11 + $j][$i] = true;
                }
            }
        }
    }

    /**
     * Percorre a matriz em ziguezague, da direita para a esquerda, em pares de
     * colunas, e grava os bits ja mascarados.
     */
    private static function gravarDados(array &$matriz, array $reservado, array $bits, int $lado, int $mascara): void
    {
        $posicao = 0;
        $subindo = true;

        for ($direita = $lado - 1; $direita > 0; $direita -= 2) {
            // A coluna 6 e a temporizacao vertical: o par de colunas a pula.
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

                    $bit = $posicao < count($bits) ? $bits[$posicao] : 0;
                    $posicao++;

                    $matriz[$linha][$coluna] = ($bit === 1) !== self::mascarar($mascara, $linha, $coluna);
                }
            }

            $subindo = !$subindo;
        }
    }

    /** As oito mascaras previstas na norma. */
    private static function mascarar(int $mascara, int $linha, int $coluna): bool
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
     * Grava a informacao de formato nas duas copias.
     *
     * Sao 15 bits: 5 de dados (nivel de correcao e mascara) e 10 de BCH,
     * embaralhados com a mascara fixa 101010000010010 para que um simbolo
     * todo claro nao produza formato valido.
     */
    private static function gravarFormato(array &$matriz, int $mascara, int $lado): void
    {
        $dados = (self::NIVEL_M << 3) | $mascara;
        $resto = $dados << 10;

        for ($i = 4; $i >= 0; $i--) {
            if ($resto & (1 << ($i + 10))) {
                $resto ^= 0b10100110111 << $i;
            }
        }

        $formato = (($dados << 10) | $resto) ^ 0b101010000010010;

        for ($i = 0; $i < 15; $i++) {
            $bit = (bool) (($formato >> $i) & 1);

            // Copia junto ao localizador superior esquerdo.
            if ($i < 6) {
                $matriz[8][$i] = $bit;
            } elseif ($i === 6) {
                $matriz[8][7] = $bit;
            } elseif ($i === 7) {
                $matriz[8][8] = $bit;
            } elseif ($i === 8) {
                $matriz[7][8] = $bit;
            } else {
                $matriz[14 - $i][8] = $bit;
            }

            // Copia espalhada pelos outros dois cantos. A parte vertical leva
            // sete bits, e nao oito: o oitavo modulo daquela coluna e o modulo
            // sempre escuro, que nao pertence a informacao de formato.
            if ($i < 7) {
                $matriz[$lado - 1 - $i][8] = $bit;
            } else {
                $matriz[8][$lado - 15 + $i] = $bit;
            }
        }
    }

    /**
     * Informacao de versao, obrigatoria da versao 7 em diante.
     * Sao 6 bits de versao e 12 de BCH, repetidos em dois cantos.
     */
    private static function gravarVersao(array &$matriz, int $versao, int $lado): void
    {
        $resto = $versao << 12;

        for ($i = 5; $i >= 0; $i--) {
            if ($resto & (1 << ($i + 12))) {
                $resto ^= 0b1111100100101 << $i;
            }
        }

        $informacao = ($versao << 12) | $resto;

        for ($i = 0; $i < 18; $i++) {
            $bit = (bool) (($informacao >> $i) & 1);
            $linha = intdiv($i, 3);
            $coluna = $i % 3;

            $matriz[$linha][$lado - 11 + $coluna] = $bit;
            $matriz[$lado - 11 + $coluna][$linha] = $bit;
        }
    }

    // -----------------------------------------------------------------
    // Penalidade das mascaras
    // -----------------------------------------------------------------

    /** Soma das quatro regras de penalidade da norma. */
    private static function penalidade(array $matriz): int
    {
        return self::penalidadeSequencias($matriz)
            + self::penalidadeBlocos($matriz)
            + self::penalidadeFalsoLocalizador($matriz)
            + self::penalidadeEquilibrio($matriz);
    }

    /** Regra 1: sequencias de cinco ou mais modulos iguais, em linha ou coluna. */
    private static function penalidadeSequencias(array $matriz): int
    {
        $lado = count($matriz);
        $total = 0;

        foreach ([true, false] as $porLinha) {
            for ($a = 0; $a < $lado; $a++) {
                $anterior = null;
                $sequencia = 0;

                for ($b = 0; $b < $lado; $b++) {
                    $atual = $porLinha ? $matriz[$a][$b] : $matriz[$b][$a];

                    if ($atual === $anterior) {
                        $sequencia++;
                    } else {
                        if ($sequencia >= 5) {
                            $total += 3 + ($sequencia - 5);
                        }
                        $anterior = $atual;
                        $sequencia = 1;
                    }
                }

                if ($sequencia >= 5) {
                    $total += 3 + ($sequencia - 5);
                }
            }
        }

        return $total;
    }

    /** Regra 2: cada bloco 2x2 de modulos iguais. */
    private static function penalidadeBlocos(array $matriz): int
    {
        $lado = count($matriz);
        $total = 0;

        for ($linha = 0; $linha < $lado - 1; $linha++) {
            for ($coluna = 0; $coluna < $lado - 1; $coluna++) {
                $valor = $matriz[$linha][$coluna];

                if ($matriz[$linha][$coluna + 1] === $valor
                    && $matriz[$linha + 1][$coluna] === $valor
                    && $matriz[$linha + 1][$coluna + 1] === $valor
                ) {
                    $total += 3;
                }
            }
        }

        return $total;
    }

    /** Regra 3: sequencias que imitam o localizador e confundem o leitor. */
    private static function penalidadeFalsoLocalizador(array $matriz): int
    {
        $lado = count($matriz);
        $total = 0;

        $padroes = [
            [true, false, true, true, true, false, true, false, false, false, false],
            [false, false, false, false, true, false, true, true, true, false, true],
        ];

        foreach ([true, false] as $porLinha) {
            for ($a = 0; $a < $lado; $a++) {
                for ($b = 0; $b <= $lado - 11; $b++) {
                    foreach ($padroes as $padrao) {
                        $casou = true;

                        for ($i = 0; $i < 11; $i++) {
                            $atual = $porLinha ? $matriz[$a][$b + $i] : $matriz[$b + $i][$a];
                            if ($atual !== $padrao[$i]) {
                                $casou = false;
                                break;
                            }
                        }

                        if ($casou) {
                            $total += 40;
                        }
                    }
                }
            }
        }

        return $total;
    }

    /** Regra 4: desequilibrio entre modulos claros e escuros. */
    private static function penalidadeEquilibrio(array $matriz): int
    {
        $lado = count($matriz);
        $escuros = 0;

        foreach ($matriz as $linha) {
            foreach ($linha as $modulo) {
                if ($modulo) {
                    $escuros++;
                }
            }
        }

        $percentual = ($escuros * 100) / ($lado * $lado);

        return (int) (floor(abs($percentual - 50) / 5) * 10);
    }
}
