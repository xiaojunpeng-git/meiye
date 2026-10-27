-- 仅检查当前实例，执行升级前核对独立数据库并备份。
SELECT DATABASE() AS current_database;
SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('eb_user','eb_wechat_user','eb_member_phone_login_reset','eb_member_management_receipt');
