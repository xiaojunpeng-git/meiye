SET NAMES utf8mb4;

CREATE TABLE `eb_cashier_v3_checkout_request` (
  `request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `organization_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint unsigned NOT NULL,
  `member_id` bigint unsigned NOT NULL DEFAULT 0,
  `operator_id` bigint unsigned NOT NULL,
  `business_date` date NOT NULL,
  `business_timezone` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `eb_cashier_v3_checkout_line_draft` (
  `line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`line_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `eb_cashier_v3_checkout_payment_draft` (
  `payment_draft_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`payment_draft_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `eb_cashier_v3_business_event` (
  `event_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_type` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `aggregate_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `aggregate_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_type` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `organization_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint unsigned NOT NULL,
  `member_id` bigint unsigned NOT NULL DEFAULT 0,
  `operator_id` bigint unsigned NOT NULL,
  `business_date` date NOT NULL,
  `occurred_at` bigint unsigned NOT NULL,
  `settled_at` bigint unsigned NOT NULL,
  `recorded_at` bigint unsigned NOT NULL,
  PRIMARY KEY (`event_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `eb_employee` (
  `id` bigint unsigned NOT NULL,
  `employment_type_code` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `employment_type_version` bigint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
