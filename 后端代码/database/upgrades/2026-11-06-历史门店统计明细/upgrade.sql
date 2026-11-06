-- 封存历史报表读模型。只保存可重建、可追溯的原单/分配/项目明细，不回写收银业务表。
CREATE TABLE IF NOT EXISTS eb_report_history_batch (
 batch_id varchar(64) NOT NULL PRIMARY KEY,
 report_code varchar(64) NOT NULL,
 policy varchar(64) NOT NULL,
 period_start date NOT NULL,
 period_end date NOT NULL,
 source_archive_sha256 char(64) NOT NULL,
 payload_sha256 char(64) NOT NULL,
 source_schema varchar(128) NOT NULL,
 status varchar(16) NOT NULL,
 counts_json text NOT NULL,
 recorded_at bigint NOT NULL,
 KEY report_period (report_code,status,period_start,period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='经批准历史统计范围及封存来源';

CREATE TABLE IF NOT EXISTS eb_report_history_orders (
 batch_id varchar(64) NOT NULL,
 source_id bigint NOT NULL,
 source_no varchar(128) NOT NULL,
 store_id int NOT NULL,
 member_id bigint NOT NULL,
 business_date date NOT NULL,
 occurred_at bigint NOT NULL,
 friend_service tinyint NOT NULL,
 cash_amount_cents bigint NOT NULL,
 cooperation_cash_cents bigint NOT NULL,
 consumption_amount_cents bigint NOT NULL,
 inside_consumption_amount_cents bigint NOT NULL,
 service_customer tinyint NOT NULL,
 new_customer tinyint NOT NULL,
 casual_customer tinyint NOT NULL,
 source_hash char(64) NOT NULL,
 source_json mediumtext NOT NULL,
 PRIMARY KEY (batch_id,source_id),
 KEY report_scope (batch_id,store_id,business_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='封存原单收款消耗与客数资格明细';

CREATE TABLE IF NOT EXISTS eb_report_history_staff (
 batch_id varchar(64) NOT NULL,
 source_id bigint NOT NULL,
 order_id bigint NOT NULL,
 link_id bigint NOT NULL,
 staff_id bigint NOT NULL,
 store_id int NOT NULL,
 member_id bigint NOT NULL,
 business_date date NOT NULL,
 occurred_at bigint NOT NULL,
 friend_service tinyint NOT NULL,
 partner_amount_cents bigint NOT NULL,
 designated_customer tinyint NOT NULL,
 source_hash char(64) NOT NULL,
 source_json mediumtext NOT NULL,
 PRIMARY KEY (batch_id,source_id),
 KEY report_scope (batch_id,store_id,business_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='封存分成与指定劳动分配明细';

CREATE TABLE IF NOT EXISTS eb_report_history_quantities (
 batch_id varchar(64) NOT NULL,
 source_id bigint NOT NULL,
 fact_kind varchar(16) NOT NULL,
 order_id bigint NOT NULL,
 product_id bigint NOT NULL,
 store_id int NOT NULL,
 business_date date NOT NULL,
 occurred_at bigint NOT NULL,
 quantity decimal(20,6) NOT NULL,
 source_hash char(64) NOT NULL,
 source_json mediumtext NOT NULL,
 PRIMARY KEY (batch_id,fact_kind,source_id),
 KEY report_scope (batch_id,store_id,business_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='封存销售项目与核销项目数量明细';
