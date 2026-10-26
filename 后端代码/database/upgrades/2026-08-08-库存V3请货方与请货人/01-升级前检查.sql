-- upgrade_key: 20260808-002-inventory-v3-request-party-requester-selector
SET NAMES utf8mb4;
SELECT table_name, column_name
FROM information_schema.columns
WHERE table_schema=DATABASE() AND table_name='eb_inventory_stock_request_document'
  AND column_name IN ('request_party_type','request_party_id','requester_name_snapshot');
