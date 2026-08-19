SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_staff_store_v3_feature_override';

SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_in_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_staff_store_v3_feature_override'
GROUP BY INDEX_NAME, NON_UNIQUE;

SELECT COUNT(*) AS invalid_effect_count
FROM eb_staff_store_v3_feature_override
WHERE effect NOT IN ('inherit','allow','deny');
