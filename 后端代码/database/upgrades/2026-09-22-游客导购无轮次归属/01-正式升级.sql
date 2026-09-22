-- upgrade_key: 20260922-guest-guide-without-member-round
-- 游客仍保留导购人员归属，但不占会员第 1～3 轮；仅放宽事实列为空，历史记录不改。
-- 执行前须按目标实例单独完成备份、迁移登记和回滚点核对。
SET NAMES utf8mb4;

ALTER TABLE `eb_cashier_v3_customer_guide_round_fact`
  MODIFY COLUMN `guide_round_no` tinyint(1) unsigned NULL DEFAULT NULL
  COMMENT '会员导购轮次1-3；游客无轮次为NULL';

SELECT 'APPLY_OK' AS apply_result;
