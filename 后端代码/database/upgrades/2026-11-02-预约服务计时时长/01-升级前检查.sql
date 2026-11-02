-- 迁移前核对预约总数及原时段异常数量；执行升级必须逐实例另行授权。
SELECT COUNT(*) AS reservation_count,
  SUM(CASE WHEN appointment_end_at <= appointment_start_at THEN 1 ELSE 0 END) AS invalid_schedule_count
FROM `eb_cashier_v3_reservation`;
