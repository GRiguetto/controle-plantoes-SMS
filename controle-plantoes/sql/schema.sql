-- ============================================================
-- Sistema de Controle de Plantões
-- Schema do banco de dados (MySQL / phpMyAdmin)
-- Versão: 1.0
-- ============================================================

CREATE DATABASE IF NOT EXISTS controle_plantoes
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE controle_plantoes;

-- ------------------------------------------------------------
-- Tabela: unidades (hospitais/unidades de lotação)
-- ------------------------------------------------------------
CREATE TABLE unidades (
  id_unidade      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome            VARCHAR(150) NOT NULL,
  sigla           VARCHAR(20)  NULL,
  ativo           TINYINT(1)   NOT NULL DEFAULT 1,
  criado_em       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Tabela: usuarios
-- ------------------------------------------------------------
CREATE TABLE usuarios (
  id_usuario      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome_completo   VARCHAR(150) NOT NULL,
  matricula       VARCHAR(30)  NOT NULL UNIQUE, -- imutável após criação
  email           VARCHAR(150) NOT NULL UNIQUE,
  senha_hash      VARCHAR(255) NOT NULL,
  perfil          ENUM('funcionario','gerente','administrador') NOT NULL DEFAULT 'funcionario',
  ativo           TINYINT(1)   NOT NULL DEFAULT 1, -- soft delete
  criado_em       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_usuarios_perfil (perfil),
  INDEX idx_usuarios_ativo (ativo)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Tabela: usuario_unidade (N:N — usuário pode ter várias unidades)
-- Para 'gerente', a regra de negócio limita a 1 unidade via aplicação.
-- ------------------------------------------------------------
CREATE TABLE usuario_unidade (
  id_usuario      INT UNSIGNED NOT NULL,
  id_unidade      INT UNSIGNED NOT NULL,
  PRIMARY KEY (id_usuario, id_unidade),
  CONSTRAINT fk_uu_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
  CONSTRAINT fk_uu_unidade FOREIGN KEY (id_unidade) REFERENCES unidades(id_unidade) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Tabela: setores (setores dentro de uma unidade)
-- ------------------------------------------------------------
CREATE TABLE setores (
  id_setor        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_unidade      INT UNSIGNED NOT NULL,
  nome            VARCHAR(100) NOT NULL,
  ativo           TINYINT(1)   NOT NULL DEFAULT 1,
  CONSTRAINT fk_setor_unidade FOREIGN KEY (id_unidade) REFERENCES unidades(id_unidade) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Tabela: plantoes (pedidos/registros de plantão)
-- ------------------------------------------------------------
CREATE TABLE plantoes (
  id_plantao        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_usuario        INT UNSIGNED NOT NULL,
  id_unidade        INT UNSIGNED NOT NULL,
  id_setor          INT UNSIGNED NULL,
  tipo_plantao      ENUM('6h','12h','24h') NOT NULL,
  data_plantao      DATE NOT NULL,          -- competência (data de referência)
  entrada           DATETIME NOT NULL,
  saida             DATETIME NULL,
  horas_diurnas     DECIMAL(5,2) NULL,
  horas_noturnas    DECIMAL(5,2) NULL,
  status            ENUM('pendente','aceito','negado') NOT NULL DEFAULT 'pendente',
  justificativa_negacao TEXT NULL,          -- obrigatório quando status = negado
  producao          INT UNSIGNED NULL,      -- obrigatório quando status = aceito
  avaliado_por      INT UNSIGNED NULL,      -- id_usuario do gerente
  avaliado_em       DATETIME NULL,
  criado_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_plantao_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario),
  CONSTRAINT fk_plantao_unidade FOREIGN KEY (id_unidade) REFERENCES unidades(id_unidade),
  CONSTRAINT fk_plantao_setor FOREIGN KEY (id_setor) REFERENCES setores(id_setor),
  CONSTRAINT fk_plantao_avaliador FOREIGN KEY (avaliado_por) REFERENCES usuarios(id_usuario),
  -- índices de performance: histórico segmentado por mês/competência (requisito 4.3)
  INDEX idx_plantao_usuario_data (id_usuario, data_plantao),
  INDEX idx_plantao_unidade_data (id_unidade, data_plantao),
  INDEX idx_plantao_status (status),
  INDEX idx_plantao_competencia (data_plantao)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Tabela: pausas (múltiplas pausas por plantão)
-- ------------------------------------------------------------
CREATE TABLE pausas (
  id_pausa        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_plantao      INT UNSIGNED NOT NULL,
  inicio_pausa    DATETIME NOT NULL,
  fim_pausa       DATETIME NULL,
  CONSTRAINT fk_pausa_plantao FOREIGN KEY (id_plantao) REFERENCES plantoes(id_plantao) ON DELETE CASCADE,
  INDEX idx_pausa_plantao (id_plantao)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Tabela: logs (auditoria de ações administrativas/gerenciais)
-- ------------------------------------------------------------
CREATE TABLE logs (
  id_log          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_usuario      INT UNSIGNED NULL,     -- quem executou a ação
  acao            VARCHAR(100) NOT NULL, -- ex: 'aprovar_plantao', 'negar_plantao', 'reset_senha', 'desativar_usuario'
  entidade        VARCHAR(50)  NULL,     -- ex: 'plantao', 'usuario'
  id_entidade     INT UNSIGNED NULL,
  detalhes        TEXT NULL,
  criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_log_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario),
  INDEX idx_log_criado_em (criado_em)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Seeds mínimos de exemplo (opcional — remover em produção)
-- ------------------------------------------------------------
-- INSERT INTO unidades (nome, sigla) VALUES ('UPA Região Norte', 'UPA-RN');
-- INSERT INTO usuarios (nome_completo, matricula, email, senha_hash, perfil)
--   VALUES ('Administrador Geral', '00001', 'admin@prefeitura.gov.br', '$2y$10$hash', 'administrador');
