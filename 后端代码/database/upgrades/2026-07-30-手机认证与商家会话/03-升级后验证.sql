-- upgrade_key: 20260730-003-mobile-auth-v1-session-security
SET NAMES utf8mb4;
SET @ma_db := DATABASE();
SET @ma_failures := 0;
SELECT COUNT(*) INTO @ma_registered FROM eb_database_upgrade_log WHERE upgrade_key='20260730-003-mobile-auth-v1-session-security';
SELECT COUNT(*) INTO @ma_tables FROM information_schema.TABLES WHERE TABLE_SCHEMA=@ma_db AND TABLE_NAME IN ('eb_employee_mobile_auth_state','eb_employee_phone_binding','eb_employee_phone_binding_audit','eb_mobile_captcha_challenge','eb_mobile_sms_challenge','eb_mobile_app_session','eb_mobile_phone_verification','eb_mobile_merchant_lease','eb_mobile_merchant_session','eb_mobile_merchant_business_context','eb_mobile_merchant_session_projection','eb_mobile_merchant_idempotency','eb_mobile_merchant_security_audit');
SELECT COUNT(*) INTO @ma_token_indexes FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@ma_db AND ((TABLE_NAME='eb_mobile_app_session' AND INDEX_NAME='uk_token_hash') OR (TABLE_NAME='eb_mobile_merchant_session' AND INDEX_NAME='uk_token_hash'));
SELECT COUNT(*) INTO @ma_context_index FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@ma_db AND TABLE_NAME='eb_mobile_merchant_business_context' AND INDEX_NAME='uk_employee_context';
SELECT COUNT(*) INTO @ma_sms_idempotency FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@ma_db AND TABLE_NAME='eb_mobile_sms_challenge' AND INDEX_NAME='uk_purpose_phone_idem';
SELECT COUNT(*) INTO @ma_auth_state_rows FROM eb_employee_mobile_auth_state;
SELECT COUNT(*) INTO @ma_active_employee_rows FROM eb_employee WHERE is_del=0;
SELECT COUNT(*) INTO @ma_binding_rows FROM eb_employee_phone_binding WHERE state='BOUND';
SELECT COUNT(*) INTO @ma_valid_phone_rows FROM eb_employee WHERE is_del=0 AND phone REGEXP '^1[3-9][0-9]{9}$';
SET @ma_failures := @ma_failures + IF(@ma_registered IN (0,1),0,1) + IF(@ma_tables=13,0,1) + IF(@ma_token_indexes=2,0,1) + IF(@ma_context_index=5,0,1) + IF(@ma_sms_idempotency=3,0,1) + IF(@ma_auth_state_rows=@ma_active_employee_rows,0,1) + IF(@ma_binding_rows=@ma_valid_phone_rows,0,1);
SELECT @ma_registered AS registered, @ma_tables AS target_table_count, @ma_token_indexes AS token_index_count, @ma_context_index AS context_index_column_count, @ma_sms_idempotency AS sms_idempotency_index_column_count, @ma_auth_state_rows AS auth_state_rows, @ma_active_employee_rows AS active_employee_rows, @ma_binding_rows AS bound_phone_rows, @ma_valid_phone_rows AS valid_phone_rows, @ma_failures AS postcheck_failure_count;
SET @ma_finish := IF(@ma_failures=0,'SELECT ''POSTCHECK_OK'' AS postcheck_result','SELECT * FROM STOP_MOBILE_AUTH_V1_POSTCHECK_FAILED');
PREPARE ma_stmt FROM @ma_finish; EXECUTE ma_stmt; DEALLOCATE PREPARE ma_stmt;
