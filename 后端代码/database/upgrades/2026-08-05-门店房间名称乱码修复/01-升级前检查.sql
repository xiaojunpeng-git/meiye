-- upgrade_key: 20260805-009-store-room-name-encoding-repair
-- Read-only. This repair is intentionally pinned to the confirmed QA rows.
SET NAMES utf8mb4;
SET @room_name_repair_failures := 0;

SELECT id, store_id, table_number, remarks, HEX(remarks) AS remarks_hex
FROM eb_table_qrcode
WHERE id IN (9, 10)
ORDER BY id;

SELECT COUNT(*) INTO @room_name_repair_targets
FROM eb_table_qrcode
WHERE (id=9 AND store_id=118 AND table_number=9001 AND HEX(remarks)='5141323032363038303420C3A9C2AAC592C3A6E2809DC2B6C3A6CB86C2BFC3A9E28094C2B441')
   OR (id=10 AND store_id=118 AND table_number=9002 AND HEX(remarks)='5141323032363038303420C3A9C2AAC592C3A6E2809DC2B6C3A6CB86C2BFC3A9E28094C2B442');

SELECT COUNT(*) INTO @room_name_repair_unexpected
FROM eb_table_qrcode
WHERE id IN (9, 10)
  AND NOT (
    (id=9 AND store_id=118 AND table_number=9001 AND HEX(remarks)='5141323032363038303420C3A9C2AAC592C3A6E2809DC2B6C3A6CB86C2BFC3A9E28094C2B441')
    OR (id=10 AND store_id=118 AND table_number=9002 AND HEX(remarks)='5141323032363038303420C3A9C2AAC592C3A6E2809DC2B6C3A6CB86C2BFC3A9E28094C2B442')
  );

SET @room_name_repair_failures := @room_name_repair_failures
  + IF(@room_name_repair_targets=2,0,1)
  + IF(@room_name_repair_unexpected=0,0,1);

SELECT @room_name_repair_targets AS expected_mojibake_target_count,
  @room_name_repair_unexpected AS unexpected_target_count,
  @room_name_repair_failures AS precheck_failure_count;

SET @room_name_repair_finish_sql := IF(
  @room_name_repair_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_STORE_ROOM_NAME_ENCODING_REPAIR_PRECHECK_FAILED'
);
PREPARE room_name_repair_statement FROM @room_name_repair_finish_sql;
EXECUTE room_name_repair_statement;
DEALLOCATE PREPARE room_name_repair_statement;
