# R33 瑞昊库存分组列表发布记录

- 产品经理确认本轮测试无问题，并明确授权本轮 commit、部署瑞昊、push。业务提交：`9c8f5a4b7a164ae71d0f36cd794256b6059ae0b7`（`R33-00002 门店库存单据分组列表闭环`）。仅包含七个本轮文件；提交前后其他任务的暂存补丁指纹均为 `dc31b057aca12a17ee75ed7a86e023bfeba79933849b91605faa54e209c95b46`。
- 构建源：从上述业务提交的 Git archive 单独提取 `前端代码/cashier-v3`、`前端代码/inventory-vue3`、`前端代码/shared`，执行门店端 `npm run build` 通过。旧版 `cf9b2512` 的同源构建与瑞昊部署前 HTML、JS、CSS 字节一致；本次未使用脏工作区或旧 8080 产物。
- 目标实例仅瑞昊。正式目录 `/www/wwwroot/rh.cc3798.com/public/view_cashier_v3/`，预览目录 `/www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3/`。无后端代码、数据库迁移、配置或其他客户实例变更；没有真实资金或库存写入测试。
- 独立备份：`/www/backups/rh.cc3798.com/20261009-r33-grouped-9c8f5a4b.2flT6H/`。正式备份 `cashier-formal-before.tar.gz` SHA-256 `1f1a5d86d3ca4c7b580a3e3c6b750533a7b4dc03b2da9b358342e0f9cc556afd`；预览备份 `cashier-preview-before.tar.gz` SHA-256 `c284f93ae272cb4ae34c67152d0e5b046729459d5245df08eec0308c8f1c3ae5`。两份压缩包均通过 `gzip -t` 和完整 tar 列表检查，内含原始 HTML 与静态资源。
- 发布包 SHA-256 `e9131e49c892591dfbd5eb77dad5b229a4884ee35403900a7a54f08de1963cb3`，上传后与本地一致。正式和预览均先安装新的哈希命名 JS/CSS，再原子替换 HTML，保留原资源。新 HTML SHA-256 `0a85b709b00a96b990e87e2c2b1cad840fcc8c2e633e7c0c7d6d5dfa81585939`；`assets/index-32b328bc.js` SHA-256 `8c4247cbbc142b8e133354ce4e60f007e4b27a804087cfdb727782edbd920ae0`；`assets/index-2b3d7eee.css` SHA-256 `2b3d7eeea38c55683b3a2b5ca00acfd84004d17bfda0c944c74d0f1320ef75e9`。两站磁盘文件和公网 HTTPS 响应均匹配这些指纹且返回 200。
- 上线后只读页面核验：正式入口登录态下，江苏江都店出库本月查询返回 16 条单据；主单号、出库日期等在同一行，下面直接列商品明细，末列“明细备注”，原“实际单位成本”显示为“成本单价”。入库、请货、调拨、院装页面均能打开各自明细表，末列“明细备注”；当前所选门店默认当天均无记录，未宣称它们的线上有数据展开已通过。
- 回滚边界：若尚无更新发布，分别从上述正式、预览备份恢复对应静态目录；不需要数据库回滚。恢复前应再次确认当前线上版本与变更窗口，避免覆盖之后的新发布。
- 远端分支 `release/store-cashier-c14-main` 在业务提交时是 `cf9b2512`。第一次执行 `git push origin HEAD:refs/heads/release/store-cashier-c14-main` 时，并行任务已把本地 HEAD 推进到 `98594e97`（`R47-00001 历史承载与新销售目录隔离`），故远端从 `cf9b2512` 前进到 `98594e97`，包含本轮 `9c8f5a4b` **及并行 R47 提交**。瑞昊本次部署仍严格取 `9c8f5a4b` 的 archive，不含 R47；未回退或改动 R47。
