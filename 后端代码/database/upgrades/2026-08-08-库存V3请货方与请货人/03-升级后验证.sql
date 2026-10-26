-- upgrade_key: 20260808-002-inventory-v3-request-party-requester-selector
SET NAMES utf8mb4;
SELECT `api_url`,`methods`,`is_del`
FROM `eb_system_menus`
WHERE `type`=1 AND `api_url`='product/inventory/v3/hq/request/parties'
ORDER BY `id` DESC LIMIT 1;
