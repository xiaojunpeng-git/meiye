-- upgrade_key: 20260727-001-cashier-v3-command-idem
-- 收银 V3 命令与幂等底座（C1-A）：命令回执/幂等、既有资源版本、工作台投影上下文。
--
-- 兼容基线：MySQL 5.6.50（008 实测 dump 头 `美容源码/database/008.cc3798.com.sql:1,5`）。
-- 本文件禁止使用 JSON 类型、生成列、窗口函数、CTE、CHECK、SKIP LOCKED/NOWAIT、
-- 降序索引、ALGORITHM=INSTANT、utf8mb4_0900_* 等 5.7+/8.0 专属能力。
--
-- 索引长度自检（严格基线：COMPACT 行格式，单个索引上限 767 字节）：
--   参与唯一索引的标识列一律使用 ASCII 字符集（1 字符 = 1 字节），
--   因为它们只存 UUID、kind、scope、资源主键这类 ASCII 内容，
--   用 utf8mb4 会把每列放大 4 倍，四列组合唯一键立刻越过 767。
--     idempotency_key   varchar(128) ascii -> 128 字节  < 767 OK
--     client_session_id varchar(128) ascii -> 128 字节  < 767 OK
--     state_context_id  varchar(64)  ascii ->  64 字节  < 767 OK
--     uk_scope_resource = scope_type(16) + scope_id(32) + resource_kind(32) + resource_id(64)
--                       = 16 + 32 + 32 + 64 = 144 字节  < 767 OK
--     uk_identity       = store_id(4) + operator_id(4) + client_session_id(128)
--                       = 136 字节  < 767 OK
--
-- 可重复执行：三张表均为新建表，使用 CREATE TABLE IF NOT EXISTS。
-- 结构不匹配的同名旧表由 01-升级前检查.sql 拦截，不会被 IF NOT EXISTS 静默跳过。
-- 本包不改动任何既有表，不写入任何业务数据，不回填历史。

SET NAMES utf8mb4;

-- ========== 1. 命令回执与幂等 ==========
-- 只有成功命令落最终回执（status=1）。失败命令整事务回滚，占位行一并消失，
-- 因此不存在需要清理的 PROCESSING 残留行。
CREATE TABLE IF NOT EXISTS `eb_cashier_v3_command_receipt` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '幂等键：已登记大写前缀-标准UUID，最长128',
  `action` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '规范写命令 action',
  `store_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '会话强制门店ID',
  `operator_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '收银员ID',
  `state_context_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '发起该命令的工作台投影上下文',
  `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'action+规范化payload 的SHA256',
  `contexts_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '带 canonical scope 的 contexts 的SHA256',
  `contexts_json` mediumtext COMMENT '本次校验通过的 contexts（含服务端解析出的 scope，排序后）',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '0=占位未完成 1=成功；仅成功命令保留',
  `result_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '预留结果码',
  `result_message` varchar(255) NOT NULL DEFAULT '' COMMENT '面向操作人的结果提示',
  `result_json` mediumtext COMMENT '第一次已确定的不可变业务结果；committed_versions 仅供审计，重放不当作当前版本返回',
  `business_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '主对象业务单号，便于对账定位',
  `operator_ip` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '操作来源IP',
  `add_time` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '命令进入时间（秒级）',
  `finish_time` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '命令完成时间（秒级）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_idempotency_key` (`idempotency_key`),
  KEY `idx_store_action_time` (`store_id`,`action`,`add_time`),
  KEY `idx_state_context` (`state_context_id`,`add_time`),
  KEY `idx_business_no` (`business_no`),
  KEY `idx_archive_time` (`add_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='收银V3命令回执与幂等（本轮不自动清理，idx_archive_time 供后续归档）';

-- ========== 2. 既有资源版本 ==========
-- 唯一身份是 canonical scope + kind + id，不是「一律按门店」：
--   会员、余额、次数池、卡权益 scope_type=tenant，集团内共享同一份版本，
--   两家门店同时扣同一个余额时第二个必然版本冲突；
--   房间、库存、服务单等 scope_type=store，A 店查不到 B 店对象，
--   连「版本冲突」这种可用于探测存在性的回应都拿不到。
-- 业务主表仍是业务权威源；本表是尚未具备版本列的 kind 的默认落点。
-- C2/C3 把某个 kind 切换到业务主表版本列后，本表不再登记该 kind。
CREATE TABLE IF NOT EXISTS `eb_cashier_v3_resource_version` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `scope_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '权威作用域类型：tenant/organization/store',
  `scope_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '作用域标识；tenant 固定为0（一库一租户）',
  `resource_kind` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '对象类型，见 CashierV3ResourceKindCatalog',
  `resource_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '对象标识',
  `current_version` bigint(20) unsigned NOT NULL DEFAULT '1' COMMENT '权威版本，从1起算，永不为0',
  `last_action` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '最后一次推进该版本的 action',
  `add_time` int(11) unsigned NOT NULL DEFAULT '0',
  `update_time` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_scope_resource` (`scope_type`,`scope_id`,`resource_kind`,`resource_id`),
  KEY `idx_scope_kind` (`scope_type`,`scope_id`,`resource_kind`),
  KEY `idx_update_time` (`update_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='收银V3既有资源版本登记（按canonical scope隔离，乐观并发，不承担页面投影职责）';

-- ========== 3. 工作台投影上下文与投影游标 ==========
-- 一行 = 一个「登录账号 + 后端强制门店 + 浏览器工作台会话」。
-- current_revision 从 0 起，首次签发投影后为 1；不同 state_context_id 之间禁止比较。
-- 只有确实返回完整根 state 时才签发新 revision。
CREATE TABLE IF NOT EXISTS `eb_cashier_v3_state_context` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `state_context_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '对前端不透明的安全随机上下文标识',
  `store_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '后端强制门店ID',
  `operator_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '登录账号（收银员）ID',
  `client_session_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '浏览器标签页稳定会话标识 SESSION-uuid',
  `current_revision` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '投影游标；对外签发时从1开始严格递增',
  `add_time` int(11) unsigned NOT NULL DEFAULT '0',
  `last_seen_time` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_state_context_id` (`state_context_id`),
  UNIQUE KEY `uk_identity` (`store_id`,`operator_id`,`client_session_id`),
  KEY `idx_last_seen` (`last_seen_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='收银V3工作台投影上下文与投影游标（本轮不自动清理，idx_last_seen 供后续归档）';

-- 成功后由执行流程写入 eb_database_upgrade_log（含真实 SHA-256）。
-- INSERT INTO `eb_database_upgrade_log`
-- (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
-- VALUES
-- ('20260727-001-cashier-v3-command-idem','收银V3命令与幂等底座','任务管理/进行中/2026-07-27-收银V3-派工/C1-00001-00014-C1A第四轮验收结论与第五轮整改.md','<真实SHA-256>','<commit>',NOW(),'<执行人>','<备份与验证证据>');
