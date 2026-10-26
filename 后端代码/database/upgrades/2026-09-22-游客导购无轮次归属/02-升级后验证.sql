-- 不读写会员个人资料，仅核对结构和历史轮次边界。
SELECT IS_NULLABLE, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_customer_guide_round_fact'
  AND COLUMN_NAME = 'guide_round_no';

SELECT COUNT(*) AS invalid_member_rounds
FROM `eb_cashier_v3_customer_guide_round_fact`
WHERE `member_id` > 0 AND (`guide_round_no` IS NULL OR `guide_round_no` NOT IN (1,2,3));

SELECT COUNT(*) AS invalid_guest_rounds
FROM `eb_cashier_v3_customer_guide_round_fact`
WHERE `member_id` = 0 AND `guide_round_no` IS NOT NULL;
