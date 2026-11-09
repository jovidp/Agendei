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

/**
 * Veste o texto com a marca. Os paragrafos vem em texto simples, um por linha
 * em branco. $codigo, quando vem, aparece num quadro grande (codigo de
 * confirmacao). Tudo em tabela e estilo inline: e o que Gmail e Outlook leem.
 */
function emailLayout(string $titulo, array $paragrafos, ?array $botao = null, ?string $codigo = null): string
{
    $fonte = "'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
    $corpo = '';
    foreach ($paragrafos as $i => $paragrafo) {
        $corpo .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.65;color:' . ($i === 0 ? '#1d2a30' : '#46545b') . '">' . nl2br(e($paragrafo)) . '</p>';
        if ($i === 0 && $codigo !== null) {
            $corpo .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:8px 0 22px"><tr><td align="center" style="background:#eef6f2;border:1px dashed #5faf8b;border-radius:12px;padding:22px 12px">'
                . '<div style="font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#46807f;font-weight:600;margin-bottom:8px">Seu código</div>'
                . '<div style="font-family:Consolas,Menlo,monospace;font-size:36px;letter-spacing:10px;font-weight:700;color:#1f4e5f;padding-left:10px">' . e($codigo) . '</div>'
                . '</td></tr></table>';
        }
    }
    if ($botao !== null) {
        $corpo .= '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:24px 0 8px"><tr><td style="border-radius:8px;background:#1f4e5f">'
            . '<a href="' . e($botao['url']) . '" style="display:inline-block;padding:13px 26px;font-size:15px;color:#ffffff;font-weight:600;text-decoration:none;border-radius:8px">' . e($botao['rotulo']) . ' &rarr;</a></td></tr></table>'
            . '<p style="margin:0 0 14px;font-size:12.5px;color:#7a868c;word-break:break-all">Se o botão não abrir, copie o link: ' . e($botao['url']) . '</p>';
    }
    // Primeira linha do texto aparece como previa na caixa de entrada.
    $previa = mb_substr($codigo !== null ? 'Seu código: ' . $codigo : (string) ($paragrafos[1] ?? $paragrafos[0] ?? ''), 0, 120);

    return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($titulo) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f5f1ea;font-family:' . $fonte . '">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . e($previa) . '</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f1ea;padding:32px 12px"><tr><td align="center">'
        . '<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;width:100%">'
        . '<tr><td style="padding:0 4px 16px;font-size:22px;font-weight:700;color:#1f4e5f;letter-spacing:-0.3px">&#128197; ' . e(NOME_SISTEMA) . '</td></tr>'
        . '<tr><td style="background:#ffffff;border-radius:14px;border:1px solid #e6e1d8;overflow:hidden">'
        . '<div style="height:6px;background:#1f4e5f;background-image:linear-gradient(90deg,#1f4e5f,#5faf8b);border-radius:14px 14px 0 0"></div>'
        . '<div style="padding:30px 32px 26px"><h1 style="margin:0 0 18px;font-size:23px;line-height:1.3;color:#1f4e5f;font-weight:700">' . e($titulo) . '</h1>' . $corpo . '</div>'
        . '</td></tr>'
        . '<tr><td align="center" style="padding:18px 12px 0;font-size:12.5px;line-height:1.6;color:#7a868c">'
        . '<strong style="color:#46545b">' . e(NOME_SISTEMA) . '</strong> &middot; ' . e(MARCA_SLOGAN) . '<br>Você recebeu este e-mail por causa de uma ação na sua conta.'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** Versao em texto: titulo, paragrafos, o codigo e, se houver, o link do botao. */
function emailTexto(string $titulo, array $paragrafos, ?array $botao = null, ?string $codigo = null): string
{
    if ($codigo !== null) {
        array_splice($paragrafos, 1, 0, ['Seu código: ' . $codigo]);
    }
    $texto = $titulo . "

" . implode("

", $paragrafos);
    if ($botao !== null) {
        $texto .= "

" . $botao['rotulo'] . ': ' . $botao['url'];
    }
    return $texto . "

-- 
" . NOME_SISTEMA . ' - ' . MARCA_SLOGAN;
}

/** Monta os tres formatos de uma vez. */
function emailMensagem(string $assunto, string $titulo, array $paragrafos, ?array $botao = null, ?string $codigo = null): array
{
    return [$assunto, emailTexto($titulo, $paragrafos, $botao, $codigo), emailLayout($titulo, $paragrafos, $botao, $codigo)];
}

/** Codigo de 6 digitos que confirma o e-mail da conta (VerificacaoEmail). */
function emailCodigoConfirmacao(string $nome, string $codigo): array
{
    $primeiroNome = explode(' ', trim($nome))[0] ?: $nome;
    return emailMensagem(
        $codigo . ' é o seu código de confirmação - ' . NOME_SISTEMA,
        'Confirme seu e-mail',
        [
            'Olá, ' . $primeiroNome . '! Sua conta no ' . NOME_SISTEMA . ' está criada. Falta só confirmar que este e-mail é seu: digite o código abaixo no aviso do seu painel.',
            'O código vale por ' . VerificacaoEmail::VALIDADE_MINUTOS . ' minutos. Se expirar, é só pedir outro no painel.',
            'Não foi você que criou a conta? Pode ignorar este e-mail.',
        ],
        ['rotulo' => 'Confirmar no painel', 'url' => urlAbsoluta('verificar_email.php')],
        $codigo
    );
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

/** Codigo e link de redefinicao de senha: o codigo serve na mesma tela, o link em qualquer aparelho. */
function emailRecuperacaoSenha(string $nome, string $link, string $empresa, ?string $codigo = null): array
{
    $primeiroNome = explode(' ', trim($nome))[0] ?: $nome;
    return emailMensagem(
        ($codigo !== null ? $codigo . ' é o seu código para redefinir a senha' : 'Redefinição de senha') . ' - ' . $empresa,
        'Redefinir sua senha',
        [
            'Olá, ' . $primeiroNome . '. Recebemos um pedido para redefinir a senha da sua conta em ' . $empresa . '.'
                . ($codigo !== null ? ' Digite o código abaixo na tela de recuperação:' : ''),
            ($codigo !== null ? 'Está em outro aparelho? Use o botão abaixo. ' : 'Use o botão abaixo para escolher uma senha nova. ')
                . 'O código e o link valem por tempo limitado e só funcionam uma vez.',
            'Não foi você? Ignore este e-mail. Sua senha atual continua valendo e ninguém entra sem o código.',
        ],
        ['rotulo' => 'Redefinir senha', 'url' => $link],
        $codigo
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
