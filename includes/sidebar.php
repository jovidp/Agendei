<?php
/**
 * Menu lateral dos paineis, montado conforme o perfil do usuario logado.
 */
require_once RAIZ . '/includes/icones.php';

$estabelecimento = $estabelecimento ?? Estabelecimento::dados();
// Usa uma variável própria para não sobrescrever $pagina, que controla a paginação das telas.
$paginaMenu = paginaAtual();

// Define rótulo, destino e ícone dos links disponíveis para cada perfil.
$menus = [
    'master' => [
        ['rotulo' => 'Visão geral', 'arquivo' => 'master/dashboard.php', 'icone' => 'dashboard'],
        ['rotulo' => 'Estabelecimentos', 'arquivo' => 'master/estabelecimentos.php', 'icone' => 'clientes'],
        ['rotulo' => 'Uso da plataforma', 'arquivo' => 'master/uso.php', 'icone' => 'relatorios'],
        ['rotulo' => 'Planos e limites', 'arquivo' => 'master/planos.php', 'icone' => 'servicos'],
        ['separador' => 'Plataforma'],
        ['rotulo' => 'Segurança', 'arquivo' => 'master/seguranca.php', 'icone' => 'bloqueio'],
        ['rotulo' => 'Auditoria', 'arquivo' => 'master/auditoria.php', 'icone' => 'historico'],
        ['rotulo' => 'Saúde do sistema', 'arquivo' => 'master/saude.php', 'icone' => 'saude'],
        ['separador' => 'Conta master'],
        ['rotulo' => 'Contas master', 'arquivo' => 'master/contas.php', 'icone' => 'profissionais'],
        ['rotulo' => 'Aparência', 'arquivo' => 'master/aparencia.php', 'icone' => 'configuracoes'],
        ['rotulo' => 'Meu perfil', 'arquivo' => 'master/perfil.php', 'icone' => 'perfil'],
        ['rotulo' => 'Verificacao em 2 etapas', 'arquivo' => 'master/autenticador.php', 'icone' => 'bloqueio'],
    ],
    'admin' => [
        ['rotulo' => 'Dashboard',     'arquivo' => 'admin/dashboard.php',     'icone' => 'dashboard'],
        ['rotulo' => 'Agenda',        'arquivo' => 'admin/agenda.php',        'icone' => 'agenda'],
        ['rotulo' => 'Agendamentos',  'arquivo' => 'admin/agendamentos.php',  'icone' => 'agendamentos'],
        ['separador' => 'Cadastros'],
        ['rotulo' => 'Clientes',      'arquivo' => 'admin/clientes.php',      'icone' => 'clientes'],
        ['rotulo' => 'Profissionais', 'arquivo' => 'admin/profissionais.php', 'icone' => 'profissionais'],
        ['rotulo' => 'Filiais',       'arquivo' => 'admin/filiais.php',       'icone' => 'dashboard'],
        ['rotulo' => 'Servicos',      'arquivo' => 'admin/servicos.php',      'icone' => 'servicos'],
        ['rotulo' => 'Horarios',      'arquivo' => 'admin/horarios.php',      'icone' => 'horarios'],
        ['separador' => 'Sistema'],
        ['rotulo' => 'Consulta de usuarios', 'arquivo' => 'admin/usuarios.php', 'icone' => 'clientes'],
        ['rotulo' => 'Logs de autenticacao', 'arquivo' => 'admin/logs.php',     'icone' => 'historico'],
        ['separador' => 'Gestao'],
        ['rotulo' => 'Relatorios',    'arquivo' => 'admin/relatorios.php',    'icone' => 'relatorios'],
        ['rotulo' => 'Diferenciais',  'arquivo' => 'admin/diferenciais.php',  'icone' => 'novo'],
        ['rotulo' => 'Aparência', 'arquivo' => 'admin/aparencia.php', 'icone' => 'configuracoes'],
        ['rotulo' => 'Configuracoes', 'arquivo' => 'admin/configuracoes.php', 'icone' => 'configuracoes'],
        ['rotulo' => 'Verificacao em 2 etapas', 'arquivo' => 'autenticador.php', 'icone' => 'bloqueio'],
    ],
    'profissional' => [
        ['rotulo' => 'Dashboard',      'arquivo' => 'profissional/dashboard.php', 'icone' => 'dashboard'],
        ['rotulo' => 'Minha agenda',   'arquivo' => 'profissional/agenda.php',    'icone' => 'agenda'],
        ['rotulo' => 'Meus horarios',  'arquivo' => 'profissional/horarios.php',  'icone' => 'horarios'],
        ['rotulo' => 'Bloqueios',      'arquivo' => 'profissional/bloqueios.php', 'icone' => 'bloqueio'],
        ['separador' => 'Conta'],
        ['rotulo' => 'Meu perfil',     'arquivo' => 'profissional/perfil.php',    'icone' => 'perfil'],
        ['rotulo' => 'Verificacao em 2 etapas', 'arquivo' => 'autenticador.php', 'icone' => 'bloqueio'],
    ],
    'cliente' => [
        ['rotulo' => 'Dashboard',         'arquivo' => 'cliente/dashboard.php',    'icone' => 'dashboard'],
        ['rotulo' => 'Novo agendamento',  'arquivo' => 'cliente/agendar.php',      'icone' => 'novo'],
        ['rotulo' => 'Meus agendamentos', 'arquivo' => 'cliente/agendamentos.php', 'icone' => 'agendamentos'],
        ['rotulo' => 'Historico',         'arquivo' => 'cliente/historico.php',    'icone' => 'historico'],
        ['rotulo' => 'Meus beneficios',   'arquivo' => 'cliente/beneficios.php',   'icone' => 'novo'],
        ['separador' => 'Conta'],
        ['rotulo' => 'Meu perfil',        'arquivo' => 'cliente/perfil.php',       'icone' => 'perfil'],
        ['rotulo' => 'Privacidade',        'arquivo' => 'cliente/privacidade.php',   'icone' => 'configuracoes'],
        ['rotulo' => 'Verificacao em 2 etapas', 'arquivo' => 'autenticador.php', 'icone' => 'bloqueio'],
        ['rotulo' => 'Modelo do BD',      'arquivo' => 'modelo_bd.php',            'icone' => 'relatorios'],
    ],
];

// Seleciona os links do perfil autenticado; cada destino também valida o acesso no servidor.
$itens = $menus[perfil()] ?? [];
?>
<aside class="sidebar" id="sidebarPainel">
    <a href="<?= url('index.php') ?>" class="sidebar-marca">
        <?= Tema::marca($estabelecimento) ?>
        <?= e($estabelecimento['nome']) ?>
    </a>

    <div class="sidebar-perfil">
        <span class="avatar"><?= e(iniciais(usuarioNome())) ?></span>
        <div class="sidebar-perfil-dados">
            <strong><?= e(usuarioNome()) ?></strong>
            <span><?= e(perfilRotulo()) ?></span>
        </div>
    </div>

    <ul class="sidebar-menu">
        <?php foreach ($itens as $item): ?>
            <?php if (isset($item['separador'])): ?>
                <li class="separador"><?= e($item['separador']) ?></li>
            <?php else: ?>
                <li>
                    <a href="<?= url($item['arquivo']) ?>"
                       class="<?= basename($item['arquivo']) === $paginaMenu ? 'ativo' : '' ?>">
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
                <a href="<?= url(ehMaster() ? 'master/logout.php' : 'logout.php') ?>"><?= icone('sair') ?> Sair</a>
            </li>
        </ul>
    </div>
</aside>
