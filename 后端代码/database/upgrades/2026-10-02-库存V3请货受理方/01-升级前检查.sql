-- upgrade_key: 20261002-001-inventory-request-acceptance-party
SET NAMES utf8mb4;
SET @db := DATABASE();

SELECT DATABASE() AS current_database;
SELECT COUNT(*) AS request_document_table_count
FROM information_schema.tables
WHERE table_schema=@db AND table_name='eb_inventory_stock_request_document';

SELECT column_name, column_type, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema=@db AND table_name='eb_inventory_stock_request_document'
  AND column_name IN ('tenant_id','supply_party_type','supply_party_id','acceptance_party_id','acceptance_party_name_snapshot')
ORDER BY ordinal_position;

SELECT COUNT(*) AS request_document_count,
       SUM(supply_party_type='HQ') AS headquarters_supply_count
FROM eb_inventory_stock_request_document;
