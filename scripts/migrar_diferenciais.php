<?php
/**
 * Estrutura dos recursos comerciais e de relacionamento.
 * A função é idempotente e pode ser executada em bancos já existentes.
 */
function migrarDiferenciais(PDO $db): void
{
    $colunaExiste = static function (string $tabela, string $coluna) use ($db): bool {
        $ddl = $db->query("SHOW CREATE TABLE `$tabela`")->fetch(PDO::FETCH_NUM)[1];
        return (bool) preg_match('/^\s*`' . preg_quote($coluna, '/') . '`\s/m', $ddl);
    };

    $colunas = [
        ['clientes', 'pontos_fidelidade', 'INT UNSIGNED NOT NULL DEFAULT 0'],
        ['profissionais', 'comissao_percentual', 'DECIMAL(5,2) NOT NULL DEFAULT 0.00'],
        ['profissionais', 'token_calendario', 'CHAR(64) NULL'],
        ['agendamentos', 'grupo_recorrencia', 'CHAR(36) NULL'],
        ['agendamentos', 'id_cliente_pacote', 'INT UNSIGNED NULL'],
    ];
    foreach ($colunas as [$tabela, $coluna, $definicao]) {
        if (!$colunaExiste($tabela, $coluna)) {
            $db->exec("ALTER TABLE `$tabela` ADD COLUMN `$coluna` $definicao");
        }
    }

    $db->exec("CREATE TABLE IF NOT EXISTS lista_espera (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS notificacoes (
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
        tentativas INT UNSIGNED NOT NULL DEFAULT 0,
        erro VARCHAR(255) NULL,
        data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_notificacao),
        UNIQUE KEY uk_notificacao_agendamento_tipo (id_estabelecimento,id_agendamento,tipo),
        KEY idx_notificacao_fila (id_estabelecimento,status,data_programada),
        CONSTRAINT fk_notificacao_empresa FOREIGN KEY (id_estabelecimento) REFERENCES estabelecimento(id_estabelecimento),
        CONSTRAINT fk_notificacao_usuario_tenant FOREIGN KEY (id_estabelecimento,id_usuario) REFERENCES usuarios(id_estabelecimento,id_usuario) ON DELETE CASCADE,
        CONSTRAINT fk_notificacao_agendamento_tenant FOREIGN KEY (id_estabelecimento,id_agendamento) REFERENCES agendamentos(id_estabelecimento,id_agendamento) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS pagamentos (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS fidelidade_movimentos (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS pacotes (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS cliente_pacotes (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS avaliacoes (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}