<?php
/**
 * Fechamento do layout dos paineis.
 * Variavel opcional: $jsExtra (array).
 */
// Completa o layout e prepara os scripts específicos solicitados pela tela atual.
$jsExtra = $jsExtra ?? [];
?>
        </div><!-- /area-conteudo -->
    </div><!-- /painel-conteudo -->
</div><!-- /painel -->

<div id="notificacoes"></div>

<?php /* Carrega primeiro as funções compartilhadas, das quais os demais scripts podem depender. */ ?><script src="<?= url('assets/js/main.js') ?>"></script>
<?php foreach ($jsExtra as $arquivo): ?>
    <script src="<?= url('assets/js/' . $arquivo) ?>"></script>
<?php endforeach; ?>
</body>
</html>
