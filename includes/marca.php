<?php
/**
 * Marca do sistema.
 *
 * O simbolo e uma agenda confirmada: a faixa superior na cor de destaque e o
 * "check" do horario marcado no corpo. As cores aqui sao fixas de proposito -
 * identificam o produto, e nao a empresa atendida. A identidade de cada empresa
 * continua vindo de Tema::marca(), que respeita a logo e as cores cadastradas.
 *
 * Os mesmos desenhos estao em assets/img/ para uso fora do sistema
 * (documentacao, e-mail, material impresso).
 */

/** Cores oficiais da marca. Coincidem com Tema::PADRAO, que e o tema de fabrica. */
const MARCA_COR_PRIMARIA = '#1F4E5F';
const MARCA_COR_DESTAQUE = '#5FAF8B';
const MARCA_COR_CLARA    = '#F5F1EA';

/**
 * Slogan oficial. Duas palavras que descrevem o simbolo (a agenda com o
 * "check") e a promessa do produto: o horario marcado e um horario garantido.
 * Vale para a entrada geral, material de divulgacao e assinaturas; a frase de
 * apoio mais longa fica em MARCA.md.
 */
const MARCA_SLOGAN = 'Marcou, confirmou.';

/**
 * Simbolo da marca em SVG embutido.
 * $invertida monta a versao de fundo escuro (rodape e menu lateral).
 */
function simboloSistema(int $tamanho = 32, bool $invertida = false): string
{
    $corpo = $invertida ? MARCA_COR_CLARA : MARCA_COR_PRIMARIA;
    $check = $invertida ? MARCA_COR_PRIMARIA : '#FFFFFF';

    // Cada parte leva uma classe para que o modo de alto contraste possa repintar o desenho.
    return '<svg class="marca-sistema-simbolo" width="' . $tamanho . '" height="' . $tamanho . '" '
        . 'viewBox="0 0 48 48" aria-hidden="true" focusable="false">'
        . '<path class="marca-abas" d="M15 6v7M33 6v7" stroke="' . $corpo . '" stroke-width="3.6" stroke-linecap="round"/>'
        . '<path class="marca-faixa" d="M6 18a8 8 0 0 1 8-8h20a8 8 0 0 1 8 8v2H6z" fill="' . MARCA_COR_DESTAQUE . '"/>'
        . '<path class="marca-corpo" d="M6 20h36v16a8 8 0 0 1-8 8H14a8 8 0 0 1-8-8z" fill="' . $corpo . '"/>'
        . '<path class="marca-check" d="M16 32 L21.5 37.5 L32.5 26" fill="none" stroke="' . $check . '" stroke-width="4.2" '
        . 'stroke-linecap="round" stroke-linejoin="round"/>'
        . '</svg>';
}

/**
 * Assinatura completa: simbolo mais o nome do sistema.
 * O nome acompanha a imagem, por isso o SVG fica marcado como decorativo.
 */
function marcaSistema(int $tamanho = 32, bool $invertida = false, string $classeExtra = ''): string
{
    $classes = 'marca-sistema'
        . ($invertida ? ' marca-sistema-invertida' : '')
        . ($classeExtra !== '' ? ' ' . $classeExtra : '');

    return '<span class="' . e($classes) . '">'
        . simboloSistema($tamanho, $invertida)
        . '<span class="marca-sistema-nome">' . e(NOME_SISTEMA) . '</span>'
        . '</span>';
}

/**
 * Icones da aba do navegador.
 * A cor da barra do celular acompanha o tema da empresa quando ele e informado.
 */
function faviconSistema(?string $corTema = null): string
{
    $cor = ($corTema !== null && preg_match('/^#[0-9a-f]{6}$/iD', $corTema)) ? $corTema : MARCA_COR_PRIMARIA;

    return '<link rel="icon" href="' . e(url('assets/img/favicon.svg')) . '" type="image/svg+xml">'
        . '<link rel="apple-touch-icon" href="' . e(url('assets/img/agendei-simbolo.svg')) . '">'
        . '<meta name="theme-color" content="' . e($cor) . '">';
}
