SELECT DATABASE() AS target_database;
SELECT COUNT(*) AS existing_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_cashier_v3_refund_attribution';
SELECT COUNT(*) AS refund_adjustment_ready
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_cashier_v3_refund_adjustment';
