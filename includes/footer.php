<?php
/**
 * Rodape das paginas publicas.
 * Variavel opcional: $jsExtra (array).
 */
// Reutiliza os dados já consultados, buscando-os apenas quando o rodapé é incluído isoladamente.
$estabelecimento = $estabelecimento ?? Estabelecimento::dados();
// Recebe a lista de scripts específicos que serão carregados depois do JavaScript compartilhado.
$jsExtra = $jsExtra ?? [];
?>
</main>

<footer class="rodape-site">
    <div class="container">
        <div class="rodape-colunas">
            <div>
                <span class="marca">
                    <?= Tema::marca($estabelecimento) ?>
                    <?= e($estabelecimento['nome']) ?>
                </span>
                <p><?= e(limitarTexto($estabelecimento['descricao'] ?? '', 180)) ?></p>
            </div>

            <div>
                <h4>Contato</h4>
                <?php if (!empty($estabelecimento['telefone'])): ?>
                    <p><?= e($estabelecimento['telefone']) ?></p>
                <?php endif; ?>
                <?php if (!empty($estabelecimento['email'])): ?>
                    <p><?= e($estabelecimento['email']) ?></p>
                <?php endif; ?>
                <?php if (Estabelecimento::enderecoCompleto() !== ''): ?>
                    <p><?= e(Estabelecimento::enderecoCompleto()) ?></p>
                <?php endif; ?>
            </div>

            <div>
                <h4>Atendimento</h4>
                <ul class="rodape-lista">
                    <li><a href="<?= url('index.php#servicos') ?>">Servicos</a></li>
                    <li><a href="<?= url('index.php#como-funciona') ?>">Como funciona</a></li>
                    <li><a href="<?= url('login.php') ?>">Entrar</a></li>
                    <li><a href="<?= url('cadastro.php') ?>">Criar conta</a></li>
                    <?php if (!estaLogado()): ?><li><a href="<?= url('master/login.php') ?>">Área master</a></li><?php endif; ?>
                </ul>
            </div>
        </div>

        <div class="rodape-base">
            <span>&copy; <?= date('Y') ?> <?= e($estabelecimento['nome']) ?>. Todos os direitos reservados.</span>
            <span>Sistema de agendamento <?= e(NOME_SISTEMA) ?></span>
        </div>
    </div>
</footer>

<div id="notificacoes"></div>

<script src="<?= url('assets/js/main.js') ?>"></script>
<?php foreach ($jsExtra as $arquivo): ?>
    <script src="<?= url('assets/js/' . $arquivo) ?>"></script>
<?php endforeach; ?>
</body>
</html>
