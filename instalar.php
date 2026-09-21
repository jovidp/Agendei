<?php
/**
 * Instalador de dados iniciais.
 *
 * Cria o administrador, os profissionais de exemplo (com expediente),
 * um cliente demonstrativo e alguns agendamentos.
 * As senhas sao geradas com password_hash(), por isso este passo nao fica no banco.sql.
 *
 * APAGUE ESTE ARQUIVO depois de executar em producao.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/config/config.php';

/**
 * Em producao o instalador so existe para quem conhece a chave.
 *
 * Esta pagina cria o administrador e a conta master com senhas padrao que estao
 * escritas na documentacao do projeto. Um banco ainda vazio — servidor novo,
 * migracao interrompida, restauracao pela metade — deixaria qualquer visitante
 * criar essas contas e entrar como dono do sistema. A chave vem da variavel de
 * ambiente AGENDEI_TOKEN_INSTALACAO; sem ela definida, a pagina simplesmente
 * nao existe em producao.
 */
if (AMBIENTE === 'producao') {
    $chaveEsperada = (string) (getenv('AGENDEI_TOKEN_INSTALACAO') ?: '');
    $chaveRecebida = get('chave');

    // Poucas tentativas por origem impedem que a chave seja descoberta por repeticao.
    if (conferirBloqueio(['instalador_ip' => ipCliente()]) !== '') {
        http_response_code(404);
        exit;
    }

    if ($chaveEsperada === '' || !hash_equals($chaveEsperada, $chaveRecebida)) {
        anotarFalha('instalador_ip', ipCliente());
        registrarEventoSeguranca('instalador_recusado');
        atrasarResposta();
        http_response_code(404);
        exit;
    }
}

$mensagens   = [];
$erro        = null;
$jaInstalado = false;
$executado   = false;

try {
    // Detecta usuários existentes para evitar repetir a carga inicial de demonstração.
    $jaInstalado = (bool) bd()->query('SELECT 1 FROM usuarios LIMIT 1')->fetch();
} catch (Throwable $falha) {
    $erro = 'As tabelas nao foram encontradas. Importe o arquivo banco.sql antes de continuar.'
        . (AMBIENTE === 'desenvolvimento' ? ' Detalhe: ' . $falha->getMessage() : '');
}

// Depois da carga inicial o instalador deixa de existir para quem vem pela web:
// em producao ele nao deve sequer confirmar que o sistema esta instalado.
if (AMBIENTE === 'producao' && $jaInstalado) {
    http_response_code(404);
    exit;
}

// Só inicia a carga por POST quando as tabelas existem e ainda não há usuários.
if ($erro === null && $_SERVER['REQUEST_METHOD'] === 'POST' && !$jaInstalado) {
    try {
        // Oito caracteres alfabeticos: a mesma regra que a especificacao exige no cadastro.
        $senhaPadrao = 'agendeis';

        // A conta global fica fora dos usuários vinculados aos estabelecimentos.
        if (!(int) bd()->query('SELECT COUNT(*) FROM administradores_master')->fetchColumn()) {
            $consulta = bd()->prepare('INSERT INTO administradores_master (nome, email, senha_hash) VALUES (?, ?, ?)');
            $consulta->execute(['Administrador Master', 'master@agendei.com.br', password_hash('agendei-master-2026', PASSWORD_DEFAULT)]);
            $mensagens[] = 'Administrador master criado: master@agendei.com.br';
        }

        // ---------------------------------------------------------
        // Administrador
        // ---------------------------------------------------------
        // O usuario master nasce pelo instalador (equivalente ao script SQL previsto
        // na especificacao) e ja recebe os dados que alimentam o segundo fator.
        $idUsuarioAdmin = Usuario::criar([
            'nome'            => 'Administrador do Sistema',
            'email'           => 'admin@agendei.com.br',
            'senha'           => $senhaPadrao,
            'login'           => 'admini',
            'telefone'        => '11300000000',
            'telefone_fixo'   => '1133000000',
            'tipo'            => 'admin',
            'sexo'            => 'O',
            'nome_materno'    => 'Helena Duarte do Sistema',
            'data_nascimento' => '1985-02-10',
            'cep'             => '01000000',
            'logradouro'      => 'Rua das Acacias',
            'numero'          => '120',
            'bairro'          => 'Centro',
            'cidade'          => 'Sao Paulo',
            'uf'              => 'SP',
        ]);

        $consulta = bd()->prepare('INSERT INTO administradores (id_estabelecimento, id_usuario, nivel) VALUES (' . Contexto::id() . ', :id, \'super\')');
        $consulta->execute([':id' => $idUsuarioAdmin]);
        $mensagens[] = 'Administrador criado: admin@agendei.com.br';

        // ---------------------------------------------------------
        // Profissionais
        // ---------------------------------------------------------
        $servicos = Servico::listar();
        // Indexa os serviços pelo nome para vincular corretamente os profissionais de exemplo.
        $idsPorNome = [];
        foreach ($servicos as $servico) {
            $idsPorNome[$servico['nome']] = (int) $servico['id_servico'];
        }

        $equipe = [
            [
                'nome'          => 'Marcos Ribeiro',
                'email'         => 'marcos@agendei.com.br',
                'telefone'      => '11990000001',
                'especialidade' => 'Barbeiro',
                'servicos'      => ['Corte de cabelo masculino', 'Barba completa', 'Corte + barba'],
            ],
            [
                'nome'          => 'Juliana Prado',
                'email'         => 'juliana@agendei.com.br',
                'telefone'      => '11990000002',
                'especialidade' => 'Cabeleireira',
                'servicos'      => ['Corte de cabelo feminino', 'Coloracao', 'Hidratacao capilar'],
            ],
            [
                'nome'          => 'Camila Souza',
                'email'         => 'camila@agendei.com.br',
                'telefone'      => '11990000003',
                'especialidade' => 'Manicure e pedicure',
                'servicos'      => ['Manicure', 'Pedicure'],
            ],
        ];

        $idsProfissionais = [];

        foreach ($equipe as $membro) {
            $idsServicos = [];
            foreach ($membro['servicos'] as $nomeServico) {
                if (isset($idsPorNome[$nomeServico])) {
                    $idsServicos[] = $idsPorNome[$nomeServico];
                }
            }

            $idProfissional = Profissional::criar([
                'nome'                 => $membro['nome'],
                'email'                => $membro['email'],
                'senha'                => $senhaPadrao,
                'telefone'             => $membro['telefone'],
                'especialidade'        => $membro['especialidade'],
                'pode_bloquear_agenda' => 1,
                'servicos'             => $idsServicos,
            ]);

            $idsProfissionais[] = $idProfissional;

            // Expediente: segunda a sexta (manha e tarde) e sabado pela manha.
            for ($dia = 1; $dia <= 5; $dia++) {
                Horario::criar([
                    'id_profissional'   => $idProfissional,
                    'dia_semana'        => $dia,
                    'hora_inicio'       => '09:00:00',
                    'hora_fim'          => '12:00:00',
                    'intervalo_minutos' => 30,
                ]);
                Horario::criar([
                    'id_profissional'   => $idProfissional,
                    'dia_semana'        => $dia,
                    'hora_inicio'       => '13:00:00',
                    'hora_fim'          => '18:00:00',
                    'intervalo_minutos' => 30,
                ]);
            }

            Horario::criar([
                'id_profissional'   => $idProfissional,
                'dia_semana'        => 6,
                'hora_inicio'       => '09:00:00',
                'hora_fim'          => '13:00:00',
                'intervalo_minutos' => 30,
            ]);

            $mensagens[] = 'Profissional criado: ' . $membro['email'];
        }

        // ---------------------------------------------------------
        // Cliente demonstrativo
        // ---------------------------------------------------------
        // Usuario comum de demonstracao, com o cadastro completo da especificacao.
        $idCliente = Cliente::criar([
            'nome'            => 'Ana Beatriz Lima Souza',
            'email'           => 'cliente@agendei.com.br',
            'senha'           => $senhaPadrao,
            'login'           => 'anabea',
            'telefone'        => '11991111111',
            'telefone_fixo'   => '1133111111',
            'cpf'             => '52998224725',
            'data_nascimento' => '1995-04-18',
            'sexo'            => 'F',
            'nome_materno'    => 'Marcia Lima Souza',
            'cep'             => '01310100',
            'logradouro'      => 'Avenida Paulista',
            'numero'          => '1000',
            'complemento'     => 'Apto 52',
            'bairro'          => 'Bela Vista',
            'cidade'          => 'Sao Paulo',
            'uf'              => 'SP',
        ]);
        $mensagens[] = 'Cliente criado: cliente@agendei.com.br';

        // ---------------------------------------------------------
        // Agendamentos de exemplo nos proximos dias uteis
        // ---------------------------------------------------------
        $criados = 0;
        $horariosExemplo = ['09:00:00', '10:30:00', '14:00:00', '15:30:00'];

        for ($dia = 0; $dia <= 7 && $criados < 4; $dia++) {
            $data = date('Y-m-d', strtotime("+{$dia} days"));

            foreach ($idsProfissionais as $indice => $idProfissional) {
                if ($criados >= 4) {
                    break;
                }

                $servicosProfissional = Profissional::idsServicos($idProfissional);
                if ($servicosProfissional === []) {
                    continue;
                }

                $resultado = Agendamento::criar([
                    'id_cliente'      => $idCliente,
                    'id_profissional' => $idProfissional,
                    'id_servico'      => $servicosProfissional[0],
                    'data'            => $data,
                    'hora_inicio'     => $horariosExemplo[$indice % count($horariosExemplo)],
                    'observacao'      => 'Agendamento de demonstracao.',
                    'origem'          => 'admin',
                ], ['ignorar_antecedencia' => true]);

                if ($resultado['sucesso']) {
                    $criados++;
                }
            }
        }

        $mensagens[] = $criados . ' agendamento(s) de exemplo criado(s).';
        $mensagens[] = 'Senha padrao de todos os usuarios: ' . $senhaPadrao;
        $mensagens[] = 'Logins de 6 letras: admini (master) e anabea (comum).';
        $mensagens[] = 'Respostas do 2FA do master: mae "Helena Duarte do Sistema", nascimento 10/02/1985, CEP 01000-000.';
        $mensagens[] = 'Respostas do 2FA do comum: mae "Marcia Lima Souza", nascimento 18/04/1995, CEP 01310-100.';

        $executado   = true;
        $jaInstalado = true;
    } catch (Throwable $falha) {
        $erro = 'Falha na instalacao: ' . $falha->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instalacao | <?= e(NOME_SISTEMA) ?></title>
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/login.css') ?>">
    <?php require RAIZ . '/includes/tema.php'; ?>
</head>
<body class="pagina-autenticacao">

<div class="autenticacao-area">
    <div class="autenticacao-caixa caixa-simples">
        <div class="autenticacao-formulario">
            <?= marcaSistema(36) ?>
            <h1 class="titulo-apos-marca">Instalacao do sistema</h1>
            <p class="subtitulo">Criacao dos usuarios iniciais e dos dados de demonstracao.</p>

            <?php if ($erro !== null): ?>
                <div class="alerta alerta-erro"><span class="alerta-texto"><?= e($erro) ?></span></div>
            <?php endif; ?>

            <?php foreach ($mensagens as $mensagem): ?>
                <div class="alerta alerta-sucesso"><span class="alerta-texto"><?= e($mensagem) ?></span></div>
            <?php endforeach; ?>

            <?php if ($erro === null && !$jaInstalado): ?>
                <div class="alerta alerta-info">
                    <span class="alerta-texto">
                        Sera criado um administrador, tres profissionais com expediente configurado,
                        um cliente e alguns agendamentos de exemplo.
                    </span>
                </div>

                <?php /* Formulário de alteração: os dados serão validados novamente pelo servidor. */ ?><form method="post">
                    <button type="submit" class="btn btn-bloco btn-grande">Executar instalacao</button>
                </form>
            <?php elseif ($erro === null): ?>
                <div class="alerta alerta-aviso">
                    <span class="alerta-texto">
                        <?= $executado
                            ? 'Instalacao concluida. Apague o arquivo instalar.php.'
                            : 'O sistema ja possui usuarios cadastrados. Nada foi alterado.' ?>
                    </span>
                </div>

                <?php if ($executado): ?>
                    <div class="credenciais-demo">
                        <strong>Acessos criados</strong><br>
                        Administrador: admin@agendei.com.br<br>
                        Profissional: marcos@agendei.com.br<br>
                        Cliente: cliente@agendei.com.br<br>
                        Senha de todos: agendei123<br><br>
                        Master: master@agendei.com.br<br>
                        Senha master inicial: agendei-master-2026
                    </div>
                <?php endif; ?>

                <a href="<?= url('login.php') ?>" class="btn btn-bloco margem-topo">Ir para o login</a>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>
