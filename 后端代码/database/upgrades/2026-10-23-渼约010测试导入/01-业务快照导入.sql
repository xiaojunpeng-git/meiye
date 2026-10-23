-- 渼约 010 测试实例：007 linlin 2026-10-23 第二份一致性快照导入。
-- 只写 mohe_010_app，只读 mohe_010_refresh；007 正式库、008、012 不在本脚本范围。
-- 必须先核验原始压缩归档 SHA-256：
-- 970dfd1c383862e30dd668bd6dbfec0805a996dc23d1a5673f70283058f8eb36
-- 执行账号需有目标库 DDL/DML 权限；mysql 客户端不能使用 --force。
USE mohe_010_app;
SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';
DROP PROCEDURE IF EXISTS mohe_010_app.codex_010_import_preflight;
DELIMITER //
CREATE PROCEDURE mohe_010_app.codex_010_import_preflight()
BEGIN
  IF (SELECT COUNT(*) FROM mohe_010_refresh.eb_user)<>12314
     OR (SELECT COUNT(*) FROM mohe_010_refresh.eb_store_order)<>125281
     OR (SELECT COUNT(*) FROM mohe_010_refresh.eb_store_order_writeoff)<>72949 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='010 source snapshot count changed';
  END IF;
  IF (SELECT SUM(c) FROM (
    SELECT COUNT(*) c FROM mohe_010_app.`eb_system_store`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_system_store_staff`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_user`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_user_card_holder`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_product`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_order`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_order_cart_info`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_order_writeoff`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_user_money`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_product_stock_detail`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_staff_yeji`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_agent_level`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_capital_flow`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_cash_source`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_cash_type`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_category`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_city_area`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_combination_order`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_community`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_community_comment`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_community_record`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_community_relevance`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_community_topic`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_community_user`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_diy`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_express`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_member_right`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_member_ship`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_oid_pid`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_old_shop_money`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_other_order`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_other_order_status`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_page_category`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_page_link`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_position`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_position_level`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_position_yeji`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_salary_agent`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_salary_field`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_salary_info`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_salary_search_field`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_salary_store`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_salary_table`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_staff_flowing_water`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_card_related`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_cart`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_combination`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_coupon_issue`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_coupon_issue_user`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_coupon_user`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_finance_flow`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_order_invoice`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_order_refund`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_order_status`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_pink`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_product_attr`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_product_attr_result`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_product_attr_value`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_product_cate`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_product_category`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_product_description`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_product_ensure`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_product_label`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_product_log`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_product_relation`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_product_reply`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_product_reservation_time`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_product_stock_order`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_product_unit`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_reservation_order`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_service_feedback`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_staff_shift_handover`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_target`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_target_allocate`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_target_metric`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_target_product`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_user`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_store_visit`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_system_attachment`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_system_file`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_system_region_agent`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_system_region_agent_store`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_system_region_manage`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_system_user_level`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_table_qrcode`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_table_seats`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_user_address`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_user_belong_store`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_user_bill`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_user_friends`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_user_invoice`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_user_level`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_user_recharge`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_user_relation`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_user_search`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_user_sign`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_user_spread`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_user_visit`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_user_visit_store`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_yeji_commission`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_yeji_range`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_system_group`
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.`eb_system_group_data`
  ) occupied)<>0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='010 target business tables not empty';
  END IF;
END//
DELIMITER ;
CALL mohe_010_app.codex_010_import_preflight();
DROP PROCEDURE mohe_010_app.codex_010_import_preflight;


CREATE TABLE IF NOT EXISTS mohe_010_app.codex_010_member_merge_group (
  source_uid INT UNSIGNED NOT NULL PRIMARY KEY,
  target_uid INT UNSIGNED NOT NULL,
  KEY idx_target_uid (target_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='010 重复手机号会员合并固定映射';
CREATE TABLE IF NOT EXISTS mohe_010_app.codex_010_member_map (
  source_uid INT UNSIGNED NOT NULL PRIMARY KEY,
  target_uid INT UNSIGNED NOT NULL,
  is_merged TINYINT NOT NULL,
  KEY idx_target_uid (target_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='010 全量会员来源到目标映射';
CREATE TABLE IF NOT EXISTS mohe_010_app.codex_010_member_asset_projection (
  target_uid INT UNSIGNED NOT NULL PRIMARY KEY,
  source_count INT UNSIGNED NOT NULL,
  total_balance DECIMAL(18,2) NOT NULL,
  principal_balance DECIMAL(18,2) NOT NULL,
  gift_balance DECIMAL(18,2) NOT NULL,
  integral BIGINT NOT NULL,
  KEY idx_source_count (source_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='010 合并后当前会员资产演练投影';
CREATE TABLE IF NOT EXISTS mohe_010_app.codex_010_member_aux_projection (
  target_uid INT UNSIGNED NOT NULL PRIMARY KEY,
  exp DECIMAL(18,2) NOT NULL,
  pay_count BIGINT UNSIGNED NOT NULL,
  spread_count BIGINT NOT NULL,
  brokerage_price DECIMAL(18,2) NOT NULL,
  max_sign_num INT NOT NULL,
  max_level INT NOT NULL,
  is_promoter TINYINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='010 合并主档其他累计资产演练投影';
CREATE TABLE IF NOT EXISTS mohe_010_app.codex_010_phone_identity_projection (
  phone_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  target_uid INT UNSIGNED NOT NULL,
  source_account_count INT UNSIGNED NOT NULL,
  UNIQUE KEY uk_target_uid (target_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='010 合并后手机号登录身份演练投影';
CREATE TABLE IF NOT EXISTS mohe_010_app.codex_010_balance_split_audit (
  source_uid INT NOT NULL PRIMARY KEY,
  original_now_money DECIMAL(18,2) NOT NULL,
  original_ben_money DECIMAL(18,2) NOT NULL,
  original_give_money DECIMAL(18,2) NOT NULL,
  target_give_money DECIMAL(18,2) NOT NULL,
  correction DECIMAL(18,2) NOT NULL,
  rule_name VARCHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='010 旧会员余额拆分修正对照';
CREATE TABLE IF NOT EXISTS mohe_010_app.codex_010_writeoff_order_target (
  order_id INT NOT NULL,
  writeoff_status TINYINT NOT NULL,
  original_total DECIMAL(18,2) NOT NULL,
  integer_total BIGINT NOT NULL,
  floor_total BIGINT NOT NULL,
  parent_pay_price DECIMAL(18,2) NULL,
  capped_by_parent TINYINT NOT NULL,
  PRIMARY KEY (order_id,writeoff_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='010 历史核销每订单每状态整数目标';
CREATE TABLE IF NOT EXISTS mohe_010_app.codex_010_writeoff_allocation (
  writeoff_id INT NOT NULL PRIMARY KEY,
  order_id INT NOT NULL,
  writeoff_status TINYINT NOT NULL,
  integer_price BIGINT NOT NULL,
  KEY order_status (order_id,writeoff_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='010 历史核销按状态稳定整数分配';
CREATE TABLE IF NOT EXISTS mohe_010_app.codex_010_writeoff_rounding_audit (
  writeoff_id INT NOT NULL PRIMARY KEY,
  original_price DECIMAL(18,2) NOT NULL,
  rounded_price BIGINT NOT NULL,
  rounding_delta DECIMAL(18,2) NOT NULL,
  source_snapshot VARCHAR(100) NOT NULL,
  rule_name VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='010 测试实例历史核销整数投影对照';
CREATE TABLE IF NOT EXISTS mohe_010_app.codex_010_legacy_writeoff_projection (
  source_writeoff_id INT UNSIGNED NOT NULL PRIMARY KEY,
  source_uid INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NOT NULL,
  source_order_id INT UNSIGNED NOT NULL,
  source_status TINYINT NOT NULL,
  record_status VARCHAR(16) NOT NULL,
  original_amount DECIMAL(12,2) NOT NULL,
  integer_amount BIGINT NOT NULL,
  source_writeoff_at INT NOT NULL,
  voided_at INT NULL,
  void_reason VARCHAR(255) NULL,
  linked_void_order_count INT NOT NULL DEFAULT 0,
  KEY idx_member_status (member_id,record_status,source_writeoff_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='010 旧核销及作废历史只读投影演练';
CREATE TABLE IF NOT EXISTS mohe_010_app.codex_010_migration_exceptions (
  issue_type VARCHAR(64) NOT NULL,
  source_table VARCHAR(64) NOT NULL,
  source_id BIGINT NOT NULL,
  PRIMARY KEY (issue_type,source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='010 迁移前来源异常索引';
CREATE TABLE IF NOT EXISTS mohe_010_app.codex_010_migration_batch (
  batch_key VARCHAR(64) NOT NULL PRIMARY KEY,
  source_schema VARCHAR(64) NOT NULL,
  source_archive_sha256 CHAR(64) NOT NULL,
  source_member_count INT NOT NULL,
  target_member_count INT NOT NULL,
  committed_at INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='010 快照导入批次审计';


DROP PROCEDURE IF EXISTS mohe_010_app.codex_010_import_verify;
DELIMITER //
CREATE PROCEDURE mohe_010_app.codex_010_import_verify()
BEGIN
  IF (SELECT COUNT(*) FROM mohe_010_app.eb_user)<>12290
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_store_order)<>125281
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_store_order_writeoff)<>72949
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_user_card_holder)<>12324
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_user_phone_identity)<>12275
     OR (SELECT COUNT(*) FROM mohe_010_app.codex_010_member_map)<>12314
     OR (SELECT COUNT(*) FROM mohe_010_app.codex_010_legacy_writeoff_projection)<>72949
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_member_name_initial_search)<>12290 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='010 target row count mismatch';
  END IF;
  IF (SELECT COUNT(*) FROM mohe_010_app.eb_user WHERE ABS(now_money-ben_money-give_money)>0.001)<>0
     OR (SELECT COUNT(*) FROM (SELECT phone FROM mohe_010_app.eb_user
         WHERE phone REGEXP '^1[3-9][0-9]{9}$' GROUP BY phone HAVING COUNT(*)>1) dup)<>0
     OR (SELECT SUM(c) FROM (
       SELECT COUNT(*) c FROM mohe_010_app.eb_user_visit t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_user_visit_store t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_store_finance_flow t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_store_product_log t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_store_product_log t JOIN mohe_010_app.codex_010_member_map m ON t.pay_uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_user_friends t JOIN mohe_010_app.codex_010_member_map m ON t.friends_uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_user_friends t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_staff_flowing_water t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_store_order t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_user_bill t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_store_coupon_issue_user t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_store_coupon_user t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_user_belong_store t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_user_money t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_capital_flow t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_store_order_writeoff t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_store_user t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_user_spread t JOIN mohe_010_app.codex_010_member_map m ON t.spread_uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_user_spread t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_store_order_cart_info t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_user_recharge t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_store_product_reply t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_store_visit t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_system_store_staff t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_user_card_holder t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_user_sign t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_store_cart t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_store_order_refund t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_store_service_feedback t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
    UNION ALL
    SELECT COUNT(*) c FROM mohe_010_app.eb_user_level t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid WHERE m.source_uid<>m.target_uid
     ) remaining)<>0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='010 member merge invariant failed';
  END IF;
  IF (SELECT SUM(now_money) FROM mohe_010_app.eb_user)<>6513985.45
     OR (SELECT SUM(ben_money) FROM mohe_010_app.eb_user)<>4034788.41
     OR (SELECT SUM(give_money) FROM mohe_010_app.eb_user)<>2479197.04
     OR (SELECT SUM(integral) FROM mohe_010_app.eb_user)<>25320
     OR (SELECT SUM(exp) FROM mohe_010_app.eb_user)<>9475719.57
     OR (SELECT SUM(pay_count) FROM mohe_010_app.eb_user)<>31063
     OR (SELECT SUM(spread_count) FROM mohe_010_app.eb_user)<>110 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='010 member asset mismatch';
  END IF;
  IF (SELECT COUNT(*) FROM mohe_010_app.eb_store_order_writeoff WHERE status=0)<>70477
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_store_order_writeoff WHERE status=1)<>2472
     OR (SELECT SUM(writeoff_price) FROM mohe_010_app.eb_store_order_writeoff)<>12452502
     OR (SELECT COUNT(*) FROM mohe_010_app.codex_010_legacy_writeoff_projection
         WHERE record_status='voided')<>2472
     OR (SELECT COUNT(*) FROM mohe_010_app.codex_010_legacy_writeoff_projection
         WHERE member_id=0)<>9473 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='010 writeoff invariant failed';
  END IF;
END//
DELIMITER ;


START TRANSACTION;

INSERT INTO mohe_010_app.codex_010_member_merge_group (source_uid,target_uid) VALUES
  (1292,1292),(10179,1292),
  (2395,2395),(5916,2395),
  (4990,4990),(11879,4990),
  (7154,7154),(10247,7154),
  (9665,9806),(9806,9806),
  (9717,9780),(9780,9780),
  (9847,9847),(11010,9847),(11011,9847),(11019,9847),(11045,9847),
  (9917,9917),(9920,9917),
  (9967,9967),(10082,9967),
  (10064,10064),(12194,10064),
  (10243,10243),(10246,10243),
  (10244,10244),(10245,10244),(11724,10244),
  (10634,10634),(10635,10634),
  (11489,11489),(11490,11489),
  (11748,11748),(11754,11748),
  (11817,11817),(11823,11817),
  (11833,11833),(11842,11833),(11844,11833),
  (11917,11918),(11918,11918),
  (12146,12146),(12160,12146);

INSERT INTO mohe_010_app.codex_010_member_map
  (source_uid,target_uid,is_merged)
SELECT u.uid,COALESCE(g.target_uid,u.uid),IF(g.source_uid IS NULL,0,1)
FROM mohe_010_refresh.eb_user u
LEFT JOIN mohe_010_app.codex_010_member_merge_group g ON g.source_uid=u.uid;

INSERT INTO mohe_010_app.codex_010_writeoff_order_target
  (order_id,writeoff_status,original_total,integer_total,floor_total,parent_pay_price,capped_by_parent)
SELECT w.oid,w.status,SUM(w.writeoff_price),
       CASE WHEN w.status=0 AND o.id IS NOT NULL AND SUM(w.writeoff_price)<=o.pay_price
            THEN LEAST(ROUND(SUM(w.writeoff_price),0),FLOOR(o.pay_price))
            ELSE ROUND(SUM(w.writeoff_price),0) END,
       SUM(FLOOR(w.writeoff_price)),o.pay_price,
       CASE WHEN w.status=0 AND o.id IS NOT NULL AND SUM(w.writeoff_price)<=o.pay_price
                     AND ROUND(SUM(w.writeoff_price),0)>FLOOR(o.pay_price)
            THEN 1 ELSE 0 END
FROM mohe_010_refresh.eb_store_order_writeoff w
LEFT JOIN mohe_010_refresh.eb_store_order o ON o.id=w.oid
GROUP BY w.oid,w.status,o.id,o.pay_price;

INSERT INTO mohe_010_app.codex_010_writeoff_allocation
  (writeoff_id,order_id,writeoff_status,integer_price)
SELECT w.id,w.oid,w.status,
       FLOOR(w.writeoff_price)+CASE WHEN
         (SELECT COUNT(*) FROM mohe_010_refresh.eb_store_order_writeoff w2
          WHERE w2.oid=w.oid AND w2.status=w.status AND
            (w2.writeoff_price-FLOOR(w2.writeoff_price)>w.writeoff_price-FLOOR(w.writeoff_price)
             OR (w2.writeoff_price-FLOOR(w2.writeoff_price)=w.writeoff_price-FLOOR(w.writeoff_price)
                 AND w2.id<=w.id))) <= t.integer_total-t.floor_total
       THEN 1 ELSE 0 END
FROM mohe_010_refresh.eb_store_order_writeoff w
JOIN mohe_010_app.codex_010_writeoff_order_target t
  ON t.order_id=w.oid AND t.writeoff_status=w.status;

INSERT INTO mohe_010_app.codex_010_writeoff_rounding_audit
  (writeoff_id,original_price,rounded_price,rounding_delta,source_snapshot,rule_name)
SELECT w.id,w.writeoff_price,a.integer_price,a.integer_price-w.writeoff_price,
       '007-linlin-2026-10-23-refresh-1','order-status-balanced-integer-yuan'
FROM mohe_010_refresh.eb_store_order_writeoff w
JOIN mohe_010_app.codex_010_writeoff_allocation a ON a.writeoff_id=w.id;

INSERT INTO `mohe_010_app`.`eb_system_store` (`id`,`type`,`region_id`,`manage_region_id`,`cate_id`,`cate_com`,`erp_shop_id`,`name`,`introduction`,`phone`,`address`,`province`,`city`,`area`,`street`,`detailed_address`,`image`,`background_image`,`oblong_image`,`latitude`,`longitude`,`bank_code`,`bank_address`,`alipay_account`,`alipay_qrcode_url`,`wechat`,`wechat_qrcode_url`,`store_rate_type`,`store_cashier_order_rate`,`store_self_order_rate`,`store_writeoff_order_rate`,`store_recharge_order_rate`,`store_svip_order_rate`,`valid_time`,`valid_range`,`day_time`,`day_start`,`day_end`,`add_time`,`is_show`,`is_del`,`delivery_type`,`is_store`,`default_delivery`,`customer_type`,`home_style`,`city_delivery_status`,`city_delivery_type`,`delivery_goods_type`,`business`,`city_shop_id`,`product_status`,`use_system_money`,`product_verify_status`,`product_category_status`,`product_change_price_status`,`is_alone`,`is_region_alone`,`coupon_verify_status`,`coupon_self_built_status`,`range_type`,`radius`,`region`,`fence`,`space_num`,`salary_status`) SELECT s.`id`,s.`type`,s.`region_id`,s.`manage_region_id`,s.`cate_id`,s.`cate_com`,s.`erp_shop_id`,s.`name`,s.`introduction`,s.`phone`,s.`address`,s.`province`,s.`city`,s.`area`,s.`street`,s.`detailed_address`,s.`image`,s.`background_image`,s.`oblong_image`,s.`latitude`,s.`longitude`,s.`bank_code`,s.`bank_address`,s.`alipay_account`,s.`alipay_qrcode_url`,s.`wechat`,s.`wechat_qrcode_url`,s.`store_rate_type`,s.`store_cashier_order_rate`,s.`store_self_order_rate`,s.`store_writeoff_order_rate`,s.`store_recharge_order_rate`,s.`store_svip_order_rate`,s.`valid_time`,s.`valid_range`,s.`day_time`,s.`day_start`,s.`day_end`,s.`add_time`,s.`is_show`,s.`is_del`,s.`delivery_type`,s.`is_store`,s.`default_delivery`,s.`customer_type`,s.`home_style`,s.`city_delivery_status`,s.`city_delivery_type`,s.`delivery_goods_type`,s.`business`,s.`city_shop_id`,s.`product_status`,s.`use_system_money`,s.`product_verify_status`,s.`product_category_status`,s.`product_change_price_status`,s.`is_alone`,s.`is_region_alone`,s.`coupon_verify_status`,s.`coupon_self_built_status`,s.`range_type`,s.`radius`,s.`region`,s.`fence`,s.`space_num`,s.`salary_status` FROM `mohe_010_refresh`.`eb_system_store` s;

INSERT INTO `mohe_010_app`.`eb_system_store_staff` (`id`,`store_id`,`uid`,`account`,`pwd`,`avatar`,`staff_name`,`phone`,`roles`,`last_ip`,`last_time`,`login_count`,`level`,`verify_status`,`order_status`,`is_admin`,`is_store`,`is_manager`,`is_cashier`,`is_customer`,`customer_phone`,`customer_url`,`work_member_id`,`work_member_code`,`status`,`notify`,`is_way`,`is_del`,`add_time`,`position`,`position_level`,`department`,`employee_number`,`join_date`,`id_card`,`birthday_date`,`birthday_type`,`age`,`join_area`,`birthday_area`,`now_area`,`contract_begin`,`contract_end`,`can_choose`,`is_reservable`,`is_butler`,`is_fencheng`,`salary_status`,`is_hezuofang`,`off_work_time`,`staff_intro`) SELECT s.`id`,s.`store_id`,s.`uid`,s.`account`,MD5(CONCAT('010-staff-disabled:',UUID(),s.id)),s.`avatar`,s.`staff_name`,s.`phone`,s.`roles`,s.`last_ip`,s.`last_time`,s.`login_count`,s.`level`,s.`verify_status`,s.`order_status`,s.`is_admin`,s.`is_store`,s.`is_manager`,s.`is_cashier`,s.`is_customer`,s.`customer_phone`,s.`customer_url`,s.`work_member_id`,s.`work_member_code`,s.`status`,s.`notify`,s.`is_way`,s.`is_del`,s.`add_time`,s.`position`,s.`position_level`,s.`department`,s.`employee_number`,s.`join_date`,s.`id_card`,s.`birthday_date`,s.`birthday_type`,s.`age`,s.`join_area`,s.`birthday_area`,s.`now_area`,s.`contract_begin`,s.`contract_end`,s.`can_choose`,s.`is_reservable`,s.`is_butler`,s.`is_fencheng`,s.`salary_status`,s.`is_hezuofang`,s.`off_work_time`,s.`staff_intro` FROM `mohe_010_refresh`.`eb_system_store_staff` s;

INSERT INTO `mohe_010_app`.`eb_user` (`uid`,`account`,`pwd`,`real_name`,`birthday`,`card_id`,`mark`,`partner_id`,`group_id`,`nickname`,`avatar`,`phone`,`add_time`,`add_ip`,`last_time`,`last_ip`,`now_money`,`brokerage_price`,`integral`,`exp`,`sign_num`,`sign_remind`,`status`,`level`,`agent_level`,`spread_open`,`spread_uid`,`spread_time`,`spread_lottery`,`work_uid`,`work_userid`,`user_type`,`is_promoter`,`pay_count`,`spread_count`,`clean_time`,`addres`,`adminid`,`login_type`,`login_city`,`record_phone`,`is_money_level`,`is_ever_level`,`overdue_time`,`uniqid`,`bar_code`,`rand_code`,`sex`,`provincials`,`province`,`city`,`area`,`street`,`is_del`,`delete_time`,`extend_info`,`level_status`,`level_extend_info`,`is_first_order`,`is_newcomer`,`salesman_id`,`salesman_time`,`belong_store_id`,`cardholder`,`bank_code`,`wechat_account`,`alipay_name`,`alipay_account`,`ben_money`,`give_money`) SELECT s.`uid`,s.`account`,MD5(CONCAT('010-member-disabled:',UUID(),s.uid)),s.`real_name`,s.`birthday`,s.`card_id`,s.`mark`,s.`partner_id`,s.`group_id`,s.`nickname`,s.`avatar`,s.`phone`,s.`add_time`,s.`add_ip`,s.`last_time`,s.`last_ip`,s.`now_money`,s.`brokerage_price`,s.`integral`,s.`exp`,s.`sign_num`,s.`sign_remind`,s.`status`,s.`level`,s.`agent_level`,s.`spread_open`,s.`spread_uid`,s.`spread_time`,s.`spread_lottery`,s.`work_uid`,s.`work_userid`,s.`user_type`,s.`is_promoter`,s.`pay_count`,s.`spread_count`,s.`clean_time`,s.`addres`,s.`adminid`,s.`login_type`,s.`login_city`,s.`record_phone`,s.`is_money_level`,s.`is_ever_level`,s.`overdue_time`,s.`uniqid`,s.`bar_code`,s.`rand_code`,s.`sex`,s.`provincials`,s.`province`,s.`city`,s.`area`,s.`street`,s.`is_del`,s.`delete_time`,s.`extend_info`,s.`level_status`,s.`level_extend_info`,s.`is_first_order`,s.`is_newcomer`,s.`salesman_id`,s.`salesman_time`,s.`belong_store_id`,s.`cardholder`,s.`bank_code`,s.`wechat_account`,s.`alipay_name`,s.`alipay_account`,s.`ben_money`,s.`give_money` FROM `mohe_010_refresh`.`eb_user` s;

INSERT INTO `mohe_010_app`.`eb_user_card_holder` (`id`,`uid`,`oid`,`card_name`,`store_id`,`product_id`,`product_type`,`verify_code`,`write_valid`,`write_days`,`write_start`,`write_end`,`write_times`,`write_surplus_times`,`is_del`,`add_time`) SELECT s.`id`,s.`uid`,s.`oid`,s.`card_name`,s.`store_id`,s.`product_id`,s.`product_type`,s.`verify_code`,s.`write_valid`,s.`write_days`,s.`write_start`,s.`write_end`,s.`write_times`,s.`write_surplus_times`,s.`is_del`,s.`add_time` FROM `mohe_010_refresh`.`eb_user_card_holder` s;

INSERT INTO `mohe_010_app`.`eb_store_product` (`id`,`pid`,`type`,`product_type`,`relation_id`,`image`,`recommend_image`,`slider_image`,`store_name`,`store_info`,`keyword`,`bar_code`,`cate_id`,`store_cate_id`,`price`,`settle_price`,`vip_price`,`ot_price`,`postage`,`delivery_type`,`store_delivery_type`,`freight`,`unit_name`,`sort`,`star`,`collect`,`sales`,`stock`,`defective_stock`,`show_type`,`is_show`,`is_hot`,`is_benefit`,`is_best`,`is_new`,`add_time`,`is_postage`,`is_verify`,`is_del`,`mer_use`,`give_integral`,`cost`,`is_seckill`,`is_bargain`,`is_good`,`is_sub`,`is_vip`,`ficti`,`browse`,`code_path`,`soure_link`,`video_open`,`video_link`,`temp_id`,`spec_type`,`activity`,`spu`,`label_id`,`command_word`,`recommend_list`,`brand_id`,`brand_com`,`code`,`is_vip_product`,`is_presale_product`,`presale_start_time`,`presale_end_time`,`presale_day`,`auto_on_time`,`auto_off_time`,`custom_form`,`system_form_type`,`system_form_id`,`applicable_type`,`applicable_store_id`,`is_support_refund`,`store_label_id`,`ensure_id`,`specs`,`specs_id`,`is_limit`,`limit_type`,`limit_num`,`refusal`,`is_police`,`is_sold`,`is_brokerage`,`brokerage_type`,`level_type`,`share_content`,`default_sku`,`presale_status`,`reservation_type`,`reservation_timing_type`,`sale_time_type`,`sale_time_week`,`sale_time_start`,`sale_time_end`,`show_reservation_days_type`,`show_reservation_days`,`is_advance`,`advance_time`,`is_cancel_reservation`,`cancel_reservation_time`,`reservation_time_type`,`customize_time_period`,`reservation_time_start`,`reservation_time_end`,`reservation_time_interval`,`project_service_duration`,`addon_service_duration`,`is_show_stock`,`card_cover`,`card_cover_image`,`card_cover_color`,`card_num`,`card_num_type`) SELECT s.`id`,s.`pid`,s.`type`,s.`product_type`,s.`relation_id`,s.`image`,s.`recommend_image`,s.`slider_image`,s.`store_name`,s.`store_info`,s.`keyword`,s.`bar_code`,s.`cate_id`,s.`store_cate_id`,s.`price`,s.`settle_price`,s.`vip_price`,s.`ot_price`,s.`postage`,s.`delivery_type`,s.`store_delivery_type`,s.`freight`,s.`unit_name`,s.`sort`,s.`star`,s.`collect`,s.`sales`,s.`stock`,s.`defective_stock`,s.`show_type`,s.`is_show`,s.`is_hot`,s.`is_benefit`,s.`is_best`,s.`is_new`,s.`add_time`,s.`is_postage`,s.`is_verify`,s.`is_del`,s.`mer_use`,s.`give_integral`,s.`cost`,s.`is_seckill`,s.`is_bargain`,s.`is_good`,s.`is_sub`,s.`is_vip`,s.`ficti`,s.`browse`,s.`code_path`,s.`soure_link`,s.`video_open`,s.`video_link`,s.`temp_id`,s.`spec_type`,s.`activity`,s.`spu`,s.`label_id`,s.`command_word`,s.`recommend_list`,s.`brand_id`,s.`brand_com`,s.`code`,s.`is_vip_product`,s.`is_presale_product`,s.`presale_start_time`,s.`presale_end_time`,s.`presale_day`,s.`auto_on_time`,s.`auto_off_time`,s.`custom_form`,s.`system_form_type`,s.`system_form_id`,s.`applicable_type`,s.`applicable_store_id`,s.`is_support_refund`,s.`store_label_id`,s.`ensure_id`,s.`specs`,s.`specs_id`,s.`is_limit`,s.`limit_type`,s.`limit_num`,s.`refusal`,s.`is_police`,s.`is_sold`,s.`is_brokerage`,s.`brokerage_type`,s.`level_type`,s.`share_content`,s.`default_sku`,s.`presale_status`,s.`reservation_type`,s.`reservation_timing_type`,s.`sale_time_type`,s.`sale_time_week`,s.`sale_time_start`,s.`sale_time_end`,s.`show_reservation_days_type`,s.`show_reservation_days`,s.`is_advance`,s.`advance_time`,s.`is_cancel_reservation`,s.`cancel_reservation_time`,s.`reservation_time_type`,s.`customize_time_period`,s.`reservation_time_start`,s.`reservation_time_end`,s.`reservation_time_interval`,s.`project_service_duration`,s.`addon_service_duration`,s.`is_show_stock`,s.`card_cover`,s.`card_cover_image`,s.`card_cover_color`,s.`card_num`,s.`card_num_type` FROM `mohe_010_refresh`.`eb_store_product` s;

INSERT INTO `mohe_010_app`.`eb_store_order` (`id`,`type`,`pid`,`order_id`,`supplier_id`,`store_id`,`trade_no`,`uid`,`real_name`,`user_phone`,`user_address`,`user_location`,`cart_id`,`activity_id`,`activity_append`,`freight_price`,`total_num`,`total_price`,`settle_price`,`total_postage`,`pay_price`,`cash_pay_price`,`debt_amount`,`repaid_debt_amount`,`is_debt_repay`,`debt_repay_origin_order_id`,`debt_repay_item_id`,`yue_pay_price`,`pay_postage`,`pay_integral`,`deduction_price`,`coupon_id`,`coupon_price`,`promotions_price`,`first_order_price`,`change_price`,`service_price`,`paid`,`pay_type`,`status`,`refund_status`,`card_upgrade_use_oid`,`service_object`,`refund_type`,`refund_express`,`refund_reason_wap_img`,`refund_reason_wap_explain`,`refund_reason_time`,`refund_reason_wap`,`refund_reason`,`refund_price`,`delivery_name`,`delivery_code`,`delivery_type`,`delivery_id`,`fictitious_content`,`delivery_uid`,`delivery_voucher`,`delivery_voucher_img`,`gain_integral`,`use_integral`,`back_integral`,`spread_uid`,`spread_two_uid`,`one_brokerage`,`two_brokerage`,`mark`,`is_del`,`is_user_del`,`unique`,`remark`,`mer_id`,`is_mer_check`,`pink_id`,`cost`,`verify_code`,`staff_id`,`shipping_type`,`store_delivery_type`,`clerk_id`,`is_channel`,`is_remind`,`is_system_del`,`channel_type`,`province`,`express_dump`,`kuaidi_label`,`product_type`,`virtual_info`,`custom_form_title`,`custom_form`,`system_form_type`,`promotions_give`,`give_integral`,`give_coupon`,`erp_id`,`erp_order_id`,`kuaidi_task_id`,`kuaidi_order_id`,`is_stock_up`,`reservation_type`,`reservation_time`,`reservation_time_id`,`reservation_show_time`,`service_staff_id`,`reservation_status`,`shipping_time`,`pay_time`,`delivery_time`,`estimate_time`,`add_time`,`create_data`,`notify_data`,`selected_product`,`cash_choose`,`remark_info`,`source`,`yeji`,`service_yeji`,`order_type`,`link_id`,`link_order`,`back_reason`,`is_budan`,`is_gendan`,`gendan_staff_id`,`send_all`,`yue_money`,`is_auto`,`changeRemark`,`kua_store`) SELECT s.`id`,s.`type`,s.`pid`,s.`order_id`,s.`supplier_id`,s.`store_id`,s.`trade_no`,s.`uid`,s.`real_name`,s.`user_phone`,s.`user_address`,s.`user_location`,s.`cart_id`,s.`activity_id`,s.`activity_append`,s.`freight_price`,s.`total_num`,s.`total_price`,s.`settle_price`,s.`total_postage`,s.`pay_price`,s.`cash_pay_price`,s.`debt_amount`,s.`repaid_debt_amount`,s.`is_debt_repay`,s.`debt_repay_origin_order_id`,s.`debt_repay_item_id`,s.`yue_pay_price`,s.`pay_postage`,s.`pay_integral`,s.`deduction_price`,s.`coupon_id`,s.`coupon_price`,s.`promotions_price`,s.`first_order_price`,s.`change_price`,s.`service_price`,s.`paid`,s.`pay_type`,s.`status`,s.`refund_status`,s.`card_upgrade_use_oid`,s.`service_object`,s.`refund_type`,s.`refund_express`,s.`refund_reason_wap_img`,s.`refund_reason_wap_explain`,s.`refund_reason_time`,s.`refund_reason_wap`,s.`refund_reason`,s.`refund_price`,s.`delivery_name`,s.`delivery_code`,s.`delivery_type`,s.`delivery_id`,s.`fictitious_content`,s.`delivery_uid`,s.`delivery_voucher`,s.`delivery_voucher_img`,s.`gain_integral`,s.`use_integral`,s.`back_integral`,s.`spread_uid`,s.`spread_two_uid`,s.`one_brokerage`,s.`two_brokerage`,s.`mark`,s.`is_del`,s.`is_user_del`,s.`unique`,s.`remark`,s.`mer_id`,s.`is_mer_check`,s.`pink_id`,s.`cost`,s.`verify_code`,s.`staff_id`,s.`shipping_type`,s.`store_delivery_type`,s.`clerk_id`,s.`is_channel`,s.`is_remind`,s.`is_system_del`,s.`channel_type`,s.`province`,s.`express_dump`,s.`kuaidi_label`,s.`product_type`,s.`virtual_info`,s.`custom_form_title`,s.`custom_form`,s.`system_form_type`,s.`promotions_give`,s.`give_integral`,s.`give_coupon`,s.`erp_id`,s.`erp_order_id`,s.`kuaidi_task_id`,s.`kuaidi_order_id`,s.`is_stock_up`,s.`reservation_type`,s.`reservation_time`,s.`reservation_time_id`,s.`reservation_show_time`,s.`service_staff_id`,s.`reservation_status`,s.`shipping_time`,s.`pay_time`,s.`delivery_time`,s.`estimate_time`,s.`add_time`,s.`create_data`,s.`notify_data`,s.`selected_product`,s.`cash_choose`,s.`remark_info`,s.`source`,s.`yeji`,s.`service_yeji`,s.`order_type`,s.`link_id`,s.`link_order`,s.`back_reason`,s.`is_budan`,s.`is_gendan`,s.`gendan_staff_id`,s.`send_all`,s.`yue_money`,s.`is_auto`,s.`changeRemark`,s.`kua_store` FROM `mohe_010_refresh`.`eb_store_order` s;

INSERT INTO `mohe_010_app`.`eb_store_order_cart_info` (`id`,`uid`,`oid`,`cart_id`,`cart_type`,`type`,`relation_id`,`staff_id`,`delivery_id`,`product_id`,`product_type`,`sku_unique`,`promotions_id`,`is_gift`,`is_card`,`is_support_refund`,`old_cart_id`,`cart_num`,`total_price`,`settle_price`,`pay_price`,`yue_pay_amount`,`card_upgrade_amount`,`cash_pay_amount`,`debt_amount`,`repaid_debt_amount`,`pay_postage`,`member_price`,`deduction_price`,`coupon_price`,`promotions_price`,`first_order_price`,`change_price`,`service_price`,`refund_num`,`surplus_num`,`split_surplus_num`,`split_status`,`write_times`,`write_surplus_times`,`write_start`,`write_end`,`is_advent_sms`,`is_expire_sms`,`is_writeoff`,`writeoff_time`,`reservation_type`,`reservation_time`,`reservation_time_id`,`cart_info`,`unique`,`add_time`) SELECT s.`id`,s.`uid`,s.`oid`,s.`cart_id`,s.`cart_type`,s.`type`,s.`relation_id`,s.`staff_id`,s.`delivery_id`,s.`product_id`,s.`product_type`,s.`sku_unique`,s.`promotions_id`,s.`is_gift`,s.`is_card`,s.`is_support_refund`,s.`old_cart_id`,s.`cart_num`,s.`total_price`,s.`settle_price`,s.`pay_price`,s.`yue_pay_amount`,s.`card_upgrade_amount`,s.`cash_pay_amount`,s.`debt_amount`,s.`repaid_debt_amount`,s.`pay_postage`,s.`member_price`,s.`deduction_price`,s.`coupon_price`,s.`promotions_price`,s.`first_order_price`,s.`change_price`,s.`service_price`,s.`refund_num`,s.`surplus_num`,s.`split_surplus_num`,s.`split_status`,s.`write_times`,s.`write_surplus_times`,s.`write_start`,s.`write_end`,s.`is_advent_sms`,s.`is_expire_sms`,s.`is_writeoff`,s.`writeoff_time`,s.`reservation_type`,s.`reservation_time`,s.`reservation_time_id`,s.`cart_info`,s.`unique`,s.`add_time` FROM `mohe_010_refresh`.`eb_store_order_cart_info` s;

INSERT INTO `mohe_010_app`.`eb_store_order_writeoff` (`id`,`uid`,`oid`,`reservation_oid`,`order_cart_id`,`type`,`relation_id`,`staff_id`,`service_type`,`service_object`,`product_id`,`product_type`,`writeoff_num`,`writeoff_price`,`writeoff_code`,`add_time`,`status`) SELECT s.`id`,s.`uid`,s.`oid`,s.`reservation_oid`,s.`order_cart_id`,s.`type`,s.`relation_id`,s.`staff_id`,s.`service_type`,s.`service_object`,s.`product_id`,s.`product_type`,s.`writeoff_num`,a.rounded_price,s.`writeoff_code`,s.`add_time`,s.`status` FROM `mohe_010_refresh`.`eb_store_order_writeoff` s JOIN `mohe_010_app`.`codex_010_writeoff_rounding_audit` a ON a.writeoff_id=s.id;

INSERT INTO `mohe_010_app`.`eb_user_money` (`id`,`uid`,`link_id`,`type`,`title`,`number`,`balance`,`pm`,`mark`,`status`,`add_time`,`ben_money`,`give_money`) SELECT s.`id`,s.`uid`,s.`link_id`,s.`type`,s.`title`,s.`number`,s.`balance`,s.`pm`,s.`mark`,s.`status`,s.`add_time`,s.`ben_money`,s.`give_money` FROM `mohe_010_refresh`.`eb_user_money` s;

INSERT INTO `mohe_010_app`.`eb_store_product_stock_detail` (`id`,`type`,`relation_id`,`stock_type`,`order_type`,`order_id`,`product_id`,`product_name`,`image`,`sku`,`unique`,`code`,`bar_code`,`cost_price`,`pm`,`stock`,`count_stock`,`defective_stock`,`count_defective_stock`,`balance_stock`,`admin_id`,`add_time`) SELECT s.`id`,s.`type`,s.`relation_id`,s.`stock_type`,s.`order_type`,s.`order_id`,s.`product_id`,s.`product_name`,s.`image`,s.`sku`,s.`unique`,s.`code`,s.`bar_code`,s.`cost_price`,s.`pm`,s.`stock`,s.`count_stock`,s.`defective_stock`,s.`count_defective_stock`,s.`balance_stock`,s.`admin_id`,s.`add_time` FROM `mohe_010_refresh`.`eb_store_product_stock_detail` s;

INSERT INTO `mohe_010_app`.`eb_staff_yeji` (`id`,`staff_id`,`staff_name`,`position_label`,`position`,`position_level`,`position_level_label`,`yeji`,`deduct_card_yeji`,`type`,`link_id`,`goods_id`,`is_dian`,`price`,`order_id`,`true_price`,`once_price`,`value`,`write_times`,`created_time`,`status`,`cart_id`,`store_id`,`changeremark`) SELECT s.`id`,s.`staff_id`,s.`staff_name`,s.`position_label`,s.`position`,s.`position_level`,s.`position_level_label`,s.`yeji`,s.`deduct_card_yeji`,s.`type`,s.`link_id`,s.`goods_id`,s.`is_dian`,s.`price`,s.`order_id`,s.`true_price`,s.`once_price`,s.`value`,s.`write_times`,s.`created_time`,s.`status`,s.`cart_id`,s.`store_id`,s.`changeremark` FROM `mohe_010_refresh`.`eb_staff_yeji` s;

INSERT INTO `mohe_010_app`.`eb_agent_level` (`id`,`name`,`image`,`color`,`one_brokerage`,`two_brokerage`,`grade`,`status`,`is_del`,`add_time`) SELECT s.`id`,s.`name`,s.`image`,s.`color`,s.`one_brokerage`,s.`two_brokerage`,s.`grade`,s.`status`,s.`is_del`,s.`add_time` FROM `mohe_010_refresh`.`eb_agent_level` s;

INSERT INTO `mohe_010_app`.`eb_capital_flow` (`id`,`flow_id`,`order_id`,`store_id`,`uid`,`nickname`,`phone`,`price`,`trading_type`,`pay_type`,`mark`,`add_time`) SELECT s.`id`,s.`flow_id`,s.`order_id`,s.`store_id`,s.`uid`,s.`nickname`,s.`phone`,s.`price`,s.`trading_type`,s.`pay_type`,s.`mark`,s.`add_time` FROM `mohe_010_refresh`.`eb_capital_flow` s;

INSERT INTO `mohe_010_app`.`eb_cash_source` (`id`,`name`,`status`) SELECT s.`id`,s.`name`,s.`status` FROM `mohe_010_refresh`.`eb_cash_source` s;

INSERT INTO `mohe_010_app`.`eb_cash_type` (`id`,`name`,`status`) SELECT s.`id`,s.`name`,s.`status` FROM `mohe_010_refresh`.`eb_cash_type` s;

INSERT INTO `mohe_010_app`.`eb_category` (`id`,`pid`,`type`,`relation_id`,`owner_id`,`name`,`sort`,`group`,`other`,`is_show`,`add_time`,`integral_min`,`integral_max`) SELECT s.`id`,s.`pid`,s.`type`,s.`relation_id`,s.`owner_id`,s.`name`,s.`sort`,s.`group`,s.`other`,s.`is_show`,s.`add_time`,s.`integral_min`,s.`integral_max` FROM `mohe_010_refresh`.`eb_category` s;

INSERT INTO `mohe_010_app`.`eb_city_area` (`id`,`path`,`parent_id`,`type`,`name`,`level`,`code`,`snum`,`create_time`) SELECT s.`id`,s.`path`,s.`parent_id`,s.`type`,s.`name`,s.`level`,s.`code`,s.`snum`,s.`create_time` FROM `mohe_010_refresh`.`eb_city_area` s;

INSERT INTO `mohe_010_app`.`eb_combination_order` (`id`,`active_pay`,`name`,`price`,`remarkInfo`,`cash_choose`,`pay_sub_type`,`upgrade_old_oid`,`upgrade_old_cart_info_id`,`order_id`,`type`,`add_time`) SELECT s.`id`,s.`active_pay`,s.`name`,s.`price`,s.`remarkInfo`,s.`cash_choose`,s.`pay_sub_type`,s.`upgrade_old_oid`,s.`upgrade_old_cart_info_id`,s.`order_id`,s.`type`,s.`add_time` FROM `mohe_010_refresh`.`eb_combination_order` s;

INSERT INTO `mohe_010_app`.`eb_community` (`id`,`type`,`relation_id`,`community_user_id`,`content_type`,`title`,`image`,`video_url`,`slider_image`,`content`,`topic_id`,`product_id`,`like_num`,`collect_num`,`play_num`,`comment_num`,`share_num`,`star`,`status`,`is_recommend`,`is_verify`,`refusal`,`sort`,`is_del`,`verify_time`,`add_time`) SELECT s.`id`,s.`type`,s.`relation_id`,s.`community_user_id`,s.`content_type`,s.`title`,s.`image`,s.`video_url`,s.`slider_image`,s.`content`,s.`topic_id`,s.`product_id`,s.`like_num`,s.`collect_num`,s.`play_num`,s.`comment_num`,s.`share_num`,s.`star`,s.`status`,s.`is_recommend`,s.`is_verify`,s.`refusal`,s.`sort`,s.`is_del`,s.`verify_time`,s.`add_time` FROM `mohe_010_refresh`.`eb_community` s;

INSERT INTO `mohe_010_app`.`eb_community_comment` (`id`,`type`,`relation_id`,`uid`,`reply_id`,`reply_uid`,`comment_reply_id`,`comment_reply_uid`,`community_id`,`nickname`,`avatar`,`comment_num`,`like_num`,`content`,`ip`,`city`,`is_verify`,`is_show`,`is_reply`,`is_del`,`update_time`,`add_time`) SELECT s.`id`,s.`type`,s.`relation_id`,s.`uid`,s.`reply_id`,s.`reply_uid`,s.`comment_reply_id`,s.`comment_reply_uid`,s.`community_id`,s.`nickname`,s.`avatar`,s.`comment_num`,s.`like_num`,s.`content`,s.`ip`,s.`city`,s.`is_verify`,s.`is_show`,s.`is_reply`,s.`is_del`,s.`update_time`,s.`add_time` FROM `mohe_010_refresh`.`eb_community_comment` s;

INSERT INTO `mohe_010_app`.`eb_community_record` (`id`,`uid`,`type`,`relation_id`,`record`,`link_id`,`comment_id`,`comment_type`,`content`,`is_viewed`,`add_time`,`is_del`) SELECT s.`id`,s.`uid`,s.`type`,s.`relation_id`,s.`record`,s.`link_id`,s.`comment_id`,s.`comment_type`,s.`content`,s.`is_viewed`,s.`add_time`,s.`is_del` FROM `mohe_010_refresh`.`eb_community_record` s;

INSERT INTO `mohe_010_app`.`eb_community_relevance` (`id`,`left_id`,`right_id`,`type`) SELECT s.`id`,s.`left_id`,s.`right_id`,s.`type` FROM `mohe_010_refresh`.`eb_community_relevance` s;

INSERT INTO `mohe_010_app`.`eb_community_topic` (`id`,`name`,`icon`,`sort`,`is_recommend`,`type`,`use_num`,`view_num`,`status`,`is_del`,`add_time`) SELECT s.`id`,s.`name`,s.`icon`,s.`sort`,s.`is_recommend`,s.`type`,s.`use_num`,s.`view_num`,s.`status`,s.`is_del`,s.`add_time` FROM `mohe_010_refresh`.`eb_community_topic` s;

INSERT INTO `mohe_010_app`.`eb_community_user` (`id`,`type`,`relation_id`,`nickname`,`avatar`,`desc`,`community_num`,`follow_num`,`fans_num`,`friend_num`,`like_num`,`status`,`is_del`,`add_time`) SELECT s.`id`,s.`type`,s.`relation_id`,s.`nickname`,s.`avatar`,s.`desc`,s.`community_num`,s.`follow_num`,s.`fans_num`,s.`friend_num`,s.`like_num`,s.`status`,s.`is_del`,s.`add_time` FROM `mohe_010_refresh`.`eb_community_user` s;

INSERT INTO `mohe_010_app`.`eb_diy` (`id`,`version`,`name`,`title`,`cover_image`,`template_name`,`value`,`default_value`,`add_time`,`update_time`,`status`,`type`,`is_show`,`is_bg_color`,`is_bg_pic`,`is_diy`,`color_picker`,`bg_pic`,`bg_tab_val`,`is_del`,`order_status`,`my_banner_status`,`menu_status`,`service_status`) SELECT s.`id`,s.`version`,s.`name`,s.`title`,s.`cover_image`,s.`template_name`,s.`value`,s.`default_value`,s.`add_time`,s.`update_time`,s.`status`,s.`type`,s.`is_show`,s.`is_bg_color`,s.`is_bg_pic`,s.`is_diy`,s.`color_picker`,s.`bg_pic`,s.`bg_tab_val`,s.`is_del`,s.`order_status`,s.`my_banner_status`,s.`menu_status`,s.`service_status` FROM `mohe_010_refresh`.`eb_diy` s;

INSERT INTO `mohe_010_app`.`eb_express` (`id`,`code`,`name`,`partner_id`,`partner_key`,`net`,`check_man`,`partner_name`,`is_code`,`courier_name`,`customer_name`,`code_name`,`account`,`key`,`net_name`,`sort`,`is_show`,`status`) SELECT s.`id`,s.`code`,s.`name`,s.`partner_id`,0,s.`net`,s.`check_man`,s.`partner_name`,s.`is_code`,s.`courier_name`,s.`customer_name`,s.`code_name`,s.`account`,'',s.`net_name`,s.`sort`,s.`is_show`,s.`status` FROM `mohe_010_refresh`.`eb_express` s;

INSERT INTO `mohe_010_app`.`eb_member_right` (`id`,`right_type`,`title`,`show_title`,`image`,`explain`,`content`,`number`,`sort`,`status`,`add_time`) SELECT s.`id`,s.`right_type`,s.`title`,s.`show_title`,s.`image`,s.`explain`,s.`content`,s.`number`,s.`sort`,s.`status`,s.`add_time` FROM `mohe_010_refresh`.`eb_member_right` s;

INSERT INTO `mohe_010_app`.`eb_member_ship` (`id`,`type`,`title`,`vip_day`,`price`,`pre_price`,`is_label`,`sort`,`is_del`,`add_time`) SELECT s.`id`,s.`type`,s.`title`,s.`vip_day`,s.`price`,s.`pre_price`,s.`is_label`,s.`sort`,s.`is_del`,s.`add_time` FROM `mohe_010_refresh`.`eb_member_ship` s;

INSERT INTO `mohe_010_app`.`eb_oid_pid` (`ooid`) SELECT s.`ooid` FROM `mohe_010_refresh`.`eb_oid_pid` s;

INSERT INTO `mohe_010_app`.`eb_old_shop_money` (`store_id`,`cash_money`,`use_money`,`add_time`) SELECT s.`store_id`,s.`cash_money`,s.`use_money`,s.`add_time` FROM `mohe_010_refresh`.`eb_old_shop_money` s;

INSERT INTO `mohe_010_app`.`eb_other_order` (`id`,`store_id`,`staff_id`,`uid`,`type`,`order_id`,`member_type`,`code`,`pay_type`,`paid`,`pay_price`,`member_price`,`pay_time`,`trade_no`,`channel_type`,`is_free`,`is_permanent`,`overdue_time`,`is_del`,`vip_day`,`add_time`,`money`,`remarks`,`changeRemark`) SELECT s.`id`,s.`store_id`,s.`staff_id`,s.`uid`,s.`type`,s.`order_id`,s.`member_type`,s.`code`,s.`pay_type`,s.`paid`,s.`pay_price`,s.`member_price`,s.`pay_time`,s.`trade_no`,s.`channel_type`,s.`is_free`,s.`is_permanent`,s.`overdue_time`,s.`is_del`,s.`vip_day`,s.`add_time`,s.`money`,s.`remarks`,s.`changeRemark` FROM `mohe_010_refresh`.`eb_other_order` s;

INSERT INTO `mohe_010_app`.`eb_other_order_status` (`oid`,`change_type`,`change_message`,`shop_type`,`change_time`) SELECT s.`oid`,s.`change_type`,s.`change_message`,s.`shop_type`,s.`change_time` FROM `mohe_010_refresh`.`eb_other_order_status` s;

INSERT INTO `mohe_010_app`.`eb_page_category` (`id`,`pid`,`type`,`name`,`sort`,`status`,`add_time`) SELECT s.`id`,s.`pid`,s.`type`,s.`name`,s.`sort`,s.`status`,s.`add_time` FROM `mohe_010_refresh`.`eb_page_category` s;

INSERT INTO `mohe_010_app`.`eb_page_link` (`id`,`cate_id`,`type`,`name`,`url`,`param`,`example`,`status`,`sort`,`add_time`) SELECT s.`id`,s.`cate_id`,s.`type`,s.`name`,s.`url`,s.`param`,s.`example`,s.`status`,s.`sort`,s.`add_time` FROM `mohe_010_refresh`.`eb_page_link` s;

INSERT INTO `mohe_010_app`.`eb_position` (`id`,`name`,`status`) SELECT s.`id`,s.`name`,s.`status` FROM `mohe_010_refresh`.`eb_position` s;

INSERT INTO `mohe_010_app`.`eb_position_level` (`id`,`name`,`status`) SELECT s.`id`,s.`name`,s.`status` FROM `mohe_010_refresh`.`eb_position_level` s;

INSERT INTO `mohe_010_app`.`eb_position_yeji` (`id`,`position_level_id`,`status`,`position_id`,`commission`,`cate_ids`,`range`,`type`,`range_type`,`time_type`,`has_recharge`) SELECT s.`id`,s.`position_level_id`,s.`status`,s.`position_id`,s.`commission`,s.`cate_ids`,s.`range`,s.`type`,s.`range_type`,s.`time_type`,s.`has_recharge` FROM `mohe_010_refresh`.`eb_position_yeji` s;

INSERT INTO `mohe_010_app`.`eb_salary_agent` (`id`,`staff_id`,`date`,`add_time`,`store_id`) SELECT s.`id`,s.`staff_id`,s.`date`,s.`add_time`,s.`store_id` FROM `mohe_010_refresh`.`eb_salary_agent` s;

INSERT INTO `mohe_010_app`.`eb_salary_field` (`id`,`key`,`name`,`type`,`info`,`sort`,`is_show`,`is_count`,`table_ids`) SELECT s.`id`,s.`key`,s.`name`,s.`type`,s.`info`,s.`sort`,s.`is_show`,s.`is_count`,s.`table_ids` FROM `mohe_010_refresh`.`eb_salary_field` s;

INSERT INTO `mohe_010_app`.`eb_salary_info` (`id`,`date`,`staff_id`,`salary_info`,`store_id`,`cmd_time`,`position_id`) SELECT s.`id`,s.`date`,s.`staff_id`,s.`salary_info`,s.`store_id`,s.`cmd_time`,s.`position_id` FROM `mohe_010_refresh`.`eb_salary_info` s;

INSERT INTO `mohe_010_app`.`eb_salary_search_field` (`id`,`key`,`name`,`input_type`,`info`,`sort`,`is_show`,`table_ids`) SELECT s.`id`,s.`key`,s.`name`,s.`input_type`,s.`info`,s.`sort`,s.`is_show`,s.`table_ids` FROM `mohe_010_refresh`.`eb_salary_search_field` s;

INSERT INTO `mohe_010_app`.`eb_salary_store` (`id`,`date`,`salary_info`,`store_id`,`table_ids`,`staff_id`) SELECT s.`id`,s.`date`,s.`salary_info`,s.`store_id`,s.`table_ids`,s.`staff_id` FROM `mohe_010_refresh`.`eb_salary_store` s;

INSERT INTO `mohe_010_app`.`eb_salary_table` (`id`,`name`,`status`,`type`,`product_type`,`show_type`) SELECT s.`id`,s.`name`,s.`status`,s.`type`,s.`product_type`,s.`show_type` FROM `mohe_010_refresh`.`eb_salary_table` s;

INSERT INTO `mohe_010_app`.`eb_staff_flowing_water` (`id`,`store_id`,`staff_id`,`uid`,`order_id`,`link_id`,`pm`,`number`,`type`,`pay_type`,`pay_price`,`total_price`,`remark`,`is_del`,`trade_time`,`add_time`) SELECT s.`id`,s.`store_id`,s.`staff_id`,s.`uid`,s.`order_id`,s.`link_id`,s.`pm`,s.`number`,s.`type`,s.`pay_type`,s.`pay_price`,s.`total_price`,s.`remark`,s.`is_del`,s.`trade_time`,s.`add_time` FROM `mohe_010_refresh`.`eb_staff_flowing_water` s;

INSERT INTO `mohe_010_app`.`eb_store_card_related` (`id`,`card_product_id`,`product_id`,`product_type`,`product_attr_unique`,`cost`,`price`,`write_times`,`status`,`add_time`) SELECT s.`id`,s.`card_product_id`,s.`product_id`,s.`product_type`,s.`product_attr_unique`,s.`cost`,s.`price`,s.`write_times`,s.`status`,s.`add_time` FROM `mohe_010_refresh`.`eb_store_card_related` s;

INSERT INTO `mohe_010_app`.`eb_store_cart` (`id`,`uid`,`tourist_uid`,`cart_type`,`type`,`product_id`,`product_type`,`activity_id`,`collate_code_id`,`store_id`,`staff_id`,`product_attr_unique`,`cart_num`,`price`,`is_pay`,`is_new`,`status`,`is_del`,`add_time`) SELECT s.`id`,s.`uid`,s.`tourist_uid`,s.`cart_type`,s.`type`,s.`product_id`,s.`product_type`,s.`activity_id`,s.`collate_code_id`,s.`store_id`,s.`staff_id`,s.`product_attr_unique`,s.`cart_num`,s.`price`,s.`is_pay`,s.`is_new`,s.`status`,s.`is_del`,s.`add_time` FROM `mohe_010_refresh`.`eb_store_cart` s;

INSERT INTO `mohe_010_app`.`eb_store_combination` (`id`,`type`,`product_id`,`product_type`,`relation_id`,`mer_id`,`image`,`images`,`title`,`attr`,`people`,`info`,`price`,`sort`,`sales`,`stock`,`add_time`,`is_host`,`is_show`,`is_del`,`combination`,`mer_use`,`is_postage`,`postage`,`start_time`,`stop_time`,`effective_time`,`cost`,`browse`,`unit_name`,`temp_id`,`weight`,`volume`,`num`,`once_num`,`quota`,`quota_show`,`virtual`,`is_support_refund`,`delivery_type`,`store_delivery_type`,`freight`,`custom_form`,`system_form_type`,`system_form_id`,`applicable_type`,`applicable_store_id`,`store_label_id`,`ensure_id`,`specs`,`only_new`) SELECT s.`id`,s.`type`,s.`product_id`,s.`product_type`,s.`relation_id`,s.`mer_id`,s.`image`,s.`images`,s.`title`,s.`attr`,s.`people`,s.`info`,s.`price`,s.`sort`,s.`sales`,s.`stock`,s.`add_time`,s.`is_host`,s.`is_show`,s.`is_del`,s.`combination`,s.`mer_use`,s.`is_postage`,s.`postage`,s.`start_time`,s.`stop_time`,s.`effective_time`,s.`cost`,s.`browse`,s.`unit_name`,s.`temp_id`,s.`weight`,s.`volume`,s.`num`,s.`once_num`,s.`quota`,s.`quota_show`,s.`virtual`,s.`is_support_refund`,s.`delivery_type`,s.`store_delivery_type`,s.`freight`,s.`custom_form`,s.`system_form_type`,s.`system_form_id`,s.`applicable_type`,s.`applicable_store_id`,s.`store_label_id`,s.`ensure_id`,s.`specs`,s.`only_new` FROM `mohe_010_refresh`.`eb_store_combination` s;

INSERT INTO `mohe_010_app`.`eb_store_coupon_issue` (`id`,`cid`,`coupon_issue_type`,`relation_id`,`category`,`type`,`receive_type`,`coupon_type`,`coupon_title`,`start_time`,`end_time`,`total_count`,`remain_count`,`is_permanent`,`is_claimed`,`quantity_count`,`status`,`is_give_subscribe`,`is_full_give`,`full_reduction`,`is_del`,`add_time`,`title`,`integral`,`coupon_price`,`top_discount_price`,`is_verify`,`verify_time`,`refusal`,`use_min_price`,`coupon_time`,`product_id`,`category_id`,`brand_id`,`brand_com`,`start_use_time`,`end_use_time`,`sort`,`applicable_type`,`applicable_store_id`,`rule`) SELECT s.`id`,s.`cid`,s.`coupon_issue_type`,s.`relation_id`,s.`category`,s.`type`,s.`receive_type`,s.`coupon_type`,s.`coupon_title`,s.`start_time`,s.`end_time`,s.`total_count`,s.`remain_count`,s.`is_permanent`,s.`is_claimed`,s.`quantity_count`,s.`status`,s.`is_give_subscribe`,s.`is_full_give`,s.`full_reduction`,s.`is_del`,s.`add_time`,s.`title`,s.`integral`,s.`coupon_price`,s.`top_discount_price`,s.`is_verify`,s.`verify_time`,s.`refusal`,s.`use_min_price`,s.`coupon_time`,s.`product_id`,s.`category_id`,s.`brand_id`,s.`brand_com`,s.`start_use_time`,s.`end_use_time`,s.`sort`,s.`applicable_type`,s.`applicable_store_id`,s.`rule` FROM `mohe_010_refresh`.`eb_store_coupon_issue` s;

INSERT INTO `mohe_010_app`.`eb_store_coupon_issue_user` (`uid`,`issue_coupon_id`,`add_time`) SELECT s.`uid`,s.`issue_coupon_id`,s.`add_time` FROM `mohe_010_refresh`.`eb_store_coupon_issue_user` s;

INSERT INTO `mohe_010_app`.`eb_store_coupon_user` (`id`,`cid`,`uid`,`coupon_title`,`coupon_price`,`use_min_price`,`add_time`,`start_time`,`end_time`,`use_time`,`type`,`status`,`is_fail`,`oid`) SELECT s.`id`,s.`cid`,s.`uid`,s.`coupon_title`,s.`coupon_price`,s.`use_min_price`,s.`add_time`,s.`start_time`,s.`end_time`,s.`use_time`,s.`type`,s.`status`,s.`is_fail`,s.`oid` FROM `mohe_010_refresh`.`eb_store_coupon_user` s;

INSERT INTO `mohe_010_app`.`eb_store_finance_flow` (`id`,`store_id`,`uid`,`staff_id`,`order_id`,`link_id`,`pm`,`number`,`type`,`pay_type`,`pay_price`,`total_price`,`rate`,`trade_type`,`remark`,`mark`,`trade_time`,`take_time`,`add_time`,`is_del`) SELECT s.`id`,s.`store_id`,s.`uid`,s.`staff_id`,s.`order_id`,s.`link_id`,s.`pm`,s.`number`,s.`type`,s.`pay_type`,s.`pay_price`,s.`total_price`,s.`rate`,s.`trade_type`,s.`remark`,s.`mark`,s.`trade_time`,s.`take_time`,s.`add_time`,s.`is_del` FROM `mohe_010_refresh`.`eb_store_finance_flow` s;

INSERT INTO `mohe_010_app`.`eb_store_order_invoice` (`id`,`uid`,`category`,`order_id`,`invoice_id`,`header_type`,`type`,`name`,`duty_number`,`drawer_phone`,`email`,`tell`,`address`,`bank`,`card_number`,`is_pay`,`is_refund`,`is_invoice`,`invoice_number`,`invoice_amount`,`remark`,`invoice_time`,`is_del`,`add_time`) SELECT s.`id`,s.`uid`,s.`category`,s.`order_id`,s.`invoice_id`,s.`header_type`,s.`type`,s.`name`,s.`duty_number`,s.`drawer_phone`,s.`email`,s.`tell`,s.`address`,s.`bank`,s.`card_number`,s.`is_pay`,s.`is_refund`,s.`is_invoice`,s.`invoice_number`,s.`invoice_amount`,s.`remark`,s.`invoice_time`,s.`is_del`,s.`add_time` FROM `mohe_010_refresh`.`eb_store_order_invoice` s;

INSERT INTO `mohe_010_app`.`eb_store_order_refund` (`id`,`store_order_id`,`store_id`,`order_id`,`uid`,`supplier_id`,`apply_type`,`apply_price`,`refund_type`,`refund_num`,`refund_price`,`refunded_price`,`settle_price`,`refund_reason`,`refund_goods_type`,`refund_phone`,`refund_express`,`refund_express_name`,`refund_explain`,`refund_img`,`refund_goods_explain`,`refund_goods_img`,`refuse_reason`,`remark`,`refunded_time`,`cart_info`,`is_cancel`,`is_del`,`is_system_del`,`add_time`) SELECT s.`id`,s.`store_order_id`,s.`store_id`,s.`order_id`,s.`uid`,s.`supplier_id`,s.`apply_type`,s.`apply_price`,s.`refund_type`,s.`refund_num`,s.`refund_price`,s.`refunded_price`,s.`settle_price`,s.`refund_reason`,s.`refund_goods_type`,s.`refund_phone`,s.`refund_express`,s.`refund_express_name`,s.`refund_explain`,s.`refund_img`,s.`refund_goods_explain`,s.`refund_goods_img`,s.`refuse_reason`,s.`remark`,s.`refunded_time`,s.`cart_info`,s.`is_cancel`,s.`is_del`,s.`is_system_del`,s.`add_time` FROM `mohe_010_refresh`.`eb_store_order_refund` s;

INSERT INTO `mohe_010_app`.`eb_store_order_status` (`id`,`oid`,`change_type`,`change_message`,`change_manager_id`,`change_manager_type`,`change_manager`,`change_time`) SELECT s.`id`,s.`oid`,s.`change_type`,s.`change_message`,s.`change_manager_id`,s.`change_manager_type`,s.`change_manager`,s.`change_time` FROM `mohe_010_refresh`.`eb_store_order_status` s;

INSERT INTO `mohe_010_app`.`eb_store_pink` (`id`,`uid`,`nickname`,`avatar`,`order_id`,`order_id_key`,`total_num`,`total_price`,`cid`,`pid`,`people`,`price`,`add_time`,`stop_time`,`k_id`,`is_tpl`,`is_refund`,`status`,`is_virtual`) SELECT s.`id`,s.`uid`,s.`nickname`,s.`avatar`,s.`order_id`,s.`order_id_key`,s.`total_num`,s.`total_price`,s.`cid`,s.`pid`,s.`people`,s.`price`,s.`add_time`,s.`stop_time`,s.`k_id`,s.`is_tpl`,s.`is_refund`,s.`status`,s.`is_virtual` FROM `mohe_010_refresh`.`eb_store_pink` s;

INSERT INTO `mohe_010_app`.`eb_store_product_attr` (`id`,`product_id`,`attr_name`,`attr_values`,`type`) SELECT s.`id`,s.`product_id`,s.`attr_name`,s.`attr_values`,s.`type` FROM `mohe_010_refresh`.`eb_store_product_attr` s;

INSERT INTO `mohe_010_app`.`eb_store_product_attr_result` (`id`,`product_id`,`result`,`change_time`,`type`) SELECT s.`id`,s.`product_id`,s.`result`,s.`change_time`,s.`type` FROM `mohe_010_refresh`.`eb_store_product_attr_result` s;

INSERT INTO `mohe_010_app`.`eb_store_product_attr_value` (`id`,`type`,`product_id`,`product_type`,`suk`,`stock`,`defective_stock`,`sum_stock`,`sales`,`price`,`price_range_status`,`price_range_min`,`price_range_max`,`settle_price`,`integral`,`image`,`unique`,`cost`,`bar_code`,`ot_price`,`vip_price`,`weight`,`volume`,`brokerage`,`brokerage_two`,`quota`,`quota_show`,`code`,`disk_info`,`write_times`,`write_valid`,`write_days`,`write_start`,`write_end`,`level_price`,`is_default_select`,`is_show`) SELECT s.`id`,s.`type`,s.`product_id`,s.`product_type`,s.`suk`,s.`stock`,s.`defective_stock`,s.`sum_stock`,s.`sales`,s.`price`,s.`price_range_status`,s.`price_range_min`,s.`price_range_max`,s.`settle_price`,s.`integral`,s.`image`,s.`unique`,s.`cost`,s.`bar_code`,s.`ot_price`,s.`vip_price`,s.`weight`,s.`volume`,s.`brokerage`,s.`brokerage_two`,s.`quota`,s.`quota_show`,s.`code`,s.`disk_info`,s.`write_times`,s.`write_valid`,s.`write_days`,s.`write_start`,s.`write_end`,s.`level_price`,s.`is_default_select`,s.`is_show` FROM `mohe_010_refresh`.`eb_store_product_attr_value` s;

INSERT INTO `mohe_010_app`.`eb_store_product_cate` (`id`,`product_id`,`cate_id`,`add_time`,`cate_pid`,`status`) SELECT s.`id`,s.`product_id`,s.`cate_id`,s.`add_time`,s.`cate_pid`,s.`status` FROM `mohe_010_refresh`.`eb_store_product_cate` s;

INSERT INTO `mohe_010_app`.`eb_store_product_category` (`id`,`pid`,`type`,`relation_id`,`sync_cate_id`,`cate_name`,`path`,`level`,`sort`,`pic`,`is_show`,`mobile_card_show`,`add_time`,`big_pic`,`adv_pic`,`adv_link`) SELECT s.`id`,s.`pid`,s.`type`,s.`relation_id`,s.`sync_cate_id`,s.`cate_name`,s.`path`,s.`level`,s.`sort`,s.`pic`,s.`is_show`,s.`mobile_card_show`,s.`add_time`,s.`big_pic`,s.`adv_pic`,s.`adv_link` FROM `mohe_010_refresh`.`eb_store_product_category` s;

INSERT INTO `mohe_010_app`.`eb_store_product_description` (`product_id`,`description`,`type`) SELECT s.`product_id`,s.`description`,s.`type` FROM `mohe_010_refresh`.`eb_store_product_description` s;

INSERT INTO `mohe_010_app`.`eb_store_product_ensure` (`id`,`type`,`relation_id`,`name`,`image`,`desc`,`sort`,`status`,`add_time`) SELECT s.`id`,s.`type`,s.`relation_id`,s.`name`,s.`image`,s.`desc`,s.`sort`,s.`status`,s.`add_time` FROM `mohe_010_refresh`.`eb_store_product_ensure` s;

INSERT INTO `mohe_010_app`.`eb_store_product_label` (`id`,`type`,`relation_id`,`label_cate`,`label_name`,`style_type`,`color`,`bg_color`,`border_color`,`icon`,`is_show`,`status`,`sort`,`add_time`) SELECT s.`id`,s.`type`,s.`relation_id`,s.`label_cate`,s.`label_name`,s.`style_type`,s.`color`,s.`bg_color`,s.`border_color`,s.`icon`,s.`is_show`,s.`status`,s.`sort`,s.`add_time` FROM `mohe_010_refresh`.`eb_store_product_label` s;

INSERT INTO `mohe_010_app`.`eb_store_product_log` (`id`,`type`,`product_id`,`store_id`,`uid`,`visit_num`,`cart_num`,`order_num`,`pay_num`,`pay_price`,`cost_price`,`pay_uid`,`refund_num`,`refund_price`,`collect_num`,`add_time`,`delete_time`) SELECT s.`id`,s.`type`,s.`product_id`,s.`store_id`,s.`uid`,s.`visit_num`,s.`cart_num`,s.`order_num`,s.`pay_num`,s.`pay_price`,s.`cost_price`,s.`pay_uid`,s.`refund_num`,s.`refund_price`,s.`collect_num`,s.`add_time`,s.`delete_time` FROM `mohe_010_refresh`.`eb_store_product_log` s;

INSERT INTO `mohe_010_app`.`eb_store_product_relation` (`id`,`type`,`product_id`,`relation_id`,`relation_pid`,`status`,`add_time`) SELECT s.`id`,s.`type`,s.`product_id`,s.`relation_id`,s.`relation_pid`,s.`status`,s.`add_time` FROM `mohe_010_refresh`.`eb_store_product_relation` s;

INSERT INTO `mohe_010_app`.`eb_store_product_reply` (`id`,`uid`,`oid`,`type`,`relation_id`,`unique`,`product_id`,`sku_unique`,`sku`,`reply_type`,`reply_score`,`product_score`,`service_score`,`delivery_score`,`comment`,`pics`,`add_time`,`praise`,`views_num`,`merchant_reply_content`,`merchant_reply_time`,`is_del`,`is_reply`,`nickname`,`avatar`) SELECT s.`id`,s.`uid`,s.`oid`,s.`type`,s.`relation_id`,s.`unique`,s.`product_id`,s.`sku_unique`,s.`sku`,s.`reply_type`,s.`reply_score`,s.`product_score`,s.`service_score`,s.`delivery_score`,s.`comment`,s.`pics`,s.`add_time`,s.`praise`,s.`views_num`,s.`merchant_reply_content`,s.`merchant_reply_time`,s.`is_del`,s.`is_reply`,s.`nickname`,s.`avatar` FROM `mohe_010_refresh`.`eb_store_product_reply` s;

INSERT INTO `mohe_010_app`.`eb_store_product_reservation_time` (`id`,`product_id`,`sku_unique`,`show_time`,`start`,`end`,`service_price`,`stock`) SELECT s.`id`,s.`product_id`,s.`sku_unique`,s.`show_time`,s.`start`,s.`end`,s.`service_price`,s.`stock` FROM `mohe_010_refresh`.`eb_store_product_reservation_time` s;

INSERT INTO `mohe_010_app`.`eb_store_product_stock_order` (`id`,`type`,`relation_id`,`stock_type`,`order_id`,`order_type`,`store_order_id`,`refund_order_id`,`count_id`,`remark`,`status`,`admin_id`,`stock_time`,`add_time`) SELECT s.`id`,s.`type`,s.`relation_id`,s.`stock_type`,s.`order_id`,s.`order_type`,s.`store_order_id`,s.`refund_order_id`,s.`count_id`,s.`remark`,s.`status`,s.`admin_id`,s.`stock_time`,s.`add_time` FROM `mohe_010_refresh`.`eb_store_product_stock_order` s;

INSERT INTO `mohe_010_app`.`eb_store_product_unit` (`id`,`type`,`relation_id`,`name`,`sort`,`status`,`is_del`,`add_time`) SELECT s.`id`,s.`type`,s.`relation_id`,s.`name`,s.`sort`,s.`status`,s.`is_del`,s.`add_time` FROM `mohe_010_refresh`.`eb_store_product_unit` s;

INSERT INTO `mohe_010_app`.`eb_store_reservation_order` (`id`,`order_id`,`uid`,`oid`,`cart_info_id`,`product_id`,`sku`,`sku_unique`,`store_id`,`staff_id`,`service_staff_id`,`staff_choose`,`confirm_staff_id`,`table_id`,`table_name`,`confirm_time`,`service_price`,`verify_code`,`reservation_type`,`reservation_time`,`reservation_time_id`,`reservation_start`,`reservation_end`,`service_duration_minutes`,`addon_items`,`reservation_name`,`reservation_phone`,`reservation_address`,`custom_form_title`,`reservation_info`,`reservation_create_time`,`service_time`,`service_end_time`,`jindu_five_notice`,`jindu_end_notice`,`service_describe`,`service_images`,`service_tags`,`service_other_tag`,`mark`,`remark`,`refuse_reason`,`status`,`is_del`,`is_system_del`,`add_time`) SELECT s.`id`,s.`order_id`,s.`uid`,s.`oid`,s.`cart_info_id`,s.`product_id`,s.`sku`,s.`sku_unique`,s.`store_id`,s.`staff_id`,s.`service_staff_id`,s.`staff_choose`,s.`confirm_staff_id`,s.`table_id`,s.`table_name`,s.`confirm_time`,s.`service_price`,s.`verify_code`,s.`reservation_type`,s.`reservation_time`,s.`reservation_time_id`,s.`reservation_start`,s.`reservation_end`,s.`service_duration_minutes`,s.`addon_items`,s.`reservation_name`,s.`reservation_phone`,s.`reservation_address`,s.`custom_form_title`,s.`reservation_info`,s.`reservation_create_time`,s.`service_time`,s.`service_end_time`,s.`jindu_five_notice`,s.`jindu_end_notice`,s.`service_describe`,s.`service_images`,s.`service_tags`,s.`service_other_tag`,s.`mark`,s.`remark`,s.`refuse_reason`,s.`status`,s.`is_del`,s.`is_system_del`,s.`add_time` FROM `mohe_010_refresh`.`eb_store_reservation_order` s;

INSERT INTO `mohe_010_app`.`eb_store_service_feedback` (`id`,`uid`,`rela_name`,`phone`,`content`,`make`,`status`,`add_time`) SELECT s.`id`,s.`uid`,s.`rela_name`,s.`phone`,s.`content`,s.`make`,s.`status`,s.`add_time` FROM `mohe_010_refresh`.`eb_store_service_feedback` s;

INSERT INTO `mohe_010_app`.`eb_store_staff_shift_handover` (`id`,`relation_id`,`staff_id`,`shift_start_time`,`shift_end_time`,`add_time`) SELECT s.`id`,s.`relation_id`,s.`staff_id`,s.`shift_start_time`,s.`shift_end_time`,s.`add_time` FROM `mohe_010_refresh`.`eb_store_staff_shift_handover` s;

INSERT INTO `mohe_010_app`.`eb_store_target` (`id`,`name`,`store_id`,`object_type`,`object_name`,`time_type`,`year`,`month`,`period_start`,`period_end`,`metric_count`,`status`,`is_del`,`add_time`,`update_time`) SELECT s.`id`,s.`name`,s.`store_id`,s.`object_type`,s.`object_name`,s.`time_type`,s.`year`,s.`month`,s.`period_start`,s.`period_end`,s.`metric_count`,s.`status`,s.`is_del`,s.`add_time`,s.`update_time` FROM `mohe_010_refresh`.`eb_store_target` s;

INSERT INTO `mohe_010_app`.`eb_store_target_allocate` (`id`,`target_id`,`store_id`,`allocate_type`,`ref_key`,`staff_id`,`staff_name`,`allocate_value`,`add_time`,`update_time`) SELECT s.`id`,s.`target_id`,s.`store_id`,s.`allocate_type`,s.`ref_key`,s.`staff_id`,s.`staff_name`,s.`allocate_value`,s.`add_time`,s.`update_time` FROM `mohe_010_refresh`.`eb_store_target_allocate` s;

INSERT INTO `mohe_010_app`.`eb_store_target_metric` (`id`,`target_id`,`metric_key`,`metric_name`,`target_value`,`unit`,`sort`,`metric_type`) SELECT s.`id`,s.`target_id`,s.`metric_key`,s.`metric_name`,s.`target_value`,s.`unit`,s.`sort`,s.`metric_type` FROM `mohe_010_refresh`.`eb_store_target_metric` s;

INSERT INTO `mohe_010_app`.`eb_store_target_product` (`id`,`target_id`,`product_id`,`product_name`,`display_name`,`metric_key`,`metric_name`,`target_value`,`unit`,`sort`) SELECT s.`id`,s.`target_id`,s.`product_id`,s.`product_name`,s.`display_name`,s.`metric_key`,s.`metric_name`,s.`target_value`,s.`unit`,s.`sort` FROM `mohe_010_refresh`.`eb_store_target_product` s;

INSERT INTO `mohe_010_app`.`eb_store_user` (`id`,`store_id`,`uid`,`label_id`,`status`,`add_time`) SELECT s.`id`,s.`store_id`,s.`uid`,s.`label_id`,s.`status`,s.`add_time` FROM `mohe_010_refresh`.`eb_store_user` s;

INSERT INTO `mohe_010_app`.`eb_store_visit` (`id`,`product_id`,`store_id`,`product_type`,`cate_id`,`type`,`uid`,`count`,`content`,`add_time`) SELECT s.`id`,s.`product_id`,s.`store_id`,s.`product_type`,s.`cate_id`,s.`type`,s.`uid`,s.`count`,s.`content`,s.`add_time` FROM `mohe_010_refresh`.`eb_store_visit` s;

INSERT INTO `mohe_010_app`.`eb_system_attachment` (`att_id`,`type`,`file_type`,`relation_id`,`name`,`att_dir`,`satt_dir`,`att_size`,`att_type`,`pid`,`time`,`image_type`,`module_type`,`real_name`,`scan_token`) SELECT s.`att_id`,s.`type`,s.`file_type`,s.`relation_id`,s.`name`,s.`att_dir`,s.`satt_dir`,s.`att_size`,s.`att_type`,s.`pid`,s.`time`,s.`image_type`,s.`module_type`,s.`real_name`,'' FROM `mohe_010_refresh`.`eb_system_attachment` s;

INSERT INTO `mohe_010_app`.`eb_system_file` (`id`,`cthash`,`filename`,`atime`,`mtime`,`ctime`) SELECT s.`id`,s.`cthash`,s.`filename`,s.`atime`,s.`mtime`,s.`ctime` FROM `mohe_010_refresh`.`eb_system_file` s;

INSERT INTO `mohe_010_app`.`eb_system_region_agent` (`id`,`pid`,`manage_region_id`,`name`,`nickname`,`phone`,`province_id`,`city_id`,`area_id`,`recommend_region`,`store_id`,`level`,`sort`,`is_alone`,`is_show`,`is_del`,`add_time`) SELECT s.`id`,s.`pid`,s.`manage_region_id`,s.`name`,s.`nickname`,s.`phone`,s.`province_id`,s.`city_id`,s.`area_id`,s.`recommend_region`,s.`store_id`,s.`level`,s.`sort`,s.`is_alone`,s.`is_show`,s.`is_del`,s.`add_time` FROM `mohe_010_refresh`.`eb_system_region_agent` s;

INSERT INTO `mohe_010_app`.`eb_system_region_agent_store` (`id`,`agent_id`,`store_id`,`add_time`) SELECT s.`id`,s.`agent_id`,s.`store_id`,s.`add_time` FROM `mohe_010_refresh`.`eb_system_region_agent_store` s;

INSERT INTO `mohe_010_app`.`eb_system_region_manage` (`id`,`pid`,`name`,`sort`,`is_del`,`add_time`) SELECT s.`id`,s.`pid`,s.`name`,s.`sort`,s.`is_del`,s.`add_time` FROM `mohe_010_refresh`.`eb_system_region_manage` s;

INSERT INTO `mohe_010_app`.`eb_system_user_level` (`id`,`mer_id`,`name`,`money`,`valid_date`,`is_forever`,`is_pay`,`is_show`,`grade`,`discount`,`image`,`color`,`icon`,`explain`,`add_time`,`is_del`,`exp_num`) SELECT s.`id`,s.`mer_id`,s.`name`,s.`money`,s.`valid_date`,s.`is_forever`,s.`is_pay`,s.`is_show`,s.`grade`,s.`discount`,s.`image`,s.`color`,s.`icon`,s.`explain`,s.`add_time`,s.`is_del`,s.`exp_num` FROM `mohe_010_refresh`.`eb_system_user_level` s;

INSERT INTO `mohe_010_app`.`eb_table_qrcode` (`id`,`store_id`,`cate_id`,`seat_num`,`qrcode`,`table_number`,`remarks`,`is_using`,`is_use`,`eat_number`,`order_time`,`is_del`,`add_time`) SELECT s.`id`,s.`store_id`,s.`cate_id`,s.`seat_num`,s.`qrcode`,s.`table_number`,s.`remarks`,s.`is_using`,s.`is_use`,s.`eat_number`,s.`order_time`,s.`is_del`,s.`add_time` FROM `mohe_010_refresh`.`eb_table_qrcode` s;

INSERT INTO `mohe_010_app`.`eb_table_seats` (`id`,`store_id`,`number`,`add_time`) SELECT s.`id`,s.`store_id`,s.`number`,s.`add_time` FROM `mohe_010_refresh`.`eb_table_seats` s;

INSERT INTO `mohe_010_app`.`eb_user_address` (`id`,`uid`,`real_name`,`phone`,`province`,`province_id`,`city`,`street`,`street_id`,`city_id`,`district`,`district_id`,`detail`,`post_code`,`longitude`,`latitude`,`is_default`,`is_del`,`add_time`) SELECT s.`id`,s.`uid`,s.`real_name`,s.`phone`,s.`province`,s.`province_id`,s.`city`,s.`street`,s.`street_id`,s.`city_id`,s.`district`,s.`district_id`,s.`detail`,s.`post_code`,s.`longitude`,s.`latitude`,s.`is_default`,s.`is_del`,s.`add_time` FROM `mohe_010_refresh`.`eb_user_address` s;

INSERT INTO `mohe_010_app`.`eb_user_belong_store` (`id`,`group`,`type`,`is_store`,`uid`,`store_id`,`staff_id`,`add_time`) SELECT s.`id`,s.`group`,s.`type`,s.`is_store`,s.`uid`,s.`store_id`,s.`staff_id`,s.`add_time` FROM `mohe_010_refresh`.`eb_user_belong_store` s;

INSERT INTO `mohe_010_app`.`eb_user_bill` (`id`,`uid`,`link_id`,`pm`,`title`,`category`,`type`,`number`,`balance`,`mark`,`add_time`,`status`,`take`,`frozen_time`,`obtain_time`) SELECT s.`id`,s.`uid`,s.`link_id`,s.`pm`,s.`title`,s.`category`,s.`type`,s.`number`,s.`balance`,s.`mark`,s.`add_time`,s.`status`,s.`take`,s.`frozen_time`,s.`obtain_time` FROM `mohe_010_refresh`.`eb_user_bill` s;

INSERT INTO `mohe_010_app`.`eb_user_friends` (`id`,`uid`,`friends_uid`,`add_time`) SELECT s.`id`,s.`uid`,s.`friends_uid`,s.`add_time` FROM `mohe_010_refresh`.`eb_user_friends` s;

INSERT INTO `mohe_010_app`.`eb_user_invoice` (`id`,`uid`,`header_type`,`type`,`name`,`duty_number`,`drawer_phone`,`email`,`tell`,`address`,`bank`,`card_number`,`is_default`,`is_del`,`add_time`) SELECT s.`id`,s.`uid`,s.`header_type`,s.`type`,s.`name`,s.`duty_number`,s.`drawer_phone`,s.`email`,s.`tell`,s.`address`,s.`bank`,s.`card_number`,s.`is_default`,s.`is_del`,s.`add_time` FROM `mohe_010_refresh`.`eb_user_invoice` s;

INSERT INTO `mohe_010_app`.`eb_user_level` (`id`,`uid`,`level_id`,`grade`,`valid_time`,`is_forever`,`mer_id`,`status`,`mark`,`remind`,`is_del`,`add_time`,`discount`) SELECT s.`id`,s.`uid`,s.`level_id`,s.`grade`,s.`valid_time`,s.`is_forever`,s.`mer_id`,s.`status`,s.`mark`,s.`remind`,s.`is_del`,s.`add_time`,s.`discount` FROM `mohe_010_refresh`.`eb_user_level` s;

INSERT INTO `mohe_010_app`.`eb_user_recharge` (`id`,`store_id`,`uid`,`staff_id`,`order_id`,`trade_no`,`price`,`give_price`,`debt_amount`,`repaid_debt_amount`,`recharge_type`,`auth_code`,`paid`,`pay_time`,`add_time`,`refund_price`,`channel_type`,`remarks`,`cash_choose`,`remark_info`,`source`,`refund_ben`,`refund_give`,`send_all`,`staff_choose`,`is_budan`,`is_gendan`,`gendan_staff_id`,`budan_time`,`combination_info`) SELECT s.`id`,s.`store_id`,s.`uid`,s.`staff_id`,s.`order_id`,s.`trade_no`,s.`price`,s.`give_price`,s.`debt_amount`,s.`repaid_debt_amount`,s.`recharge_type`,'',s.`paid`,s.`pay_time`,s.`add_time`,s.`refund_price`,s.`channel_type`,s.`remarks`,s.`cash_choose`,s.`remark_info`,s.`source`,s.`refund_ben`,s.`refund_give`,s.`send_all`,s.`staff_choose`,s.`is_budan`,s.`is_gendan`,s.`gendan_staff_id`,s.`budan_time`,s.`combination_info` FROM `mohe_010_refresh`.`eb_user_recharge` s;

INSERT INTO `mohe_010_app`.`eb_user_relation` (`id`,`uid`,`relation_id`,`type`,`category`,`add_time`) SELECT s.`id`,s.`uid`,s.`relation_id`,s.`type`,s.`category`,s.`add_time` FROM `mohe_010_refresh`.`eb_user_relation` s;

INSERT INTO `mohe_010_app`.`eb_user_search` (`id`,`uid`,`keyword`,`vicword`,`num`,`result`,`is_del`,`add_time`) SELECT s.`id`,s.`uid`,s.`keyword`,s.`vicword`,s.`num`,s.`result`,s.`is_del`,s.`add_time` FROM `mohe_010_refresh`.`eb_user_search` s;

INSERT INTO `mohe_010_app`.`eb_user_sign` (`id`,`uid`,`title`,`number`,`balance`,`exp_num`,`exp_balance`,`add_time`) SELECT s.`id`,s.`uid`,s.`title`,s.`number`,s.`balance`,s.`exp_num`,s.`exp_balance`,s.`add_time` FROM `mohe_010_refresh`.`eb_user_sign` s;

INSERT INTO `mohe_010_app`.`eb_user_spread` (`id`,`store_id`,`uid`,`staff_id`,`spread_uid`,`spread_time`,`admin_id`) SELECT s.`id`,s.`store_id`,s.`uid`,s.`staff_id`,s.`spread_uid`,s.`spread_time`,s.`admin_id` FROM `mohe_010_refresh`.`eb_user_spread` s;

INSERT INTO `mohe_010_app`.`eb_user_visit` (`id`,`uid`,`url`,`ip`,`stay_time`,`add_time`,`channel_type`,`province`) SELECT s.`id`,s.`uid`,s.`url`,s.`ip`,s.`stay_time`,s.`add_time`,s.`channel_type`,s.`province` FROM `mohe_010_refresh`.`eb_user_visit` s;

INSERT INTO `mohe_010_app`.`eb_user_visit_store` (`id`,`uid`,`store_id`,`ip`,`channel_type`,`add_time`) SELECT s.`id`,s.`uid`,s.`store_id`,s.`ip`,s.`channel_type`,s.`add_time` FROM `mohe_010_refresh`.`eb_user_visit_store` s;

INSERT INTO `mohe_010_app`.`eb_yeji_commission` (`id`,`product_id`,`yeji`,`commission_json`) SELECT s.`id`,s.`product_id`,s.`yeji`,s.`commission_json` FROM `mohe_010_refresh`.`eb_yeji_commission` s;

INSERT INTO `mohe_010_app`.`eb_yeji_range` (`id`,`yeji_min`,`yeji_max`) SELECT s.`id`,s.`yeji_min`,s.`yeji_max` FROM `mohe_010_refresh`.`eb_yeji_range` s;

INSERT INTO `mohe_010_app`.`eb_system_group` SELECT * FROM `mohe_010_refresh`.`eb_system_group`;

INSERT INTO `mohe_010_app`.`eb_system_group_data` SELECT * FROM `mohe_010_refresh`.`eb_system_group_data`;

INSERT INTO mohe_010_app.codex_010_balance_split_audit
  (source_uid,original_now_money,original_ben_money,original_give_money,
   target_give_money,correction,rule_name)
SELECT uid,now_money,ben_money,give_money,now_money-ben_money,
       now_money-ben_money-give_money,'now-money-authoritative-add-to-gift'
FROM mohe_010_refresh.eb_user
WHERE ABS(now_money-ben_money-give_money)>0.001;

UPDATE mohe_010_app.eb_user u
JOIN mohe_010_app.codex_010_balance_split_audit a ON a.source_uid=u.uid
SET u.give_money=a.target_give_money;

INSERT INTO mohe_010_app.codex_010_member_asset_projection
  (target_uid,source_count,total_balance,principal_balance,gift_balance,integral)
SELECT m.target_uid,COUNT(*),SUM(u.now_money),SUM(u.ben_money),SUM(u.give_money),SUM(u.integral)
FROM mohe_010_app.codex_010_member_map m
JOIN mohe_010_app.eb_user u ON u.uid=m.source_uid
GROUP BY m.target_uid;

INSERT INTO mohe_010_app.codex_010_member_aux_projection
  (target_uid,exp,pay_count,spread_count,brokerage_price,max_sign_num,max_level,is_promoter)
SELECT m.target_uid,SUM(u.exp),SUM(u.pay_count),SUM(u.spread_count),
       SUM(u.brokerage_price),MAX(u.sign_num),MAX(u.level),MAX(u.is_promoter)
FROM mohe_010_refresh.eb_user u
JOIN mohe_010_app.codex_010_member_map m ON m.source_uid=u.uid
GROUP BY m.target_uid;

UPDATE mohe_010_app.eb_user_visit t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_user_visit_store t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_store_finance_flow t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_store_product_log t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_store_product_log t JOIN mohe_010_app.codex_010_member_map m ON t.pay_uid=m.source_uid SET t.pay_uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_user_friends t JOIN mohe_010_app.codex_010_member_map m ON t.friends_uid=m.source_uid SET t.friends_uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_user_friends t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_staff_flowing_water t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_store_order t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_user_bill t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_store_coupon_issue_user t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_store_coupon_user t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_user_belong_store t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_user_money t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_capital_flow t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_store_order_writeoff t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_store_user t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_user_spread t JOIN mohe_010_app.codex_010_member_map m ON t.spread_uid=m.source_uid SET t.spread_uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_user_spread t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_store_order_cart_info t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_user_recharge t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_store_product_reply t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_store_visit t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_system_store_staff t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_user_card_holder t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_user_sign t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_store_cart t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_store_order_refund t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_store_service_feedback t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;
UPDATE mohe_010_app.eb_user_level t JOIN mohe_010_app.codex_010_member_map m ON t.uid=m.source_uid SET t.uid=m.target_uid WHERE m.source_uid<>m.target_uid;

-- 辅助索引是可重建的派生表，删除旧 UID 的脏标记并留主档变更的触发器重建。
DELETE d FROM mohe_010_app.eb_ai_member_rights_amount_dirty d
JOIN mohe_010_app.codex_010_member_map m ON d.member_id=m.source_uid
WHERE m.source_uid<>m.target_uid;

-- 当前资产按已核准来源总额合并一次，历史流水不重放。
UPDATE mohe_010_app.eb_user u
JOIN mohe_010_app.codex_010_member_asset_projection a ON a.target_uid=u.uid
JOIN mohe_010_app.codex_010_member_aux_projection x ON x.target_uid=u.uid
SET u.now_money=a.total_balance,u.ben_money=a.principal_balance,
    u.give_money=a.gift_balance,u.integral=a.integral,
    u.exp=x.exp,u.pay_count=x.pay_count,u.spread_count=x.spread_count,
    u.brokerage_price=x.brokerage_price,u.sign_num=x.max_sign_num,
    u.level=x.max_level,u.is_promoter=x.is_promoter
WHERE a.source_count>1;
DELETE u FROM mohe_010_app.eb_user u
JOIN mohe_010_app.codex_010_member_map m ON m.source_uid=u.uid
WHERE m.source_uid<>m.target_uid;



INSERT INTO mohe_010_app.codex_010_phone_identity_projection
  (phone_digest,target_uid,source_account_count)
SELECT SHA2(u.phone,256),MIN(m.target_uid),COUNT(*)
FROM mohe_010_refresh.eb_user u
JOIN mohe_010_app.codex_010_member_map m ON m.source_uid=u.uid
WHERE u.is_del=0 AND u.phone REGEXP '^1[3-9][0-9]{9}$'
GROUP BY u.phone
HAVING COUNT(DISTINCT m.target_uid)=1;

INSERT INTO mohe_010_app.eb_user_phone_identity
  (phone_digest,bound_uid,state,identity_version,bound_at,released_at,created_at,updated_at)
SELECT SHA2(u.phone,256),u.uid,'BOUND',1,UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM mohe_010_app.eb_user u WHERE u.is_del=0 AND u.phone REGEXP '^1[3-9][0-9]{9}$';
INSERT INTO mohe_010_app.eb_user_phone_identity_audit
  (identity_id,phone_digest,before_uid,after_uid,before_state,after_state,
   before_version,after_version,action,operator_type,operator_id,source,
   request_id,occurred_at,recorded_at)
SELECT i.id,i.phone_digest,NULL,i.bound_uid,'RESERVED','BOUND',0,1,
       'IMPORT_BIND','SYSTEM',0,'LINLIN_IMPORT','010-linlin-20261023-refresh-1',
       UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM mohe_010_app.eb_user_phone_identity i;

INSERT INTO mohe_010_app.codex_010_legacy_writeoff_projection
  (source_writeoff_id,source_uid,member_id,source_order_id,source_status,
   record_status,original_amount,integer_amount,source_writeoff_at,voided_at,
   void_reason,linked_void_order_count)
SELECT w.id,w.uid,COALESCE(m.target_uid,w.uid),w.oid,w.status,
       IF(w.status=1,'voided','effective'),w.writeoff_price,a.rounded_price,
       w.add_time,NULL,IF(w.status=1,r.void_reason,NULL),COALESCE(r.order_count,0)
FROM mohe_010_refresh.eb_store_order_writeoff w
JOIN mohe_010_app.codex_010_writeoff_rounding_audit a ON a.writeoff_id=w.id
LEFT JOIN mohe_010_app.codex_010_member_map m ON m.source_uid=w.uid
LEFT JOIN (
  SELECT link_id,COUNT(*) order_count,
         IF(COUNT(DISTINCT NULLIF(back_reason,''))=1,MIN(NULLIF(back_reason,'')),NULL) void_reason
  FROM mohe_010_refresh.eb_store_order WHERE refund_status=2 AND link_id>0 GROUP BY link_id
) r ON r.link_id=w.id AND w.status=1;

INSERT INTO mohe_010_app.codex_010_migration_exceptions
SELECT 'balance_split_mismatch','eb_user',uid FROM mohe_010_refresh.eb_user
WHERE ABS(now_money-ben_money-give_money)>0.001;
INSERT INTO mohe_010_app.codex_010_migration_exceptions
SELECT 'duplicate_phone_member','eb_user',u.uid FROM mohe_010_refresh.eb_user u
JOIN (SELECT phone FROM mohe_010_refresh.eb_user WHERE phone<>'' AND is_del=0
      GROUP BY phone HAVING COUNT(*)>1) d ON d.phone=u.phone WHERE u.is_del=0;
INSERT INTO mohe_010_app.codex_010_migration_exceptions
SELECT 'orphan_points_bill','eb_user_bill',b.id FROM mohe_010_refresh.eb_user_bill b
LEFT JOIN mohe_010_refresh.eb_user u ON u.uid=b.uid WHERE u.uid IS NULL;
INSERT INTO mohe_010_app.codex_010_migration_exceptions
SELECT 'orphan_balance_flow','eb_user_money',m.id FROM mohe_010_refresh.eb_user_money m
LEFT JOIN mohe_010_refresh.eb_user u ON u.uid=m.uid WHERE u.uid IS NULL;
INSERT INTO mohe_010_app.codex_010_migration_exceptions
SELECT 'orphan_card_holder','eb_user_card_holder',c.id FROM mohe_010_refresh.eb_user_card_holder c
LEFT JOIN mohe_010_refresh.eb_user u ON u.uid=c.uid WHERE u.uid IS NULL;
INSERT INTO mohe_010_app.codex_010_migration_exceptions
SELECT 'card_product_missing','eb_user_card_holder',c.id FROM mohe_010_refresh.eb_user_card_holder c
LEFT JOIN mohe_010_refresh.eb_store_product p ON p.id=c.product_id WHERE p.id IS NULL;
INSERT INTO mohe_010_app.codex_010_migration_exceptions
SELECT 'orphan_writeoff_order','eb_store_order_writeoff',w.id
FROM mohe_010_refresh.eb_store_order_writeoff w
LEFT JOIN mohe_010_refresh.eb_store_order o ON o.id=w.oid WHERE o.id IS NULL;

INSERT INTO mohe_010_app.codex_010_migration_exceptions
  (issue_type,source_table,source_id)
SELECT 'unattributed_writeoff_member','eb_store_order_writeoff',w.id
FROM mohe_010_refresh.eb_store_order_writeoff w
LEFT JOIN mohe_010_app.codex_010_member_map m ON m.source_uid=w.uid
WHERE m.source_uid IS NULL;

INSERT INTO mohe_010_app.codex_010_migration_batch
  (batch_key,source_schema,source_archive_sha256,source_member_count,target_member_count,committed_at)
VALUES ('010-linlin-20261023-refresh-1','mohe_010_refresh',
  '970dfd1c383862e30dd668bd6dbfec0805a996dc23d1a5673f70283058f8eb36',
  12314,12290,UNIX_TIMESTAMP());

CALL mohe_010_app.codex_010_import_verify();

COMMIT;

DROP PROCEDURE mohe_010_app.codex_010_import_verify;

SELECT batch_key,source_member_count,target_member_count,committed_at
FROM mohe_010_app.codex_010_migration_batch;
