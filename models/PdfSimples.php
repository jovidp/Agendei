<?php
/**
 * Gerador de PDF minimo, sem dependencias externas.
 *
 * Cobre o que este projeto precisa: uma folha A4 retrato com titulo, subtitulo
 * e uma tabela de texto que quebra sozinha em varias paginas. As fontes usadas
 * sao as padrao do formato (Helvetica), por isso nenhum arquivo precisa ser embutido.
 */
class PdfSimples
{
    /** Medidas da folha A4 em pontos (1 pt = 1/72 de polegada). */
    private const LARGURA = 595.28;
    private const ALTURA  = 841.89;
    private const MARGEM  = 40.0;

    private const ALTURA_LINHA = 16.0;

    /** Fluxos de conteudo ja fechados, um por pagina. */
    private array $paginas = [];

    /** Comandos da pagina que ainda esta sendo montada. */
    private string $atual = '';

    /** Distancia do topo ja ocupada na pagina atual. */
    private float $cursor = 0.0;

    private string $titulo;
    private string $subtitulo = '';

    /** Indica se a primeira pagina ja foi aberta. */
    private bool $iniciado = false;

    public function __construct(string $titulo)
    {
        $this->titulo = $titulo;
    }

    /**
     * Linha de apoio exibida sob o titulo em todas as paginas.
     * Deve ser definida antes do primeiro conteudo, porque compoe o cabecalho.
     */
    public function subtitulo(string $texto): void
    {
        if ($this->iniciado) {
            throw new LogicException('Defina o subtitulo antes de adicionar conteudo ao PDF.');
        }

        $this->subtitulo = $texto;
    }

    /**
     * Desenha a tabela. $larguras usa a mesma unidade da folha e deve somar,
     * no maximo, a largura util (515 pt).
     */
    public function tabela(array $colunas, array $linhas, array $larguras): void
    {
        if (!$this->iniciado) {
            $this->abrirPagina();
        }

        $this->cabecalhoTabela($colunas, $larguras);

        foreach ($linhas as $linha) {
            // Reserva espaco para o rodape antes de aceitar mais uma linha.
            if ($this->cursor > self::ALTURA - self::MARGEM - (self::ALTURA_LINHA * 2)) {
                $this->fecharPagina();
                $this->abrirPagina();
                $this->cabecalhoTabela($colunas, $larguras);
            }

            $this->escreverLinha(array_values($linha), $larguras, false);
        }
    }

    /** Envia o arquivo ao navegador como download e encerra a execucao. */
    public function enviar(string $nomeArquivo): void
    {
        $conteudo = $this->montar();

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
        header('Content-Length: ' . strlen($conteudo));
        header('Cache-Control: private, max-age=0, must-revalidate');

        echo $conteudo;
        exit;
    }

    // -----------------------------------------------------------------
    // Montagem das paginas
    // -----------------------------------------------------------------

    /** Comeca uma pagina nova ja com o cabecalho do relatorio. */
    private function abrirPagina(): void
    {
        $this->iniciado = true;
        $this->atual    = '';
        $this->cursor   = self::MARGEM;

        $this->texto($this->titulo, self::MARGEM, $this->cursor, 15, true);
        $this->cursor += 20;

        if ($this->subtitulo !== '') {
            $this->texto($this->subtitulo, self::MARGEM, $this->cursor, 9);
            $this->cursor += 14;
        }

        $this->linhaHorizontal($this->cursor);
        $this->cursor += 12;
    }

    /** Guarda o fluxo da pagina atual na lista de paginas prontas. */
    private function fecharPagina(): void
    {
        $this->paginas[] = $this->atual;
    }

    /** Repete o titulo das colunas no inicio de cada pagina. */
    private function cabecalhoTabela(array $colunas, array $larguras): void
    {
        $this->escreverLinha($colunas, $larguras, true);
        $this->linhaHorizontal($this->cursor - 4);
        $this->cursor += 4;
    }

    /** Escreve uma linha da tabela distribuindo as celulas pelas larguras informadas. */
    private function escreverLinha(array $celulas, array $larguras, bool $negrito): void
    {
        $x = self::MARGEM;

        foreach ($celulas as $indice => $celula) {
            $largura = (float) ($larguras[$indice] ?? 80);
            $this->texto($this->truncar((string) $celula, $largura, 9), $x, $this->cursor, 9, $negrito);
            $x += $largura;
        }

        $this->cursor += self::ALTURA_LINHA;
    }

    /**
     * Corta o texto que nao cabe na coluna.
     * A largura media dos caracteres da Helvetica gira em torno de 0,5 do corpo,
     * o que basta para evitar que uma celula invada a seguinte.
     */
    private function truncar(string $texto, float $largura, float $corpo): string
    {
        $limite = (int) max(1, floor(($largura - 6) / ($corpo * 0.5)));

        return mb_strlen($texto) > $limite ? mb_substr($texto, 0, $limite - 1) . '.' : $texto;
    }

    /** Acrescenta um comando de texto ao fluxo da pagina atual. */
    private function texto(string $conteudo, float $x, float $distanciaDoTopo, float $corpo, bool $negrito = false): void
    {
        // No PDF a origem fica no canto inferior esquerdo, por isso a inversao do eixo Y.
        $y = self::ALTURA - $distanciaDoTopo - $corpo;

        $this->atual .= sprintf(
            "BT /%s %.2f Tf %.2f %.2f Td (%s) Tj ET\n",
            $negrito ? 'F2' : 'F1',
            $corpo,
            $x,
            $y,
            $this->escapar($conteudo)
        );
    }

    /** Traco horizontal usado para separar cabecalho e corpo. */
    private function linhaHorizontal(float $distanciaDoTopo): void
    {
        $y = self::ALTURA - $distanciaDoTopo;

        $this->atual .= sprintf(
            "0.7 w 0.8 0.8 0.8 RG %.2f %.2f m %.2f %.2f l S\n",
            self::MARGEM,
            $y,
            self::LARGURA - self::MARGEM,
            $y
        );
    }

    /**
     * Converte o texto para a codificacao das fontes padrao e protege os
     * caracteres que delimitam strings no formato.
     */
    private function escapar(string $texto): string
    {
        $convertido = iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $texto);
        $texto = $convertido !== false ? $convertido : $texto;

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ''], $texto);
    }

    // -----------------------------------------------------------------
    // Estrutura do arquivo
    // -----------------------------------------------------------------

    /** Monta o arquivo completo, com a tabela de referencias cruzadas. */
    private function montar(): string
    {
        $this->fecharPagina();

        $totalPaginas = count($this->paginas);
        $objetos = [];

        // 1: catalogo | 2: arvore de paginas | 3 e 4: fontes
        $idsPaginas = [];
        for ($indice = 0; $indice < $totalPaginas; $indice++) {
            $idsPaginas[] = (5 + ($indice * 2)) . ' 0 R';
        }

        $objetos[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objetos[2] = '<< /Type /Pages /Kids [' . implode(' ', $idsPaginas) . '] /Count ' . $totalPaginas . ' >>';
        $objetos[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objetos[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        foreach ($this->paginas as $indice => $fluxo) {
            $idPagina   = 5 + ($indice * 2);
            $idConteudo = $idPagina + 1;

            $objetos[$idPagina] = '<< /Type /Page /Parent 2 0 R'
                . sprintf(' /MediaBox [0 0 %.2f %.2f]', self::LARGURA, self::ALTURA)
                . ' /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >>'
                . ' /Contents ' . $idConteudo . ' 0 R >>';

            $objetos[$idConteudo] = '<< /Length ' . strlen($fluxo) . " >>\nstream\n" . $fluxo . 'endstream';
        }

        ksort($objetos);

        $arquivo = "%PDF-1.4\n";
        $posicoes = [];

        foreach ($objetos as $numero => $corpo) {
            $posicoes[$numero] = strlen($arquivo);
            $arquivo .= $numero . " 0 obj\n" . $corpo . "\nendobj\n";
        }

        // A tabela xref precisa apontar o byte exato onde cada objeto comeca.
        $inicioXref = strlen($arquivo);
        $totalObjetos = count($objetos) + 1;

        $arquivo .= "xref\n0 " . $totalObjetos . "\n";
        $arquivo .= "0000000000 65535 f \n";

        for ($numero = 1; $numero < $totalObjetos; $numero++) {
            $arquivo .= sprintf("%010d 00000 n \n", $posicoes[$numero] ?? 0);
        }

        $arquivo .= 'trailer << /Size ' . $totalObjetos . " /Root 1 0 R >>\n";
        $arquivo .= "startxref\n" . $inicioXref . "\n%%EOF";

        return $arquivo;
    }
}
