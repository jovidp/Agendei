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

/** Marca do Google no botao "Entrar com o Google" (as quatro cores oficiais, em SVG). */
function iconeGoogle(): string
{
    return '<svg viewBox="0 0 48 48" aria-hidden="true" focusable="false">'
        . '<path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.8 2.5 30.3 0 24 0 14.6 0 6.5 5.4 2.6 13.3l7.9 6.1C12.4 13.6 17.7 9.5 24 9.5z"/>'
        . '<path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.7 6c4.5-4.2 6.9-10.3 6.9-17.7z"/>'
        . '<path fill="#FBBC05" d="M10.5 28.6A14.5 14.5 0 0 1 9.5 24c0-1.6.3-3.2.8-4.6l-7.9-6.1A24 24 0 0 0 0 24c0 3.9.9 7.5 2.6 10.7l7.9-6.1z"/>'
        . '<path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.7-6c-2.1 1.4-4.9 2.3-8.2 2.3-6.3 0-11.6-4.1-13.5-9.9l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/>'
        . '</svg>';
}


/** Cores oficiais da marca. Coincidem com Tema::PADRAO, que e o tema de fabrica. */
const MARCA_COR_PRIMARIA = '#1F4E5F';
const MARCA_COR_DESTAQUE = '#5FAF8B';
const MARCA_COR_CLARA    = '#F5F1EA';

/** Desenho do simbolo (viewBox 48x48). Os SVGs de assets/img/ usam os mesmos caminhos. */
const MARCA_PATH_CORPO = 'M0.4 12.8A7.5 7.5 0 0 1 7.9 5.3H35.1Q37 5.3 38.4 6.3L29.7 15.9H6.8Q5.8 15.9 5.8 16.9V38.8A2.9 2.9 0 0 0 8.7 41.7H35.3A2.65 2.65 0 0 1 35.3 47H8.3A7.9 7.9 0 0 1 0.4 39.1Z';
const MARCA_PATH_ABAS  = 'M12.5 3.4v4.5M29.8 3.4v4.5';
const MARCA_PATH_CHECK = 'M13.6 26.4L20.4 34L44.2 7.9';
const MARCA_PATH_A     = 'M36.2 25.3L40.8 19.5L47.6 42.6A2.9 2.9 0 0 1 42 44.2ZM27.6 33.9L32.1 28.8H37.4L38.9 33.9Z';

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
    $verde = MARCA_COR_DESTAQUE;

    // Agenda aberta com o "check" saindo dela; a perna do "A" completa a inicial.
    // Cada parte leva uma classe para que o modo de alto contraste possa repintar o desenho.
    return '<svg class="marca-sistema-simbolo" width="' . $tamanho . '" height="' . $tamanho . '" '
        . 'viewBox="0 0 48 48" aria-hidden="true" focusable="false">'
        . '<path class="marca-corpo" d="' . MARCA_PATH_CORPO . '" fill="' . $corpo . '"/>'
        . '<path class="marca-abas" d="' . MARCA_PATH_ABAS . '" stroke="' . $verde . '" stroke-width="4.8" stroke-linecap="round"/>'
        . '<path class="marca-check" d="' . MARCA_PATH_CHECK . '" fill="none" stroke="' . $verde . '" stroke-width="4.9" '
        . 'stroke-linecap="round" stroke-linejoin="round"/>'
        . '<path class="marca-faixa" d="' . MARCA_PATH_A . '" fill="' . $verde . '"/>'
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
