-- upgrade_key: 20261013-001-group-dashboard-registered-metric-version
-- Existing rows remain tagged legacy and cannot be read as canonical metrics.
ALTER TABLE `eb_cashier_v3_group_dashboard_daily_aggregate`
  ADD COLUMN `metric_version` varchar(64) CHARACTER SET ascii COLLATE ascii_bin
    NOT NULL DEFAULT 'legacy' COMMENT 'registered metric formula version'
    AFTER `consumption_performance_cents`;
