-- 分享凭据只保存摘要；券归属仍以原券实例和既有转赠审计为权威。
CREATE TABLE IF NOT EXISTS eb_store_coupon_share (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 token_hash char(64) NOT NULL,
 coupon_user_id int unsigned NOT NULL,
 sender_uid int unsigned NOT NULL,
 existing_member_max_uid int unsigned NOT NULL DEFAULT 0 COMMENT '签发时已有会员上界，新注册判定',
 claimed_uid int unsigned NOT NULL DEFAULT 0,
 created_at int unsigned NOT NULL,
 expires_at int unsigned NOT NULL,
 claimed_at int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (id), UNIQUE KEY uk_token_hash (token_hash),
 KEY idx_coupon_sender (coupon_user_id,sender_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='优惠券分享领取凭据，领取不复制券';

SET @share_column_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_coupon_share' AND COLUMN_NAME='existing_member_max_uid')=0,
 'ALTER TABLE eb_store_coupon_share ADD COLUMN existing_member_max_uid int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE share_column_stmt FROM @share_column_sql;
EXECUTE share_column_stmt;
DEALLOCATE PREPARE share_column_stmt;
