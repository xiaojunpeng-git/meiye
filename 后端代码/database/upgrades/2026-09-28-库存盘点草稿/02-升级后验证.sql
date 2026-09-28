-- 升级后只读验证；请在目标实例的独立数据库执行并登记结果。
SELECT table_name FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'eb_inventory_stock_count_draft';

SELECT column_name, data_type FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'eb_inventory_stock_count_draft'
ORDER BY ordinal_position;

-- 草稿不能冒充已确认盘点；上线业务验收还需实测保存、重开、修改、完成及库存对账。
SELECT document_status, COUNT(*) AS draft_count, SUM(CASE WHEN confirmed_document_id > 0 THEN 1 ELSE 0 END) AS linked_count
FROM eb_inventory_stock_count_draft GROUP BY document_status;
