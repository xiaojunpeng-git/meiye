# 008 商家端旧服务历史读取固定版（待产品经理验收）

- 基线 HEAD：`1e743acfa046a4e3c837df31b0f2fab3ada215e5`。
- 版本指纹：以下 11 个文件按相对路径排序，依次将 `路径 UTF-8 + NUL + 文件原始字节 + NUL` 输入 SHA-256，结果为 `73f8a6f2c7985c1bec2a448dc7d922b8b87df917f57a4635ce06420b993c619f`。验收后这些文件如有改动必须重新固定并说明补验范围。
- 目标实例仅 `008.cc3798.com / mohe_008:3307`；旧站仅只读 `morestore:3306`。不包含 007、012、rh、体验版上传、`git push`。

## 固定文件清单与 SHA-256

| 类别 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/mobile/customer/MobileCustomerServiceRecordServices.php` | `0b98455856dcfe0b775fc29d72934335a51c8dea907494b677b63bb7f126c69d` |
| 前端源码契约 | `前端代码/mobile-vue3/src/shared/contracts/mobile-customer-v1.contract.json` | `d7f4b2b6bd42dc52cf484a8973e1c781b2090488d751194133f5ffc037032f78` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-13-008渼约历史服务读取/00-升级清单.md` | `eb30d29bfd79904b8df9f5eadf7cb84bd186a5a4749a18ae10b7cebca5c21a01` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-13-008渼约历史服务读取/02-正式升级.sql` | `8712b214b97bac10932207fb79c1191cc073ae51a1515b1a45aa1a3249bd6f23` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-13-008渼约历史服务读取/04-回滚或应急说明.md` | `48de25abf39624264b90e47c9203df760d3cb3ac0b399e7b58d5d262e17750f7` |
| 工程脚本 | `scripts/import/customer_008_morestore_service_history.php` | `75d75ee42ea7b80a1882be198a71e115556818abedc2e595919543b35c6edeb0` |
| 工程脚本 | `scripts/import/customer_008_morestore_service_history_plan.json` | `7215c297a29696ece69b05c89d4edb584e3cabdea69d94e2de0a8459da03092a` |
| 自动化测试 | `tests/customer-008/php/service-history-contract.php` | `3464efa3e440b6ce917af1ef81fb270900b40c659beb734fd916688a02165b8f` |
| 自动化测试 | `tests/customer-008/php/service-history-import-mysql.php` | `0b5d9c99c681acf8897e163f1563c950eac54918a76b793b33e008a337f78113` |
| 自动化测试 | `tests/mobile-customer/php/mobile-api-contract.php` | `bfee350fb0b3ecbb6c2d8ff27c2b9b606634f7b6a8bac1230e4cabd7a4d5bab1` |
| 自动化测试 | `tests/mobile-vue3/js/customer-page.test.mjs` | `8440384dea849402037df7937d439d971c7e5bf472e6b9e170fe3bbeaaf243ef` |

验收证据为本文件；无生成物。后端类、合并分页方法、旧站快照提取、身份校验、批次写入和摘要验证均补充或更新了职责与数据边界注释。

## 业务行为

- 旧站状态 4/6 且有核销时间的工作单，按旧工作单粒度导入独立展示表；卡项和项目名称取工作单关联明细的购买时快照。不把旧销售、预约或卡项余额当成已完成服务。
- 发布时在旧站只读一致性事务中固定一次截止时间、行数与 SHA-256；之后以 008 的新服务事实为准，不持续同步旧站。导入表不参与扣次、工资、报表、最近到店等经营数据计算。
- 服务页对已授权可见的客户合并展示旧站全部门店历史与 008 当前门店范围内的新服务，统一按完成时间分页，不增加“旧数据”等页面标注。PoPo 旧工作单 51080 显示一条服务，名称为“面部卡（单项）、王牌 · 胶原瓷光肌”，次数 2，门店“苏州东环路店”；当前两条海伦堡服务保留。

## 自测与只读预检

- PHP 语法检查与 `service-history-contract.php` 通过；Node 客户服务契约断言通过；新服务接口 `MC-API-08` 通过；相关跟踪文件 `git diff --check` 通过。
- 一次性本地 MySQL 5.7 库应用正式 SQL 成功，`source_cutoff_at` 字段存在。`service-history-import-mysql.php` 验证事务导入、逐行与整批 SHA-256、PoPo 样本、重复导入拒绝、篡改检测及回滚后复核，测试库已删除。
- 本地 MySQL 合并查询验证：可见门店新服务、跨门店旧服务、较早新服务按时间排列；不可见门店新服务不出现。
- 旧站与 008 只读预检：旧站截至预检秒 `1791896546` 有 49,637 条符合条件工作单，15 条无具体项目名称，未来完成时间 0 条；PoPo 工作单 51080 存在且名称可读。此数字是预检，不是最终发布批次；发布时重新固定。008 目标新表、新批次表、升级标记目前均不存在。
- 008 当前服务文件 SHA-256 `405fc3e3c4c58cdc6dda5f01797f145e476f367cdb81c45526c312ef6e289fcc`，与基线 HEAD 完全一致，可作为代码回滚点。仅新增独立表，批次写入用事务和唯一键；回滚说明见迁移目录。遵照用户此前要求，不做整库备份。
- 现有 `mobile-api-contract.php` 的 `MC-API-03` 仍失败：HEAD 中已存在的断言要求控制器包含 `queryAudience`，而 HEAD 控制器并无该字符串，与本次服务记录无关。`customer-page.test.mjs` 的本次契约用例通过；该文件另外 5 个既有页面断言失败，均未在本闭环修改相关页面。

## 当前状态

代码与本地自测完成，待产品经理对上述固定版本确认验收；尚未本地 commit、部署、远程增表或导入。验收后本地提交仍按 11 个文件逐项暂存和核对；008 远程写入与部署还须单独明确授权。其他并行任务的脏文件保持原状。
