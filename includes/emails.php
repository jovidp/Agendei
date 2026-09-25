<?php
/**
 * Mensagens de e-mail do sistema.
 *
 * Cada funcao devolve [assunto, texto, html]: a versao em texto e a que vale,
 * o HTML so veste a mesma mensagem com a marca. Quem envia e models/Email.php;
 * aqui nao ha nada de SMTP.
 *
 * Os links sao absolutos porque o e-mail e lido fora do sistema.
 */

/** Endereco completo (esquema, host e caminho) de uma pagina do sistema. */
function urlAbsoluta(string $caminho = ''): string
{
    // Endereco publico fixo (AGENDEI_URL, ex.: https://agendei.exemplo.com): vale
    // para a tarefa periodica e a linha de comando, que nao recebem requisicao.
    $fixo = rtrim((string) (getenv('AGENDEI_URL') ?: ''), '/');
    if ($fixo !== '') {
        // url() ja acrescenta a empresa ao caminho; so o caminho base da a vez ao endereco fixo.
        return $fixo . substr(url($caminho), strlen(BASE_URL));
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return url($caminho);
    }
    $https = requisicaoSegura() || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    return ($https ? 'https://' : 'http://') . $host . url($caminho);
}

/** Veste o texto com a marca. Os paragrafos vem em texto simples, um por linha em branco. */
function emailLayout(string $titulo, array $paragrafos, ?array $botao = null): string
{
    $corpo = '';
    foreach ($paragrafos as $paragrafo) {
        $corpo .= '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#2e3438">' . nl2br(e($paragrafo)) . '</p>';
    }
    if ($botao !== null) {
        $corpo .= '<p style="margin:22px 0 6px"><a href="' . e($botao['url']) . '" style="display:inline-block;padding:12px 22px;border-radius:6px;background:#1f4e5f;color:#ffffff;font-weight:600;text-decoration:none">' . e($botao['rotulo']) . '</a></p>'
            . '<p style="margin:0 0 14px;font-size:13px;color:#636d72;word-break:break-all">' . e($botao['url']) . '</p>';
    }

    return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><title>' . e($titulo) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f5f1ea;font-family:Segoe UI,Roboto,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f1ea;padding:28px 12px"><tr><td align="center">'
        . '<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden">'
        . '<tr><td style="background:#1f4e5f;padding:18px 26px;color:#ffffff;font-size:20px;font-weight:700">' . e(NOME_SISTEMA) . '</td></tr>'
        . '<tr><td style="padding:26px"><h1 style="margin:0 0 16px;font-size:21px;line-height:1.25;color:#1f4e5f">' . e($titulo) . '</h1>' . $corpo . '</td></tr>'
        . '<tr><td style="padding:14px 26px;border-top:1px solid #e6e1d8;font-size:12.5px;color:#636d72">' . e(NOME_SISTEMA) . ' &middot; ' . e(MARCA_SLOGAN) . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** Versao em texto: titulo, paragrafos e, se houver, o link do botao. */
function emailTexto(string $titulo, array $paragrafos, ?array $botao = null): string
{
    $texto = $titulo . "\n\n" . implode("\n\n", $paragrafos);
    if ($botao !== null) {
        $texto .= "\n\n" . $botao['rotulo'] . ': ' . $botao['url'];
    }
    return $texto . "\n\n-- \n" . NOME_SISTEMA . ' - ' . MARCA_SLOGAN;
}

/** Monta os tres formatos de uma vez. */
function emailMensagem(string $assunto, string $titulo, array $paragrafos, ?array $botao = null): array
{
    return [$assunto, emailTexto($titulo, $paragrafos, $botao), emailLayout($titulo, $paragrafos, $botao)];
}

// ---------------------------------------------------------------------
// Cadastro de empresa
// ---------------------------------------------------------------------

/** Para o responsavel, logo depois do envio do cadastro. */
function emailCadastroRecebido(array $solicitacao): array
{
    $link = urlAbsoluta('login.php?estabelecimento=' . rawurlencode($solicitacao['slug']));
    return emailMensagem(
        'Recebemos o cadastro de ' . $solicitacao['estabelecimento_nome'],
        'Cadastro recebido',
        [
            'Olá, ' . $solicitacao['responsavel'] . '.',
            'Recebemos o cadastro de ' . $solicitacao['estabelecimento_nome'] . ' no ' . NOME_SISTEMA . '. Vamos conferir os dados e avisar por este e-mail assim que o acesso for liberado. Costuma ser rápido.',
            'Depois da aprovação, o endereço da sua empresa será o link abaixo. A senha é a que você escolheu no cadastro.',
        ],
        ['rotulo' => 'Endereço da empresa', 'url' => $link]
    );
}

/** Para os masters: um cadastro novo espera decisao. */
function emailCadastroParaMaster(array $solicitacao): array
{
    $link = urlAbsoluta('master/estabelecimentos.php#cadastros-pendentes');
    return emailMensagem(
        'Novo cadastro de empresa: ' . $solicitacao['estabelecimento_nome'],
        'Cadastro aguardando aprovação',
        [
            $solicitacao['estabelecimento_nome'] . ' (' . $solicitacao['slug'] . ') se cadastrou pela página inicial e aguarda a sua decisão.',
            'Responsável: ' . $solicitacao['responsavel'] . "\nE-mail: " . $solicitacao['email'] . "\nTelefone: " . ((string) ($solicitacao['telefone'] ?? '') ?: 'não informado'),
            'Mensagem: ' . ((string) ($solicitacao['mensagem'] ?? '') ?: 'nenhuma'),
        ],
        ['rotulo' => 'Ver e decidir', 'url' => $link]
    );
}

/** Para o responsavel, quando o master aprova. */
function emailCadastroAprovado(array $solicitacao): array
{
    $link = urlAbsoluta('login.php?estabelecimento=' . rawurlencode($solicitacao['slug']));
    return emailMensagem(
        $solicitacao['estabelecimento_nome'] . ' está no ar no ' . NOME_SISTEMA,
        'Acesso liberado',
        [
            'Olá, ' . $solicitacao['responsavel'] . '.',
            'O cadastro de ' . $solicitacao['estabelecimento_nome'] . ' foi aprovado. Entre com o e-mail ' . $solicitacao['email'] . ' e a senha que você escolheu.',
            'Primeiros passos: cadastre os serviços, a equipe e os horários em Admin. Em Aparência você coloca a logo e as cores da empresa.',
        ],
        ['rotulo' => 'Entrar no painel', 'url' => $link]
    );
}

/** Para o responsavel, quando o master recusa. */
function emailCadastroRecusado(array $solicitacao): array
{
    return emailMensagem(
        'Sobre o cadastro de ' . $solicitacao['estabelecimento_nome'],
        'Cadastro não aprovado',
        [
            'Olá, ' . $solicitacao['responsavel'] . '.',
            'Não foi possível aprovar o cadastro de ' . $solicitacao['estabelecimento_nome'] . ' no ' . NOME_SISTEMA . ' neste momento. Os dados enviados foram removidos.',
            'Se acredita que houve um engano, responda a este e-mail que a gente confere.',
        ]
    );
}

// ---------------------------------------------------------------------
// Contas
// ---------------------------------------------------------------------

/** Link de redefinicao de senha. */
function emailRecuperacaoSenha(string $nome, string $link, string $empresa): array
{
    return emailMensagem(
        'Redefinição de senha - ' . $empresa,
        'Redefinir sua senha',
        [
            'Olá, ' . $nome . '.',
            'Recebemos um pedido para redefinir a senha da sua conta em ' . $empresa . '. Use o botão abaixo para escolher uma senha nova. O link vale por tempo limitado.',
            'Se não foi você, ignore este e-mail. A senha atual continua valendo.',
        ],
        ['rotulo' => 'Redefinir senha', 'url' => $link]
    );
}

/** Mensagem de teste, disparada pelo master na tela de saude. */
function emailTeste(string $nome): array
{
    return emailMensagem(
        'Teste de envio do ' . NOME_SISTEMA,
        'O envio de e-mail está funcionando',
        [
            'Olá, ' . $nome . '.',
            'Esta mensagem foi enviada pela tela Saúde do sistema. Se ela chegou, cadastros, aprovações e recuperação de senha também chegam.',
        ]
    );
}
