-- 只读检查；执行结果必须属于本次明确点名的目标数据库。
SELECT DATABASE() AS target_database;
SELECT COUNT(*) AS has_user FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_user';
SELECT COUNT(*) AS has_user_bill FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_user_bill';
SELECT COUNT(*) AS existing_guard FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_user_integral_expiry';
SELECT COUNT(*) AS previous_clear_bills FROM eb_user_bill
 WHERE category = 'integral' AND type = 'system_clear';
SELECT mark, is_open, type, cycle FROM eb_system_timer
 WHERE mark = 'auto_clear_integral' AND is_del = 0;
