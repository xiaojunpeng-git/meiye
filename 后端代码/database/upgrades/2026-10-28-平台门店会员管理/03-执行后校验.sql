-- 不输出密码或微信标识，只验证回执结构。
SHOW INDEX FROM eb_member_management_receipt;
SELECT actor_type,COUNT(*) AS receipt_count FROM eb_member_management_receipt GROUP BY actor_type;
