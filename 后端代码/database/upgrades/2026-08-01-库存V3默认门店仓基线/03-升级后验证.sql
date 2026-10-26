-- upgrade_key: 20260801-003-inventory-v3-default-store-location-baseline
-- Read-only postcheck. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @ids_failures := 0;

SELECT COUNT(*) INTO @ids_missing_defaults
FROM eb_system_store s
LEFT JOIN eb_inventory_location l
  ON l.tenant_id='0' AND l.store_id=s.id AND l.location_type='STORE'
 AND l.is_default=1 AND l.location_status='ACTIVE'
WHERE s.is_show=1 AND s.is_del=0 AND l.id IS NULL;

SELECT COUNT(*) INTO @ids_duplicate_defaults
FROM (
  SELECT tenant_id,store_id
  FROM eb_inventory_location
  WHERE tenant_id='0' AND location_type='STORE' AND is_default=1 AND location_status='ACTIVE'
  GROUP BY tenant_id,store_id HAVING COUNT(*)>1
) duplicate_defaults;

SELECT COUNT(*) INTO @ids_invalid_defaults
FROM eb_inventory_location l
JOIN eb_system_store s ON s.id=l.store_id
JOIN eb_organization_store os ON os.store_id=s.id
WHERE l.tenant_id='0' AND l.location_type='STORE' AND l.is_default=1 AND l.location_status='ACTIVE'
  AND s.is_show=1 AND s.is_del=0
  AND (l.owner_id<>s.id OR l.organization_id<>os.org_id OR l.location_code<>CONCAT('STORE-',s.id)
       OR l.organization_path='' OR l.version<=0);

SET @ids_failures := IF(@ids_missing_defaults=0,0,1)
                   + IF(@ids_duplicate_defaults=0,0,1)
                   + IF(@ids_invalid_defaults=0,0,1);

SELECT @ids_missing_defaults AS missing_default_location_count,
       @ids_duplicate_defaults AS duplicate_default_location_count,
       @ids_invalid_defaults AS invalid_default_location_count,
       @ids_failures AS postcheck_failure_count;

SET @ids_finish := IF(@ids_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''inventory default store location postcheck failed''');
PREPARE ids_finish_stmt FROM @ids_finish; EXECUTE ids_finish_stmt; DEALLOCATE PREPARE ids_finish_stmt;
