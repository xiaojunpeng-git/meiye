-- upgrade_key: 20261002-001-inventory-request-acceptance-party
SET NAMES utf8mb4;
SET @db := DATABASE();

SELECT COUNT(*) AS directory_table_count
FROM information_schema.tables
WHERE table_schema=@db AND table_name='eb_inventory_stock_request_acceptance_party';

SELECT COUNT(*) AS document_column_count
FROM information_schema.columns
WHERE table_schema=@db AND table_name='eb_inventory_stock_request_document'
  AND column_name IN ('acceptance_party_id','acceptance_party_name_snapshot');

SELECT id, tenant_id, party_code, party_name, status, sort
FROM eb_inventory_stock_request_acceptance_party
WHERE tenant_id='0'
ORDER BY sort,id;

SELECT COUNT(*) AS invalid_non_hq_acceptance_count
FROM eb_inventory_stock_request_document
WHERE supply_party_type<>'HQ' AND (acceptance_party_id<>0 OR acceptance_party_name_snapshot<>'');

SELECT COUNT(*) AS broken_acceptance_snapshot_count
FROM eb_inventory_stock_request_document
WHERE acceptance_party_id>0 AND acceptance_party_name_snapshot='';
