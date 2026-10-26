-- 同一个 mysql 会话中先 SET @codex_010_store_hash 为新生成的 bcrypt 散列。
-- 只在 010 测试数据中给 11 家门店的原管理岗统一设置测试账号；007 账号不变。
USE mohe_010_app;
SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';
DROP PROCEDURE IF EXISTS mohe_010_app.codex_010_store_preflight;
DELIMITER //
CREATE PROCEDURE mohe_010_app.codex_010_store_preflight()
BEGIN
  IF (SELECT COUNT(*) FROM mohe_010_app.codex_010_migration_batch
      WHERE batch_key='010-linlin-20261023-refresh-1')<>1
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_system_store_staff
         WHERE level=0 AND is_admin=1 AND status=1 AND is_del=0)<>11
     OR (SELECT COUNT(DISTINCT store_id) FROM mohe_010_app.eb_system_store_staff
         WHERE level=0 AND is_admin=1 AND status=1 AND is_del=0)<>11
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_system_store_staff
         WHERE account='test010store')<>0
     OR @codex_010_store_hash IS NULL
     OR @codex_010_store_hash NOT REGEXP '^\\$2[aby]\\$[0-9][0-9]\\$' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='010 store test account preflight failed';
  END IF;
END//
DELIMITER ;
CALL mohe_010_app.codex_010_store_preflight();
DROP PROCEDURE mohe_010_app.codex_010_store_preflight;

START TRANSACTION;
UPDATE mohe_010_app.eb_system_store_staff
SET account='test010store',pwd=@codex_010_store_hash
WHERE level=0 AND is_admin=1 AND status=1 AND is_del=0;
SELECT COUNT(*) AS store_test_accounts,COUNT(DISTINCT store_id) AS stores
FROM mohe_010_app.eb_system_store_staff
WHERE account='test010store' AND level=0 AND is_admin=1 AND status=1 AND is_del=0;
COMMIT;
