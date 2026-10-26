-- 只读检查：确认会员名称字段和目标索引现状，不读取会员具体内容。
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_user'
  AND COLUMN_NAME IN ('real_name', 'nickname')
ORDER BY COLUMN_NAME;

SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_user'
  AND INDEX_NAME IN ('idx_user_real_name', 'idx_user_nickname')
ORDER BY INDEX_NAME, SEQ_IN_INDEX;

SELECT COUNT(*) AS member_row_count_before FROM eb_user;
