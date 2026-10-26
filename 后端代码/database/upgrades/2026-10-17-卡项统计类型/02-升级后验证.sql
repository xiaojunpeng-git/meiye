-- 校验字段定义以及所有历史卡项的数值域；新建体验卡可为 1。
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_store_product'
   AND COLUMN_NAME = 'card_statistics_type';

SELECT COUNT(*) AS card_total,
       SUM(card_statistics_type = 0) AS normal_card_count,
       SUM(card_statistics_type = 1) AS experience_card_count,
       SUM(card_statistics_type NOT IN (0, 1)) AS invalid_card_count
  FROM eb_store_product
 WHERE product_type = 5;
