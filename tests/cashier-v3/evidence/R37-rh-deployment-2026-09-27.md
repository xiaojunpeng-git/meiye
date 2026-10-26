# R37 瑞昊发布记录

- 产品经理明确授权“Commit,部署瑞昊,push”，并以“q确认”确认包含前述 R37 配套后端。固定业务版本 `9da87326`，最新提交为查询栏紧凑样式与商品类型前置；之前 R37 查询、权益订单、跨店升级等依赖一并发布。未提交工作区改动均排除。
- 源码：18091 对应 `前端代码/cashier-v3` 及共享工程；Docker 原挂载执行 npm build 成功，1977 modules。无新增迁移、配置或依赖版本变更。
- 自测：r37-unified-query-preferences、r37-sales-query-column-parity、date-range-contract 通过；本地真实只读 PHP 回归 130 字段、销售人/手艺人、组合条件、分页、权限、导出口径通过。远端 24 个 PHP 文件语法检查通过。
- 目标仅瑞昊，数据库只读核实为 `ruihao`，迁移保持 `20260924-001-cashier-v3-checkout-reservation-service-link-v1` / `302f841c`。本次未执行 SQL、未变更生产业务记录。
- 后端24文件取自 Git 固定版本，清单及校验保存在服务器 `/www/backups/rh.cc3798.com/20260927-r37-9da87326/backend-files.txt` 和 `SHA256SUMS.backend`；发布后全部校验一致。
- 回滚目录同上。backend-before.tar.gz SHA256 `c78d1e9ab7908abd56ac236ad389644c3eadda7c7432b66ddbb8dafda4f35352`；cashier-main-before.tar.gz `a7e6e8908179518e90d751b02dd0b04c69a6033adb5d3ab07364f064bff3d3e2`；cashier-preview-before.tar.gz `3f53ef8a8b45d06d4f070aa24000df1994f2bb8f850995d68996b34c5d4019fd`。恢复这些目标文件并按 new-files.txt 移除本批新增文件后重启瑞昊 Swoole 即可回滚；未备份或修改数据库。
- 前端更新正式及 `rh.cc3798.com-8080` 的 public/view_cashier_v3，先资源后 HTML，保留旧资源。Swoole 重启正常，manager_count=1，PID17238。
- 公网正式及 `/preview-8080/view_cashier_v3/` 均可访问，新 JS/CSS 下载字节与本地完全一致：JS index-39b11901.js SHA256 `0511d0492d646c1f7caa6ac11d974e9e6c03db597a0e2ed8d526b76b7157f70c`；CSS index-e9ceb07b.css SHA256 `e9ceb07bb5dd36deb5a72e1b2f574b0cb65192ba31401dd29c118b4c75ee9427`。两个服务器 HTML 指纹 `d255c4a900b1435b74e8f176988e08a896d94e5c4a4e0cbc47086c5f009b353d`；preview 公网 HTML 经既有 Nginx 前缀替换，非字节一致。不存在本机8080监听，实际预览入口是443上的preview路径。
- 浏览器新版登录页加载正常，当前无门店登录会话，未冒称线上订单交互、收银、作废或人员修改业务验收；未做生产写入测试。
- 推送使用独立 `release/r37-20260927`，从 origin 已发布01987fb2重放六个R37提交，不切换工作区、不携带R46任务提交。线上资源/服务验证通过后才执行 origin、github 双远端 push，结果以工具回执为准。
