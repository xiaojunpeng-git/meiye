# R14 仓库设置菜单：瑞昊与渼约008发布记录

- 固定验收提交：`b2a32e2f`（`R14 恢复平台仓库设置导航入口`）；产品经理已确认此版本验收通过并授权两站发布。
- 菜单由各实例 `eb_system_menus.is_show` 和岗位权限决定；平台前端仅移除了旧的强制隐藏逻辑，没有写入客户名或固定显示项。迁移键：`20261013-003-inventory-warehouse-settings-menu`。
- 两站使用同一份从提交版干净源码构建的后台包：`system.html` SHA-256 `eb1d4c0c61371f0ede6086cbf163a5e0a71f1cf83d7aeb2bb56aa3a5838df27b`；`view_admin/js/app.59b94f5d.js` SHA-256 `92e0ed1d67be8755dddd4032686542c3d7f78d2c878d4a65a762ca06eddf9b59`。

| 实例 | 发布前备份 | 迁移与菜单 | 上线检查 |
| --- | --- | --- | --- |
| 瑞昊 `ruihao:3306` | `/www/backups/rh.cc3798.com/r14-menu-b2a32e2f-20261013-185439/`；平台包 SHA-256 `48ace191ed94da42e698edbf1f802297b44ae023b7bcf9ffd44b4b90f353c278`；整库 SQL gzip SHA-256 `ed4232e439bb6a91c5771706fd76477ade83f2a5a935077196b4f7a7179da014` | 菜单 id `2288`，`is_show: 0 -> 1`；升级日志 id `228` | HTTPS 入口和 JS 均 200；浏览器中商品库存导航及仓库设置页可见，已有总部仓 1 条。 |
| 渼约008 `mohe_008:3307` | `/www/backups/008.cc3798.com/r14-menu-b2a32e2f-20261013-185439/`；平台包 SHA-256 `b8a36b0a1bfb80e2e32a2e90e628c77a6e033762de2a91149933edb72e158fe9`；发布前整库 SQL gzip SHA-256 `a2ffe4d472829f5243f66c928e1bec624c1d8725f03fd75020953e62c6106a8d` | 菜单 id `2285`，`is_show: 0 -> 1`；升级日志 id `17` | HTTPS 入口和 JS 均 200；经单独授权配置岗位和初始化后，商品库存导航、仓库列表及新建表单均可用。 |

两站发布前备份均已通过 gzip 完整性检查。共同发布只更新两站平台端静态包及各自菜单显示配置，未改 PHP、其他客户实例，未 push。

008 的菜单显示配置开启后，当前账号 `A13405042786` 的岗位 `18` 仍因缺少菜单 id `2285` 而看不到入口。产品经理随后明确授权该岗位查看和管理仓库（启用账号 1 个）：先补做当前整库备份 `database-before-role18.sql.gz`，gzip 完整性通过，SHA-256 `1a8d1fafe0399ee99bb063d1aec0af5d4a4d89823e1b4fab494a290344956780`；再以事务仅向岗位 `18` 的 `rules` 追加一次 `2285`。规则 SHA-256 从 `ee7707f4860a1a94d544e87d5f4644d86f283de540824615eeb538912a2770bc` 变为 `3f4410424fa052da4cc87e89bf171eeb96c419c81bb80499267e631406d73588`；其他岗位未变。线上刷新后侧栏和顶部均显示仓库设置。

008 当时 `eb_inventory_location`、批次、库存余额和变动事实表均为 0 条，原有新建流程要求先选已存在的总部仓作为组织锚点，按钮因此禁用。产品经理再明确授权在 008 的唯一有效集团根组织下初始化一座空库存的“总部仓”。核对根组织 `id=1`、名称“集团”、状态有效及四张库存表均为空后，事务插入 `eb_inventory_location` 一行：`id=1, tenant_id=0, organization_id=1, organization_path=/1/, location_type=HQ, owner_id=1, location_code=HQ-1, location_name=总部仓, store_id=0, is_default=1, location_status=ACTIVE, version=1`。后检仅此仓库主数据 1 行，批次、库存余额和变动事实仍为 0；线上列表显示 1 条，新建表单可打开并选择“集团”，取消返回，未创建第二座仓库或变动库存。

回滚时先按实例恢复各自 `platform-before.tar.gz` 的 `public/system.html` 和 `public/view_admin/`，再将该实例菜单的 `is_show` 恢复为 `0` 并记录回滚。008 若需撤销岗位授权，仅移除岗位 `18` 的 `2285`；若需撤销初始化仓库，须先确认 `HQ-1` 未产生库存、单据和其他引用，再单独处理，不直接全库覆盖。整库备份仅作灾难恢复依据。线上未执行其他仓库新建、编辑或任何库存写操作。
