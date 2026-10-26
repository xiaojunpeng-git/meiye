# R46 集团管理看板瑞昊发布记录

- 固定代码提交：`fc2f153d`（`R46-00002 集团管理看板查询与明细优化闭环`）。仅发布瑞昊实例 `rh.cc3798.com`，独立数据库 `ruihao`；其他客户实例未操作。
- 本地回归：`group-dashboard-stability-contract.php`、`group-dashboard-refresh-contract.mjs` 均 PASS；容器内只读集成回归 PASS，102 家门店，实际业绩 200,614,600 分，月目标 1,020,000,000 分，响应约 10.278 秒，后端越权请求被拒绝。
- 生产库升级前确认 `metric_version` 不存在；在瑞昊独立服务器完整备份 `ruihao`，gzip 完整性通过，备份 SHA-256 `7f4e6c0824749a9d61226d1e61ab8dfdf49831c627c8bbd815bd84c3ce96f4a0`。备份目录：`/root/rh-deploy-backups/R46-20261013-fc2f153d/`，仅 root 可访问。
- 已执行 `2026-10-13-集团看板日汇总指标版本/01-正式升级.sql`；升级后列为 `varchar(64) NOT NULL DEFAULT 'legacy'`，核对时 103/103 行均为 `legacy`。没有把离线汇总接入页面，也没有重建或改写旧行。读模型行数在备份前后有并发增长，属线上业务写入。
- 运行文件备份 `runtime-before.tgz` 含四个后端服务和正式/预览两处静态入口及其原引用资源，共 10 项；SHA-256 `3c3a91682f63ab664228dc92a4584706fd13ccf88d871ef53323c7e36812bf31`。回滚时先恢复对应后端文件与两处 `index.html`，再重启瑞昊 Swoole；数据库新增列无需在紧急回滚时删除，旧代码可忽略它。
- 只替换 `GroupManagementDashboardServices.php`、`GroupManagementDashboardDailyAggregateServices.php`、`StoreUnifiedReportOrganizationDimensionServices.php`、`TargetSchemeReportServices.php`，线上 SHA-256 分别为 `61f2c7f09f2ee91d29e60e2deaf2f62839592d84f0126a126936adc10279f84e`、`941674c8eb4ea28733b58292834797e5b3431f65fafdf7bbfb644f49825d4976`、`96db9e12bd10724477888deccde94c97f3ca1e86306eeb8ab2c423aeb5ee59cd`、`d4fa43fbd0ee40bd4003af076cfe8ebbc34d1f067e1f9a38fb663b14854bdf30`。瑞昊 Swoole 已重启且状态正常。
- 前端构建源为固定提交 `fc2f153d` 的 `cashier-v3`、`inventory-vue3`、`shared`，仅在一次性构建目录保留上一轮已上线但仍未并入该提交的“导入历史欠款补交不计有效人次”说明。未把当前工作区其他未提交的库存、员工看板等代码带入构建。候选包 SHA-256 `00f6b12e0312eb9797eabd446e646513addabc48507e143a2e803b0d69050a49`。
- 瑞昊正式及预览门店端 `index.html` 均为 SHA-256 `f20f392d5ef24a1fcd8f8086f2cefb5168b58d8ec7c1c1ac9fa11cee7124a608`，新 JS `index-c78cb37d.js` 与 CSS `index-274d58d4.css` 均已按哈希上传；旧资源未删除。
- 线上只读验证：正式集团看板已显示实时经营数据、102 家门店每店 10 万元的本月目标；无顶部“目标管理”按钮。实际业绩明细弹窗展示日期、商品分类条件及业务日期/门店/商品或项目/金额表格。嵌入文档 `scrollWidth=clientWidth=1423`，未产生多余水平滚动。正式静态入口 HTTP 200，Swoole `manager_count=1`。
- 本轮未操作瑞昊之外的实例；未执行离线读模型重建。其他任务原有暂存及未提交文件保持不变。
