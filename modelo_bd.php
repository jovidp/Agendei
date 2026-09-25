<?php
/**
 * Modelo do banco de dados.
 * Acessivel aos dois perfis. Mostra o diagrama entidade-relacionamento das
 * tabelas centrais do sistema.
 *
 * O diagrama e desenhado em SVG aqui mesmo, mas se existir o arquivo
 * assets/img/der.png (ou .svg / .jpg) ele tem prioridade, o que permite
 * publicar a imagem exportada da ferramenta de modelagem do grupo.
 */
// Carrega as configurações, a sessão e as funções compartilhadas antes de processar a página.
require_once __DIR__ . '/config/config.php';

// Tela interna: exige uma conta autenticada, de qualquer perfil.
exigirLogin();

// Procura uma imagem publicada pelo grupo antes de cair no diagrama embutido.
$imagemPropria = '';
foreach (['der.png', 'der.svg', 'der.jpg'] as $arquivo) {
    if (is_file(RAIZ . '/assets/img/' . $arquivo)) {
        $imagemPropria = 'assets/img/' . $arquivo;
        break;
    }
}

// Entidades exibidas no diagrama: [x, y, titulo, [campos], destaque]
$entidades = [
    ['x' => 20,  'y' => 20,  'nome' => 'estabelecimento', 'campos' => ['id_estabelecimento (PK)', 'nome', 'slug', 'cores / logo'], 'tipo' => 'base'],
    ['x' => 20,  'y' => 150, 'nome' => 'usuarios',        'campos' => ['id_usuario (PK)', 'nome, email (unico)', 'senha_hash, status', 'nome_materno, data_nascimento', 'cep, logradouro, numero', 'bairro, cidade, uf', 'sexo, telefone, telefone_fixo', 'totp_segredo, totp_ativado_em'], 'tipo' => 'auth'],
    ['x' => 20,  'y' => 340, 'nome' => 'vinculos',        'campos' => ['id_vinculo (PK)', 'id_estabelecimento (FK)', 'id_usuario (FK)', 'tipo, status', 'login (6 letras)', 'ultimo_acesso'], 'tipo' => 'auth'],
    ['x' => 330, 'y' => 20,  'nome' => 'clientes',        'campos' => ['id_cliente (PK)', 'id_vinculo (FK)', 'id_usuario (FK)', 'cpf', 'data_nascimento', 'pontos_fidelidade'], 'tipo' => 'perfil'],
    ['x' => 330, 'y' => 190, 'nome' => 'administradores', 'campos' => ['id_administrador (PK)', 'id_vinculo (FK)', 'id_usuario (FK)', 'nivel'], 'tipo' => 'perfil'],
    ['x' => 330, 'y' => 330, 'nome' => 'profissionais',   'campos' => ['id_profissional (PK)', 'id_vinculo (FK)', 'id_usuario (FK)', 'especialidade'], 'tipo' => 'perfil'],
    ['x' => 630, 'y' => 20,  'nome' => 'logs_autenticacao', 'campos' => ['id_log (PK)', 'id_usuario (sem FK)', 'nome, cpf (copia)', 'evento', 'fator_2fa', 'data_hora, ip'], 'tipo' => 'auth'],
    ['x' => 630, 'y' => 230, 'nome' => 'agendamentos',    'campos' => ['id_agendamento (PK)', 'id_cliente (FK)', 'id_profissional (FK)', 'id_servico (FK)', 'data, hora, status'], 'tipo' => 'base'],
    ['x' => 630, 'y' => 420, 'nome' => 'servicos',        'campos' => ['id_servico (PK)', 'nome, preco', 'duracao_minutos'], 'tipo' => 'base'],
];

// Ligacoes: [origem, destino, rotulo]
$ligacoes = [
    ['estabelecimento', 'vinculos', '1:N'],
    ['usuarios', 'vinculos', '1:N'],
    ['vinculos', 'clientes', '1:1'],
    ['vinculos', 'administradores', '1:1'],
    ['vinculos', 'profissionais', '1:1'],
    ['clientes', 'agendamentos', '1:N'],
    ['servicos', 'agendamentos', 'N:1'],
];

/** Altura da caixa conforme a quantidade de campos listados. */
function alturaEntidade(array $entidade): float
{
    return 34 + (count($entidade['campos']) * 16) + 8;
}

/** Localiza a entidade pelo nome para desenhar as ligacoes. */
function acharEntidade(array $entidades, string $nome): ?array
{
    foreach ($entidades as $entidade) {
        if ($entidade['nome'] === $nome) {
            return $entidade;
        }
    }
    return null;
}

// Define o título e os demais dados de apresentação utilizados pelo cabeçalho.
$tituloPagina  = 'Modelo do banco de dados';
$subtituloTopo = 'Diagrama entidade-relacionamento das tabelas centrais';

// Renderiza a estrutura comum do painel após preparar os dados desta tela.
require_once RAIZ . '/includes/painel_header.php';
?>

<div class="cartao">
    <div class="cartao-cabecalho">
        <h3>Diagrama entidade-relacionamento</h3>
    </div>

    <div class="diagrama-area">
        <?php if ($imagemPropria !== ''): ?>
            <img src="<?= url($imagemPropria) ?>" alt="Diagrama entidade-relacionamento do banco de dados" class="diagrama-imagem">
        <?php else: ?>
            <?php /* Diagrama vetorial: acompanha o tema e continua legivel em qualquer zoom. */ ?>
            <svg class="diagrama-svg" viewBox="0 0 900 560" role="img"
                 aria-label="Diagrama entidade-relacionamento com as tabelas estabelecimento, usuarios, clientes, administradores, profissionais, logs de autenticacao, agendamentos e servicos.">
                <defs>
                    <marker id="seta" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto">
                        <path d="M0,0 L8,4 L0,8 Z" fill="var(--borda-forte)"></path>
                    </marker>
                </defs>

                <?php foreach ($ligacoes as [$origem, $destino, $rotulo]): ?>
                    <?php
                    $a = acharEntidade($entidades, $origem);
                    $b = acharEntidade($entidades, $destino);
                    if ($a === null || $b === null) {
                        continue;
                    }
                    // Liga o centro vertical das duas caixas, saindo pela lateral mais proxima.
                    $ax = $a['x'] < $b['x'] ? $a['x'] + 260 : $a['x'];
                    $bx = $a['x'] < $b['x'] ? $b['x'] : $b['x'] + 260;
                    $ay = $a['y'] + (alturaEntidade($a) / 2);
                    $by = $b['y'] + (alturaEntidade($b) / 2);
                    $meio = ($ax + $bx) / 2;
                    ?>
                    <path d="M<?= $ax ?>,<?= $ay ?> C<?= $meio ?>,<?= $ay ?> <?= $meio ?>,<?= $by ?> <?= $bx ?>,<?= $by ?>"
                          fill="none" stroke="var(--borda-forte)" stroke-width="1.5" marker-end="url(#seta)"></path>
                    <text x="<?= $meio ?>" y="<?= (($ay + $by) / 2) - 4 ?>" class="diagrama-cardinalidade"><?= e($rotulo) ?></text>
                <?php endforeach; ?>

                <?php foreach ($entidades as $entidade): ?>
                    <?php $altura = alturaEntidade($entidade); ?>
                    <g class="diagrama-entidade diagrama-<?= e($entidade['tipo']) ?>">
                        <rect x="<?= $entidade['x'] ?>" y="<?= $entidade['y'] ?>" width="260" height="<?= $altura ?>" rx="6"></rect>
                        <rect x="<?= $entidade['x'] ?>" y="<?= $entidade['y'] ?>" width="260" height="26" rx="6" class="diagrama-titulo-fundo"></rect>
                        <text x="<?= $entidade['x'] + 12 ?>" y="<?= $entidade['y'] + 18 ?>" class="diagrama-titulo"><?= e($entidade['nome']) ?></text>

                        <?php foreach ($entidade['campos'] as $indice => $campo): ?>
                            <text x="<?= $entidade['x'] + 12 ?>" y="<?= $entidade['y'] + 44 + ($indice * 16) ?>" class="diagrama-campo"><?= e($campo) ?></text>
                        <?php endforeach; ?>
                    </g>
                <?php endforeach; ?>
            </svg>
        <?php endif; ?>
    </div>

    <div class="diagrama-legenda">
        <span><span class="marcador marcador-auth"></span> Autenticacao e log</span>
        <span><span class="marcador marcador-perfil"></span> Perfis de usuario</span>
        <span><span class="marcador marcador-base"></span> Operacao do agendamento</span>
    </div>
</div>

<div class="cartao">
    <div class="cartao-cabecalho">
        <h3>Como o modelo atende aos perfis</h3>
    </div>

    <div class="tabela-area">
        <table class="tabela">
            <thead>
                <tr>
                    <th>Decisao</th>
                    <th>Onde fica</th>
                    <th>Por que</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="celula-principal">Perfil do usuario</td>
                    <td><code>vinculos.tipo</code></td>
                    <td>O perfil master corresponde a <code>admin</code> e o comum a <code>cliente</code>. O controle de acesso le esse valor da sessao, que e sempre um vinculo: a pessoa (<code>usuarios</code>, e-mail unico) pode ter varios vinculos, em empresas diferentes ou com papeis diferentes na mesma.</td>
                </tr>
                <tr>
                    <td class="celula-principal">2FA por codigo</td>
                    <td><code>usuarios.totp_segredo</code>, <code>totp_ativado_em</code>, <code>totp_ultimo_contador</code></td>
                    <td>Caminho padrao do segundo fator. O segredo fica cifrado; a data so e preenchida depois que o usuario confirma um codigo, e o contador guarda a janela ja usada para impedir repetir o mesmo codigo. As tres colunas se repetem em <code>administradores_master</code>.</td>
                </tr>
                <tr>
                    <td class="celula-principal">2FA por pergunta (reserva)</td>
                    <td><code>usuarios.nome_materno</code>, <code>data_nascimento</code>, <code>cep</code></td>
                    <td>Atende quem ainda nao cadastrou o aplicativo autenticador. Ficam na tabela comum a todos os perfis para que master e comum respondam as mesmas perguntas.</td>
                </tr>
                <tr>
                    <td class="celula-principal">Login de 6 letras</td>
                    <td><code>vinculos.login</code></td>
                    <td>E do vinculo, unico por estabelecimento. Aceita nulo para nao invalidar as contas criadas antes do campo existir.</td>
                </tr>
                <tr>
                    <td class="celula-principal">Historico de acesso</td>
                    <td><code>logs_autenticacao</code></td>
                    <td>Guarda copia do nome e do CPF e nao tem chave estrangeira para <code>usuarios</code>, entao o log sobrevive a exclusao feita pelo master.</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<?php require_once RAIZ . '/includes/painel_footer.php'; ?>
