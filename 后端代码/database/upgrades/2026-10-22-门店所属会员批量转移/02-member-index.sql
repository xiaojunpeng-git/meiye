-- 确认名单按主归属门店读取，避免 INSERT SELECT 全表扫描并锁住无关门店会员。
-- 允许重复执行；既有实例已存在同名索引时不重复创建。
SET @r26_index_exists = (SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'eb_user' AND index_name = 'idx_belong_active_uid');
SET @r26_index_sql = IF(@r26_index_exists = 0,
  'ALTER TABLE `eb_user` ADD INDEX `idx_belong_active_uid` (`belong_store_id`,`is_del`,`delete_time`,`uid`), ALGORITHM=INPLACE, LOCK=NONE',
  'SELECT 1');
PREPARE r26_member_index FROM @r26_index_sql;
EXECUTE r26_member_index;
DEALLOCATE PREPARE r26_member_index;
