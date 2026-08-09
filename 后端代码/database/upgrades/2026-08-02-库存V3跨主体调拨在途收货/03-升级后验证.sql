-- upgrade_key: 20260802-003-inventory-v3-cross-subject-transfer-receipt
SET NAMES utf8mb4;
SET @db := DATABASE();
SELECT COUNT(*) INTO @table_count FROM information_schema.tables WHERE table_schema=@db AND table_name IN ('eb_inventory_cross_transfer_document','eb_inventory_cross_transfer_line','eb_inventory_cross_transfer_batch_allocation','eb_inventory_stock_request_fulfillment');
SELECT COUNT(*) INTO @request_supply_columns FROM information_schema.columns WHERE table_schema=@db AND table_name='eb_inventory_stock_request_document' AND column_name IN ('supply_party_type','supply_party_id','supply_party_name_snapshot');
SELECT COUNT(*) INTO @hq_route_menu_count FROM eb_system_menus
WHERE type=1 AND is_del=0 AND unique_auth='inventory-v3-platform-warehouse-manage'
  AND (api_url='product/inventory/v3/hq/inbound' AND methods='POST'
    OR api_url='product/inventory/v3/hq/cross-transfer' AND methods IN ('GET','POST')
    OR api_url='product/inventory/v3/hq/cross-transfer/counterparties' AND methods='GET'
    OR api_url='product/inventory/v3/hq/cross-transfer/incoming-requests' AND methods='GET'
    OR api_url='product/inventory/v3/hq/cross-transfer/<id>/detail' AND methods='GET'
    OR api_url='product/inventory/v3/hq/cross-transfer/<id>/dispatch' AND methods='POST'
    OR api_url='product/inventory/v3/hq/cross-transfer/<id>/receive' AND methods='POST'
    OR api_url='product/inventory/v3/hq/cross-transfer/<id>/cancel' AND methods='POST');
SELECT COUNT(*) INTO @hq_default_count FROM eb_inventory_location WHERE location_type='HQ' AND store_id=0 AND is_default=1 AND location_status='ACTIVE';
SELECT COUNT(*) INTO @bad_hq_default FROM (SELECT tenant_id,owner_id FROM eb_inventory_location WHERE location_type='HQ' AND is_default=1 AND location_status='ACTIVE' GROUP BY tenant_id,owner_id HAVING COUNT(*)<>1) hq;
SELECT COUNT(*) INTO @missing_hq_default
FROM (
  SELECT DISTINCT l.tenant_id,CAST(SUBSTRING_INDEX(TRIM(BOTH '/' FROM l.organization_path),'/',1) AS UNSIGNED) AS root_id
  FROM eb_inventory_location l
  WHERE l.location_type='STORE' AND l.is_default=1 AND l.location_status='ACTIVE'
) roots
LEFT JOIN eb_inventory_location hq
  ON hq.tenant_id=roots.tenant_id AND hq.location_type='HQ' AND hq.owner_id=roots.root_id
 AND hq.store_id=0 AND hq.is_default=1 AND hq.location_status='ACTIVE'
WHERE roots.root_id>0 AND hq.id IS NULL;
SELECT COUNT(*) INTO @invalid_hq_default
FROM eb_inventory_location hq
LEFT JOIN eb_organization root ON root.id=hq.owner_id AND root.pid=0 AND root.status=1 AND root.is_del=0
WHERE hq.location_type='HQ' AND hq.store_id=0 AND hq.is_default=1 AND hq.location_status='ACTIVE'
  AND (root.id IS NULL OR hq.organization_id<>hq.owner_id OR hq.organization_path<>CONCAT('/',hq.owner_id,'/')
       OR hq.location_code<>CONCAT('HQ-',hq.owner_id) OR hq.version<=0);
SELECT COUNT(*) INTO @bad_document FROM eb_inventory_cross_transfer_document WHERE transfer_no='' OR idempotency_key='' OR request_fingerprint='' OR tenant_id='' OR from_party_type NOT IN ('STORE','HQ') OR to_party_type NOT IN ('STORE','HQ') OR (from_party_type='STORE' AND from_party_id=0) OR (from_party_type='HQ' AND from_party_id<>0) OR (to_party_type='STORE' AND to_party_id=0) OR (to_party_type='HQ' AND to_party_id<>0) OR from_location_id=0 OR to_location_id=0 OR from_location_id=to_location_id OR document_status NOT IN ('DRAFT','DISPATCHED','RECEIVED','CANCELLED') OR business_date='0000-00-00';
SELECT COUNT(*) INTO @bad_line FROM eb_inventory_cross_transfer_line WHERE document_id=0 OR line_no=0 OR from_product_id=0 OR from_sku_id=0 OR from_sku_unique='' OR to_product_id=0 OR to_sku_id=0 OR to_sku_unique='' OR requested_quantity_units=0 OR quantity_scale>4;
SELECT COUNT(*) INTO @bad_allocation FROM eb_inventory_cross_transfer_batch_allocation WHERE document_id=0 OR line_id=0 OR from_stock_id=0 OR from_batch_id=0 OR quantity_units=0 OR quantity_scale>4;
SELECT COUNT(*) INTO @bad_allocation_lineage
FROM eb_inventory_cross_transfer_batch_allocation a
LEFT JOIN eb_inventory_cross_transfer_line l ON l.id=a.line_id AND l.document_id=a.document_id
LEFT JOIN eb_inventory_cross_transfer_document d ON d.id=a.document_id
WHERE l.id IS NULL OR d.id IS NULL OR a.quantity_scale<>l.quantity_scale
   OR a.quantity_units>l.requested_quantity_units
   OR a.origin_batch_id=0;
SELECT COUNT(*) INTO @bad_dispatched FROM eb_inventory_cross_transfer_document d LEFT JOIN eb_inventory_cross_transfer_batch_allocation a ON a.document_id=d.id WHERE d.document_status IN ('DISPATCHED','RECEIVED') AND (a.id IS NULL OR a.dispatched_at=0);
SELECT COUNT(*) INTO @bad_receipt FROM eb_inventory_cross_transfer_document d LEFT JOIN eb_inventory_cross_transfer_batch_allocation a ON a.document_id=d.id WHERE d.document_status='RECEIVED' AND (a.to_stock_id=0 OR a.to_batch_id=0 OR a.received_at=0);
SELECT COUNT(*) INTO @bad_draft_or_cancelled_allocation
FROM eb_inventory_cross_transfer_document d
JOIN eb_inventory_cross_transfer_batch_allocation a ON a.document_id=d.id
WHERE d.document_status IN ('DRAFT','CANCELLED');
SELECT COUNT(*) INTO @bad_dispatch_fact
FROM (
  SELECT a.id
  FROM eb_inventory_cross_transfer_batch_allocation a
  JOIN eb_inventory_cross_transfer_document d ON d.id=a.document_id
  LEFT JOIN eb_inventory_batch_movement_fact f
    ON f.tenant_id=d.tenant_id AND f.source_type='cross_transfer_out' AND f.source_id=d.transfer_no
   AND f.source_detail_id=CAST(a.id AS CHAR)
  WHERE d.document_status IN ('DISPATCHED','RECEIVED')
  GROUP BY a.id
  HAVING COUNT(f.id)<>1 OR MAX(f.direction)<>-1 OR MAX(f.stock_id)<>MAX(a.from_stock_id)
      OR MAX(f.batch_id)<>MAX(a.from_batch_id) OR MAX(f.quantity_units)<>MAX(a.quantity_units)
      OR MAX(f.unit_cost_cents)<>MAX(a.unit_cost_cents)
      OR MAX(f.cost_amount_cents)<>MAX(FLOOR(a.quantity_units*a.unit_cost_cents/POW(10,a.quantity_scale)))
) invalid_dispatch_fact;
SELECT COUNT(*) INTO @bad_receipt_fact
FROM (
  SELECT a.id
  FROM eb_inventory_cross_transfer_batch_allocation a
  JOIN eb_inventory_cross_transfer_document d ON d.id=a.document_id
  LEFT JOIN eb_inventory_batch_movement_fact f
    ON f.tenant_id=d.tenant_id AND f.source_type='cross_transfer_in' AND f.source_id=d.transfer_no
   AND f.source_detail_id=CAST(a.id AS CHAR)
  WHERE d.document_status='RECEIVED'
  GROUP BY a.id
  HAVING COUNT(f.id)<>1 OR MAX(f.direction)<>1 OR MAX(f.stock_id)<>MAX(a.to_stock_id)
      OR MAX(f.batch_id)<>MAX(a.to_batch_id) OR MAX(f.quantity_units)<>MAX(a.quantity_units)
      OR MAX(f.unit_cost_cents)<>MAX(a.unit_cost_cents)
      OR MAX(f.cost_amount_cents)<>MAX(FLOOR(a.quantity_units*a.unit_cost_cents/POW(10,a.quantity_scale)))
) invalid_receipt_fact;
SELECT COUNT(*) INTO @premature_receipt_fact
FROM eb_inventory_cross_transfer_batch_allocation a
JOIN eb_inventory_cross_transfer_document d ON d.id=a.document_id
JOIN eb_inventory_batch_movement_fact f
  ON f.tenant_id=d.tenant_id AND f.source_type='cross_transfer_in' AND f.source_id=d.transfer_no
 AND f.source_detail_id=CAST(a.id AS CHAR)
WHERE d.document_status<>'RECEIVED';
SELECT COUNT(*) INTO @bad_fulfillment
FROM (
  SELECT l.id
  FROM eb_inventory_cross_transfer_line l
  JOIN eb_inventory_cross_transfer_document d ON d.id=l.document_id
  LEFT JOIN eb_inventory_stock_request_fulfillment f ON f.transfer_line_id=l.id
  WHERE l.request_line_id>0 AND d.document_status='RECEIVED'
  GROUP BY l.id
  HAVING COUNT(f.id)<>1 OR MAX(f.request_document_id)<>MAX(d.request_document_id)
      OR MAX(f.request_line_id)<>MAX(l.request_line_id) OR MAX(f.transfer_document_id)<>MAX(d.id)
      OR MAX(f.fulfilled_quantity_units)<>MAX(l.requested_quantity_units) OR MAX(f.received_at)=0
) invalid_fulfillment;
SELECT COUNT(*) INTO @premature_fulfillment
FROM eb_inventory_cross_transfer_line l
JOIN eb_inventory_cross_transfer_document d ON d.id=l.document_id
JOIN eb_inventory_stock_request_fulfillment f ON f.transfer_line_id=l.id
WHERE d.document_status<>'RECEIVED';
SET @failures := IF(@table_count=4,0,1)+IF(@request_supply_columns=3,0,1)+IF(@hq_route_menu_count=9,0,1)+IF(@hq_default_count>0,0,1)
  +IF(@bad_hq_default=0,0,1)+IF(@missing_hq_default=0,0,1)+IF(@invalid_hq_default=0,0,1)
  +IF(@bad_document=0,0,1)+IF(@bad_line=0,0,1)+IF(@bad_allocation=0,0,1)+IF(@bad_allocation_lineage=0,0,1)
  +IF(@bad_dispatched=0,0,1)+IF(@bad_receipt=0,0,1)+IF(@bad_draft_or_cancelled_allocation=0,0,1)
  +IF(@bad_dispatch_fact=0,0,1)+IF(@bad_receipt_fact=0,0,1)+IF(@premature_receipt_fact=0,0,1)
  +IF(@bad_fulfillment=0,0,1)+IF(@premature_fulfillment=0,0,1);
SELECT @table_count AS table_count,@request_supply_columns AS request_supply_column_count,@hq_route_menu_count AS hq_route_menu_count,@hq_default_count AS hq_default_count,
  @bad_hq_default AS bad_hq_default_count,@missing_hq_default AS missing_hq_default_count,@invalid_hq_default AS invalid_hq_default_count,
  @bad_document AS bad_document_count,@bad_line AS bad_line_count,@bad_allocation AS bad_allocation_count,@bad_allocation_lineage AS bad_allocation_lineage_count,
  @bad_dispatched AS bad_dispatched_count,@bad_receipt AS bad_receipt_count,@bad_draft_or_cancelled_allocation AS draft_or_cancelled_allocation_count,
  @bad_dispatch_fact AS bad_dispatch_fact_count,@bad_receipt_fact AS bad_receipt_fact_count,@premature_receipt_fact AS premature_receipt_fact_count,
  @bad_fulfillment AS bad_fulfillment_count,@premature_fulfillment AS premature_fulfillment_count,@failures AS verification_failure_count;
SET @finish_sql := IF(@failures=0,'SELECT ''VERIFY_OK'' AS verify_result','SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''cross subject inventory transfer verification failed''');
PREPARE stmt FROM @finish_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
