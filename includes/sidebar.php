<?php
/**
 * Menu lateral dos paineis, montado conforme o perfil do usuario logado.
 * No computador pode ser recolhido (so icones, com o nome na dica); no celular
 * abre por cima do conteudo pelo botao do topo (assets/js/main.js).
 */
require_once RAIZ . '/includes/icones.php';

$estabelecimento = $estabelecimento ?? Estabelecimento::dados();
// Usa uma variável própria para não sobrescrever $pagina, que controla a paginação das telas.
$paginaMenu = paginaAtual();

// Define rótulo, destino e ícone dos links disponíveis para cada perfil.
$menus = [
    'master' => [
        ['separador' => 'Visão geral'],
        ['rotulo' => 'Visão geral', 'arquivo' => 'master/dashboard.php', 'icone' => 'dashboard'],
        ['rotulo' => 'Estabelecimentos', 'arquivo' => 'master/estabelecimentos.php', 'icone' => 'clientes'],
        ['rotulo' => 'Contas por estabelecimento', 'arquivo' => 'master/usuarios.php', 'icone' => 'perfil'],
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
        ['rotulo' => 'Verificação em 2 etapas', 'arquivo' => 'master/autenticador.php', 'icone' => 'bloqueio'],
    ],
    'admin' => [
        ['separador' => 'Operação'],
        ['rotulo' => 'Dashboard',     'arquivo' => 'admin/dashboard.php',     'icone' => 'dashboard'],
        ['rotulo' => 'Agenda',        'arquivo' => 'admin/agenda.php',        'icone' => 'agenda'],
        ['rotulo' => 'Agendamentos',  'arquivo' => 'admin/agendamentos.php',  'icone' => 'agendamentos'],
        ['separador' => 'Cadastros'],
        ['rotulo' => 'Clientes',      'arquivo' => 'admin/clientes.php',      'icone' => 'clientes'],
        ['rotulo' => 'Profissionais', 'arquivo' => 'admin/profissionais.php', 'icone' => 'profissionais'],
        ['rotulo' => 'Filiais',       'arquivo' => 'admin/filiais.php',       'icone' => 'dashboard'],
        ['rotulo' => 'Serviços',      'arquivo' => 'admin/servicos.php',      'icone' => 'servicos'],
        ['rotulo' => 'Horários',      'arquivo' => 'admin/horarios.php',      'icone' => 'horarios'],
        ['separador' => 'Gestão'],
        ['rotulo' => 'Relatórios',    'arquivo' => 'admin/relatorios.php',    'icone' => 'relatorios'],
        ['rotulo' => 'Diferenciais',  'arquivo' => 'admin/diferenciais.php',  'icone' => 'novo'],
        ['rotulo' => 'Assinaturas de clientes', 'arquivo' => 'admin/assinaturas_clientes.php', 'icone' => 'dinheiro'],
        ['separador' => 'Sistema'],
        ['rotulo' => 'Consulta de usuários', 'arquivo' => 'admin/usuarios.php', 'icone' => 'clientes'],
        ['rotulo' => 'Logs de autenticação', 'arquivo' => 'admin/logs.php',     'icone' => 'historico'],
        ['rotulo' => 'Assinatura',    'arquivo' => 'assinatura.php',          'icone' => 'dinheiro'],
        ['rotulo' => 'Aparência', 'arquivo' => 'admin/aparencia.php', 'icone' => 'configuracoes'],
        ['rotulo' => 'Configurações', 'arquivo' => 'admin/configuracoes.php', 'icone' => 'configuracoes'],
        ['rotulo' => 'Verificação em 2 etapas', 'arquivo' => 'autenticador.php', 'icone' => 'bloqueio'],
    ],
    'profissional' => [
        ['separador' => 'Atendimento'],
        ['rotulo' => 'Dashboard',      'arquivo' => 'profissional/dashboard.php', 'icone' => 'dashboard'],
        ['rotulo' => 'Minha agenda',   'arquivo' => 'profissional/agenda.php',    'icone' => 'agenda'],
        ['rotulo' => 'Meus horários',  'arquivo' => 'profissional/horarios.php',  'icone' => 'horarios'],
        ['rotulo' => 'Bloqueios',      'arquivo' => 'profissional/bloqueios.php', 'icone' => 'bloqueio'],
        ['separador' => 'Conta'],
        ['rotulo' => 'Meu perfil',     'arquivo' => 'profissional/perfil.php',    'icone' => 'perfil'],
        ['rotulo' => 'Verificação em 2 etapas', 'arquivo' => 'autenticador.php', 'icone' => 'bloqueio'],
    ],
    'cliente' => [
        ['separador' => 'Agendamentos'],
        ['rotulo' => 'Dashboard',         'arquivo' => 'cliente/dashboard.php',    'icone' => 'dashboard'],
        ['rotulo' => 'Novo agendamento',  'arquivo' => 'cliente/agendar.php',      'icone' => 'novo'],
        ['rotulo' => 'Meus agendamentos', 'arquivo' => 'cliente/agendamentos.php', 'icone' => 'agendamentos'],
        ['rotulo' => 'Histórico',         'arquivo' => 'cliente/historico.php',    'icone' => 'historico'],
        ['rotulo' => 'Meus benefícios',   'arquivo' => 'cliente/beneficios.php',   'icone' => 'dinheiro'],
        ['separador' => 'Conta'],
        ['rotulo' => 'Meu perfil',        'arquivo' => 'cliente/perfil.php',       'icone' => 'perfil'],
        ['rotulo' => 'Privacidade',        'arquivo' => 'cliente/privacidade.php',   'icone' => 'configuracoes'],
        ['rotulo' => 'Verificação em 2 etapas', 'arquivo' => 'autenticador.php', 'icone' => 'bloqueio'],
    ],
];

// Seleciona os links do perfil autenticado; cada destino também valida o acesso no servidor.
$itens = $menus[perfil()] ?? [];
// O cartao do usuario no rodape leva a pagina de conta de cada perfil.
$paginaConta = [
    'master' => 'master/perfil.php', 'admin' => 'admin/configuracoes.php',
    'profissional' => 'profissional/perfil.php', 'cliente' => 'cliente/perfil.php',
][perfil()] ?? null;
?>
<aside class="sidebar" id="sidebarPainel" aria-label="Menu principal">
    <div class="sidebar-topo">
        <a href="<?= url('index.php') ?>" class="sidebar-marca" title="<?= e($estabelecimento['nome']) ?>">
            <?= Tema::marca($estabelecimento) ?>
            <span class="sidebar-texto"><?= e($estabelecimento['nome']) ?></span>
        </a>
        <button type="button" class="sidebar-recolher" aria-controls="sidebarPainel" aria-expanded="true" title="Recolher menu">
            <?= icone('recolher') ?><span class="sr-only">Recolher menu</span>
        </button>
    </div>

    <nav class="sidebar-navegacao">
        <ul class="sidebar-menu">
            <?php foreach ($itens as $item): ?>
                <?php if (isset($item['separador'])): ?>
                    <li class="separador"><span class="sidebar-texto"><?= e($item['separador']) ?></span></li>
                <?php else: $ativo = basename($item['arquivo']) === $paginaMenu; ?>
                    <li>
                        <a href="<?= url($item['arquivo']) ?>" title="<?= e($item['rotulo']) ?>"
                           class="<?= $ativo ? 'ativo' : '' ?>"<?= $ativo ? ' aria-current="page"' : '' ?>>
                            <?= icone($item['icone']) ?>
                            <span class="sidebar-texto"><?= e($item['rotulo']) ?></span>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </nav>

    <div class="sidebar-rodape">
        <?php $cartaoUsuario = '<span class="avatar">' . e(iniciais(usuarioNome())) . '</span>'
            . '<span class="sidebar-perfil-dados sidebar-texto"><strong>' . e(usuarioNome()) . '</strong><span>' . e(perfilRotulo()) . '</span></span>'; ?>
        <?php if ($paginaConta && !ehSimulacao()): ?>
            <a class="sidebar-perfil" href="<?= url($paginaConta) ?>" title="Sua conta"><?= $cartaoUsuario ?></a>
        <?php else: ?>
            <div class="sidebar-perfil"><?= $cartaoUsuario ?></div>
        <?php endif; ?>
        <a class="sidebar-sair" href="<?= url(ehMaster() ? 'master/logout.php' : 'logout.php') ?>" title="Sair">
            <?= icone('sair') ?><span class="sidebar-texto">Sair</span>
        </a>
    </div>
</aside>
