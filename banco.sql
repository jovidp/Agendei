-- =====================================================================
-- AGENDEI - Sistema de Agendamento de Servicos
-- Estrutura do banco de dados MySQL
-- Charset: utf8mb4 / Engine: InnoDB
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `agendei`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `agendei`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `bloqueios_agenda`;
DROP TABLE IF EXISTS `agendamentos`;
DROP TABLE IF EXISTS `horarios_profissionais`;
DROP TABLE IF EXISTS `profissional_servico`;
DROP TABLE IF EXISTS `servicos`;
DROP TABLE IF EXISTS `administradores`;
DROP TABLE IF EXISTS `profissionais`;
DROP TABLE IF EXISTS `clientes`;
DROP TABLE IF EXISTS `usuarios`;
DROP TABLE IF EXISTS `configuracoes`;
DROP TABLE IF EXISTS `estabelecimento`;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- USUARIOS
-- Tabela unica de autenticacao. Os dados especificos de cada perfil
-- ficam nas tabelas clientes / profissionais / administradores (1-1).
-- ---------------------------------------------------------------------
CREATE TABLE `usuarios` (
  `id_usuario`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`               VARCHAR(120) NOT NULL,
  `email`              VARCHAR(150) NOT NULL,
  `senha_hash`         VARCHAR(255) NOT NULL,
  `telefone`           VARCHAR(20)  DEFAULT NULL,
  `tipo`               ENUM('cliente','profissional','admin') NOT NULL DEFAULT 'cliente',
  `status`             ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  `token_recuperacao`  VARCHAR(64)  DEFAULT NULL,
  `token_expiracao`    DATETIME     DEFAULT NULL,
  `ultimo_acesso`      DATETIME     DEFAULT NULL,
  `data_criacao`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao`   DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_usuario`),
  UNIQUE KEY `uk_usuarios_email` (`email`),
  KEY `idx_usuarios_tipo_status` (`tipo`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- CLIENTES
-- ---------------------------------------------------------------------
CREATE TABLE `clientes` (
  `id_cliente`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_usuario`       INT UNSIGNED NOT NULL,
  `cpf`              CHAR(11)     DEFAULT NULL,
  `data_nascimento`  DATE         DEFAULT NULL,
  `observacoes`      TEXT             NULL,
  `data_cadastro`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_cliente`),
  UNIQUE KEY `uk_clientes_usuario` (`id_usuario`),
  UNIQUE KEY `uk_clientes_cpf` (`cpf`),
  CONSTRAINT `fk_clientes_usuario` FOREIGN KEY (`id_usuario`)
    REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- PROFISSIONAIS
-- O status do profissional e controlado por usuarios.status
-- ---------------------------------------------------------------------
CREATE TABLE `profissionais` (
  `id_profissional`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_usuario`            INT UNSIGNED NOT NULL,
  `especialidade`         VARCHAR(120) DEFAULT NULL,
  `bio`                   TEXT             NULL,
  `foto`                  VARCHAR(255) DEFAULT NULL,
  `pode_bloquear_agenda`  TINYINT(1)   NOT NULL DEFAULT 1,
  `data_cadastro`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_profissional`),
  UNIQUE KEY `uk_profissionais_usuario` (`id_usuario`),
  CONSTRAINT `fk_profissionais_usuario` FOREIGN KEY (`id_usuario`)
    REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- ADMINISTRADORES
-- ---------------------------------------------------------------------
CREATE TABLE `administradores` (
  `id_administrador` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_usuario`       INT UNSIGNED NOT NULL,
  `nivel`            ENUM('super','gerente') NOT NULL DEFAULT 'super',
  `data_cadastro`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_administrador`),
  UNIQUE KEY `uk_administradores_usuario` (`id_usuario`),
  CONSTRAINT `fk_administradores_usuario` FOREIGN KEY (`id_usuario`)
    REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- SERVICOS
-- ---------------------------------------------------------------------
CREATE TABLE `servicos` (
  `id_servico`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`              VARCHAR(120)   NOT NULL,
  `descricao`         TEXT               NULL,
  `preco`             DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  `duracao_minutos`   SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  `status`            ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  `destaque`          TINYINT(1)     NOT NULL DEFAULT 0,
  `data_cadastro`     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao`  DATETIME       DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_servico`),
  KEY `idx_servicos_status` (`status`),
  CONSTRAINT `ck_servicos_duracao` CHECK (`duracao_minutos` > 0),
  CONSTRAINT `ck_servicos_preco` CHECK (`preco` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- PROFISSIONAL_SERVICO (N:N)
-- ---------------------------------------------------------------------
CREATE TABLE `profissional_servico` (
  `id_profissional` INT UNSIGNED NOT NULL,
  `id_servico`      INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id_profissional`,`id_servico`),
  KEY `idx_ps_servico` (`id_servico`),
  CONSTRAINT `fk_ps_profissional` FOREIGN KEY (`id_profissional`)
    REFERENCES `profissionais` (`id_profissional`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ps_servico` FOREIGN KEY (`id_servico`)
    REFERENCES `servicos` (`id_servico`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- HORARIOS_PROFISSIONAIS
-- Cada linha e uma faixa de expediente. Um mesmo dia pode ter varias
-- faixas (ex.: 08:00-12:00 e 13:00-18:00).
-- dia_semana: 0=Domingo ... 6=Sabado
-- ---------------------------------------------------------------------
CREATE TABLE `horarios_profissionais` (
  `id_horario`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_profissional`    INT UNSIGNED NOT NULL,
  `dia_semana`         TINYINT UNSIGNED NOT NULL,
  `hora_inicio`        TIME NOT NULL,
  `hora_fim`           TIME NOT NULL,
  `intervalo_minutos`  SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  `status`             ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  `data_cadastro`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_horario`),
  UNIQUE KEY `uk_horario_faixa` (`id_profissional`,`dia_semana`,`hora_inicio`,`hora_fim`),
  KEY `idx_horario_prof_dia` (`id_profissional`,`dia_semana`,`status`),
  CONSTRAINT `fk_horarios_profissional` FOREIGN KEY (`id_profissional`)
    REFERENCES `profissionais` (`id_profissional`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ck_horarios_dia` CHECK (`dia_semana` BETWEEN 0 AND 6),
  CONSTRAINT `ck_horarios_faixa` CHECK (`hora_fim` > `hora_inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- BLOQUEIOS_AGENDA
-- Indisponibilidades pontuais (folga, ferias, compromisso).
-- ---------------------------------------------------------------------
CREATE TABLE `bloqueios_agenda` (
  `id_bloqueio`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_profissional`  INT UNSIGNED NOT NULL,
  `data_bloqueio`    DATE NOT NULL,
  `hora_inicio`      TIME NOT NULL DEFAULT '00:00:00',
  `hora_fim`         TIME NOT NULL DEFAULT '23:59:59',
  `motivo`           VARCHAR(255) DEFAULT NULL,
  `id_usuario_criou` INT UNSIGNED DEFAULT NULL,
  `data_criacao`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_bloqueio`),
  KEY `idx_bloqueio_prof_data` (`id_profissional`,`data_bloqueio`),
  CONSTRAINT `fk_bloqueios_profissional` FOREIGN KEY (`id_profissional`)
    REFERENCES `profissionais` (`id_profissional`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bloqueios_usuario` FOREIGN KEY (`id_usuario_criou`)
    REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `ck_bloqueio_faixa` CHECK (`hora_fim` > `hora_inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- AGENDAMENTOS
-- valor e duracao sao copiados do servico no momento da criacao para
-- preservar o historico caso o preco mude depois.
-- ---------------------------------------------------------------------
CREATE TABLE `agendamentos` (
  `id_agendamento`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_cliente`            INT UNSIGNED NOT NULL,
  `id_profissional`       INT UNSIGNED NOT NULL,
  `id_servico`            INT UNSIGNED NOT NULL,
  `data_agendamento`      DATE NOT NULL,
  `hora_inicio`           TIME NOT NULL,
  `hora_fim`              TIME NOT NULL,
  `valor`                 DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status`                ENUM('agendado','confirmado','concluido','cancelado') NOT NULL DEFAULT 'agendado',
  `observacao`            TEXT NULL,
  `origem`                ENUM('cliente','admin','profissional') NOT NULL DEFAULT 'cliente',
  `motivo_cancelamento`   VARCHAR(255) DEFAULT NULL,
  `id_usuario_cancelou`   INT UNSIGNED DEFAULT NULL,
  `data_criacao`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao`      DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_agendamento`),
  KEY `idx_agend_profissional_data` (`id_profissional`,`data_agendamento`,`hora_inicio`),
  KEY `idx_agend_cliente_data` (`id_cliente`,`data_agendamento`),
  KEY `idx_agend_status_data` (`status`,`data_agendamento`),
  KEY `idx_agend_servico` (`id_servico`),
  CONSTRAINT `fk_agend_cliente` FOREIGN KEY (`id_cliente`)
    REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_agend_profissional` FOREIGN KEY (`id_profissional`)
    REFERENCES `profissionais` (`id_profissional`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_agend_servico` FOREIGN KEY (`id_servico`)
    REFERENCES `servicos` (`id_servico`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_agend_usuario_cancelou` FOREIGN KEY (`id_usuario_cancelou`)
    REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `ck_agend_horario` CHECK (`hora_fim` > `hora_inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- ESTABELECIMENTO (registro unico - dados institucionais)
-- ---------------------------------------------------------------------
CREATE TABLE `estabelecimento` (
  `id_estabelecimento`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`                   VARCHAR(120) NOT NULL,
  `slogan`                 VARCHAR(180) DEFAULT NULL,
  `descricao`              TEXT NULL,
  `telefone`               VARCHAR(20)  DEFAULT NULL,
  `whatsapp`               VARCHAR(20)  DEFAULT NULL,
  `email`                  VARCHAR(150) DEFAULT NULL,
  `endereco`               VARCHAR(180) DEFAULT NULL,
  `bairro`                 VARCHAR(100) DEFAULT NULL,
  `cidade`                 VARCHAR(100) DEFAULT NULL,
  `uf`                     CHAR(2)      DEFAULT NULL,
  `cep`                    VARCHAR(9)   DEFAULT NULL,
  `horario_funcionamento`  TEXT NULL,
  `instagram`              VARCHAR(120) DEFAULT NULL,
  `facebook`               VARCHAR(120) DEFAULT NULL,
  `data_atualizacao`       DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_estabelecimento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- CONFIGURACOES (chave/valor - regras ajustaveis sem alterar codigo)
-- ---------------------------------------------------------------------
CREATE TABLE `configuracoes` (
  `id_configuracao` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chave`           VARCHAR(60)  NOT NULL,
  `valor`           VARCHAR(255) NOT NULL,
  `descricao`       VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id_configuracao`),
  UNIQUE KEY `uk_configuracoes_chave` (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- DADOS INICIAIS
-- Os usuarios (admin, profissionais e cliente demo) sao criados pelo
-- arquivo instalar.php, que usa password_hash() do PHP.
-- =====================================================================

INSERT INTO `estabelecimento`
  (`nome`,`slogan`,`descricao`,`telefone`,`whatsapp`,`email`,`endereco`,`bairro`,`cidade`,`uf`,`cep`,`horario_funcionamento`)
VALUES
  ('Agendei Studio',
   'Atendimento com hora marcada, do jeito que voce precisa.',
   'Somos um estudio de beleza e bem-estar com profissionais qualificados e atendimento personalizado. Trabalhamos com hora marcada para que voce nao perca tempo em filas de espera.',
   '(11) 3000-0000', '(11) 99000-0000', 'contato@agendei.com.br',
   'Rua das Acacias, 120', 'Centro', 'Sao Paulo', 'SP', '01000-000',
   'Segunda a sexta: 08:00 as 19:00\nSabado: 08:00 as 14:00\nDomingo: fechado');

INSERT INTO `configuracoes` (`chave`,`valor`,`descricao`) VALUES
  ('antecedencia_minima_horas', '2',  'Antecedencia minima, em horas, para o cliente agendar'),
  ('antecedencia_maxima_dias',  '60', 'Quantos dias no futuro o cliente pode agendar'),
  ('cancelamento_limite_horas', '4',  'Ate quantas horas antes o cliente pode cancelar'),
  ('intervalo_slots_minutos',   '30', 'Intervalo padrao entre horarios exibidos'),
  ('permitir_bloqueio_profissional', '1', 'Profissional pode bloquear a propria agenda (1=sim, 0=nao)'),
  ('confirmar_automaticamente', '0',  'Agendamentos nascem confirmados (1=sim, 0=nao)');

INSERT INTO `servicos` (`nome`,`descricao`,`preco`,`duracao_minutos`,`status`,`destaque`) VALUES
  ('Corte de cabelo masculino', 'Corte na tesoura ou maquina, com finalizacao e acabamento na navalha.', 45.00, 30, 'ativo', 1),
  ('Corte de cabelo feminino',  'Corte personalizado com lavagem e escova de finalizacao.', 75.00, 60, 'ativo', 1),
  ('Barba completa',            'Toalha quente, navalha, hidratacao e finalizacao.', 35.00, 30, 'ativo', 1),
  ('Corte + barba',             'Combo com corte de cabelo e barba completa.', 70.00, 60, 'ativo', 1),
  ('Coloracao',                 'Coloracao profissional com produtos de alta performance.', 160.00, 120, 'ativo', 0),
  ('Hidratacao capilar',        'Tratamento profundo de reconstrucao e brilho.', 90.00, 60, 'ativo', 0),
  ('Manicure',                  'Cuidado completo das unhas das maos com esmaltacao.', 40.00, 45, 'ativo', 0),
  ('Pedicure',                  'Cuidado completo das unhas dos pes com esmaltacao.', 45.00, 45, 'ativo', 0);
