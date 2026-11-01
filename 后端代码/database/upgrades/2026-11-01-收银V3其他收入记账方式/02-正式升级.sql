-- 新实际收入方式默认关闭，不改变既有非现金other_collection的语义。
SET NAMES utf8mb4;
INSERT INTO eb_cashier_v3_payment_method_config
(code,default_name,display_name,status,sort,version,created_at,updated_at)
VALUES ('other_income','其他收入','其他收入',0,75,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE code=VALUES(code);
