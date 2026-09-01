SET NAMES utf8mb4;

SELECT COUNT(*) AS missing_columns
FROM (
  SELECT 'lifecycle_generation' AS column_name UNION ALL
  SELECT 'source_type' UNION ALL SELECT 'confirmed_at' UNION ALL
  SELECT 'rejected_at' UNION ALL SELECT 'reject_reason' UNION ALL
  SELECT 'actual_service_started_at' UNION ALL SELECT 'actual_service_ended_at' UNION ALL
  SELECT 'member_deleted_at' UNION ALL SELECT 'reservation_form_json' UNION ALL
  SELECT 'reservation_form_title_snapshot' UNION ALL SELECT 'reservation_address_snapshot'
) c
LEFT JOIN information_schema.COLUMNS col
  ON col.TABLE_SCHEMA=DATABASE() AND col.TABLE_NAME='eb_cashier_v3_reservation' AND col.COLUMN_NAME=c.column_name
WHERE col.COLUMN_NAME IS NULL;

SELECT COUNT(*) AS occupation_table_missing
FROM (SELECT 1 AS one) x
WHERE NOT EXISTS (
  SELECT 1 FROM information_schema.TABLES
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_reservation_entitlement_occupation'
);

SELECT COUNT(*) AS invalid_new_generation_rows
FROM eb_cashier_v3_reservation
WHERE lifecycle_generation='RESERVATION_V3_20260901'
  AND (source_type NOT IN ('MEMBER','STORE') OR status NOT IN ('PENDING_CONFIRMATION','UNSTARTED','IN_SERVICE','COMPLETED','CANCELLED','REJECTED'));

SELECT COUNT(*) AS upgrade_log_count
FROM eb_database_upgrade_log
WHERE upgrade_key='20260901-001-reservation-v3-cross-client-lifecycle';

SELECT 'VERIFY_OK' AS verify_result;
