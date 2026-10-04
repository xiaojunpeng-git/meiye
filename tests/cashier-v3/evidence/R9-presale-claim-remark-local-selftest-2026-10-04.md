# R9 预售/赠送商品领用备注：本地自测与待验收版本

- 基线 HEAD：`09dafbbb88581d393788f3e61ed8c5f0e1d237ac`；本轮代码尚未提交。
- 范围：领用弹窗可填选填备注；每次领用明细显示备注，且可在原门店修改。预售、赠送共用同一领用服务。备注修改不改变领用数量、库存事实、业务日期或作废状态。
- 前端源码：`前端代码/cashier-v3/src/services/presaleClaimApi.js` (`73d6b85c`)、`前端代码/cashier-v3/src/views/PresaleClaimView.vue` (`b848e7fd`)。明细页“备注”列只显示内容，按钮移到“操作”列并命名“修改备注”。
- 后端源码：`后端代码/app/controller/store/product/inventory/PresaleClaim.php` (`5ff398b7`)、`后端代码/app/http/middleware/store/StoreCkeckRoleMiddleware.php` (`2f381ac9`)、`后端代码/app/services/cashier/v3/presale/CashierV3PresaleClaimServices.php` (`4f4ca232`)、`后端代码/route/store.php` (`8bf1c0c2`)。
- 数据库迁移：`后端代码/database/upgrades/2026-10-04-R9-预售赠送领用备注/01-正式升级.sql` (`ee60062b`)。本地 `ruihao` 已执行，执行前单表备份 `/tmp/r9-presale-claim-local-before.sql`（SHA-256 `cff0d97848587ec350fec6ee7f881ec37a23366e2279cfaad722eb9cd0c173ce`）。原有 18 条领用记录保留，新增字段均为默认值；生产库未执行。
- 自动化测试：`tests/cashier-v3/php/presale-claim-remark-contract.php` (`d267971d`)、`tests/cashier-v3/php/presale-claim-remark-local-integration.php` (`c85159a4`)；工程脚本：无；生成物：本地 Vite `dist/`，未作为源码提交。
- 上述 9 个源码/迁移/测试文件按清单顺序连接完整 SHA-256 行后再取 SHA-256，补丁指纹为 `068203f29d084e8e4667e9ff0d9d51e2f77f6dc485af9733ecffc8e993ccaaee`。完整文件指纹：

```text
73d6b85c0f6de4a3db373c3cf08dc8031501d317a401c96b194b9da71f61334f  前端代码/cashier-v3/src/services/presaleClaimApi.js
b848e7fd133cfcb29efdabc8beb375ba09d98ff9bbc197708b379dbfbdc9d28c  前端代码/cashier-v3/src/views/PresaleClaimView.vue
5ff398b7176bf9842fa69923007da183d9c72263dd34f576c7ab8ee6056bb087  后端代码/app/controller/store/product/inventory/PresaleClaim.php
2f381ac949e1ac8b3aa130e7bb11e03c527bee081ba37afdcc7a5ae4e788739c  后端代码/app/http/middleware/store/StoreCkeckRoleMiddleware.php
4f4ca232a0f253ca1cdb71a40dd06a9e7ea20efc9d75c292b63cd2a33860a729  后端代码/app/services/cashier/v3/presale/CashierV3PresaleClaimServices.php
8bf1c0c23c910eced7bd939402cd80daa198262d412afefd6cf65ef49eb2dd8b  后端代码/route/store.php
ee60062b8d850a37dd36b6a338810dd95d41ec999b9d331b532024593bad7744  后端代码/database/upgrades/2026-10-04-R9-预售赠送领用备注/01-正式升级.sql
d267971d490ce00f734678d52e6d994525f7aca640563a297509fff5918d5a3a  tests/cashier-v3/php/presale-claim-remark-contract.php
c85159a49945afab81959b90a4f364c90dcd1c1ab658ac4ee0c4654349f2ed13  tests/cashier-v3/php/presale-claim-remark-local-integration.php
```

## 数据架构自检

- 权威源和粒度：每次成功领用对应一条 `cashier_v3_presale_claim` 出库单；备注归属该单，不归属预售订单或库存批次。
- 事务和幂等：创建时备注参与请求指纹；无备注请求保留旧版指纹，兼容在途重试。修改备注采用版本检查与行锁，同事务追加修改历史。重复保存同值不增加版本或审计行。
- 时间与历史：修改只更新 `updated_at`，不覆盖原 `business_date`、`occurred_at`、`settled_at` 或库存事实；修改历史保留前后值、操作人和真实时间。作废仍按原反向库存事实处理。
- 权限和兼容：原门店范围内且拥有领用创建权限的账号可修改；委托只读会话不能写。旧领用记录的备注默认空、版本默认 1。无报表指标变更、无跨客户部署。
- 性能与对账：详情沿用原单据索引；备注历史按租户和领用号索引。可对账 `remark_version - 1` 与该单历史行数，库存数量和事实保持不变。

## 验证

- `php tests/cashier-v3/php/presale-claim-remark-contract.php`：通过（旧指纹、非空备注指纹、500 字边界及入口合同）。本机 PHP 8.5 的第三方依赖弃用警告不影响结果。
- 本地 PHP 7.4 容器事务回滚测试：`PRESALE_CLAIM_REMARK_LOCAL_INTEGRATION_OK`，覆盖保存、旧版本冲突、审计行，测试临时单及历史行均回滚。
- 既有 `gift-product-presale-claim-contract.php` 10 项、`legacy-import-presale-claim-contract.php` 通过；`npm run build` 通过（备注错误提示入弹窗后重跑）；后端 PHP 7.4 语法检查通过；`git diff --check` 通过。
- 18091 页面实际打开领用弹窗，确认备注输入显示；随后在现有明细中确认“备注”列只显示文本，“操作”列依次显示“修改备注”和“作废领用”。按钮位置回归合同通过。未由 Codex 在现存会员数据上提交真实领用。产品经理仍需确认本固定版本验收。

状态：开发与自测完成，待产品经理确认验收；未 commit、未部署、未 push。
