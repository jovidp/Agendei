<?php
/**
 * Menu lateral dos paineis, montado conforme o perfil do usuario logado.
 */
require_once RAIZ . '/includes/icones.php';

$estabelecimento = $estabelecimento ?? Estabelecimento::dados();
$pagina = paginaAtual();

$menus = [
    'admin' => [
        ['rotulo' => 'Dashboard',     'arquivo' => 'admin/dashboard.php',     'icone' => 'dashboard'],
        ['rotulo' => 'Agenda',        'arquivo' => 'admin/agenda.php',        'icone' => 'agenda'],
        ['rotulo' => 'Agendamentos',  'arquivo' => 'admin/agendamentos.php',  'icone' => 'agendamentos'],
        ['separador' => 'Cadastros'],
        ['rotulo' => 'Clientes',      'arquivo' => 'admin/clientes.php',      'icone' => 'clientes'],
        ['rotulo' => 'Profissionais', 'arquivo' => 'admin/profissionais.php', 'icone' => 'profissionais'],
        ['rotulo' => 'Servicos',      'arquivo' => 'admin/servicos.php',      'icone' => 'servicos'],
        ['rotulo' => 'Horarios',      'arquivo' => 'admin/horarios.php',      'icone' => 'horarios'],
        ['separador' => 'Gestao'],
        ['rotulo' => 'Relatorios',    'arquivo' => 'admin/relatorios.php',    'icone' => 'relatorios'],
        ['rotulo' => 'Configuracoes', 'arquivo' => 'admin/configuracoes.php', 'icone' => 'configuracoes'],
    ],
    'profissional' => [
        ['rotulo' => 'Dashboard',      'arquivo' => 'profissional/dashboard.php', 'icone' => 'dashboard'],
        ['rotulo' => 'Minha agenda',   'arquivo' => 'profissional/agenda.php',    'icone' => 'agenda'],
        ['rotulo' => 'Meus horarios',  'arquivo' => 'profissional/horarios.php',  'icone' => 'horarios'],
        ['rotulo' => 'Bloqueios',      'arquivo' => 'profissional/bloqueios.php', 'icone' => 'bloqueio'],
        ['separador' => 'Conta'],
        ['rotulo' => 'Meu perfil',     'arquivo' => 'profissional/perfil.php',    'icone' => 'perfil'],
    ],
    'cliente' => [
        ['rotulo' => 'Dashboard',         'arquivo' => 'cliente/dashboard.php',    'icone' => 'dashboard'],
        ['rotulo' => 'Novo agendamento',  'arquivo' => 'cliente/agendar.php',      'icone' => 'novo'],
        ['rotulo' => 'Meus agendamentos', 'arquivo' => 'cliente/agendamentos.php', 'icone' => 'agendamentos'],
        ['rotulo' => 'Historico',         'arquivo' => 'cliente/historico.php',    'icone' => 'historico'],
        ['separador' => 'Conta'],
        ['rotulo' => 'Meu perfil',        'arquivo' => 'cliente/perfil.php',       'icone' => 'perfil'],
    ],
];

$rotulosPerfil = [
    'admin'        => 'Administrador',
    'profissional' => 'Profissional',
    'cliente'      => 'Cliente',
];

$itens = $menus[perfil()] ?? [];
?>
<aside class="sidebar" id="sidebarPainel">
    <a href="<?= url('index.php') ?>" class="sidebar-marca">
        <span class="marca-simbolo"><?= e(mb_substr($estabelecimento['nome'], 0, 1)) ?></span>
        <?= e($estabelecimento['nome']) ?>
    </a>

    <div class="sidebar-perfil">
        <span class="avatar"><?= e(iniciais(usuarioNome())) ?></span>
        <div class="sidebar-perfil-dados">
            <strong><?= e(usuarioNome()) ?></strong>
            <span><?= e($rotulosPerfil[perfil()] ?? '') ?></span>
        </div>
    </div>

    <ul class="sidebar-menu">
        <?php foreach ($itens as $item): ?>
            <?php if (isset($item['separador'])): ?>
                <li class="separador"><?= e($item['separador']) ?></li>
            <?php else: ?>
                <li>
                    <a href="<?= url($item['arquivo']) ?>"
                       class="<?= basename($item['arquivo']) === $pagina ? 'ativo' : '' ?>">
                        <?= icone($item['icone']) ?>
                        <?= e($item['rotulo']) ?>
                    </a>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ul>

    <div class="sidebar-rodape">
        <ul class="sidebar-menu" style="padding:0">
            <li>
                <a href="<?= url('logout.php') ?>"><?= icone('sair') ?> Sair</a>
            </li>
        </ul>
    </div>
</aside>
