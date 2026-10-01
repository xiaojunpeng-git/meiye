-- R57: AI-only derived index. Dirty markers are committed with source writes.
-- Migration is per customer database; bootstrap must finish before ready=1.
CREATE TABLE IF NOT EXISTS eb_ai_member_rights_amount_dirty (
  member_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  changed_at BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS eb_ai_member_rights_amount_snapshot (
  tenant_id VARCHAR(32) NOT NULL,
  member_id BIGINT UNSIGNED NOT NULL,
  amount_cents BIGINT UNSIGNED NOT NULL,
  active_card_count INT UNSIGNED NOT NULL,
  remaining_project_times BIGINT UNSIGNED NOT NULL,
  processed_version BIGINT UNSIGNED NOT NULL,
  next_transition_at BIGINT UNSIGNED NOT NULL,
  calculated_at BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (tenant_id, member_id),
  KEY idx_ai_rights_amount (tenant_id, amount_cents, member_id),
  KEY idx_ai_rights_transition (tenant_id, next_transition_at, member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS eb_ai_member_rights_amount_state (
  tenant_id VARCHAR(32) NOT NULL PRIMARY KEY,
  ready TINYINT(1) NOT NULL DEFAULT 0,
  backfilled_at BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELIMITER $$
DROP PROCEDURE IF EXISTS eb_ai_rights_mark_member$$
CREATE PROCEDURE eb_ai_rights_mark_member(IN p_member_id BIGINT UNSIGNED)
MODIFIES SQL DATA
BEGIN
  IF p_member_id > 0 THEN
    INSERT INTO eb_ai_member_rights_amount_dirty(member_id,version,changed_at)
    VALUES(p_member_id,1,UNIX_TIMESTAMP())
    ON DUPLICATE KEY UPDATE version=version+1,changed_at=VALUES(changed_at);
  END IF;
END$$
DROP PROCEDURE IF EXISTS eb_ai_rights_mark_holder$$
CREATE PROCEDURE eb_ai_rights_mark_holder(IN p_holder_id BIGINT UNSIGNED,IN p_member_id BIGINT UNSIGNED)
MODIFIES SQL DATA
BEGIN
  CALL eb_ai_rights_mark_member(p_member_id);
  INSERT INTO eb_ai_member_rights_amount_dirty(member_id,version,changed_at)
  SELECT h.uid,1,UNIX_TIMESTAMP() FROM eb_user_card_holder h
  WHERE h.id=p_holder_id AND h.uid>0
  ON DUPLICATE KEY UPDATE version=version+1,changed_at=VALUES(changed_at);
END$$
DROP PROCEDURE IF EXISTS eb_ai_rights_mark_order$$
CREATE PROCEDURE eb_ai_rights_mark_order(IN p_order_id BIGINT UNSIGNED)
MODIFIES SQL DATA
BEGIN
  -- A cart/order without a card holder cannot affect remaining card rights.
  -- Holder insertion later marks its member in the same business transaction.
  INSERT INTO eb_ai_member_rights_amount_dirty(member_id,version,changed_at)
  SELECT h.uid,1,UNIX_TIMESTAMP() FROM eb_user_card_holder h
  WHERE h.oid=p_order_id AND h.uid>0
  ON DUPLICATE KEY UPDATE version=version+1,changed_at=VALUES(changed_at);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_holder_ai$$
CREATE TRIGGER eb_ai_rights_holder_ai AFTER INSERT ON eb_user_card_holder
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_member(NEW.uid);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_holder_au$$
CREATE TRIGGER eb_ai_rights_holder_au AFTER UPDATE ON eb_user_card_holder
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_member(OLD.uid);
  CALL eb_ai_rights_mark_member(NEW.uid);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_holder_ad$$
CREATE TRIGGER eb_ai_rights_holder_ad AFTER DELETE ON eb_user_card_holder
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_member(OLD.uid);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_cart_ai$$
CREATE TRIGGER eb_ai_rights_cart_ai AFTER INSERT ON eb_store_order_cart_info
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_order(NEW.oid);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_cart_au$$
CREATE TRIGGER eb_ai_rights_cart_au AFTER UPDATE ON eb_store_order_cart_info
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_order(OLD.oid);
  CALL eb_ai_rights_mark_order(NEW.oid);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_cart_ad$$
CREATE TRIGGER eb_ai_rights_cart_ad AFTER DELETE ON eb_store_order_cart_info
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_order(OLD.oid);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_order_ai$$
CREATE TRIGGER eb_ai_rights_order_ai AFTER INSERT ON eb_store_order
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_order(NEW.id);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_order_au$$
CREATE TRIGGER eb_ai_rights_order_au AFTER UPDATE ON eb_store_order
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_order(OLD.id);
  CALL eb_ai_rights_mark_order(NEW.id);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_order_ad$$
CREATE TRIGGER eb_ai_rights_order_ad AFTER DELETE ON eb_store_order
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_order(OLD.id);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_card_state_ai$$
CREATE TRIGGER eb_ai_rights_card_state_ai AFTER INSERT ON eb_cashier_v3_card_state
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_holder(NEW.card_holder_id,NEW.current_member_id);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_card_state_au$$
CREATE TRIGGER eb_ai_rights_card_state_au AFTER UPDATE ON eb_cashier_v3_card_state
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_holder(OLD.card_holder_id,OLD.current_member_id);
  CALL eb_ai_rights_mark_holder(NEW.card_holder_id,NEW.current_member_id);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_card_state_ad$$
CREATE TRIGGER eb_ai_rights_card_state_ad AFTER DELETE ON eb_cashier_v3_card_state
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_holder(OLD.card_holder_id,OLD.current_member_id);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_rule_state_ai$$
CREATE TRIGGER eb_ai_rights_rule_state_ai AFTER INSERT ON eb_cashier_v3_card_rule_state
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_holder(NEW.card_holder_id,NEW.member_id);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_rule_state_au$$
CREATE TRIGGER eb_ai_rights_rule_state_au AFTER UPDATE ON eb_cashier_v3_card_rule_state
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_holder(OLD.card_holder_id,OLD.member_id);
  CALL eb_ai_rights_mark_holder(NEW.card_holder_id,NEW.member_id);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_rule_state_ad$$
CREATE TRIGGER eb_ai_rights_rule_state_ad AFTER DELETE ON eb_cashier_v3_card_rule_state
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_holder(OLD.card_holder_id,OLD.member_id);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_rule_component_ai$$
CREATE TRIGGER eb_ai_rights_rule_component_ai AFTER INSERT ON eb_cashier_v3_card_rule_component
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_holder(NEW.card_holder_id,0);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_rule_component_au$$
CREATE TRIGGER eb_ai_rights_rule_component_au AFTER UPDATE ON eb_cashier_v3_card_rule_component
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_holder(OLD.card_holder_id,0);
  CALL eb_ai_rights_mark_holder(NEW.card_holder_id,0);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_rule_component_ad$$
CREATE TRIGGER eb_ai_rights_rule_component_ad AFTER DELETE ON eb_cashier_v3_card_rule_component
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_holder(OLD.card_holder_id,0);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_receipt_ai$$
CREATE TRIGGER eb_ai_rights_receipt_ai AFTER INSERT ON eb_cashier_v3_card_purchase_receipt
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_holder(NEW.card_holder_id,NEW.member_id);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_receipt_au$$
CREATE TRIGGER eb_ai_rights_receipt_au AFTER UPDATE ON eb_cashier_v3_card_purchase_receipt
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_holder(OLD.card_holder_id,OLD.member_id);
  CALL eb_ai_rights_mark_holder(NEW.card_holder_id,NEW.member_id);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_receipt_ad$$
CREATE TRIGGER eb_ai_rights_receipt_ad AFTER DELETE ON eb_cashier_v3_card_purchase_receipt
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_holder(OLD.card_holder_id,OLD.member_id);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_allocation_ai$$
CREATE TRIGGER eb_ai_rights_allocation_ai AFTER INSERT ON eb_cashier_v3_card_sale_item_allocation_fact
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_member(NEW.member_id);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_allocation_au$$
CREATE TRIGGER eb_ai_rights_allocation_au AFTER UPDATE ON eb_cashier_v3_card_sale_item_allocation_fact
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_member(OLD.member_id);
  CALL eb_ai_rights_mark_member(NEW.member_id);
END$$
DROP TRIGGER IF EXISTS eb_ai_rights_allocation_ad$$
CREATE TRIGGER eb_ai_rights_allocation_ad AFTER DELETE ON eb_cashier_v3_card_sale_item_allocation_fact
FOR EACH ROW
BEGIN
  CALL eb_ai_rights_mark_member(OLD.member_id);
END$$
DELIMITER ;
