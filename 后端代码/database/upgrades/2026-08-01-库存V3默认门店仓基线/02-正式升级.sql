-- upgrade_key: 20260801-003-inventory-v3-default-store-location-baseline
-- MySQL 5.6.51 compatible. Replay-safe location master-data migration.
SET NAMES utf8mb4;
SET @ids_now := UNIX_TIMESTAMP();

DROP TEMPORARY TABLE IF EXISTS tmp_inventory_default_store_location;
CREATE TEMPORARY TABLE tmp_inventory_default_store_location (
  store_id bigint(20) unsigned NOT NULL,
  store_name varchar(100) NOT NULL,
  organization_id bigint(20) unsigned NOT NULL,
  organization_name varchar(64) NOT NULL,
  organization_path varchar(191) NOT NULL,
  current_org_id bigint(20) unsigned NOT NULL,
  depth smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (store_id)
) ENGINE=InnoDB;

INSERT INTO tmp_inventory_default_store_location
  (store_id,store_name,organization_id,organization_name,organization_path,current_org_id,depth)
SELECT s.id,s.name,o.id,o.name,CONCAT('/',o.id,'/'),o.pid,0
FROM eb_system_store s
INNER JOIN eb_organization_store os ON os.store_id=s.id
INNER JOIN eb_organization o ON o.id=os.org_id AND o.is_del=0
WHERE s.is_show=1 AND s.is_del=0;

DROP PROCEDURE IF EXISTS inventory_v3_default_location_path;
DELIMITER $$
CREATE PROCEDURE inventory_v3_default_location_path()
BEGIN
  DECLARE step_count INT DEFAULT 0;
  WHILE step_count < 64 AND EXISTS(SELECT 1 FROM tmp_inventory_default_store_location WHERE current_org_id<>0) DO
    UPDATE tmp_inventory_default_store_location target
    INNER JOIN eb_organization parent ON parent.id=target.current_org_id AND parent.is_del=0
    SET target.organization_path=CONCAT('/',parent.id,SUBSTRING(target.organization_path,1)),
        target.current_org_id=parent.pid,
        target.depth=target.depth+1
    WHERE target.current_org_id<>0;
    SET step_count=step_count+1;
  END WHILE;
END$$
DELIMITER ;
CALL inventory_v3_default_location_path();
DROP PROCEDURE inventory_v3_default_location_path;

SET @ids_unresolved_paths := (SELECT COUNT(*) FROM tmp_inventory_default_store_location WHERE current_org_id<>0 OR depth>=64);
SET @ids_path_abort := IF(@ids_unresolved_paths=0,
  'SELECT ''PATHS_OK'' AS path_result',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''inventory default location organization path unresolved''');
PREPARE ids_path_stmt FROM @ids_path_abort; EXECUTE ids_path_stmt; DEALLOCATE PREPARE ids_path_stmt;

START TRANSACTION;

INSERT INTO eb_inventory_location
  (tenant_id,organization_id,organization_path,organization_name_snapshot,location_type,
   owner_id,location_code,location_name,store_id,store_name_snapshot,is_default,
   location_status,version,created_at,updated_at)
SELECT '0',target.organization_id,target.organization_path,target.organization_name,'STORE',
       target.store_id,CONCAT('STORE-',target.store_id),'默认门店仓',target.store_id,target.store_name,1,
       'ACTIVE',1,@ids_now,@ids_now
FROM tmp_inventory_default_store_location target
LEFT JOIN eb_inventory_location current_location
  ON current_location.tenant_id='0' AND current_location.store_id=target.store_id
 AND current_location.location_type='STORE' AND current_location.is_default=1
 AND current_location.location_status='ACTIVE'
WHERE current_location.id IS NULL;

COMMIT;

SELECT COUNT(*) AS generated_default_location_target_count
FROM tmp_inventory_default_store_location;
DROP TEMPORARY TABLE IF EXISTS tmp_inventory_default_store_location;
SELECT 'APPLY_OK' AS apply_result;
