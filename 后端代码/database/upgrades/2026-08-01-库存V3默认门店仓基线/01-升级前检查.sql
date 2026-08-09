-- upgrade_key: 20260801-003-inventory-v3-default-store-location-baseline
-- Read-only precheck. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @ids_db := DATABASE();

SELECT COUNT(*) INTO @ids_required_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@ids_db
  AND TABLE_NAME IN ('eb_inventory_location','eb_system_store','eb_organization_store','eb_organization')
  AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @ids_unbound_active_stores
FROM eb_system_store s
LEFT JOIN eb_organization_store os ON os.store_id=s.id
WHERE s.is_show=1 AND s.is_del=0 AND (os.org_id IS NULL OR os.org_id=0);

SELECT COUNT(*) INTO @ids_duplicate_defaults
FROM (
  SELECT tenant_id,store_id
  FROM eb_inventory_location
  WHERE location_type='STORE' AND is_default=1 AND location_status='ACTIVE'
  GROUP BY tenant_id,store_id HAVING COUNT(*)>1
) duplicate_defaults;

SELECT COUNT(*) INTO @ids_missing_defaults
FROM eb_system_store s
LEFT JOIN eb_inventory_location l
  ON l.tenant_id='0' AND l.store_id=s.id AND l.location_type='STORE'
 AND l.is_default=1 AND l.location_status='ACTIVE'
WHERE s.is_show=1 AND s.is_del=0 AND l.id IS NULL;

SELECT @ids_required_tables AS required_table_count,
       @ids_unbound_active_stores AS unbound_active_store_count,
       @ids_duplicate_defaults AS duplicate_default_location_count,
       @ids_missing_defaults AS missing_default_location_count;

SET @ids_abort := IF(
  @ids_required_tables=4 AND @ids_unbound_active_stores=0 AND @ids_duplicate_defaults=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''inventory default store location precheck failed'''
);
PREPARE ids_stmt FROM @ids_abort; EXECUTE ids_stmt; DEALLOCATE PREPARE ids_stmt;
