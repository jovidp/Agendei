<?php
/**
 * Contas da administracao master.
 *
 * Ate aqui so o instalador criava a conta global, o que deixava a plataforma
 * dependente de uma unica senha: perde-la significava perder a administracao
 * sem caminho de volta pela interface. Esta tela cria contas adicionais, liga
 * e desliga acessos e redefine senhas — sempre com rastro na auditoria.
 *
 * Duas regras nao podem ser quebradas aqui, e por isso valem tambem no model:
 * a ultima conta ativa nunca e desligada e ninguem desliga a si mesmo.
 */
define('AREA_MASTER', true);
require_once __DIR__ . '/../config/config.php';
exigirLogin('master');

$idSessao = (int) $_SESSION['master_id'];
$erros = [];
$acaoTela = get('acao');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $acao = post('acao');

    try {
        if ($acao === 'criar') {
            $nome  = post('nome');
            $email = mb_strtolower(post('email'));
            $senha = post('senha');

            if (mb_strlen($nome) < 3) $erros[] = 'Informe o nome da pessoa responsavel pela conta.';
            if (!validarEmail($email)) $erros[] = 'Informe um e-mail valido.';
            if (!validarSenha($senha)) $erros[] = 'A senha deve ter pelo menos 6 caracteres.';
            if (Master::emailEmUso($email)) $erros[] = 'Este e-mail ja pertence a outra conta master.';

            if (!$erros) {
                $novo = Master::criar(['nome' => $nome, 'email' => $email, 'senha' => $senha]);
                LogMaster::registrar('master_criado', ['alvo' => $email, 'detalhe' => 'conta ' . $novo]);
                definirFlash('sucesso', 'Conta master criada.');
                redirecionar('master/contas.php?acao=ver&id=' . $novo);
            }
        }

        if ($acao === 'dados') {
            $id    = (int) post('id_master');
            $conta = Master::porId($id);
            $nome  = post('nome');
            $email = mb_strtolower(post('email'));

            if (!$conta) $erros[] = 'Conta master nao encontrada.';
            if (mb_strlen($nome) < 3 || !validarEmail($email)) $erros[] = 'Informe um nome e um e-mail validos.';
            if (Master::emailEmUso($email, $id)) $erros[] = 'Este e-mail ja pertence a outra conta master.';

            if (!$erros) {
                Master::atualizarPerfil($id, $nome, $email);
                LogMaster::registrar('master_dados', [
                    'alvo'    => $email,
                    'detalhe' => $email !== $conta['email'] ? 'e-mail anterior: ' . $conta['email'] : 'nome atualizado',
                ]);

                // A propria conta editada e a da sessao: o topo do painel
                // precisa refletir a mudanca sem exigir novo login.
                if ($id === $idSessao) {
                    $_SESSION['usuario_nome']  = $nome;
                    $_SESSION['usuario_email'] = $email;
                }

                definirFlash('sucesso', 'Dados da conta master atualizados.');
                redirecionar('master/contas.php?acao=ver&id=' . $id);
            }
        }

        if ($acao === 'senha') {
            $id    = (int) post('id_master');
            $conta = Master::porId($id);
            $nova  = post('nova_senha');

            if (!$conta) $erros[] = 'Conta master nao encontrada.';
            // Redefinir a senha de outra conta global e uma acao de alto
            // impacto: confirmar a propria senha impede que uma sessao deixada
            // aberta vire acesso permanente para quem passar pela maquina.
            if (!Master::senhaConfere($idSessao, post('senha_atual'))) $erros[] = 'Confirme a sua senha atual para redefinir.';
            if (!validarSenha($nova)) $erros[] = 'A nova senha deve ter pelo menos 6 caracteres.';
            if ($nova !== post('confirmar_senha')) $erros[] = 'As novas senhas nao conferem.';

            if (!$erros) {
                Master::atualizarSenha($id, $nova);
                LogMaster::registrar('master_senha', [
                    'alvo'    => $conta['email'],
                    'detalhe' => $id === $idSessao ? 'propria conta' : 'senha temporaria definida pelo master',
                ]);
                definirFlash('sucesso', 'Senha da conta master redefinida.');
                redirecionar('master/contas.php?acao=ver&id=' . $id);
            }
        }

        if ($acao === 'status') {
            $id     = (int) post('id_master');
            $conta  = Master::porId($id);
            $status = post('status') === 'ativo' ? 'ativo' : 'inativo';

            if (!$conta) {
                $erros[] = 'Conta master nao encontrada.';
            } elseif ($id === $idSessao && $status === 'inativo') {
                $erros[] = 'Voce nao pode desativar a conta que esta usando agora.';
            } elseif (!Master::alterarStatus($id, $status)) {
                $erros[] = 'Esta e a ultima conta master ativa. Crie ou ative outra antes de desativa-la.';
            } else {
                LogMaster::registrar('master_status', [
                    'alvo'    => $conta['email'],
                    'detalhe' => 'de ' . $conta['status'] . ' para ' . $status,
                ]);
                definirFlash('sucesso', 'Acesso da conta master atualizado.');
                redirecionar('master/contas.php');
            }
        }
    } catch (PDOException $erro) {
        $erros[] = $erro->getCode() === '23000'
            ? 'Este e-mail ja pertence a outra conta master.'
            : 'Nao foi possivel concluir a operacao.';
        if ($erro->getCode() !== '23000') error_log($erro->getMessage());
    }
}

$selecionada = $acaoTela === 'ver' ? Master::porId((int) get('id')) : null;
$contas = Master::listar();
$ativas = Master::contarAtivos();

$tituloPagina  = 'Contas master';
$subtituloTopo = count($contas) . ' conta(s) global(is), ' . $ativas . ' ativa(s)';
$acoesTopo = $acaoTela === 'novo'
    ? '<a class="btn btn-contorno btn-pequeno" href="' . url('master/contas.php') . '">Voltar</a>'
    : '<a class="btn btn-pequeno" href="' . url('master/contas.php?acao=novo') . '">Nova conta master</a>';
require RAIZ . '/includes/painel_header.php';
?>
<?php if ($erros): ?>
    <div class="alerta alerta-erro" role="alert"><ul><?php foreach ($erros as $mensagem): ?><li><?= e($mensagem) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($ativas < 2): ?>
    <div class="alerta alerta-aviso" role="status">
        Existe apenas uma conta master ativa. Perder essa senha deixa a plataforma sem administracao: crie uma segunda conta.
    </div>
<?php endif; ?>

<?php if ($acaoTela === 'novo' || ($erros && post('acao') === 'criar')): ?>
    <div class="cartao">
        <div class="cartao-cabecalho"><h3>Nova conta master</h3></div>
        <div class="cartao-corpo">
            <form method="post">
                <?= campoCsrf() ?>
                <input type="hidden" name="acao" value="criar">
                <div class="linha-campos">
                    <div class="campo"><label for="nome">Nome</label><input id="nome" name="nome" maxlength="120" required></div>
                    <div class="campo"><label for="email">E-mail</label><input type="email" id="email" name="email" maxlength="150" required></div>
                </div>
                <div class="campo">
                    <label for="senha">Senha temporaria</label>
                    <input type="password" id="senha" name="senha" minlength="6" autocomplete="new-password" required>
                    <span class="ajuda-campo">A pessoa deve troca-la no primeiro acesso, em Meu perfil.</span>
                </div>
                <button class="btn" type="submit">Criar conta master</button>
            </form>
        </div>
    </div>

<?php elseif ($selecionada): ?>
    <div class="cartao">
        <div class="cartao-cabecalho">
            <div>
                <h3><?= e($selecionada['nome']) ?></h3>
                <small><?= e($selecionada['email']) ?></small>
            </div>
            <a href="<?= url('master/contas.php') ?>">Voltar</a>
        </div>
        <div class="cartao-corpo">
            <p><strong>Status:</strong> <?= badgeStatus($selecionada['status']) ?></p>
            <p>
                <strong>Ultimo acesso:</strong>
                <?= $selecionada['ultimo_acesso']
                    ? e(formatarData(substr((string) $selecionada['ultimo_acesso'], 0, 10)) . ' as ' . substr((string) $selecionada['ultimo_acesso'], 11, 5))
                    : 'nunca entrou' ?>
            </p>
        </div>
    </div>

    <div class="grade-painel grade-painel-igual">
        <div class="cartao">
            <div class="cartao-cabecalho"><h3>Dados da conta</h3></div>
            <div class="cartao-corpo">
                <form method="post">
                    <?= campoCsrf() ?>
                    <input type="hidden" name="acao" value="dados">
                    <input type="hidden" name="id_master" value="<?= (int) $selecionada['id_master'] ?>">
                    <div class="campo"><label for="nome_conta">Nome</label><input id="nome_conta" name="nome" value="<?= e($selecionada['nome']) ?>" maxlength="120" required></div>
                    <div class="campo"><label for="email_conta">E-mail</label><input type="email" id="email_conta" name="email" value="<?= e($selecionada['email']) ?>" maxlength="150" required></div>
                    <button class="btn" type="submit">Salvar dados</button>
                </form>
            </div>
        </div>

        <div class="cartao">
            <div class="cartao-cabecalho"><h3>Redefinir senha</h3></div>
            <div class="cartao-corpo">
                <form method="post">
                    <?= campoCsrf() ?>
                    <input type="hidden" name="acao" value="senha">
                    <input type="hidden" name="id_master" value="<?= (int) $selecionada['id_master'] ?>">
                    <div class="campo">
                        <label for="senha_atual">Sua senha atual</label>
                        <input type="password" id="senha_atual" name="senha_atual" autocomplete="current-password" required>
                        <span class="ajuda-campo">Confirma que e voce quem esta no teclado.</span>
                    </div>
                    <div class="campo"><label for="nova_senha">Nova senha</label><input type="password" id="nova_senha" name="nova_senha" minlength="6" autocomplete="new-password" required></div>
                    <div class="campo"><label for="confirmar_senha">Confirmar nova senha</label><input type="password" id="confirmar_senha" name="confirmar_senha" minlength="6" autocomplete="new-password" required></div>
                    <button class="btn" type="submit">Redefinir senha</button>
                </form>
            </div>
        </div>
    </div>

<?php else: ?>
    <div class="cartao">
        <div class="tabela-area">
            <table class="tabela">
                <thead>
                    <tr><th>Conta</th><th>Ultimo acesso</th><th>Criada em</th><th>Status</th><th>Acoes</th></tr>
                </thead>
                <tbody>
                <?php foreach ($contas as $conta): ?>
                    <tr>
                        <td class="celula-principal">
                            <strong><?= e($conta['nome']) ?></strong>
                            <span class="celula-secundaria"><?= e($conta['email']) ?><?= (int) $conta['id_master'] === $idSessao ? ' (voce)' : '' ?></span>
                        </td>
                        <td><?= $conta['ultimo_acesso'] ? e(formatarData(substr((string) $conta['ultimo_acesso'], 0, 10))) : '-' ?></td>
                        <td><?= e(formatarData(substr((string) $conta['data_criacao'], 0, 10))) ?></td>
                        <td><?= badgeStatus($conta['status']) ?></td>
                        <td>
                            <div class="acoes-tabela">
                                <a class="btn btn-contorno btn-pequeno" href="<?= url('master/contas.php?acao=ver&id=' . (int) $conta['id_master']) ?>">Detalhes</a>
                                <?php if ((int) $conta['id_master'] !== $idSessao): ?>
                                    <form method="post">
                                        <?= campoCsrf() ?>
                                        <input type="hidden" name="acao" value="status">
                                        <input type="hidden" name="id_master" value="<?= (int) $conta['id_master'] ?>">
                                        <input type="hidden" name="status" value="<?= $conta['status'] === 'ativo' ? 'inativo' : 'ativo' ?>">
                                        <button class="btn btn-pequeno <?= $conta['status'] === 'ativo' ? 'btn-perigo' : 'btn-secundario' ?>" type="submit"
                                                data-confirmar="Alterar o acesso desta conta master?">
                                            <?= $conta['status'] === 'ativo' ? 'Desativar' : 'Ativar' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
<?php require RAIZ . '/includes/painel_footer.php'; ?>
