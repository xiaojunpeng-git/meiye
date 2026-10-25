-- 010补齐遗漏的8项产品默认记账方式；与008只读核对一致。仅插入缺项，不覆盖现有配置。
SET NAMES utf8mb4;
START TRANSACTION;
INSERT INTO `eb_cashier_v3_payment_method_config`
  (`code`,`default_name`,`display_name`,`status`,`sort`,`version`,`created_at`,`updated_at`)
VALUES
  ('cash','现金','现金',1,5,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('unionpay','银联','银联',1,10,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('wechat','微信','微信',1,20,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('alipay','支付宝','支付宝',1,30,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('dianping_voucher','大众验券','大众验券',1,40,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('douyin_voucher','抖音验券','抖音验券',1,50,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('partner_collection','合作方收款','合作方收款',1,60,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('other_collection','其他收款','其他收款',1,70,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `code`=VALUES(`code`);

COMMIT;
