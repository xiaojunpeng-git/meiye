# D4-00003 盘点零差异与门店名称瑞昊发布记录

## 授权与版本

- 产品经理明确确认“我测试没有问题,本轮commit,部署瑞昊,push”；范围仅本轮零差异盘点与盘点列表门店名称。
- 功能提交：`460686e19d17e9ad3a8811bf0b4071ce650192d8`，`D4-00001 盘点零差异记录与门店名称闭环`；父提交 `31598aaddad0962e675acb89739cb916ae38fd63`。
- 目标仅瑞昊 `rh.cc3798.com`、数据库 `ruihao`；正式及 `preview-8080` 门店静态包同版。其他客户、平台端、预览后端及配置未改。
- 推送目标为既有 `origin` 的 `release/store-cashier-c14-main`，只在本记录的线上验证通过后推送；不推送 `github`。

## 精准提交与构建

- 功能提交共 24 文件：前端源码 4、后端源码 5、自动化测试 9、原有验收证据 6。实际路径以 `git show --name-only 460686e1` 为准。
- 共享 `App.vue` 仅暂存 count 查询字段、表头、行映射三个位置；其他任务的分组列表、收银布局、移动端及其他脏改动均未夹带。
- 原有 9 个已暂存文件保持不变，提交前后暂存补丁 SHA-256 均为 `dc31b057aca12a17ee75ed7a86e023bfeba79933849b91605faa54e209c95b46`。
- 从正本 Git 提交归档进行本机原生 Node 16 串行构建；归档不可编辑，不是另一份开发正本。门店 18091 对应的 cashier-v3 源码及其 inventory-vue3/shared 依赖是构建源。
- 父版本独立构建的 HTML/JS/CSS 与两处现网旧包逐字节一致；发布包只来自功能提交，没有使用脏工作区生成物。
- 新 cashier-v3 集成包构建通过，1991 modules；仅保留大 chunk 告警。包包含库存配套路由，无需构建或替换旧 store/admin 工程。
- 新主 JS：`index-9167f526.js`，SHA-256 `fe7ea887b86d1e9f9fda5e5d4ca2fa18ef537d2eb8b82240439e728edd9c5f4a`。
- 新 CSS：`index-5ef44bff.css`，SHA-256 `5ef44bffd4cfdccf61f4863ffc1dc53a2e4de94c9b5d2fdf3ffe5e597b2e7ec8`。
- 新磁盘 HTML SHA-256：`6ff6d4d52fb8738466a8a246342ae4680cc005a233cb7d50e12c4206467fa6b0`。
- 本机后端包 SHA-256 `32799cd8ccfa5efe3ff77afdce39d2a03b5b680560e2f9aa48d509f03bdb8de8`；前端包 `7550c1dee77372b6ef80151c234e4030a365f45798a656314a2298e155f633d5`；服务器校验一致。

## 备份与部署

- 瑞昊独立备份目录：`/www/backups/rh.cc3798.com/20261009-d4-460686e1.LsFmL8/`，目录权限 0700。
- 旧五个 PHP 文件包 `backend-before.tar.gz` SHA-256：`4751e1f8f3114117cdb73860006dc380f1fa984056f3f48e68c70331a56e44da`。
- 旧正式静态包 `cashier-formal-before.tar.gz`：`2b0570139d2aedebfc5ff176f79a9903886ff5b07472c2240a8b896022eccec2`。
- 旧预览静态包 `cashier-preview-before.tar.gz`：`1ca273b638e8fc002d36fa0e8d7314c23b0e5cb79d7f681b0015d30da238a4c0`。
- 三个备份包完成 gzip/tar 完整性及解压回读校验；旧五个 PHP 文件与父提交指纹一致，切换前再次对比运行文件与备份，未发生发布覆盖冲突。
- 仅替换正式根目录中功能提交的五个 PHP 文件；静态资源增量安装、保留旧 hash 资源，正式与预览 HTML 分别原子切换。没有上传整棵工作区或删除旧资源。
- 2026-10-09 17:12:45（北京时间）切换成功；正式 Swoole 重启后 `manager_count=1`、PID 15121、20800 监听。重启失败回滚保护未触发。
- 正式/预览 `.env`、nginx 及 init 脚本指纹前后一致，不记录凭证。
- 回滚须先核对没有后续部署，再恢复备份中的五个 PHP 和各自旧 HTML，重启正式 Swoole；新增 hash 资源可保留。无数据库迁移可回退。

## 数据与验证

- 数据库只读事务实查：MySQL 5.7.44，`stock_count_line.stock_id` 默认 0、无外键限制，满足零库存持平行契约；无需建列、数据回填或生产业务写入。
- 最新迁移为 `20261009-002-r32-unlinked-catalog-refund`，此前为 `20261009-001-r32-refund-salesperson`；本轮未改变迁移记录。
- 本地隔离集成：零差异/门店投影 26 项、总部与门店草稿 23 项通过，夹具全部回滚；更多覆盖和既有合同限制见本轮两份自测记录，不声称全库测试通过。
- 发布前再次对提交归档运行门店名称、提交、日期、实时库存权威及草稿列表五项前端合同，全部通过；五个新 PHP 在瑞昊 PHP 7.4.33 语法检查通过。
- 发布后五个运行 PHP 指纹与功能提交一致；正式及预览 HTML、JS、CSS 公网 HTTP 200，资源内容校验一致。预览 HTML 经既有 nginx 路径重写，公网 SHA-256 为 `e2b05929f2b172370e56d76e74543cb1987a5d9d5379cf9f7d284b7cb207bd84`。
- 正式 `/cashierapi/config` 返回预期未登录状态 410000。已登录预览浏览器能正常读取库存：本月肥西水晶城 3 张历史盘点显示“门店名称 / 肥西水晶城”；只读打开 `PD2610030005` 明细成功，再返回列表。
- 只读调用已部署统一盘点来源投影再次核对 3 张单据门店名称；未创建盘点、保存草稿、制造测试流水或修改客户库存。零差异写入业务由本地隔离测试及产品经理验收覆盖，线上只读冒烟不冒充生产写入验收。
- 既有预览 20801 后端未监听，带 `/preview-8080/cashierapi` 前缀请求部署前已为 502。本次页面实际使用绝对正式 API，登录页面和盘点查询正常；未擅自启用预览服务或修改代理。
- 近期 Swoole 日志未出现 Fatal error、Parse error、Uncaught。页面截图为 `D4-rh-count-store-name-2026-10-09.png`。

## 文件分组

- 前端源码：`App.vue`、`InventoryBusinessModal.vue`、`inventoryApi.js`、`countSubmission.js`，均位于 inventory-vue3；职责和边界注释见本轮自测记录。
- 后端源码：`InventoryErrorMessage.php`、`InventoryStockCountDetailProjectionServices.php`、`InventoryStockCountServices.php`、`InventoryOperationalUnifiedQueryContract.php`、`InventoryOperationalUnifiedQueryProvider.php`。
- 自动化测试：功能提交中的 9 个库存测试；数据库迁移、工程脚本：无。
- 生成物：提交归档的 cashier-v3 集成 dist、两处线上静态包及五个 PHP 部署文件；不作为开发源或提交 dist。
- 验收/发布证据：功能提交的 6 份固定版本证据，本发布记录及线上截图；保留原自测记录当时的“待验收”历史状态，不倒改旧记录。
