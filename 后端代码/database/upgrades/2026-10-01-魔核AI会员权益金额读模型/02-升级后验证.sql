SELECT DATABASE() AS target_database, VERSION() AS mysql_version;
SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (
  'eb_ai_member_rights_amount_dirty',
  'eb_ai_member_rights_amount_snapshot',
  'eb_ai_member_rights_amount_state'
);
SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME LIKE 'eb_ai_rights_%'
ORDER BY TRIGGER_NAME;
SELECT tenant_id,ready,backfilled_at FROM eb_ai_member_rights_amount_state;
SET @ai_tenant := (SELECT tenant_id FROM eb_ai_member_rights_amount_state WHERE ready=1 LIMIT 1);
SELECT tenant_id,COUNT(*) AS snapshot_members,
  SUM(amount_cents>5000000) AS above_50000_members
FROM eb_ai_member_rights_amount_snapshot GROUP BY tenant_id;
SELECT COUNT(*) AS unprocessed_members FROM eb_ai_member_rights_amount_dirty d
LEFT JOIN eb_ai_member_rights_amount_snapshot s
  ON s.member_id=d.member_id AND s.tenant_id=@ai_tenant
WHERE s.member_id IS NULL OR s.processed_version<>d.version;
