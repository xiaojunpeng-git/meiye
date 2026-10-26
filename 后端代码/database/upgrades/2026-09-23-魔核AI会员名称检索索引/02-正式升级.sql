-- 精确姓名/昵称匹配分别使用独立索引，使 OR 条件可以采用索引合并；
-- 不建立客户专属索引，也不改变会员表中的任何业务字段或数据。
SET @real_name_index_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_user' AND INDEX_NAME = 'idx_user_real_name'
);
SET @real_name_sql := IF(
    @real_name_index_exists = 0,
    'ALTER TABLE `eb_user` ADD INDEX `idx_user_real_name` (`real_name`)',
    'SELECT ''idx_user_real_name already exists'' AS migration_notice'
);
PREPARE real_name_statement FROM @real_name_sql;
EXECUTE real_name_statement;
DEALLOCATE PREPARE real_name_statement;

SET @nickname_index_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_user' AND INDEX_NAME = 'idx_user_nickname'
);
SET @nickname_sql := IF(
    @nickname_index_exists = 0,
    'ALTER TABLE `eb_user` ADD INDEX `idx_user_nickname` (`nickname`)',
    'SELECT ''idx_user_nickname already exists'' AS migration_notice'
);
PREPARE nickname_statement FROM @nickname_sql;
EXECUTE nickname_statement;
DEALLOCATE PREPARE nickname_statement;
