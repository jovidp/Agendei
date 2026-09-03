<?php
/**
 * Fechamento do layout dos paineis.
 * Variavel opcional: $jsExtra (array).
 */
$jsExtra = $jsExtra ?? [];
?>
        </div><!-- /area-conteudo -->
    </div><!-- /painel-conteudo -->
</div><!-- /painel -->

<div id="notificacoes"></div>

<script src="<?= url('assets/js/main.js') ?>"></script>
<?php foreach ($jsExtra as $arquivo): ?>
    <script src="<?= url('assets/js/' . $arquivo) ?>"></script>
<?php endforeach; ?>
</body>
</html>
