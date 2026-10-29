-- 会员调查。只在目标环境执行；不修改既有业务表。
CREATE TABLE IF NOT EXISTS `eb_member_survey_questionnaire` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `description` varchar(500) NOT NULL DEFAULT '',
  `questions` mediumtext NOT NULL,
  `revision` int unsigned NOT NULL DEFAULT 1,
  `creator_id` int unsigned NOT NULL DEFAULT 0,
  `created_at` int unsigned NOT NULL,
  `updated_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员调查问卷库';

CREATE TABLE IF NOT EXISTS `eb_member_survey_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `questionnaire_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(150) NOT NULL,
  `description` varchar(500) NOT NULL DEFAULT '',
  `questions` mediumtext NOT NULL,
  `filters` text NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'draft',
  `deadline` int unsigned NOT NULL DEFAULT 0,
  `creator_id` int unsigned NOT NULL DEFAULT 0,
  `created_at` int unsigned NOT NULL,
  `published_at` int unsigned NOT NULL DEFAULT 0,
  `send_round` int unsigned NOT NULL DEFAULT 1,
  `sent_at` int unsigned NOT NULL DEFAULT 0,
  `stopped_at` int unsigned NOT NULL DEFAULT 0,
  `entry_token` varchar(64) DEFAULT NULL,
  UNIQUE KEY `uq_survey_entry_token` (`entry_token`),
  PRIMARY KEY (`id`),
  KEY `idx_questionnaire_id` (`questionnaire_id`),
  KEY `idx_status_deadline` (`status`,`deadline`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员调查任务';

CREATE TABLE IF NOT EXISTS `eb_member_survey_target` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `task_id` int unsigned NOT NULL,
  `uid` int unsigned NOT NULL,
  `cash_amount` decimal(14,2) NOT NULL DEFAULT 0,
  `visit_count` int unsigned NOT NULL DEFAULT 0,
  `source` varchar(16) NOT NULL DEFAULT 'filter',
  `submitted_at` int unsigned NOT NULL DEFAULT 0,
  `completed_round` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_task_uid` (`task_id`,`uid`),
  KEY `idx_uid_submitted` (`uid`,`submitted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员调查目标名单';

CREATE TABLE IF NOT EXISTS `eb_member_survey_answer` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `task_id` int unsigned NOT NULL,
  `uid` int unsigned NOT NULL,
  `answers` mediumtext NOT NULL,
  `send_round` int unsigned NOT NULL DEFAULT 1,
  `submitted_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_task_uid_round` (`task_id`,`uid`,`send_round`),
  KEY `idx_task_time` (`task_id`,`submitted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员调查回答';

CREATE TABLE IF NOT EXISTS `eb_member_survey_popup` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uid` int unsigned NOT NULL,
  `task_id` int unsigned NOT NULL,
  `show_date` date NOT NULL,
  `send_round` int unsigned NOT NULL DEFAULT 1,
  `shown_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_uid_day_task_round` (`uid`,`show_date`,`task_id`,`send_round`),
  KEY `idx_task` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员每日问卷弹窗记录';

-- 第12轮：二维码是任务公开入口；名单只负责提醒。任务发布后固定奖励门店与内容。
CREATE TABLE IF NOT EXISTS eb_member_survey_reward_config (
 id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, gift_type tinyint unsigned NOT NULL, relation_id int unsigned NOT NULL, config mediumtext NOT NULL, created_at int unsigned NOT NULL, updated_at int unsigned NOT NULL, UNIQUE KEY uq_survey_reward_config(gift_type,relation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- 每份答卷只产生一次应发记录；赠送权益的权威仍是010赠送服务，本表只追踪履约状态。
CREATE TABLE IF NOT EXISTS eb_member_survey_reward (
 id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, task_id int unsigned NOT NULL, uid int unsigned NOT NULL, answer_id bigint unsigned NOT NULL, reward_round int unsigned NOT NULL,
 reward_key varchar(64) NOT NULL, config_snapshot mediumtext NOT NULL, status varchar(16) NOT NULL DEFAULT 'pending', gift_id varchar(64) NOT NULL DEFAULT '',
 attempts int unsigned NOT NULL DEFAULT 0, last_error varchar(500) NOT NULL DEFAULT '', created_at int unsigned NOT NULL, updated_at int unsigned NOT NULL,
 UNIQUE KEY uq_survey_reward_key(reward_key), UNIQUE KEY uq_survey_answer(answer_id), KEY ix_survey_reward_task(task_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
