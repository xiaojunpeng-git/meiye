-- 商品服务时长（分钟）
ALTER TABLE `eb_store_product`
  ADD COLUMN `project_service_duration` int(11) NOT NULL DEFAULT 0 COMMENT '项目服务时长（分钟）' AFTER `reservation_time_interval`,
  ADD COLUMN `addon_service_duration` int(11) NOT NULL DEFAULT 0 COMMENT '增项服务时长（分钟）' AFTER `project_service_duration`;

-- 预约单记录服务时长与加项
ALTER TABLE `eb_store_reservation_order`
  ADD COLUMN `service_duration_minutes` int(11) NOT NULL DEFAULT 0 COMMENT '服务总时长（分钟）' AFTER `reservation_end`,
  ADD COLUMN `addon_items` text NULL COMMENT '加项服务JSON' AFTER `service_duration_minutes`;
