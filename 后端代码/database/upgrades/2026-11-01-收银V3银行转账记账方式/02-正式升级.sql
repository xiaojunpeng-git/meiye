-- upgrade_key: 20261101-bank-transfer-accounting
-- 新代码默认关闭；仅经授权目标实例启用，不更改既有渠道、资产或历史事实。
SET NAMES utf8mb4;
INSERT INTO eb_cashier_v3_payment_method_config
(code, default_name, display_name, status, sort, version, created_at, updated_at)
VALUES ('bank_transfer', '银行转账', '银行转账', 0, 15, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE code = VALUES(code);
