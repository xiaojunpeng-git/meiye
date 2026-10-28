-- 只读预检。必须存在 user/wechat_user/手机号身份表后才运行本轮新增表迁移。
SELECT CASE WHEN COUNT(DISTINCT TABLE_NAME) = 4 THEN 'PRECHECK_OK'
 ELSE 'STOP_MISSING_CANONICAL_MEMBER_TABLES' END AS result
FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
 AND TABLE_NAME IN ('eb_user','eb_wechat_user','eb_user_phone_identity','eb_user_phone_identity_audit');
SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND
 ((TABLE_NAME = 'eb_user' AND COLUMN_NAME IN ('uid','phone','status','is_del'))
 OR (TABLE_NAME = 'eb_wechat_user' AND COLUMN_NAME IN ('id','uid','is_del')))
 ORDER BY TABLE_NAME, ORDINAL_POSITION;
