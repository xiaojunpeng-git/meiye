-- 两个索引均存在且各自只绑定一个名称列，会员总行数需与升级前记录一致。
SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX, INDEX_TYPE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_user'
  AND INDEX_NAME IN ('idx_user_real_name', 'idx_user_nickname')
ORDER BY INDEX_NAME, SEQ_IN_INDEX;

SELECT COUNT(*) AS member_row_count_after FROM eb_user;
