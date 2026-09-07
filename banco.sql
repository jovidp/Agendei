-- =====================================================================
-- AGENDEI - banco multiestabelecimento
-- Cada registro operacional carrega id_estabelecimento e as chaves
-- compostas impedem misturar clientes, profissionais, serviços e agendas.
-- ATENÇÃO: este arquivo recria as tabelas e deve ser usado na instalação.
-- Para atualizar uma base existente, execute: php scripts/migrar.php
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `agendei` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `agendei`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `configuracoes`;
DROP TABLE IF EXISTS `agendamentos`;
DROP TABLE IF EXISTS `bloqueios_agenda`;
DROP TABLE IF EXISTS `horarios_profissionais`;
DROP TABLE IF EXISTS `profissional_servico`;
DROP TABLE IF EXISTS `servicos`;
DROP TABLE IF EXISTS `administradores`;
DROP TABLE IF EXISTS `profissionais`;
DROP TABLE IF EXISTS `clientes`;
DROP TABLE IF EXISTS `usuarios`;
DROP TABLE IF EXISTS `administradores_master`;
DROP TABLE IF EXISTS `estabelecimento`;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- ESTABELECIMENTOS
-- Uma linha por empresa. O slug identifica seu link público e a identidade visual fica nesta tabela.
-- ---------------------------------------------------------------------
CREATE TABLE `estabelecimento` (
  `id_estabelecimento` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(120) NOT NULL,
  `slogan` varchar(180) DEFAULT NULL,
  `descricao` text DEFAULT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `whatsapp` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `endereco` varchar(180) DEFAULT NULL,
  `bairro` varchar(100) DEFAULT NULL,
  `cidade` varchar(100) DEFAULT NULL,
  `uf` char(2) DEFAULT NULL,
  `cep` varchar(9) DEFAULT NULL,
  `horario_funcionamento` text DEFAULT NULL,
  `instagram` varchar(120) DEFAULT NULL,
  `facebook` varchar(120) DEFAULT NULL,
  `data_atualizacao` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `slug` varchar(80) NOT NULL,
  `cor_primaria` char(7) NOT NULL DEFAULT '#1F4E5F',
  `cor_secundaria` char(7) NOT NULL DEFAULT '#5FAF8B',
  `cor_fundo` char(7) NOT NULL DEFAULT '#F5F1EA',
  `fonte` varchar(30) NOT NULL DEFAULT 'padrao',
  `logo` mediumtext DEFAULT NULL,
  `status` enum('ativo','inativo') NOT NULL DEFAULT 'ativo',
  PRIMARY KEY (`id_estabelecimento`),
  UNIQUE KEY `uk_estabelecimento_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- ADMINISTRADORES MASTER
-- Contas globais, sem vínculo com uma empresa. A senha inicial é criada pelo instalador PHP.
-- ---------------------------------------------------------------------
CREATE TABLE `administradores_master` (
  `id_master` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(120) NOT NULL,
  `email` varchar(150) NOT NULL,
  `senha_hash` varchar(255) NOT NULL,
  `status` enum('ativo','inativo') NOT NULL DEFAULT 'ativo',
  `cor_primaria` char(7) NOT NULL DEFAULT '#252B36',
  `cor_secundaria` char(7) NOT NULL DEFAULT '#6E8BFF',
  `cor_fundo` char(7) NOT NULL DEFAULT '#F3F5F8',
  `fonte` varchar(30) NOT NULL DEFAULT 'padrao',
  `logo` mediumtext DEFAULT NULL,
  `ultimo_acesso` datetime DEFAULT NULL,
  `data_criacao` datetime NOT NULL DEFAULT current_timestamp(),
  `data_atualizacao` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_master`),
  UNIQUE KEY `uk_master_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- USUÁRIOS DOS ESTABELECIMENTOS
-- Toda conta local pertence obrigatoriamente a uma empresa. E-mail é único dentro dessa empresa.
-- ---------------------------------------------------------------------
CREATE TABLE `usuarios` (
  `id_usuario` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(120) NOT NULL,
  `email` varchar(150) NOT NULL,
  `senha_hash` varchar(255) NOT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `tipo` enum('cliente','profissional','admin') NOT NULL DEFAULT 'cliente',
  `status` enum('ativo','inativo') NOT NULL DEFAULT 'ativo',
  `token_recuperacao` varchar(64) DEFAULT NULL,
  `token_expiracao` datetime DEFAULT NULL,
  `ultimo_acesso` datetime DEFAULT NULL,
  `data_criacao` datetime NOT NULL DEFAULT current_timestamp(),
  `data_atualizacao` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `id_estabelecimento` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id_usuario`),
  UNIQUE KEY `uk_estabelecimento_registro` (`id_estabelecimento`,`id_usuario`),
  UNIQUE KEY `uk_estabelecimento_email` (`id_estabelecimento`,`email`),
  KEY `idx_usuarios_tipo_status` (`tipo`,`status`),
  KEY `idx_estabelecimento` (`id_estabelecimento`),
  CONSTRAINT `fk_usuarios_estabelecimento` FOREIGN KEY (`id_estabelecimento`) REFERENCES `estabelecimento` (`id_estabelecimento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- CLIENTES
-- O vínculo composto impede associar o perfil a uma conta de outra empresa.
-- ---------------------------------------------------------------------
CREATE TABLE `clientes` (
  `id_cliente` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_usuario` int(10) unsigned NOT NULL,
  `cpf` char(11) DEFAULT NULL,
  `data_nascimento` date DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  `data_cadastro` datetime NOT NULL DEFAULT current_timestamp(),
  `id_estabelecimento` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id_cliente`),
  UNIQUE KEY `uk_clientes_usuario` (`id_usuario`),
  UNIQUE KEY `uk_estabelecimento_registro` (`id_estabelecimento`,`id_cliente`),
  UNIQUE KEY `uk_estabelecimento_cpf` (`id_estabelecimento`,`cpf`),
  KEY `idx_estabelecimento` (`id_estabelecimento`),
  KEY `fk_tenant_clientes_id_usuario` (`id_estabelecimento`,`id_usuario`),
  CONSTRAINT `fk_clientes_estabelecimento` FOREIGN KEY (`id_estabelecimento`) REFERENCES `estabelecimento` (`id_estabelecimento`),
  CONSTRAINT `fk_clientes_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tenant_clientes_id_usuario` FOREIGN KEY (`id_estabelecimento`, `id_usuario`) REFERENCES `usuarios` (`id_estabelecimento`, `id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- PROFISSIONAIS
-- Equipe isolada por estabelecimento.
-- ---------------------------------------------------------------------
CREATE TABLE `profissionais` (
  `id_profissional` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_usuario` int(10) unsigned NOT NULL,
  `especialidade` varchar(120) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `pode_bloquear_agenda` tinyint(1) NOT NULL DEFAULT 1,
  `data_cadastro` datetime NOT NULL DEFAULT current_timestamp(),
  `id_estabelecimento` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id_profissional`),
  UNIQUE KEY `uk_profissionais_usuario` (`id_usuario`),
  UNIQUE KEY `uk_estabelecimento_registro` (`id_estabelecimento`,`id_profissional`),
  KEY `idx_estabelecimento` (`id_estabelecimento`),
  KEY `fk_tenant_profissionais_id_usuario` (`id_estabelecimento`,`id_usuario`),
  CONSTRAINT `fk_profissionais_estabelecimento` FOREIGN KEY (`id_estabelecimento`) REFERENCES `estabelecimento` (`id_estabelecimento`),
  CONSTRAINT `fk_profissionais_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tenant_profissionais_id_usuario` FOREIGN KEY (`id_estabelecimento`, `id_usuario`) REFERENCES `usuarios` (`id_estabelecimento`, `id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- ADMINISTRADORES LOCAIS
-- Cada empresa tem pelo menos uma conta administrativa criada junto com seu cadastro.
-- ---------------------------------------------------------------------
CREATE TABLE `administradores` (
  `id_administrador` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_usuario` int(10) unsigned NOT NULL,
  `nivel` enum('super','gerente') NOT NULL DEFAULT 'super',
  `data_cadastro` datetime NOT NULL DEFAULT current_timestamp(),
  `id_estabelecimento` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id_administrador`),
  UNIQUE KEY `uk_administradores_usuario` (`id_usuario`),
  UNIQUE KEY `uk_estabelecimento_registro` (`id_estabelecimento`,`id_administrador`),
  KEY `idx_estabelecimento` (`id_estabelecimento`),
  KEY `fk_tenant_administradores_id_usuario` (`id_estabelecimento`,`id_usuario`),
  CONSTRAINT `fk_administradores_estabelecimento` FOREIGN KEY (`id_estabelecimento`) REFERENCES `estabelecimento` (`id_estabelecimento`),
  CONSTRAINT `fk_administradores_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tenant_administradores_id_usuario` FOREIGN KEY (`id_estabelecimento`, `id_usuario`) REFERENCES `usuarios` (`id_estabelecimento`, `id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- SERVIÇOS
-- Catálogo próprio de cada estabelecimento.
-- ---------------------------------------------------------------------
CREATE TABLE `servicos` (
  `id_servico` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(120) NOT NULL,
  `descricao` text DEFAULT NULL,
  `preco` decimal(10,2) NOT NULL DEFAULT 0.00,
  `duracao_minutos` smallint(5) unsigned NOT NULL DEFAULT 30,
  `status` enum('ativo','inativo') NOT NULL DEFAULT 'ativo',
  `destaque` tinyint(1) NOT NULL DEFAULT 0,
  `data_cadastro` datetime NOT NULL DEFAULT current_timestamp(),
  `data_atualizacao` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `id_estabelecimento` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id_servico`),
  UNIQUE KEY `uk_estabelecimento_registro` (`id_estabelecimento`,`id_servico`),
  KEY `idx_servicos_status` (`status`),
  KEY `idx_estabelecimento` (`id_estabelecimento`),
  CONSTRAINT `fk_servicos_estabelecimento` FOREIGN KEY (`id_estabelecimento`) REFERENCES `estabelecimento` (`id_estabelecimento`),
  CONSTRAINT `ck_servicos_duracao` CHECK (`duracao_minutos` > 0),
  CONSTRAINT `ck_servicos_preco` CHECK (`preco` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- PROFISSIONAL × SERVIÇO
-- As chaves compostas impedem vínculos entre empresas diferentes.
-- ---------------------------------------------------------------------
CREATE TABLE `profissional_servico` (
  `id_profissional` int(10) unsigned NOT NULL,
  `id_servico` int(10) unsigned NOT NULL,
  `id_estabelecimento` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id_profissional`,`id_servico`),
  KEY `idx_ps_servico` (`id_servico`),
  KEY `idx_estabelecimento` (`id_estabelecimento`),
  KEY `fk_tenant_profissional_servico_id_profissional` (`id_estabelecimento`,`id_profissional`),
  KEY `fk_tenant_profissional_servico_id_servico` (`id_estabelecimento`,`id_servico`),
  CONSTRAINT `fk_profissional_servico_estabelecimento` FOREIGN KEY (`id_estabelecimento`) REFERENCES `estabelecimento` (`id_estabelecimento`),
  CONSTRAINT `fk_ps_profissional` FOREIGN KEY (`id_profissional`) REFERENCES `profissionais` (`id_profissional`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ps_servico` FOREIGN KEY (`id_servico`) REFERENCES `servicos` (`id_servico`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tenant_profissional_servico_id_profissional` FOREIGN KEY (`id_estabelecimento`, `id_profissional`) REFERENCES `profissionais` (`id_estabelecimento`, `id_profissional`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tenant_profissional_servico_id_servico` FOREIGN KEY (`id_estabelecimento`, `id_servico`) REFERENCES `servicos` (`id_estabelecimento`, `id_servico`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- EXPEDIENTE
-- Faixas semanais dos profissionais da empresa.
-- ---------------------------------------------------------------------
CREATE TABLE `horarios_profissionais` (
  `id_horario` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_profissional` int(10) unsigned NOT NULL,
  `dia_semana` tinyint(3) unsigned NOT NULL,
  `hora_inicio` time NOT NULL,
  `hora_fim` time NOT NULL,
  `intervalo_minutos` smallint(5) unsigned NOT NULL DEFAULT 30,
  `status` enum('ativo','inativo') NOT NULL DEFAULT 'ativo',
  `data_cadastro` datetime NOT NULL DEFAULT current_timestamp(),
  `id_estabelecimento` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id_horario`),
  UNIQUE KEY `uk_horario_faixa` (`id_profissional`,`dia_semana`,`hora_inicio`,`hora_fim`),
  UNIQUE KEY `uk_estabelecimento_registro` (`id_estabelecimento`,`id_horario`),
  KEY `idx_horario_prof_dia` (`id_profissional`,`dia_semana`,`status`),
  KEY `idx_estabelecimento` (`id_estabelecimento`),
  KEY `fk_tenant_horarios_profissionais_id_profissional` (`id_estabelecimento`,`id_profissional`),
  CONSTRAINT `fk_horarios_profissionais_estabelecimento` FOREIGN KEY (`id_estabelecimento`) REFERENCES `estabelecimento` (`id_estabelecimento`),
  CONSTRAINT `fk_horarios_profissional` FOREIGN KEY (`id_profissional`) REFERENCES `profissionais` (`id_profissional`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tenant_horarios_profissionais_id_profissional` FOREIGN KEY (`id_estabelecimento`, `id_profissional`) REFERENCES `profissionais` (`id_estabelecimento`, `id_profissional`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ck_horarios_dia` CHECK (`dia_semana` between 0 and 6),
  CONSTRAINT `ck_horarios_faixa` CHECK (`hora_fim` > `hora_inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- BLOQUEIOS
-- Indisponibilidades da agenda.
-- ---------------------------------------------------------------------
CREATE TABLE `bloqueios_agenda` (
  `id_bloqueio` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_profissional` int(10) unsigned NOT NULL,
  `data_bloqueio` date NOT NULL,
  `hora_inicio` time NOT NULL DEFAULT '00:00:00',
  `hora_fim` time NOT NULL DEFAULT '23:59:59',
  `motivo` varchar(255) DEFAULT NULL,
  `id_usuario_criou` int(10) unsigned DEFAULT NULL,
  `data_criacao` datetime NOT NULL DEFAULT current_timestamp(),
  `id_estabelecimento` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id_bloqueio`),
  UNIQUE KEY `uk_estabelecimento_registro` (`id_estabelecimento`,`id_bloqueio`),
  KEY `idx_bloqueio_prof_data` (`id_profissional`,`data_bloqueio`),
  KEY `fk_bloqueios_usuario` (`id_usuario_criou`),
  KEY `idx_estabelecimento` (`id_estabelecimento`),
  KEY `fk_tenant_bloqueios_agenda_id_profissional` (`id_estabelecimento`,`id_profissional`),
  CONSTRAINT `fk_bloqueios_agenda_estabelecimento` FOREIGN KEY (`id_estabelecimento`) REFERENCES `estabelecimento` (`id_estabelecimento`),
  CONSTRAINT `fk_bloqueios_profissional` FOREIGN KEY (`id_profissional`) REFERENCES `profissionais` (`id_profissional`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bloqueios_usuario` FOREIGN KEY (`id_usuario_criou`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tenant_bloqueios_agenda_id_profissional` FOREIGN KEY (`id_estabelecimento`, `id_profissional`) REFERENCES `profissionais` (`id_estabelecimento`, `id_profissional`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ck_bloqueio_faixa` CHECK (`hora_fim` > `hora_inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- AGENDAMENTOS
-- Cliente, profissional e serviço precisam pertencer ao mesmo estabelecimento.
-- ---------------------------------------------------------------------
CREATE TABLE `agendamentos` (
  `id_agendamento` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_cliente` int(10) unsigned NOT NULL,
  `id_profissional` int(10) unsigned NOT NULL,
  `id_servico` int(10) unsigned NOT NULL,
  `data_agendamento` date NOT NULL,
  `hora_inicio` time NOT NULL,
  `hora_fim` time NOT NULL,
  `valor` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('agendado','confirmado','concluido','cancelado') NOT NULL DEFAULT 'agendado',
  `observacao` text DEFAULT NULL,
  `origem` enum('cliente','admin','profissional') NOT NULL DEFAULT 'cliente',
  `motivo_cancelamento` varchar(255) DEFAULT NULL,
  `id_usuario_cancelou` int(10) unsigned DEFAULT NULL,
  `data_criacao` datetime NOT NULL DEFAULT current_timestamp(),
  `data_atualizacao` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `id_estabelecimento` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id_agendamento`),
  UNIQUE KEY `uk_estabelecimento_registro` (`id_estabelecimento`,`id_agendamento`),
  KEY `idx_agend_profissional_data` (`id_profissional`,`data_agendamento`,`hora_inicio`),
  KEY `idx_agend_cliente_data` (`id_cliente`,`data_agendamento`),
  KEY `idx_agend_status_data` (`status`,`data_agendamento`),
  KEY `idx_agend_servico` (`id_servico`),
  KEY `fk_agend_usuario_cancelou` (`id_usuario_cancelou`),
  KEY `idx_estabelecimento` (`id_estabelecimento`),
  KEY `fk_tenant_agendamentos_id_cliente` (`id_estabelecimento`,`id_cliente`),
  KEY `fk_tenant_agendamentos_id_profissional` (`id_estabelecimento`,`id_profissional`),
  KEY `fk_tenant_agendamentos_id_servico` (`id_estabelecimento`,`id_servico`),
  CONSTRAINT `fk_agend_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_agend_profissional` FOREIGN KEY (`id_profissional`) REFERENCES `profissionais` (`id_profissional`) ON UPDATE CASCADE,
  CONSTRAINT `fk_agend_servico` FOREIGN KEY (`id_servico`) REFERENCES `servicos` (`id_servico`) ON UPDATE CASCADE,
  CONSTRAINT `fk_agend_usuario_cancelou` FOREIGN KEY (`id_usuario_cancelou`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_agendamentos_estabelecimento` FOREIGN KEY (`id_estabelecimento`) REFERENCES `estabelecimento` (`id_estabelecimento`),
  CONSTRAINT `fk_tenant_agendamentos_id_cliente` FOREIGN KEY (`id_estabelecimento`, `id_cliente`) REFERENCES `clientes` (`id_estabelecimento`, `id_cliente`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tenant_agendamentos_id_profissional` FOREIGN KEY (`id_estabelecimento`, `id_profissional`) REFERENCES `profissionais` (`id_estabelecimento`, `id_profissional`) ON UPDATE CASCADE,
  CONSTRAINT `fk_tenant_agendamentos_id_servico` FOREIGN KEY (`id_estabelecimento`, `id_servico`) REFERENCES `servicos` (`id_estabelecimento`, `id_servico`) ON UPDATE CASCADE,
  CONSTRAINT `ck_agend_horario` CHECK (`hora_fim` > `hora_inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- CONFIGURAÇÕES
-- Regras independentes para cada estabelecimento.
-- ---------------------------------------------------------------------
CREATE TABLE `configuracoes` (
  `id_configuracao` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `chave` varchar(60) NOT NULL,
  `valor` varchar(255) NOT NULL,
  `descricao` varchar(255) DEFAULT NULL,
  `id_estabelecimento` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id_configuracao`),
  UNIQUE KEY `uk_estabelecimento_registro` (`id_estabelecimento`,`id_configuracao`),
  UNIQUE KEY `uk_estabelecimento_chave` (`id_estabelecimento`,`chave`),
  KEY `idx_estabelecimento` (`id_estabelecimento`),
  CONSTRAINT `fk_configuracoes_estabelecimento` FOREIGN KEY (`id_estabelecimento`) REFERENCES `estabelecimento` (`id_estabelecimento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- DADOS INICIAIS
-- Os usuarios (admin, profissionais e cliente demo) sao criados pelo
-- arquivo instalar.php, que usa password_hash() do PHP.
-- =====================================================================

INSERT INTO `estabelecimento`
  (`nome`,`slug`,`slogan`,`descricao`,`telefone`,`whatsapp`,`email`,`endereco`,`bairro`,`cidade`,`uf`,`cep`,`horario_funcionamento`)
VALUES
  ('Agendei Studio', 'agendei-studio',
   'Atendimento com hora marcada, do jeito que voce precisa.',
   'Somos um estudio de beleza e bem-estar com profissionais qualificados e atendimento personalizado. Trabalhamos com hora marcada para que voce nao perca tempo em filas de espera.',
   '(11) 3000-0000', '(11) 99000-0000', 'contato@agendei.com.br',
   'Rua das Acacias, 120', 'Centro', 'Sao Paulo', 'SP', '01000-000',
   'Segunda a sexta: 08:00 as 19:00\nSabado: 08:00 as 14:00\nDomingo: fechado');

-- Valores iniciais das regras que podem ser ajustadas pelo painel administrativo.
INSERT INTO `configuracoes` (`id_estabelecimento`,`chave`,`valor`,`descricao`) VALUES
  (1, 'antecedencia_minima_horas', '2',  'Antecedencia minima, em horas, para o cliente agendar'),
  (1, 'antecedencia_maxima_dias',  '60', 'Quantos dias no futuro o cliente pode agendar'),
  (1, 'cancelamento_limite_horas', '4',  'Ate quantas horas antes o cliente pode cancelar'),
  (1, 'intervalo_slots_minutos',   '30', 'Intervalo padrao entre horarios exibidos'),
  (1, 'permitir_bloqueio_profissional', '1', 'Profissional pode bloquear a propria agenda (1=sim, 0=nao)'),
  (1, 'confirmar_automaticamente', '0',  'Agendamentos nascem confirmados (1=sim, 0=nao)');

-- Serviços de exemplo usados para demonstrar o catálogo e a criação de reservas.
INSERT INTO `servicos` (`id_estabelecimento`,`nome`,`descricao`,`preco`,`duracao_minutos`,`status`,`destaque`) VALUES
  (1, 'Corte de cabelo masculino', 'Corte na tesoura ou maquina, com finalizacao e acabamento na navalha.', 45.00, 30, 'ativo', 1),
  (1, 'Corte de cabelo feminino',  'Corte personalizado com lavagem e escova de finalizacao.', 75.00, 60, 'ativo', 1),
  (1, 'Barba completa',            'Toalha quente, navalha, hidratacao e finalizacao.', 35.00, 30, 'ativo', 1),
  (1, 'Corte + barba',             'Combo com corte de cabelo e barba completa.', 70.00, 60, 'ativo', 1),
  (1, 'Coloracao',                 'Coloracao profissional com produtos de alta performance.', 160.00, 120, 'ativo', 0),
  (1, 'Hidratacao capilar',        'Tratamento profundo de reconstrucao e brilho.', 90.00, 60, 'ativo', 0),
  (1, 'Manicure',                  'Cuidado completo das unhas das maos com esmaltacao.', 40.00, 45, 'ativo', 0),
  (1, 'Pedicure',                  'Cuidado completo das unhas dos pes com esmaltacao.', 45.00, 45, 'ativo', 0);
