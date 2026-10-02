-- 确认目标数据库、既有草稿表和已有授权；此脚本只读。
SELECT DATABASE() AS target_database;
SHOW TABLES LIKE 'eb_inventory_stock_count_draft';
SELECT id,api_url,methods,unique_auth FROM eb_system_menus
WHERE api_url LIKE 'product/inventory/v3/hq/count/%';
