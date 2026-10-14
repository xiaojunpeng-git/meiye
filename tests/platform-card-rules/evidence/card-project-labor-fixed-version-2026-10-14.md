# 卡内项目手工费：本地固定版本与自测

## 范围和口径

- 平台端卡内项目表在「项目原价」右侧增加可修改的「手工」列。新增项目首次取项目通用手工费，此后保存为该卡、该项目规格的独立配置。
- 本地现有卡项目关系已按项目通用手工费赋初值。已售卡尚未核销的权益在选择手艺人和收银草稿默认值时读取当前卡配置；已完成服务、人员业绩等事实不追溯改写。
- 未修改本闭环的收银前端页面；其他任务在工作区的收银前端脏文件不属于本闭环。
- 本轮仅本地开发、迁移、自测。未提交、推送或部署正式客户实例。

## 权威数据与边界

- 当前配置来源：`eb_store_card_related.labor_fee_cents`，单位为分；缺失旧行按当前平台主项目规则赋值。配置保存时卡关系可重建，已售权益通过持卡记录、订单明细、卡与项目身份重新定位当前关系，不依赖旧关系行 ID。
- 客户端只能提交非负整数元，后端验证后以分保存；0 元是有效配置。无有效卡关系时回退到项目通用值。
- 已售权益选择手艺人时，后端校验 holder、detail、project 三者同属一个订单权益，不能仅凭客户端传入卡 ID 读取其他卡配置。已完成核销仍保留原事实快照，避免历史业绩漂移。
- 本地迁移按空值赋初值且可重跑，不覆盖卡内人工设置。正式客户迁移需另行逐实例授权、备份、验证。卡关系查找按卡和项目过滤；购物车数据量受单笔订单约束，新增项目选择器只批量读取当前页项目规则。

## 固定版本

- 基线 HEAD：`c6b073efb5586368446eb33b46fc011cd9dfa5f6`
- 已跟踪文件的定向补丁 SHA-256：`b897e0a541189929fb2125c2f21258245fe730cb912130f1d35175059ed2f298`
- 前端源码：`前端代码/admin/src/pages/product/productAdd/index.vue` `78f048b87c2b4079147ca91210ca4c41e93a37574873846028ad286142cad384`
- 后端源码：
  - `后端代码/app/services/product/product/StoreProductServices.php` `f80730158717cf098af179d6abcabb21b40bd11ce831c5d6164e805b89c7dd3f`
  - `后端代码/app/services/product/product/StoreCardRuleServices.php` `cc5627982729679f4de79bf2dbc6f6562c9d68eb3c2e00e01deffd2c1df547a0`
  - `后端代码/app/services/product/product/StoreCardRelatedServices.php` `800cb894d23feb0cfecd897302c5a69260956ae881c5c428b53cb75eec6f07fd`
  - `后端代码/app/services/cashier/v3/CashierV3ProjectLaborDefault.php` `57065369f7f1d939452db588e812aee8824c0d8602878a0824a2426fd188367f`
  - `后端代码/app/services/cashier/v3/member/CashierV3QueryEntitySelectorServices.php` `5c8e4d2a16cac34a8ce0a8f9783e2a5caf6523165ef6764a0d75f561b106e699`
  - `后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php` `36784272edb0eec80b1ea063defbef8b637c81519af9e7491b5458e66e3a9c5f`
- 数据库迁移：
  - `后端代码/database/upgrades/2026-10-14-卡内项目手工费/00-升级清单.md` `f9cebb0acf8656332f1070e91249ee8f08334c47994d81c0af98d00e4e32e58b`
  - `后端代码/database/upgrades/2026-10-14-卡内项目手工费/01-升级前检查.sql` `797d15e5d3e609b5809aef7c0aafdcebba455ff7dd0f8e8eddac7b4836043e2d`
  - `后端代码/database/upgrades/2026-10-14-卡内项目手工费/02-正式升级.sql` `a465ab7fe6b0051975ac9175606e7a5621f3746656fa116fe74a9d3a86ea565a`
  - `后端代码/database/upgrades/2026-10-14-卡内项目手工费/03-升级后验证.sql` `a06efde05a984ae0fb85449cbdc976d1b625eadb1da273e9183cb0dd7b52f459`
- 自动化测试：`tests/platform-card-rules/php/card-rule-contract.php` `23a5e7a760c464c93d2a43482a387a61c17cb43084c250f0c646cb38033ffc3b`；`tests/platform-card-rules/js/card-rule-frontend-contract.mjs` `abdf8ee9789f0ed99b7a014f417c9af2d13965a129fa986b7a583ab2f38801ce`
- 工程脚本：无。生成物：平台端 `npm run build` 的忽略产物，仅用于本地编译验证，不是 8080 集成包。
- 验收截图：`tests/platform-card-rules/evidence/card-labor-edit-local-2026-10-14.png` `b43a8e792d67ab4a5ddc77631be20a8ef03e39c424850cc4d98b0365bf139618`

## 自测结果

- `php -d error_reporting=22527 tests/platform-card-rules/php/card-rule-contract.php`：PASS；`node tests/platform-card-rules/js/card-rule-frontend-contract.mjs`：30/30 PASS。
- 六个本闭环后端文件 `php -l` 通过；`git diff --check` 通过；平台端 `npm run build` 成功，仅有既有体积提示。
- 本地 `ruihao` 先备份两张相关表至 `/private/tmp/card-labor-ruihao-local-20261014-rollback.sql`，SHA-256 `258f8d6460d4ff9bf7aace22bef64363523f06f2066bbcff95682e6fe20fefce`。执行迁移后字段存在、空值为 0、项目关系 14571 条已赋值，其中非零 329 条；重复执行进入已完成分支，未覆盖设置。
- 浏览器实际编辑未出售的卡 606468：原有两项回显 6 元、8 元；第一项改 7 元保存后数据库为 700/800 分，重进页面仍为 7 元；再改回 6 元，数据库恢复 600/800 分。截图记录最终列位置与回显。
- 本地已售卡真实权益 holder `1860123`、detail `4248825`、project `322544`：读模型原为 0 分；在数据库事务内将对应卡关系临时改为 900 分、1100 分时，权益随即读到 900 分、1100 分；回滚后恢复 0 分，没有留下测试金额。

## 注释与后续门禁

- 本轮在卡新增/编辑映射、后端保存规则、关系持久化、已售权益读模型及人员默认值分支补充了配置来源、身份校验和历史事实边界注释。
- 开发与自测完成，待产品经理对以上固定版本确认验收。按项目规则，验收前不创建本地 commit；本地 commit 不授权正式部署或 push。
