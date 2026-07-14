-- 预约单手艺人（添加/修改预约时暂存，开始服务后再写入 eb_staff_yeji）
ALTER TABLE `eb_store_reservation_order`
  ADD COLUMN `staff_choose` text NULL COMMENT '手艺人JSON' AFTER `service_staff_id`;
