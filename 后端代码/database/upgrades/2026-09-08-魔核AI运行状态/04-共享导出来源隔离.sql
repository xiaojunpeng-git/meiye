-- MOHE-AI-00002: NOT an online additive migration. Execute only during the documented creation/consumer barrier.
-- Prefix eb_ must match the explicitly selected isolated instance. Do not re-run blindly.
-- Preconditions: no AI rows ever created; all ordinary creation/retry paths blocked;
-- all old consumers (including scheduled CLI and resident workers) confirmed physically stopped.
ALTER TABLE `eb_unified_query_export_task`
  ADD COLUMN `source_type` VARCHAR(6) NULL AFTER `task_no`,
  ADD COLUMN `ai_binding` MEDIUMTEXT NULL AFTER `source_type`;

-- This is a one-off historical migration, NOT an application fallback/default.
UPDATE `eb_unified_query_export_task` SET `source_type`='REPORT' WHERE `source_type` IS NULL;

ALTER TABLE `eb_unified_query_export_task`
  MODIFY COLUMN `source_type` VARCHAR(6) NOT NULL,
  ADD INDEX `idx_source_status_lease` (`source_type`,`status`,`lease_expires_at`,`id`);

-- No DEFAULT REPORT. Creating code must explicitly write REPORT or AI.
-- Application source policy rejects unknown values, missing AI binding, partition mismatch,
-- and both outer/inner export scopes other than query. Source is immutable after creation.
