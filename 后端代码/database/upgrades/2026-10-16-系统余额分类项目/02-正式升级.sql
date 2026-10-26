-- One binding per independent customer database. The platform product is only a
-- report-classification identity: it is not listed for sale and creates no order.
CREATE TABLE IF NOT EXISTS `eb_system_business_product` (
  `business_key` varchar(64) NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `created_at` int(11) unsigned NOT NULL,
  PRIMARY KEY (`business_key`),
  UNIQUE KEY `uk_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The requested initial class is 生美/卡项 where that hierarchy exists.
-- Category remains editable; a customer without that hierarchy starts unclassified.
INSERT INTO `eb_store_product`
  (`pid`,`type`,`relation_id`,`product_type`,`store_name`,`code`,`cate_id`,
   `image`,`slider_image`,`unit_name`,`delivery_type`,`freight`,`temp_id`,
   `is_inventory`,`allow_negative_stock`,`salon_stock_enabled`,`is_show`,`is_verify`,
   `is_del`,`price`,`stock`,`add_time`)
SELECT 0,0,0,6,'余额','system:member_balance',
  (SELECT IF(COUNT(*)=1,CAST(MIN(child.id) AS CHAR),'') FROM `eb_store_product_category` child
    JOIN `eb_store_product_category` parent ON parent.id=child.pid
    WHERE child.type=0 AND child.relation_id=0 AND child.cate_name='卡项'
      AND parent.type=0 AND parent.relation_id=0 AND parent.cate_name='生美'),
  '/statics/images/store-default.png','["/statics/images/store-default.png"]',
  '次','2',1,0,0,1,0,0,1,0,0,0,UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `eb_system_business_product` WHERE `business_key`='member_balance')
  AND NOT EXISTS (SELECT 1 FROM `eb_store_product` WHERE `code`='system:member_balance');

INSERT INTO `eb_system_business_product` (`business_key`,`product_id`,`created_at`)
SELECT 'member_balance',p.id,UNIX_TIMESTAMP() FROM `eb_store_product` p
WHERE p.code='system:member_balance' AND p.type=0 AND p.relation_id=0
  AND p.product_type=6 AND p.is_show=0 AND p.is_del=0
  AND NOT EXISTS (SELECT 1 FROM `eb_system_business_product` WHERE `business_key`='member_balance')
ORDER BY p.id LIMIT 1;

-- The existing admin editor needs one zero-value default SKU to treat this
-- project as complete. It is hidden and cannot create stock or a sale.
INSERT INTO `eb_store_product_attr_value`
  (`type`,`product_id`,`product_type`,`suk`,`stock`,`stock_unit`,`sale_unit`,
   `unit_convert`,`decimal_scale`,`price`,`ot_price`,`cost`,`image`,`unique`,`is_show`)
SELECT 0,p.id,6,'默认',0,'次','次',1,0,0,0,0,
  '/statics/images/store-default.png',LEFT(MD5(CONCAT('member_balance:',p.id)),8),0
FROM `eb_system_business_product` binding
JOIN `eb_store_product` p ON p.id=binding.product_id
WHERE binding.business_key='member_balance'
  AND NOT EXISTS (SELECT 1 FROM `eb_store_product_attr_value` sku
    WHERE sku.product_id=p.id AND sku.type=0);
