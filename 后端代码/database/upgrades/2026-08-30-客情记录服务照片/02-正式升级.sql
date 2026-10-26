-- upgrade_key: 20260830-001-customer-care-service-photos
-- MySQL 5.6 compatible. Run only after 01 returns PRECHECK_OK.
SET NAMES utf8mb4;
ALTER TABLE `eb_customer_care_record`
  ADD COLUMN `service_before_photos` text NOT NULL AFTER `detail`,
  ADD COLUMN `service_after_photos` text NOT NULL AFTER `service_before_photos`;
