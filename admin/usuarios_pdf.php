<?php
/**
 * Download da lista de usuarios em PDF (desafio extra da especificacao).
 * Respeita a pesquisa aplicada na tela de consulta.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/../config/config.php';

// Somente o perfil master gera o relatorio.
exigirLogin('admin');

$busca   = get('busca');
$filtros = array_filter(['busca' => $busca]);

// Sem paginacao: o relatorio traz a consulta inteira.
$lista = Cliente::listar($filtros);

$estabelecimento = Estabelecimento::dados();

$pdf = new PdfSimples('Usuarios cadastrados - ' . $estabelecimento['nome']);
$pdf->subtitulo(
    'Gerado em ' . date('d/m/Y \a\s H:i')
    . ' | ' . count($lista) . ' registro(s)'
    . ($busca !== '' ? ' | pesquisa: "' . $busca . '"' : '')
);

// Cada linha segue a ordem das colunas declaradas logo abaixo.
$linhas = [];
foreach ($lista as $usuario) {
    $linhas[] = [
        $usuario['nome'],
        $usuario['login'] ?: '-',
        formatarCpf($usuario['cpf']),
        $usuario['email'],
        formatarTelefoneInternacional($usuario['telefone']),
        formatarData($usuario['data_cadastro']),
    ];
}

if ($linhas === []) {
    $linhas[] = ['Nenhum usuario encontrado.', '', '', '', '', ''];
}

// As larguras somam 502 pt e cabem na area util da folha (515 pt).
$pdf->tabela(
    ['Nome', 'Login', 'CPF', 'E-mail', 'Celular', 'Cadastro'],
    $linhas,
    [130, 42, 72, 118, 85, 55]
);

$pdf->enviar('usuarios-' . date('Y-m-d') . '.pdf');
