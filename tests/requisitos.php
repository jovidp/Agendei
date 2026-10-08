<?php

/**
 * Teste das regras exigidas pela especificacao do projeto.
 * Cobre validacao de cadastro, formatacao e o segundo fator de autenticacao.
 * Nao depende de banco de dados: execute com "php tests/requisitos.php".
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(dirname(__DIR__));

// Constantes que o segundo fator por codigo consulta ao proteger o segredo.
// Aqui nao ha segredo cifrado para ler, mas definir evita depender de ordem.
define('AMBIENTE', 'desenvolvimento');
define('RAIZ', dirname(__DIR__));

// Carrega apenas os arquivos de funcoes: nenhuma consulta e feita neste teste.
require_once 'includes/funcoes.php';
require_once 'includes/auth.php';

// Usuario e Totp entram porque exigeSegundoFator() precisa saber se a conta ja
// cadastrou o aplicativo autenticador. Carregar as classes nao abre conexao:
// so a chamada de um metodo que consulta o banco faria isso, e nenhuma ocorre
// neste teste.
require_once 'models/Totp.php';
require_once 'models/Usuario.php';

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
    echo "  FALHA  {$descricao}\n";
    echo '         esperado: ' . var_export($esperado, true) . "\n";
    echo '         obtido:   ' . var_export($obtido, true) . "\n";
}

// ---------------------------------------------------------------------
// Nome completo: de 15 a 80 caracteres alfabeticos
// ---------------------------------------------------------------------
verificar('nome com 14 caracteres e recusado', validarNomeCompleto('Ana Beatriz Li'), false);
verificar('nome com 15 caracteres e aceito', validarNomeCompleto('Ana Beatriz Lim'), true);
verificar('nome com acento e aceito', validarNomeCompleto('João Marcelo de Assunção'), true);
verificar('nome com numero e recusado', validarNomeCompleto('Ana Beatriz Lima 2'), false);
verificar('nome com 80 caracteres e aceito', validarNomeCompleto(str_repeat('a', 80)), true);
verificar('nome com 81 caracteres e recusado', validarNomeCompleto(str_repeat('a', 81)), false);
verificar('espacos repetidos nao contam duas vezes', validarNomeCompleto('Ana    Beatriz    Lim'), true);

// Nome com sobrenome: quem responde pela empresa precisa de pelo menos duas palavras
verificar('nome sem sobrenome e recusado', validarNomeSobrenome('Ana'), false);
verificar('nome com sobrenome e aceito', validarNomeSobrenome('Ana Souza'), true);
verificar('nome com sobrenome acentuado e aceito', validarNomeSobrenome('José Responsável'), true);
verificar('sobrenome composto com hifen e aceito', validarNomeSobrenome('Ana Souza-Lima'), true);
verificar('nome com numero e recusado', validarNomeSobrenome('Ana Souza 2'), false);
verificar('espacos extras nao criam sobrenome', validarNomeSobrenome('  Ana   '), false);
verificar('nome com sobrenome acima de 120 caracteres e recusado', validarNomeSobrenome(str_repeat('a', 60) . ' ' . str_repeat('b', 60)), false);

// ---------------------------------------------------------------------
// Login: exatamente 6 caracteres alfabeticos
// ---------------------------------------------------------------------
verificar('login com 6 letras e aceito', validarLogin('anabea'), true);
verificar('login com 5 letras e recusado', validarLogin('anabe'), false);
verificar('login com 7 letras e recusado', validarLogin('anabeat'), false);
verificar('login com numero e recusado', validarLogin('anabe1'), false);
verificar('login com acento e recusado', validarLogin('joãoab'), false);

// ---------------------------------------------------------------------
// Senha: exatamente 8 caracteres alfabeticos
// ---------------------------------------------------------------------
verificar('senha com 8 letras e aceita', validarSenhaProjeto('agendeis'), true);
verificar('senha com 7 letras e recusada', validarSenhaProjeto('agendei'), false);
verificar('senha com digito e recusada', validarSenhaProjeto('agende12'), false);
verificar('senha com simbolo e recusada', validarSenhaProjeto('agende!s'), false);

// ---------------------------------------------------------------------
// CPF: conferencia do digito verificador
// ---------------------------------------------------------------------
verificar('CPF valido e aceito', validarCpf('529.982.247-25'), true);
verificar('CPF com DV errado e recusado', validarCpf('529.982.247-26'), false);
verificar('CPF com digitos repetidos e recusado', validarCpf('111.111.111-11'), false);

// ---------------------------------------------------------------------
// Telefones e CEP
// ---------------------------------------------------------------------
verificar('celular com 11 digitos e aceito', validarTelefoneBr('(11) 99111-1111', true), true);
verificar('celular com 10 digitos e recusado', validarTelefoneBr('(11) 9111-1111', true), false);
verificar('fixo com 10 digitos e aceito', validarTelefoneBr('(11) 3311-1111'), true);
verificar('fixo com 11 digitos e recusado', validarTelefoneBr('(11) 93311-1111'), false);
verificar('celular formatado como pede a especificacao', formatarTelefoneInternacional('11991111111'), '(+55)11-991111111');
verificar('fixo formatado como pede a especificacao', formatarTelefoneInternacional('1133111111'), '(+55)11-33111111');
verificar('CEP com 8 digitos e aceito', validarCep('01310-100'), true);
verificar('CEP com 7 digitos e recusado', validarCep('0131010'), false);
verificar('CEP formatado com hifen', formatarCep('01310100'), '01310-100');

// ---------------------------------------------------------------------
// Demais campos do cadastro
// ---------------------------------------------------------------------
verificar('nome materno com 5 letras e aceito', validarNomeMaterno('Marcia'), true);
verificar('nome materno com 4 letras e recusado', validarNomeMaterno('Ana'), false);
verificar('nome materno com numero e recusado', validarNomeMaterno('Marcia 2'), false);
verificar('UF valida e aceita', validarUf('sp'), true);
verificar('UF inexistente e recusada', validarUf('XX'), false);
verificar('rotulo do sexo feminino', sexoTexto('F'), 'Feminino');
verificar('rotulo do sexo ausente', sexoTexto(null), '-');

// ---------------------------------------------------------------------
// Segundo fator: perguntas disponiveis por conta
// ---------------------------------------------------------------------
$comum = [
    'id_usuario'      => 7,
    'tipo'            => 'cliente',
    'nome_materno'    => 'Márcia Lima Souza',
    'data_nascimento' => '1995-04-18',
    'cep'             => '01310100',
];
$master = ['id_usuario' => 1, 'tipo' => 'admin'] + $comum;
$profissional = ['id_usuario' => 3, 'tipo' => 'profissional'] + $comum;
$semDados = ['id_usuario' => 9, 'tipo' => 'cliente', 'nome_materno' => '', 'data_nascimento' => null, 'cep' => ''];

verificar('conta completa oferece as 3 perguntas', array_keys(fatoresDisponiveis($comum)), ['nome_materno', 'data_nascimento', 'cep']);
verificar('conta sem dados nao oferece pergunta', fatoresDisponiveis($semDados), []);
verificar('usuario comum passa pelo 2FA', exigeSegundoFator($comum), true);
verificar('usuario master passa pelo 2FA', exigeSegundoFator($master), true);
verificar('profissional fica fora do 2FA', exigeSegundoFator($profissional), false);
verificar('conta sem dados nao entra no 2FA', exigeSegundoFator($semDados), false);

// ---------------------------------------------------------------------
// Segundo fator: conferencia da resposta
// ---------------------------------------------------------------------
verificar('nome da mae exato', respostaSegundoFatorConfere('nome_materno', 'Márcia Lima Souza', $comum), true);
verificar('nome da mae sem acento', respostaSegundoFatorConfere('nome_materno', 'Marcia Lima Souza', $comum), true);
verificar('nome da mae em caixa alta', respostaSegundoFatorConfere('nome_materno', 'MARCIA LIMA SOUZA', $comum), true);
verificar('nome da mae com espacos sobrando', respostaSegundoFatorConfere('nome_materno', '  Marcia   Lima Souza  ', $comum), true);
verificar('nome da mae errado', respostaSegundoFatorConfere('nome_materno', 'Marcia Souza', $comum), false);
verificar('nome da mae vazio', respostaSegundoFatorConfere('nome_materno', '   ', $comum), false);

verificar('nascimento no formato brasileiro', respostaSegundoFatorConfere('data_nascimento', '18/04/1995', $comum), true);
verificar('nascimento no formato do banco', respostaSegundoFatorConfere('data_nascimento', '1995-04-18', $comum), true);
verificar('nascimento errado', respostaSegundoFatorConfere('data_nascimento', '17/04/1995', $comum), false);

verificar('CEP com mascara', respostaSegundoFatorConfere('cep', '01310-100', $comum), true);
verificar('CEP sem mascara', respostaSegundoFatorConfere('cep', '01310100', $comum), true);
verificar('CEP errado', respostaSegundoFatorConfere('cep', '01310-101', $comum), false);
verificar('CEP vazio', respostaSegundoFatorConfere('cep', '', $comum), false);

// Uma pergunta que a conta nao consegue responder nunca pode ser dada como certa.
verificar('pergunta indisponivel e recusada', respostaSegundoFatorConfere('cep', '', $semDados), false);
verificar('pergunta desconhecida e recusada', respostaSegundoFatorConfere('inexistente', 'qualquer', $comum), false);

// ---------------------------------------------------------------------
// Endereco montado a partir das colunas separadas
// ---------------------------------------------------------------------
verificar(
    'endereco completo em uma linha',
    enderecoTexto([
        'logradouro' => 'Avenida Paulista', 'numero' => '1000', 'complemento' => 'Apto 52',
        'bairro' => 'Bela Vista', 'cidade' => 'Sao Paulo', 'uf' => 'SP', 'cep' => '01310100',
    ]),
    'Avenida Paulista, 1000 - Apto 52 - Bela Vista - Sao Paulo/SP - 01310-100'
);
verificar(
    'endereco vazio',
    enderecoTexto(['logradouro' => '', 'numero' => '', 'complemento' => '', 'bairro' => '', 'cidade' => '', 'uf' => '', 'cep' => '']),
    '-'
);

// ---------------------------------------------------------------------
echo "\n{$total} verificacao(oes), {$falhas} falha(s).\n";
exit($falhas === 0 ? 0 : 1);
