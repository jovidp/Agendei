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
DROP TABLE IF EXISTS `estabelecimento_plano`;
DROP TABLE IF EXISTS `planos`;
DROP TABLE IF EXISTS `avaliacoes`;
DROP TABLE IF EXISTS `cliente_pacotes`;
DROP TABLE IF EXISTS `pacotes`;
DROP TABLE IF EXISTS `fidelidade_movimentos`;
DROP TABLE IF EXISTS `pagamentos`;
DROP TABLE IF EXISTS `notificacoes`;
DROP TABLE IF EXISTS `lista_espera`;
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
  `totp_segredo` varchar(255) DEFAULT NULL,
  `totp_ativado_em` datetime DEFAULT NULL,
  `totp_ultimo_contador` bigint(20) DEFAULT NULL,
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
  `telefone_fixo` varchar(20) DEFAULT NULL,
  `login` varchar(6) DEFAULT NULL,
  `sexo` enum('F','M','O') DEFAULT NULL,
  `nome_materno` varchar(120) DEFAULT NULL,
  `data_nascimento` date DEFAULT NULL,
  `cep` char(8) DEFAULT NULL,
  `logradouro` varchar(150) DEFAULT NULL,
  `numero` varchar(20) DEFAULT NULL,
  `complemento` varchar(60) DEFAULT NULL,
  `bairro` varchar(100) DEFAULT NULL,
  `cidade` varchar(100) DEFAULT NULL,
  `uf` char(2) DEFAULT NULL,
  `tipo` enum('cliente','profissional','admin') NOT NULL DEFAULT 'cliente',
  `status` enum('ativo','inativo') NOT NULL DEFAULT 'ativo',
  `totp_segredo` varchar(255) DEFAULT NULL,
  `totp_ativado_em` datetime DEFAULT NULL,
  `totp_ultimo_contador` bigint(20) DEFAULT NULL,
  `token_recuperacao` varchar(64) DEFAULT NULL,
  `token_expiracao` datetime DEFAULT NULL,
  `ultimo_acesso` datetime DEFAULT NULL,
  `data_criacao` datetime NOT NULL DEFAULT current_timestamp(),
  `data_atualizacao` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `id_estabelecimento` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id_usuario`),
  UNIQUE KEY `uk_estabelecimento_registro` (`id_estabelecimento`,`id_usuario`),
  UNIQUE KEY `uk_estabelecimento_email` (`id_estabelecimento`,`email`),
  UNIQUE KEY `uk_estabelecimento_login` (`id_estabelecimento`,`login`),
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
  `pontos_fidelidade` int(10) unsigned NOT NULL DEFAULT 0,
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
  `comissao_percentual` decimal(5,2) NOT NULL DEFAULT 0.00,
  `token_calendario` char(64) DEFAULT NULL,
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
  `grupo_recorrencia` char(36) DEFAULT NULL,
  `id_cliente_pacote` int(10) unsigned DEFAULT NULL,
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
-- RECURSOS DIFERENCIAIS
-- ---------------------------------------------------------------------
CREATE TABLE lista_espera (
        id_lista INT UNSIGNED NOT NULL AUTO_INCREMENT,
        id_estabelecimento INT UNSIGNED NOT NULL,
        id_cliente INT UNSIGNED NOT NULL,
        id_servico INT UNSIGNED NOT NULL,
        id_profissional INT UNSIGNED NULL,
        data_desejada DATE NOT NULL,
        periodo ENUM('qualquer','manha','tarde','noite') NOT NULL DEFAULT 'qualquer',
        status ENUM('aguardando','avisado','convertido','cancelado') NOT NULL DEFAULT 'aguardando',
        data_aviso DATETIME NULL,
        data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_lista),
        UNIQUE KEY uk_lista_cliente_preferencia (id_estabelecimento,id_cliente,id_servico,id_profissional,data_desejada),
        KEY idx_lista_vaga (id_estabelecimento,data_desejada,id_profissional,status),
        CONSTRAINT fk_lista_empresa FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento(id_estabelecimento),
        CONSTRAINT fk_lista_cliente_tenant FOREIGN KEY (id_estabelecimento,id_cliente) REFERENCES clientes(id_estabelecimento,id_cliente) ON DELETE CASCADE,
        CONSTRAINT fk_lista_servico_tenant FOREIGN KEY (id_estabelecimento,id_servico) REFERENCES servicos(id_estabelecimento,id_servico) ON DELETE CASCADE,
        CONSTRAINT fk_lista_profissional_tenant FOREIGN KEY (id_estabelecimento,id_profissional) REFERENCES profissionais(id_estabelecimento,id_profissional) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notificacoes (
        id_notificacao INT UNSIGNED NOT NULL AUTO_INCREMENT,
        id_estabelecimento INT UNSIGNED NOT NULL,
        id_usuario INT UNSIGNED NULL,
        id_agendamento INT UNSIGNED NULL,
        canal ENUM('whatsapp','email','sistema') NOT NULL DEFAULT 'sistema',
        tipo VARCHAR(40) NOT NULL,
        destinatario VARCHAR(150) NULL,
        mensagem TEXT NOT NULL,
        status ENUM('pendente','enviada','lida','cancelada') NOT NULL DEFAULT 'pendente',
        data_programada DATETIME NULL,
        data_envio DATETIME NULL,
        data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_notificacao),
        UNIQUE KEY uk_notificacao_agendamento_tipo (id_estabelecimento,id_agendamento,tipo),
        KEY idx_notificacao_fila (id_estabelecimento,status,data_programada),
        CONSTRAINT fk_notificacao_empresa FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento(id_estabelecimento),
        CONSTRAINT fk_notificacao_usuario_tenant FOREIGN KEY (id_estabelecimento,id_usuario) REFERENCES usuarios(id_estabelecimento,id_usuario) ON DELETE CASCADE,
        CONSTRAINT fk_notificacao_agendamento_tenant FOREIGN KEY (id_estabelecimento,id_agendamento) REFERENCES agendamentos(id_estabelecimento,id_agendamento) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pagamentos (
        id_pagamento INT UNSIGNED NOT NULL AUTO_INCREMENT,
        id_estabelecimento INT UNSIGNED NOT NULL,
        id_agendamento INT UNSIGNED NOT NULL,
        tipo ENUM('sinal','integral') NOT NULL DEFAULT 'sinal',
        valor DECIMAL(10,2) NOT NULL,
        metodo ENUM('pix','dinheiro','cartao','outro') NOT NULL DEFAULT 'pix',
        status ENUM('pendente','pago','cancelado','estornado') NOT NULL DEFAULT 'pendente',
        referencia VARCHAR(80) NULL,
        data_pagamento DATETIME NULL,
        data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_pagamento),
        UNIQUE KEY uk_pagamento_agendamento_tipo (id_estabelecimento,id_agendamento,tipo),
        KEY idx_pagamento_status (id_estabelecimento,status),
        CONSTRAINT fk_pagamento_empresa FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento(id_estabelecimento),
        CONSTRAINT fk_pagamento_agendamento_tenant FOREIGN KEY (id_estabelecimento,id_agendamento) REFERENCES agendamentos(id_estabelecimento,id_agendamento) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fidelidade_movimentos (
        id_movimento INT UNSIGNED NOT NULL AUTO_INCREMENT,
        id_estabelecimento INT UNSIGNED NOT NULL,
        id_cliente INT UNSIGNED NOT NULL,
        id_agendamento INT UNSIGNED NULL,
        pontos INT NOT NULL,
        descricao VARCHAR(180) NOT NULL,
        data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_movimento),
        UNIQUE KEY uk_fidelidade_agendamento (id_estabelecimento,id_agendamento),
        KEY idx_fidelidade_cliente (id_estabelecimento,id_cliente),
        CONSTRAINT fk_fidelidade_empresa FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento(id_estabelecimento),
        CONSTRAINT fk_fidelidade_cliente_tenant FOREIGN KEY (id_estabelecimento,id_cliente) REFERENCES clientes(id_estabelecimento,id_cliente) ON DELETE CASCADE,
        CONSTRAINT fk_fidelidade_agendamento_tenant FOREIGN KEY (id_estabelecimento,id_agendamento) REFERENCES agendamentos(id_estabelecimento,id_agendamento) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pacotes (
        id_pacote INT UNSIGNED NOT NULL AUTO_INCREMENT,
        id_estabelecimento INT UNSIGNED NOT NULL,
        id_servico INT UNSIGNED NOT NULL,
        nome VARCHAR(120) NOT NULL,
        quantidade SMALLINT UNSIGNED NOT NULL,
        validade_dias SMALLINT UNSIGNED NOT NULL DEFAULT 90,
        preco DECIMAL(10,2) NOT NULL,
        status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
        data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_pacote),
        UNIQUE KEY uk_estabelecimento_pacote (id_estabelecimento,id_pacote),
        KEY idx_pacote_status (id_estabelecimento,status),
        CONSTRAINT fk_pacote_empresa FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento(id_estabelecimento),
        CONSTRAINT fk_pacote_servico_tenant FOREIGN KEY (id_estabelecimento,id_servico) REFERENCES servicos(id_estabelecimento,id_servico)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cliente_pacotes (
        id_cliente_pacote INT UNSIGNED NOT NULL AUTO_INCREMENT,
        id_estabelecimento INT UNSIGNED NOT NULL,
        id_cliente INT UNSIGNED NOT NULL,
        id_pacote INT UNSIGNED NOT NULL,
        creditos_total SMALLINT UNSIGNED NOT NULL,
        creditos_restantes SMALLINT UNSIGNED NOT NULL,
        status_pagamento ENUM('pendente','pago','cancelado') NOT NULL DEFAULT 'pendente',
        data_expiracao DATE NOT NULL,
        data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_cliente_pacote),
        UNIQUE KEY uk_estabelecimento_cliente_pacote (id_estabelecimento,id_cliente_pacote),
        KEY idx_cliente_pacotes (id_estabelecimento,id_cliente,status_pagamento),
        CONSTRAINT fk_cliente_pacote_empresa FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento(id_estabelecimento),
        CONSTRAINT fk_cliente_pacote_cliente_tenant FOREIGN KEY (id_estabelecimento,id_cliente) REFERENCES clientes(id_estabelecimento,id_cliente) ON DELETE CASCADE,
        CONSTRAINT fk_cliente_pacote_pacote_tenant FOREIGN KEY (id_estabelecimento,id_pacote) REFERENCES pacotes(id_estabelecimento,id_pacote)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE avaliacoes (
        id_avaliacao INT UNSIGNED NOT NULL AUTO_INCREMENT,
        id_estabelecimento INT UNSIGNED NOT NULL,
        id_agendamento INT UNSIGNED NOT NULL,
        id_cliente INT UNSIGNED NOT NULL,
        id_profissional INT UNSIGNED NOT NULL,
        nota TINYINT UNSIGNED NOT NULL,
        comentario VARCHAR(500) NULL,
        status ENUM('publicada','oculta') NOT NULL DEFAULT 'publicada',
        data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_avaliacao),
        UNIQUE KEY uk_avaliacao_agendamento (id_estabelecimento,id_agendamento),
        KEY idx_avaliacao_profissional (id_estabelecimento,id_profissional,status),
        CONSTRAINT fk_avaliacao_empresa FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento(id_estabelecimento),
        CONSTRAINT fk_avaliacao_agendamento_tenant FOREIGN KEY (id_estabelecimento,id_agendamento) REFERENCES agendamentos(id_estabelecimento,id_agendamento) ON DELETE CASCADE,
        CONSTRAINT fk_avaliacao_cliente_tenant FOREIGN KEY (id_estabelecimento,id_cliente) REFERENCES clientes(id_estabelecimento,id_cliente) ON DELETE CASCADE,
        CONSTRAINT fk_avaliacao_profissional_tenant FOREIGN KEY (id_estabelecimento,id_profissional) REFERENCES profissionais(id_estabelecimento,id_profissional),
        CONSTRAINT ck_avaliacao_nota CHECK (nota BETWEEN 1 AND 5)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- LOG DE AUTENTICAÇÃO
-- Nome e CPF ficam desnormalizados de propósito: o log precisa sobreviver
-- à exclusão do usuário feita pelo master.
-- ---------------------------------------------------------------------
CREATE TABLE `logs_autenticacao` (
  `id_log` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_estabelecimento` int(10) unsigned NOT NULL,
  `id_usuario` int(10) unsigned DEFAULT NULL,
  `login_informado` varchar(150) NOT NULL,
  `nome` varchar(120) NOT NULL DEFAULT '',
  `cpf` char(11) DEFAULT NULL,
  `perfil` varchar(20) DEFAULT NULL,
  `evento` enum('login_sucesso','login_falha','2fa_sucesso','2fa_falha','2fa_bloqueio','logout') NOT NULL,
  `fator_2fa` enum('nome_materno','data_nascimento','cep','totp') DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `data_hora` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_log`),
  KEY `idx_logs_estabelecimento` (`id_estabelecimento`,`data_hora`),
  KEY `idx_logs_nome` (`nome`),
  KEY `idx_logs_cpf` (`cpf`),
  CONSTRAINT `fk_logs_estabelecimento` FOREIGN KEY (`id_estabelecimento`) REFERENCES `estabelecimento` (`id_estabelecimento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- PLANOS DE CONTRATACAO
-- Tetos que a administracao master aplica a um estabelecimento: equipe,
-- catalogo e volume de agendamentos no mes.
--
-- Limite NULL significa "sem teto", e estabelecimento sem vinculo continua
-- sem limite nenhum: a funcionalidade nasce sem mudar quem ja usa o sistema.
-- ---------------------------------------------------------------------
CREATE TABLE `planos` (
  `id_plano` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(60) NOT NULL,
  `descricao` varchar(255) DEFAULT NULL,
  `limite_profissionais` int(10) unsigned DEFAULT NULL,
  `limite_servicos` int(10) unsigned DEFAULT NULL,
  `limite_agendamentos_mes` int(10) unsigned DEFAULT NULL,
  `status` enum('ativo','inativo') NOT NULL DEFAULT 'ativo',
  `data_criacao` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_plano`),
  UNIQUE KEY `uk_planos_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Um estabelecimento tem um plano de cada vez: a chave primaria e o proprio
-- vinculo, entao a troca substitui a linha em vez de empilhar historico.
CREATE TABLE `estabelecimento_plano` (
  `id_estabelecimento` int(10) unsigned NOT NULL,
  `id_plano` int(10) unsigned NOT NULL,
  `data_inicio` datetime NOT NULL DEFAULT current_timestamp(),
  `observacao` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_estabelecimento`),
  KEY `idx_ep_plano` (`id_plano`),
  CONSTRAINT `fk_ep_estabelecimento` FOREIGN KEY (`id_estabelecimento`) REFERENCES `estabelecimento` (`id_estabelecimento`),
  CONSTRAINT `fk_ep_plano` FOREIGN KEY (`id_plano`) REFERENCES `planos` (`id_plano`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- AUDITORIA DA ADMINISTRACAO MASTER
-- Registro do que a conta global faz: empresas criadas, acessos ligados e
-- desligados, senhas redefinidas e bloqueios liberados.
--
-- Nome do master e nome da empresa ficam gravados na propria linha, e nao por
-- chave estrangeira: o historico precisa continuar legivel depois que a conta
-- ou o estabelecimento citado deixar de existir.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `logs_master`;
CREATE TABLE `logs_master` (
  `id_log_master` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_master` int(10) unsigned DEFAULT NULL,
  `master_nome` varchar(120) NOT NULL DEFAULT '',
  `master_email` varchar(150) NOT NULL DEFAULT '',
  `acao` varchar(40) NOT NULL,
  `id_estabelecimento` int(10) unsigned DEFAULT NULL,
  `estabelecimento_nome` varchar(120) DEFAULT NULL,
  `alvo` varchar(150) DEFAULT NULL,
  `detalhe` varchar(255) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `data_hora` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_log_master`),
  KEY `idx_logs_master_data` (`data_hora`),
  KEY `idx_logs_master_acao` (`acao`),
  KEY `idx_logs_master_empresa` (`id_estabelecimento`,`data_hora`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- TENTATIVAS DE ACESSO
-- Contador usado pelo controle de forca bruta (includes/seguranca.php).
--
-- Fica fora do escopo de estabelecimento de proposito: o bloqueio protege
-- tambem a area master, que nao pertence a empresa nenhuma. Guarda apenas o
-- hash da origem, nunca o e-mail ou o IP em texto claro.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `tentativas_acesso`;
CREATE TABLE `tentativas_acesso` (
  `id_tentativa` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `escopo` varchar(30) NOT NULL,
  `chave` char(64) NOT NULL,
  `data_hora` datetime NOT NULL,
  PRIMARY KEY (`id_tentativa`),
  KEY `idx_tentativa_busca` (`escopo`,`chave`,`data_hora`)
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
