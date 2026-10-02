-- 应有三个已登记的仓库管理 API；不新增角色授权。
SELECT api_url,methods,unique_auth,COUNT(*) AS registrations FROM eb_system_menus
WHERE api_url IN ('product/inventory/v3/hq/count/draft','product/inventory/v3/hq/count/draft/:id','product/inventory/v3/hq/count/draft/:id/delete')
AND type=1 AND auth_type=2 AND is_del=0
GROUP BY api_url,methods,unique_auth;
