-- upgrade_key: 20260814-001-cashier-v3-manual-labor-fee-override
SET @db := DATABASE();
SET @workspace_column := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_workspace_line' AND COLUMN_NAME='manual_labor_fee_cents');
SET @checkout_column := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_checkout_line_draft' AND COLUMN_NAME='manual_labor_fee_cents');
SET @sales_order_column := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_sales_order_line' AND COLUMN_NAME='manual_labor_fee_cents');
SET @service_amount_column := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact' AND COLUMN_NAME='labor_amount_cents');
SET @service_mode_column := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact' AND COLUMN_NAME='labor_mode');
SELECT @workspace_column AS workspace_column_count, @checkout_column AS checkout_column_count, @sales_order_column AS sales_order_column_count, @service_amount_column AS service_labor_amount_count, @service_mode_column AS service_labor_mode_count;
SELECT CASE WHEN @workspace_column=1 AND @checkout_column=1 AND @sales_order_column=1 AND @service_amount_column=1 AND @service_mode_column=1 THEN 'POSTCHECK_OK' ELSE 'POSTCHECK_FAILURE' END AS postcheck_result;
