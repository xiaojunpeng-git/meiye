# R19 新客报表与补交来源：瑞昊发布记录（2026-10-03）

- 授权：产品经理测试固定版本无问题，明确要求 commit、部署瑞昊、push；仅操作 `rh.cc3798.com`，未操作其他客户实例。
- 已验收本地提交：`1cc3270c`（新客明细、汇总口径）和 `a9e5ca3d`（未来补交继承原单来源）。仅分别暂存本轮 8、13 个文件，其他脏工作区改动未纳入。变更的业务注释位置和测试见同目录 R19 自测证据。
- 隔离发布提交：`c63cbb64f664e0870d7cc162a4beddd1eda8298c`，父版为已发布的 `3b565241`，只叠加上述 21 个最终文件 blob；逐文件核对与本地验收提交一致。仅推送 Gitee `origin/release/r19-new-customer-source-20261003`，未推送混合开发分支、GitHub 或其他客户分支。
- 构建源：本机 `18091` 热更新门店端对应 `前端代码/cashier-v3/`，源码提交 `a9e5ca3d`；Vite 构建 1988 modules 成功，仅有既存大 chunk 提示。正式构建 `index.html` SHA-256 `38aafa8fa3af81c08ab30447b7bac305e0073474c6d891cdc8ea9f09cf36e6fa`，主 JS `index-6286cff4.js` SHA-256 `2f3d39399b9af68a85e4e50151564915ed137df13eb80f20eecab61ee1b64ab8`。生成的 `dist/` 未作为源码提交。

## 发布前核对与回滚点

- 瑞昊独立站点 `/www/wwwroot/rh.cc3798.com/` 和独立 8080 预览站存在，磁盘可用约 211 GB；历史来源列升级 `20261003-001-cashier-v3-repayment-source-snapshot` 已登记一次。本次无新结构迁移、无配置修改。
- 上线 36 笔销售欠款补交、0 笔充值欠款补交；其中只有 1 笔可关联到有可靠来源快照的 V3 原销售单，其补交与原单来源已经一致；另 35 笔关联旧销售单，旧单 `source` 全为 `0`，不存在可核实的原单来源。故本次**未改写任何线上历史业务记录**，不猜测来源、不处理本地历史数据。补交主表仍为 36/0，补交收款事实仍为 49 条。
- 独立备份 `/www/backups/rh.cc3798.com/20261003-r19-a9e5ca3d/`：四个旧 PHP 的 `backend-before.tgz` SHA-256 `0cff8a02245569e6f7991dd4ae5ac30ac5d9f7a8f6528b02da877cde5576ac17`；正式门店端 `cashier-formal-before.tgz` 为 `8df1db01e6fcfeb9579686843053f175b67c055d28b4d698f2d9543ae5994ea5`；预览门店端 `cashier-preview-before.tgz` 为 `a87235993f67068ececf8e743d2db8bff18f0043ea1ccd69b896d7ecd0d6795b`。三包 `gzip -t` 均通过。若需回滚，须先确认无后续发布，再从该目录恢复这四个 PHP 和两处门店端静态目录，重启瑞昊 Swoole；不回写其后新增业务事实。

## 部署与验证

- 精确暂存四个后端文件及门店端构建包，服务器 PHP 7.4 语法检查通过；逐一核对旧文件 SHA 后替换四个 PHP，先追加带哈希静态资源、后切换正式和预览入口，重启 Swoole。没有运行全量同步脚本、没有覆盖 `.env`、没有删除旧静态资源。
- 新 PHP SHA-256：报表 `7ca5c7eb7e690443a1d99f8bdc42f940e5a3eb04f1390b82bbcb7593778bd484`；会员欠款投影 `dbf5816f4c62d22eacc55025ba496ea1d1976bc349fa544d19ffa69e97db0ac1`；销售补交 `07fe7a0aab5a8d563d191b5715ae60604cbca3b08d86b55abe65cad537658598`；充值补交 `f9ec26c9cb087a6ece8ec8ba86e23d1b98bfa49e1d525fc1fec1e473685559cc`。正式与预览站磁盘入口 SHA 均为上述构建 HTML；预览公网响应由代理改写资源路径，故响应体 SHA 不等于磁盘 HTML。
- Swoole `manager_count=1`、20800 监听；正式 `/view_cashier_v3/`、预览 `/preview-8080/view_cashier_v3/` 和正式主 JS 均返回 HTTPS 200。正式公网 HTML、主 JS 与构建 SHA 一致；最近日志未发现 `Fatal error`、`Parse error` 或 `Uncaught`。上线后数据库 36/0/49 数量不变。
- 未持生产登录态执行报表下钻或真实收款写入；该业务路径以产品经理对本地固定版本的验收及本轮自动化测试为依据，不把 HTTP 冒烟称为线上业务验收。

文件分组：前端源码、后端源码和自动化测试均见两个本地闭环提交；本次无数据库迁移、工程脚本源码或线上历史数据变更；本文件是发布验收证据。
