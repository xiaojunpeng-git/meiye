-- Additive migration only; never grants consent. Select/verify the target DB and prefix first.
-- MySQL compatible idempotent column addition. No business rows or encrypted keys are changed.
SET @mohe_scope_exists = (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'eb_mohe_ai_config' AND column_name = 'external_scope_version');
SET @mohe_scope_sql = IF(@mohe_scope_exists = 0,
  'ALTER TABLE `eb_mohe_ai_config` ADD COLUMN `external_scope_version` varchar(64) NOT NULL DEFAULT '''' COMMENT ''Explicit versioned outbound consent; empty = legacy scope''',
  'SELECT ''external_scope_version already present; verify definition'' AS migration_status');
PREPARE mohe_scope_statement FROM @mohe_scope_sql;
EXECUTE mohe_scope_statement;
DEALLOCATE PREPARE mohe_scope_statement;
