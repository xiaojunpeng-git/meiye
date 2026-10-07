-- The AI rights snapshot is derived, not an authority. A cashier projection
-- change invalidates all previously calculated amounts even when no source
-- row changed. Existing instances stay on the live reader until rebuilt.
ALTER TABLE eb_ai_member_rights_amount_state
  ADD COLUMN projection_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER ready;
