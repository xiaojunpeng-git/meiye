-- Read-only checks: all three columns must exist and both violation counts must be zero.
SELECT TABLE_NAME, COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'eb_cashier_v3_checkout_line_draft',
    'eb_cashier_v3_sales_order_line',
    'eb_cashier_v3_sale_fact'
  )
  AND COLUMN_NAME = 'coupon_payment_cents'
ORDER BY TABLE_NAME;

SELECT COUNT(*) AS invalid_sales_line_count
FROM eb_cashier_v3_sales_order_line
WHERE coupon_payment_cents < 0
   OR (coupon_payment_cents > 0 AND coupon_discount_cents > 0)
   OR coupon_payment_cents > sale_amount_cents
   OR debt_amount_cents > sale_amount_cents - coupon_payment_cents;

SELECT COUNT(*) AS invalid_checkout_line_count
FROM eb_cashier_v3_checkout_line_draft
WHERE coupon_payment_cents < 0
   OR (coupon_payment_cents > 0 AND coupon_discount_cents > 0)
   OR coupon_payment_cents > sale_amount_cents
   OR debt_amount_cents > sale_amount_cents - coupon_payment_cents;
