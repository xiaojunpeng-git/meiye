-- upgrade_key: 20260729-013-mobile-auth-canonical-identity
-- MySQL 5.6.51 compatible. Run 01 first and 03 after this script.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_user_phone_identity` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `phone_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `bound_uid` bigint(20) unsigned DEFAULT NULL,
  `state` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'RESERVED',
  `identity_version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `bound_at` int(10) unsigned NOT NULL DEFAULT '0',
  `released_at` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` int(10) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_phone_digest` (`phone_digest`),
  UNIQUE KEY `uk_bound_uid` (`bound_uid`),
  KEY `idx_state_updated` (`state`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Canonical member phone identity';

CREATE TABLE IF NOT EXISTS `eb_user_phone_identity_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `identity_id` bigint(20) unsigned NOT NULL,
  `phone_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `before_uid` bigint(20) unsigned DEFAULT NULL,
  `after_uid` bigint(20) unsigned DEFAULT NULL,
  `before_state` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `after_state` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `before_version` bigint(20) unsigned NOT NULL,
  `after_version` bigint(20) unsigned NOT NULL,
  `action` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operator_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operator_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `source` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_id` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `occurred_at` int(10) unsigned NOT NULL DEFAULT '0',
  `recorded_at` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_identity_time` (`identity_id`,`recorded_at`),
  KEY `idx_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Canonical member phone identity audit';

-- Only valid mobile numbers are seeded. Existing member rows remain untouched.
INSERT IGNORE INTO `eb_user_phone_identity`
  (`phone_digest`,`bound_uid`,`state`,`identity_version`,`bound_at`,`released_at`,`created_at`,`updated_at`)
SELECT SHA2(u.phone,256), IF(u.is_del=0,u.uid,NULL),
  IF(u.is_del=0,'BOUND','RELEASED'), 1,
  IF(u.is_del=0,UNIX_TIMESTAMP(),0), IF(u.is_del=0,0,UNIX_TIMESTAMP()), UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `eb_user` u
INNER JOIN (
  SELECT phone FROM `eb_user`
  WHERE phone REGEXP '^1[3-9][0-9]{9}$'
  GROUP BY phone HAVING COUNT(*)=1
) unique_phone ON unique_phone.phone=u.phone;

INSERT IGNORE INTO `eb_user_phone_identity`
  (`phone_digest`,`bound_uid`,`state`,`identity_version`,`bound_at`,`released_at`,`created_at`,`updated_at`)
SELECT SHA2(duplicate_phone.phone,256), NULL, 'LEGACY_CONFLICT', 1, 0, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM (
  SELECT phone FROM `eb_user`
  WHERE phone REGEXP '^1[3-9][0-9]{9}$'
  GROUP BY phone HAVING COUNT(*)>1
) duplicate_phone;

SELECT 'APPLY_OK' AS apply_result;
