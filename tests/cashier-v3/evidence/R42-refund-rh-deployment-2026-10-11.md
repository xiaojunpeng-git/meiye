# R42 退款人员与台账瑞昊发布记录

- 授权与验收：产品经理于 2026-10-11 确认本轮测试无问题，并明确要求 commit、部署瑞昊、push。仅对瑞昊正式实例 `rh.cc3798.com` 操作；其他客户实例未改变。
- 功能提交：`0c870d08fe524425cd82bed78459330bd02fa48f`（`R42-00001 退款人员归属与台账闭环`），父提交 `4a00cf487274dcf88460ac091567a7527ba04679`，47 个文件。使用独立 Git 索引逐项提交，原工作区其他任务的 9 个暂存 blob 已恢复，未夹带新客汇总系统名、会员创建或其他并行改动。
- 前端源码：`前端代码/cashier-v3/`，树 `f07e368760f24f9f23669a42cd00e053857b8ab2`；共享库存和统一查询包一并从上述提交归档用于构建。后端源码：13 份 PHP；数据库迁移：两组新建表升级；自动化测试与验收证据见同一提交。工程脚本未改；`dist/`、发布包与线上备份均为生成物，不是源码正本。

## 发布前核对与回滚点

- 独立目标：SSH `rh-server`，站点 `/www/wwwroot/rh.cc3798.com/`，预览静态根 `/www/wwwroot/rh.cc3798.com-8080/public/`，数据库 `ruihao`（MySQL 5.7.44）。服务器 Git HEAD `3254ecdc` 是历史初始记录，不作为当前线上代码版本；13 份 PHP 中 12 份与功能提交父版本逐份 SHA-256 相同，新服务文件原先不存在。两份旧前端入口均为 `dad2d51fe83d38764b8c50a8334dd9445d8b058835cf06994462c1cff91a3b89`。
- 发布前 `ruihao` 升级日志 215 条、最大 id 217；本轮三张归属/改派表均不存在。独立整库、12 份旧 PHP 和两处静态资源备份保存在 `/www/backups/rh.cc3798.com/20261011-r42-0c870d08/`；四包 gzip 校验通过，磁盘余量约 208G。
- 备份 SHA-256：`backend-before.tar.gz` 为 `df5b73ecad42955d7c8eae50c6bacad2becfc239b9ed70542de2c543ba61759f`；`frontend-formal-before.tar.gz` 为 `be82d10374758036fa6fea4d494b2231576e67d892b83cfbb3a2094093d7065b`；`frontend-preview-before.tar.gz` 为 `0d4c4ef0232d6b7670e6afa38edeac3d18ad09d54e1ed5faa616466d27d5385a`；`ruihao-before.sql.gz` 为 `8bbb310f52a4d59bc356a98c40f1f5e6160f2158a47e5d27ca7445d62b0defca`。
- 回滚应用时须先确认没有后续发布，再恢复上述 PHP 和前端包并重启瑞昊 Swoole。三张新表与升级日志保留；一旦产生正式归属或改派记录，不得 DROP 表或用整库备份覆盖后续业务，应采用前向修复。

## 迁移与发布

- 固定源码归档 `source.tar` SHA-256 `1214ea7aa388846578b4984c70529c5ae1c4b1aba2d6a5405e0e6ed082ef4af8`；候选后端包 `backend.tar` SHA-256 `4592e6736933f3aa5727dd61cf6ad7a73f3e418538a4e3148ef9d667c22defda`；候选前端包 `frontend.tar.gz` SHA-256 `a2c1f84bc40a0119955134181963fedba8552f472659a0c0b1d26da5342011a3`。服务器上传后哈希相同，13/13 PHP 7.4 语法检查通过。固定版本 Vite 构建 1996 模块通过，只有已有的大包告警。
- 依次执行两包 `01/02/03`，新增 `eb_cashier_v3_refund_attribution`、`eb_cashier_v3_refund_attribution_revision`、`eb_cashier_v3_refund_attribution_revision_person`。正式 SQL SHA-256 分别为 `e5d175afa546782bce7833283f301126a25afacd43071b56ed480dfaa27e0799` 和 `179154446655afa79007d6c2557f494c46cb90be3822607ea8d1dd0d869f36a0`；登记 `20261011-001-refund-attribution` 与 `20261011-002-refund-attribution-adjustment`，代码版本 `0c870d08`。三表验证后均为空，未回填历史。
- 先追加两处新哈希静态资源，再原子替换 13 份 PHP 并重启瑞昊 Swoole，最后原子切换正式和预览入口。发布后 13/13 PHP 与提交逐份相同；Swoole `manager_count=1`，监听 20800。

## 线上验证与限制

- 正式 HTTPS 入口返回的 HTML SHA-256 `d1289bc368ab0da615d8a7c5aff3ebdcee288002142714eea3949f6ec62dad16`，与固定构建一致；预览入口经既有路径前缀重写后也引用同一 JS/CSS。两处主 JS 均为 `84522150ad1bb3683525fc444cce0e323a69172425f16d25e90d0403519cdc0b`，两处 CSS 均为 `4e8ea79f4874033c62c10f5d8b4828fc31871dda4c4195e04a80218d79ee5399`。
- 匿名 `/cashierapi/config` 正常返回业务码 `410000 请登录`。生产只读台账查询以门店 23、2026-10-10 的既有一笔退款核对，返回 `store-refund-ledger-v5`、指定的销售人/导购/销售经理/分类/退款项目列顺序、金额 200000 分；导出列顺序和一笔记录一致。生产错误日志最后修改时间 13:00:35，早于本次切换与验证。
- 未在生产发起退款、人员改派或资金操作；线上业务写路径仍依赖有权限人员的后续日常使用验证。本地旧卡销售人回滚脚本在复测时因固定样本已有人员版本而提前停止，不计为通过；其他本轮 R42 契约、退款归属/改派回滚、来源类型回滚、商品列及台账集成测试均通过。
