-- 产品通用配置：企微是独立的实际到账代码。默认停用，绝不改写既有微信事实。
SET NAMES utf8mb4;
INSERT INTO eb_cashier_v3_payment_method_config
  (code,default_name,display_name,status,sort,version,created_at,updated_at)
VALUES ('enterprise_wechat','企微收款','企微收款',0,45,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE code=VALUES(code);
