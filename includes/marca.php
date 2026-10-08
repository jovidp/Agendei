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
