-- 所有旧预约均已赋时长；异常旧时段采用 1 分钟保底。
SELECT COUNT(*) AS missing_duration_count FROM `eb_cashier_v3_reservation`
WHERE service_countdown_duration_minutes = 0;
SELECT MIN(service_countdown_duration_minutes) AS min_minutes,
  MAX(service_countdown_duration_minutes) AS max_minutes
FROM `eb_cashier_v3_reservation` WHERE appointment_end_at > appointment_start_at;
