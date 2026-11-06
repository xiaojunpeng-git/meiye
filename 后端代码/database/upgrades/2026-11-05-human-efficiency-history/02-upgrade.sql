-- Authoritative, explicitly evidenced manager/store periods; never backfill from today's hierarchy.
CREATE TABLE IF NOT EXISTS eb_human_efficiency_management_period (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 tenant_id VARCHAR(32) NOT NULL,
 store_id BIGINT UNSIGNED NOT NULL,
 manager_organization_id VARCHAR(32) NOT NULL,
 manager_name_snapshot VARCHAR(128) NOT NULL,
 valid_from DATE NOT NULL,
 valid_to DATE NOT NULL,
 evidence_reference VARCHAR(255) NOT NULL,
 verified_at BIGINT UNSIGNED NOT NULL,
 verified_by BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_evidence_period(tenant_id,store_id,valid_from,valid_to),
 KEY ix_scope_date(tenant_id,store_id,valid_from,valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Verified historical management periods for human efficiency';
