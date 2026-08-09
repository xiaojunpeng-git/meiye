-- upgrade_key: 20260730-003-mobile-auth-v1-session-security
-- Read only. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @ma_db := DATABASE();
SET @ma_failures := 0;
SELECT COUNT(*) INTO @ma_log FROM information_schema.TABLES WHERE TABLE_SCHEMA=@ma_db AND TABLE_NAME='eb_database_upgrade_log' AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @ma_done FROM eb_database_upgrade_log WHERE upgrade_key='20260730-003-mobile-auth-v1-session-security';
SELECT COUNT(*) INTO @ma_prerequisites FROM eb_database_upgrade_log WHERE upgrade_key IN (
 '20260719-009-employee-org-leader','20260722-027-employee-org-staff-i1','20260722-028-employee-auth-center-i2','20260722-029-employee-auth-i2-schema-strict','20260722-031-job-position-data-scope-i2','20260723-033-org-auth-version-merchant','20260723-035-hq-store-role-template','20260724-001-org-identity-final','20260727-001-cashier-v3-command-idem','20260728-003-cashier-v3-event-outbox','20260728-002-cashier-v3-member-consistency','20260729-013-mobile-auth-canonical-identity');
SELECT COUNT(*) INTO @ma_identity FROM information_schema.TABLES WHERE TABLE_SCHEMA=@ma_db AND TABLE_NAME IN ('eb_user_phone_identity','eb_user_phone_identity_audit');
SELECT COUNT(*) INTO @ma_employee_columns FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ma_db AND TABLE_NAME='eb_employee' AND COLUMN_NAME IN ('id','phone','status','is_del','auth_version');
SELECT COUNT(*) INTO @ma_employee_phone_duplicates FROM (SELECT phone FROM eb_employee WHERE is_del=0 AND phone REGEXP '^1[3-9][0-9]{9}$' GROUP BY phone HAVING COUNT(*)>1) ma_employee_duplicates;
SELECT COUNT(*) INTO @ma_existing FROM information_schema.TABLES WHERE TABLE_SCHEMA=@ma_db AND TABLE_NAME IN ('eb_employee_mobile_auth_state','eb_employee_phone_binding','eb_employee_phone_binding_audit','eb_mobile_captcha_challenge','eb_mobile_sms_challenge','eb_mobile_app_session','eb_mobile_phone_verification','eb_mobile_merchant_lease','eb_mobile_merchant_session','eb_mobile_merchant_business_context','eb_mobile_merchant_session_projection','eb_mobile_merchant_idempotency','eb_mobile_merchant_security_audit');
SET @ma_failures := @ma_failures + IF(@ma_log=1,0,1) + IF(@ma_done=0,0,1) + IF(@ma_prerequisites=12,0,1) + IF(@ma_identity=2,0,1) + IF(@ma_employee_columns=5,0,1) + IF(@ma_employee_phone_duplicates=0,0,1) + IF(@ma_existing IN (0,13),0,1);
SELECT @ma_db AS db_name, VERSION() AS mysql_version, @ma_done AS already_registered, @ma_prerequisites AS prerequisite_upgrade_count, @ma_identity AS canonical_identity_table_count, @ma_employee_columns AS employee_column_count, @ma_employee_phone_duplicates AS employee_phone_duplicate_groups, @ma_existing AS existing_target_table_count, @ma_failures AS precheck_failure_count;
SET @ma_finish := IF(@ma_failures=0,'SELECT ''PRECHECK_OK'' AS precheck_result','SELECT * FROM STOP_MOBILE_AUTH_V1_PRECHECK_FAILED');
PREPARE ma_stmt FROM @ma_finish; EXECUTE ma_stmt; DEALLOCATE PREPARE ma_stmt;
