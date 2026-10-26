-- upgrade_key: 20260805-009-store-room-name-encoding-repair
-- Read-only postcheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @room_name_repair_postcheck_failures := 0;

SELECT id, store_id, table_number, remarks, HEX(remarks) AS remarks_hex
FROM eb_table_qrcode
WHERE id IN (9, 10)
ORDER BY id;

SELECT COUNT(*) INTO @room_name_repair_fixed
FROM eb_table_qrcode
WHERE (id=9 AND store_id=118 AND table_number=9001 AND HEX(remarks)='5141323032363038303420E9AA8CE694B6E688BFE997B441')
   OR (id=10 AND store_id=118 AND table_number=9002 AND HEX(remarks)='5141323032363038303420E9AA8CE694B6E688BFE997B442');

SELECT COUNT(*) INTO @room_name_repair_remaining_mojibake
FROM eb_table_qrcode
WHERE (id=9 AND HEX(remarks)='5141323032363038303420C3A9C2AAC592C3A6E2809DC2B6C3A6CB86C2BFC3A9E28094C2B441')
   OR (id=10 AND HEX(remarks)='5141323032363038303420C3A9C2AAC592C3A6E2809DC2B6C3A6CB86C2BFC3A9E28094C2B442');

SET @room_name_repair_postcheck_failures := @room_name_repair_postcheck_failures
  + IF(@room_name_repair_fixed=2,0,1)
  + IF(@room_name_repair_remaining_mojibake=0,0,1);

SELECT @room_name_repair_fixed AS fixed_target_count,
  @room_name_repair_remaining_mojibake AS remaining_mojibake_count,
  @room_name_repair_postcheck_failures AS postcheck_failure_count;

SET @room_name_repair_finish_sql := IF(
  @room_name_repair_postcheck_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_STORE_ROOM_NAME_ENCODING_REPAIR_POSTCHECK_FAILED'
);
PREPARE room_name_repair_statement FROM @room_name_repair_finish_sql;
EXECUTE room_name_repair_statement;
DEALLOCATE PREPARE room_name_repair_statement;
