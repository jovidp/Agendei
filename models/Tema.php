<?php
/** Identidade visual validada. Não aceita CSS, HTML nem nomes de fonte arbitrários. */
class Tema
{
    public const PADRAO = ['cor_primaria' => '#1F4E5F', 'cor_secundaria' => '#5FAF8B', 'cor_fundo' => '#F5F1EA', 'fonte' => 'padrao'];
    public const FONTES = [
        'padrao' => ['Padrão do sistema', '"Segoe UI", -apple-system, BlinkMacSystemFont, Roboto, Arial, sans-serif'],
        'arial' => ['Arial', 'Arial, Helvetica, sans-serif'],
        'verdana' => ['Verdana', 'Verdana, Geneva, sans-serif'],
        'georgia' => ['Georgia', 'Georgia, "Times New Roman", serif'],
        'trebuchet' => ['Trebuchet MS', '"Trebuchet MS", Arial, sans-serif'],
        'calibri' => ['Calibri', 'Calibri, Candara, "Segoe UI", sans-serif'],
        'tahoma' => ['Tahoma', 'Tahoma, Verdana, sans-serif'],
        'century' => ['Century Gothic', '"Century Gothic", Futura, Arial, sans-serif'],
        'garamond' => ['Garamond', 'Garamond, Georgia, serif'],
        'palatino' => ['Palatino', 'Palatino, "Palatino Linotype", Georgia, serif'],
        'times' => ['Times New Roman', '"Times New Roman", Times, serif'],
        'courier' => ['Courier New', '"Courier New", Courier, monospace'],
        'lucida' => ['Lucida Sans', '"Lucida Sans Unicode", "Lucida Grande", sans-serif'],
        'bookman' => ['Bookman', '"Bookman Old Style", Georgia, serif'],
    ];

    public static function valores(array $dados): array
    {
        $tema = self::PADRAO;
        foreach (['cor_primaria', 'cor_secundaria', 'cor_fundo'] as $campo) {
            if (isset($dados[$campo]) && is_string($dados[$campo]) && preg_match('/^#[0-9a-f]{6}$/iD', $dados[$campo])) $tema[$campo] = strtoupper($dados[$campo]);
        }
        if (isset($dados['fonte']) && is_string($dados['fonte']) && isset(self::FONTES[$dados['fonte']])) $tema['fonte'] = $dados['fonte'];
        return $tema;
    }

    public static function validar(array $dados): array
    {
        $erros = [];
        foreach (['cor_primaria', 'cor_secundaria', 'cor_fundo'] as $campo) {
            if (!isset($dados[$campo]) || !is_string($dados[$campo]) || !preg_match('/^#[0-9a-f]{6}$/iD', $dados[$campo])) $erros[] = 'Selecione uma cor válida para ' . str_replace('_', ' ', $campo) . '.';
        }
        if (!isset($dados['fonte']) || !is_string($dados['fonte']) || !isset(self::FONTES[$dados['fonte']])) $erros[] = 'Selecione uma das fontes disponíveis.';
        return $erros;
    }

    /** Deriva as variações de uma cor para que botões, estados e fundos usem a mesma identidade. */
    private static function misturar(string $cor, int $destino, float $peso): string
    {
        $rgb = sscanf($cor, '#%02x%02x%02x');
        return '#' . implode('', array_map(fn ($v) => sprintf('%02X', (int) round($v * (1 - $peso) + $destino * $peso)), $rgb));
    }

    private static function textoSobre(string $cor): string
    {
        $rgb = array_map(static function ($v) { $v /= 255; return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4; }, sscanf($cor, '#%02x%02x%02x'));
        return $rgb[0] * 0.2126 + $rgb[1] * 0.7152 + $rgb[2] * 0.0722 > 0.179 ? '#000000' : '#FFFFFF';
    }

    public static function variaveis(array $dados): array
    {
        $t = self::valores($dados);
        return [
            '--cor-primaria' => $t['cor_primaria'], '--cor-primaria-escura' => self::misturar($t['cor_primaria'], 0, .25),
            '--cor-primaria-clara' => self::misturar($t['cor_primaria'], 255, .9),
            '--cor-secundaria' => $t['cor_secundaria'], '--cor-secundaria-escura' => self::misturar($t['cor_secundaria'], 0, .25),
            '--cor-secundaria-clara' => self::misturar($t['cor_secundaria'], 255, .9),
            '--fundo' => $t['cor_fundo'], '--fonte-interface' => self::FONTES[$t['fonte']][1],
            '--sobre-primaria' => self::textoSobre($t['cor_primaria']), '--sobre-secundaria' => self::textoSobre($t['cor_secundaria']),
        ];
    }

    public static function estilo(array $dados): string
    {
        $css = '';
        foreach (self::variaveis($dados) as $nome => $valor) $css .= $nome . ':' . $valor . ';';
        return $css;
    }

    /** Guarda a imagem como dados no banco, sem criar arquivos executáveis na pasta pública. */
    public static function receberLogo(array $arquivo): string
    {
        if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Não foi possível receber a logo. Escolha uma imagem de até 512 KB.');
        $caminho = $arquivo['tmp_name'] ?? '';
        if (!is_string($caminho) || !is_uploaded_file($caminho) || filesize($caminho) > 512 * 1024) throw new InvalidArgumentException('A logo deve ter até 512 KB.');
        $bytes = file_get_contents($caminho);
        return self::validarImagem($bytes);
    }

    public static function validarImagem(string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);
        $tipos = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp'];
        if (strlen($bytes) > 512 * 1024 || !$info || !isset($tipos[$info[2]]) || $info[0] > 2048 || $info[1] > 2048) {
            throw new InvalidArgumentException('Use uma imagem PNG, JPG ou WebP de até 512 KB e 2048 × 2048 pixels.');
        }
        return 'data:' . $tipos[$info[2]] . ';base64,' . base64_encode($bytes);
    }

    public static function logoValida(?string $logo): bool
    {
        return is_string($logo) && (bool) preg_match('~^data:image/(?:png|jpeg|webp);base64,[A-Za-z0-9+/=]+$~D', $logo);
    }

    /** O nome aparece junto à marca, por isso a imagem usa alt vazio para evitar leitura duplicada. */
    public static function marca(array $dados): string
    {
        return self::logoValida($dados['logo'] ?? null)
            ? '<img class="marca-logo" src="' . e($dados['logo']) . '" alt="">'
            : '<span class="marca-simbolo">' . e(mb_substr($dados['nome'], 0, 1)) . '</span>';
    }
}
