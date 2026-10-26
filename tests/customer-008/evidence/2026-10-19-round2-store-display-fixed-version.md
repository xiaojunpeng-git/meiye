# M008-R2-20261019-STORE-DISPLAY 固定候选

状态：开发与自测完成，待产品经理确认验收。未提交、未执行生产写入、未清缓存、未 push。

## 需求与边界

用户要求：按照原渼约配置补齐 008，修复会员首页门店信息显示。
本轮只恢复门店显示相关配置，不是整站配置同步；旧渼约只读，瑞昊/007/012 不操作。
不改会员归属、门店主数据、经营模式、装修、支付、微信、地图密钥或商品可用范围。

## 双基线

- 唯一源码正本：`美容源码/`；任务开始 HEAD `ab9579d72f1accaae34dae00a46ae0156d5807fa`，固定候选前 HEAD `89cdbf6cc487c402e3c4bccdabb21e70c7ebb608`。
- 期间其他任务新增 `b7b7911a`、`82ffb0a8`，仅触及收银订单/结算及其测试五文件；本轮脚本、会员首页与门店/配置服务指纹未变。本次不构建后端整包，不夹带它们。
- 最后核对又出现 `89cdbf6c`：16 个文件均属收银旧数据结账、订单和岗位策略及测试，未触及本轮三个脚本/测试及其门店解析、首页依赖。更新候选基线到此提交，本轮补丁指纹不变。
- 工作区有其他任务的大量未提交改动；其中 `SystemConfigServices.php` 已有 3 增 1 删，不属于本候选，不暂存、不覆盖。
- 本候选不构建/发布前端包，不带入上一项已提交但未部署的导航外边距功能。
- 008 数据库 `mohe_008:3307`，独立 Redis `6388`，配置键前缀 `008:`；旧站 `morestore:3306`。
- 008 三个配置完整行 JSON 指纹：`f320f80415afe687d740da816552b32486a5c080205c22e6a7732748265e60ed`。
- 008 `shop_operation_type` 从先前调查时的空值变为 `2`；视为外部有效修改，本轮明确保留，不恢复为空、不改为其他模式。
- 008 门店 130 行均 `is_show=1,is_del=0`。沿用既有 22 店映射，不复制或重编号门店。
- 本次只读重核最新迁移：`20261019-008-media-absolute-urls-r2`，id 41，版本 `b3c6dfe5d4fa1d031920d43a7251af825d70867f`；两张目标表配置/审计均为 InnoDB。实施前再次核对。

线上受影响文件只读指纹：

| 实例与文件 | SHA-256 |
| --- | --- |
| 008 `public/index.html` | `48c0a7e57dc001ebf9bca13eff477e21a0f774a2930604aed10fa5da3acb1cbc` |
| 008 `app/controller/api/v1/store/Store.php` | `159de39b3347b99f70c412b8ddc7219fdabf2b4408c2c3f7a089b6d3c38ce8d2` |
| 008 `app/services/store/SystemStoreServices.php` | `d85807f789118c6a0d47d4e3d007ffa76b9a96a50e515868944379ea87f37250` |
| 008 `mohe/services/SystemConfigService.php` | `5b90c05cdc27fa826f9db0d5d4b427d510072a970600d9bf7e78b9b1aaa0a422` |
| 旧渼约 `app/controller/api/v1/store/StoreController.php` | `a6622bd227ef7592815d06d219ee6ee99c6e38bab1c76456ada43f15475e2033` |
| 旧渼约 `app/services/store/SystemStoreServices.php` | `d3eaf13dbe11510bd9ffba11fe5f51316bbdbe6a35437fec83b2fbd0215f74f7` |

008 的门店服务与配置缓存服务和本地正本 SHA 完全相同。会员首页源码未改，合同测试直接执行其方法。

## 根因与等价转换

旧渼约仅有 `store_func_status=1`，不存在新版 `shop_operation_type`、`user_entry_rules` 配置。
旧门店接口优先使用传入门店，否则使用位置/IP附近门店，无匹配时按既有排序取门店；不会据此改会员归属。

008 门店开关、进店规则为空。空规则 JSON 解码为 null，会员端 `entryRules.forEach` 失败，未进入门店详情加载；独立 `/api/nearby_store` 也因空开关返回空 info。

本轮只改 `eb_system_config` 两行的 `value`：

1. `store_func_status`：空字符串改为 JSON 数字 `1`。
2. `user_entry_rules`：空字符串改为当前编辑器兼容的六卡片数组，仅启用“带参门店”和“定位推荐”；归属、最近访问、强制默认店、地区推荐均禁用。

`shop_operation_type=2` 保留。用户手选门店优先；没有手选时沿用附近/原有排序逻辑，不硬编码任何门店 ID。
禁用的规则仍保留编辑器需要的完整字段，不构成启用归属绑定、访问历史推荐或区域隔离。

## 本轮文件分组与固定指纹

前端业务源码：无。后端业务源码：无。数据库结构/SQL迁移：无。

工程脚本：

- `scripts/import/customer_008_store_display.php`
  SHA-256 `6c5475c326f7869882d19dd3f15e8352c364d1120c977c05afac0b0094d29bb4`。

自动化测试：

- `tests/customer-008/php/store-display.php`
  SHA-256 `bf90b28df187c55a8724e1ca7e952c9c338a2725ffab9487c44f8cb5cf31913e`。
- `tests/customer-008/store-display-h5.cjs`
  SHA-256 `57a1b9e02f85156fb99a71eb1cf021b94df4b29ad91046a53243d8fce201f7cf`。

上述三文件按此顺序拼接 `git diff --no-index -- /dev/null <file>` 的补丁 SHA-256：
`5472eb9d17a9ecff2b2a3d32aa33ee56ca4e539fba8ad0b53427ab13f95beeeb`。

验收证据：本文件；修复前截图 `2026-10-19-round2-store-display-before.png` 是生成证据，不是源码或线上修复成功证明。
注释已覆盖上述三个新增脚本/测试的职责、旧新系统转换、禁止改变的边界、事务、缓存和回滚保护。

## 自测结果

1. `php -l scripts/import/customer_008_store_display.php`：通过。
2. `php tests/customer-008/php/store-display.php`：通过。直接提取正本真实门店解析方法，以合成 DAO/位置/缓存替代外部依赖；覆盖匿名/登录上下文、带参门店、手动切店、附近兜底、模式2和规则数组契约。
3. `php tests/customer-008/php/store-display.php --mysql`：MySQL 5.7 真实写入通过。拒绝 MyISAM、两行变更、模式/元数据/无关字段不变、私有快照、审计 varchar(500)、审计失败全事务回滚、重复执行拒绝、并发修改拒绝、回滚保留后续人工修改、完整恢复及审计保留均通过。
4. `node tests/customer-008/store-display-h5.cjs`：通过。执行正本首页真实 `entryStore` / `getnearbyStore` 方法，复现 null 规则错误；新规则能传递选择/附近门店名称，保留正常门店切换及隔离门店限制。
5. 候选脚本通过 SSH 标准输入执行 `check`：旧站和 008 均使用只读事务；结果 `CHECKED`，固定行指纹一致，预期仅两行修改。未在服务器保存脚本。
6. Redis 只读 `EXISTS`：三个目标配置键均存在，未读取键值。实施时只失效这三个全局键，不 flush，不删除会话或会员缓存，不重启服务。
7. 专用隔离容器 `m008-rights-isolate` 测试前为停止，测试后恢复停止；本次随机合成数据库已清理。

测试中的解析/组件合同不能代替正式会员页面与选店业务验证。当前线上仍显示“选择门店”；不能称“已修复上线”。

## 验收后实施与回滚

验收只对应本固定版本。确认后精准暂存四个文本文件并创建本地提交，不夹带其他任务文件，不 push。
生产应用仅限本用户点名的 008：重核源码/目标配置/接口基线、旧站只读来源和迁移状态，再传已提交脚本；如相关基线发生实质变化，先补验。

脚本操作：`check`；`apply <accepted-commit>`；`verify`。独立快照目录：
`/www/backups/008.cc3798.com/20261019-round2-store-display/before.json`。
两行配置及 `eb_database_upgrade_log` 审计同事务；缺快照、并发编辑、审计失败或读回差异均拒绝/回滚。缓存仅在 SQL 提交后失效；若缓存步骤失败，报告“SQL已提交、缓存未完成”，核验后可单独 `invalidate`，不得误称整个事务已回滚。

应急 `rollback`：只在当前三行仍等于本次 after 且审计匹配时还原两行，保留审计回滚记录，随后失效同三缓存键；不覆盖用户后续配置修改。

实施后必须验证：匿名首页和当前会员首页能显示有效门店名；选店入口能打开，正常门店可切换；原商品/员工模块正常，会员归属字段未改。不得为测试创建订单/预约/支付。正常访问可能刷新进店缓存或记录访问，不把这类接口冒称纯只读。
旧站配置复核和未触及实例保持原样须单独记录；上一项导航外边距未部署，不算本轮门店显示修复的发布成果。
