-- upgrade_key: 20260814-001-admin-table-column
SELECT table_name,
       table_rows,
       engine,
       table_collation
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'eb_admin_table_column';

SELECT COUNT(*) AS unique_key_exists
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'eb_admin_table_column'
  AND index_name = 'uk_admin_table';
