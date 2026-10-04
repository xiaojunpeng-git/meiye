# R22 订单归属与补交来源更正：瑞昊发布记录

- 授权：产品经理已验收第22轮并要求 commit、部署瑞昊、push；后续确认将本轮实际依赖的公用代码一起打包提交。仅操作瑞昊 `rh.cc3798.com` / `ruihao`，未操作 007、008、012。
- 业务提交：`bc841c42e24e1a19be9506892af4061ac8362d7e`（`R22-00002 订单归属与补交来源更正闭环`），基于远端原有 `a0dd16993a3a1bd9abb02f3dd7064c00c1c0489f`。提交含本轮 Vue3 页面、共享调用契约、PHP 服务、迁移、自动化测试及本地验收证据；同文件的旧卡录入、专属服务人多选等未验收改动已从暂存和构建中排除。
- 构建：从该 commit 的 Git 归档构建 `前端代码/cashier-v3/`，并同源带入 `inventory-vue3/`、`shared/unified-query-vue3/` 依赖；Vite 1989 模块构建通过。`index.html` SHA-256 `24644dfb7fb37e0f3cc75ad4bfd6629dff823ad760e23980cc09d483afdc9bfe`；主 JS `index-64bb37a9.js` SHA-256 `eb4ba6b4a9edc7451662087de250404039bf7f8b5725ef6afe5181f9e7beb229`；CSS `index-d9e57cbc.css` SHA-256 `d9e57cbc37ab16797920e03f690baa4a07350c0e2dd4156f1a986778a8928bf2`。未将 `dist` 当作源码提交。
- 基线：发布前瑞昊正式、预览入口的 `index.html` 指纹均为 `24e845d34be13c3cc76c4a53910418313d122c83096a7224c04fe799f8368304`，与父提交的干净构建一致；待替换的 8 个 PHP 文件也逐个与父提交一致。第9个更正服务为新增文件。
- 备份：`/www/backups/rh.cc3798.com/20261004-r22-bc841c42-XJFy12/`。全库 `ruihao-before.sql.gz` SHA-256 `33ed3135186757c82f81af294379e168763a13994da4d55c723cd482ddccef3e`；后端 `backend-before.tar.gz` SHA-256 `d2580984754b6259a77be129ef8a3c560739e7e7a29ecd96fdc38a3fdfe3dd06`；正式与预览前端各一份，SHA-256 均为 `0a509216f8ee0bbf0e8fed18e4ef52eeaa89c3754a6b02a63315c52ed5b9f959`。所有压缩包 `gzip -t` 通过。全库回滚须先保护备份之后的新业务数据，不能直接覆盖正在经营的数据库。
- 数据库：执行 `后端代码/database/upgrades/2026-10-04-订单归属更正/02-正式升级.sql`，SHA-256 `415353fb1a70ab058aae2dbe4a6156b9a5d11cc5cd1aad65fc98f289f5878622`。充值 62 条的 `business_date` 空值 0；变更审计表存在，发布后记录数 0（未在生产做更正写入）；验证 SQL 通过。`eb_database_upgrade_log` 登记 `20261004-001-order-attribution-correction` 与上述完整 commit、SQL 指纹。
- 部署：仅同步 9 个本轮 PHP 文件，远端分发后逐一 `cmp` 相同、PHP 7.4 语法通过；使用目标实例 `/etc/init.d/ruihao_swoole restart`，校验 20800 仅 1 个 manager、16 个 HTTP worker。正式及同实例 8080 预览目录只追加带哈希静态资源、原子替换 `index.html`；两处首页和主资源均与构建指纹一致，未删除旧资源。
- 线上只读验证：正式/预览 HTTPS 首页、正式/预览主 JS、正式登录页均返回 200；预览 HTML 资源路径正确改写至 `/preview-8080/view_cashier_v3/assets/`。正式收银 API 未登录请求返回既有结构化“请登录”响应，无 5xx；Swoole 末段日志未发现 PHP Fatal/Parse/Uncaught。未使用真实生产单据做更正写入；此前六笔本地验收记录保留。预览 20801 后端当前未监听，故不声称预览业务 API 已联调。
- 回滚点：应用文件可从该目录的独立备份恢复；如需数据库回滚，必须先评估发布后的真实交易与迁移兼容，不能直接用全库备份覆盖新增业务记录。该发布仅针对瑞昊。
- 变更分组：前端源码、后端源码、数据库迁移、自动化测试及本地验收证据见业务提交；工程脚本无变更；构建包为生成物，本文件为部署证据。
