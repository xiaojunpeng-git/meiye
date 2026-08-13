-- upgrade_key: 20260814-001-admin-table-column
SELECT DATABASE() AS db_name,
       COUNT(*) AS table_exists
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'eb_admin_table_column';
