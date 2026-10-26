-- upgrade_key: 20260805-008-cashier-v3-recharge-checkout
SET NAMES utf8mb4;
SELECT CASE WHEN DATABASE() <> '' THEN 'PRECHECK_OK' ELSE 'STOP_DATABASE_UNSELECTED' END AS precheck_result;
