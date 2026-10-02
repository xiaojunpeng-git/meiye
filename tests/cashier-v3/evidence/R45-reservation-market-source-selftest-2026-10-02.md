# R45 预约客户来源快照自测与固定版本

日期：2026-10-02。状态：开发与本地自测完成，待产品经理对本固定版本确认验收；未提交、未部署、未推送。

## 范围与业务口径

- 门店新增预约时必须选择客户来源；会员端创建预约仍不让会员填写经营归因，门店确认时必须选择。
- 预约单保存一级、二级来源 ID、名称、组合显示名及归因类型的历史快照。预约完成服务时，复制到不可变服务事实；不伪造收银结账请求、收款或结账快照。
- 市场服务客次按服务事实来源计入；真实结账已有来源时优先使用结账来源。同一预约多项目只算一次客次，纯服务不增加金额。旧预约和旧服务事实不猜测、不回填来源。
- 本次只改预约相关页面的新增客户来源选择与展示；原有“预约来源”（会员/门店渠道）保持原义。王春已产生的两笔历史服务记录保持原状。

## 固定版本

基线 HEAD：`a5ac18d566a3c1e8d6d5dd9754c4144fc64c0a4f`，分支 `release/store-cashier-c14-main`。以下清单仅含本闭环文件；工作区其他脏改动不属于本闭环。

前端源码：

| 文件 | SHA-256 |
| --- | --- |
| `前端代码/cashier-v3/src/components/reservation/ReservationDetailOverlay.vue` | `dbd38b796d62e662b771ade338a8856e8869dbf9902a96a88fa0d196d6637bcc` |
| `前端代码/cashier-v3/src/components/reservation/ReservationEditorOverlay.vue` | `2cc0b679aec129302e337025ddea74bae09de7b5db16baae974a49f7444224a2` |
| `前端代码/cashier-v3/src/views/ReservationListView.vue` | `c7a36f02a161b38fbb52b2c248f9e4ca08fab842e2fe729e263f4da5f9ee69ef` |
| `前端代码/mobile-vue3/src/merchant/pages/reservation-confirmations/index.uvue` | `6b43241e54fba63d0bb288bfb4f635fb0c733b7cb2f2fcba772dfe87ce6b6ca0` |

后端源码：

| 文件 | SHA-256 |
| --- | --- |
| `后端代码/app/services/cashier/v3/reservation/CashierV3ReservationCompletionFactServices.php` | `ac947b82bcf059d73e3f56780f9d2ae608c17d0b6e61064dc787fc1e1ba3fd0e` |
| `后端代码/app/services/cashier/v3/reservation/CashierV3ReservationDetailQueryServices.php` | `ff8ee0c9a254e3521cfaafe6ad3853f7427585246a38469f8985093b3f5db6d1` |
| `后端代码/app/services/cashier/v3/reservation/CashierV3ReservationModule.php` | `7dd347c1bf67f06c1310bbf47208f40a49ddfa3f699cdb9ab80c83b2d8813ddf` |
| `后端代码/app/services/query/metric/RegisteredMetricReadServices.php` | `aafe2770d73a371047b28ba4cb5ec666c25fd9a2a74c4826dc4b86e3c34781c7` |
| `后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php` | `1eafd0f9915d6a8f4d1ac9257ae346a2bce8ba1f2915054f740518f8a5e7532d` |

数据库迁移：

| 文件 | SHA-256 |
| --- | --- |
| `后端代码/database/upgrades/2026-10-02-预约市场来源快照/00-升级清单.md` | `dce6f95a0c053c00bc555d8f05d7f738a9c0216905609ed9ab4e792b8ae3f355` |
| `后端代码/database/upgrades/2026-10-02-预约市场来源快照/02-正式升级.sql` | `616cce9d3533d4994d22cab6c0add13c6a90b5d421a6870fff60f9a549e8a358` |
| `后端代码/database/upgrades/2026-10-02-预约市场来源快照/03-升级后验证.sql` | `9c312964fff6cb737f13f25c186de93ed063da051196a7094f2a40ee158c6643` |

自动化测试：

| 文件 | SHA-256 |
| --- | --- |
| `tests/cashier-v3/php/reservation-market-source-mysql-integration.php` | `4d08079f4626c04254dad4ae078a10c91d7f279d038e1c8830c442aee321524d` |

工程脚本：无。生成物：仅本地 Vite 编译产物 `前端代码/cashier-v3/dist/`，未纳入源码，不等于 8080 集成包。验收证据：本文件。

上述九个已跟踪源码文件的 `git diff --binary` 补丁 SHA-256：`6d79015540f58f74c0ea954507bd70baa3c530e1472b9561ff457c8e44de3082`。新建迁移和测试文件以逐文件 SHA-256 固定。

## 自测结果

- 修改的 PHP 文件及测试文件 `php -l` 通过；`git diff --check` 通过。
- `cashier-v3` 的 `npm run build` 通过，产物 JS `index-ee26dde9.js`、CSS `index-7755b057.css`；仅作前端编译验证，未打包 8080。
- `reservation-lifecycle-contract.php`、`reservation-entitlement-project-contract.php` 通过；市场客次合同测试 16/16、市场明细固定表测试 5/5 通过。
- `tests/mobile-vue3/js/reservation-page.test.mjs` 8/8 通过。
- 迁移在隔离的本地 MySQL 5.7 临时库首次与重复执行均通过；每表新增六列，升级日志仅一条。临时库已删除。迁移仅另应用于本地 `mohe-mysql/ruihao` 开发库，未连接或修改线上实例，349 条旧预约与 1041 条旧服务事实没有来源回填。
- `reservation-market-source-mysql-integration.php` 在本地开发库事务中通过并回滚：无结账单的预约服务增加一个来源客次，两个项目仍只算一次，金额不变，市场明细与汇总一致。
- `mobile-vue3` 全量 `npm run check` 未通过：既有测试引用缺失的 `src/merchant/contracts/mobile-merchant-v1.contract.json`，以及其他既有断言失败（22/48）；上述定向测试通过。该命令不编译本次修改的 `.uvue` 页面，故手机端实机编译仍待验。
- 本地 `18091` 预约页服务可达，但浏览器被重定向至登录页；没有可用测试会话，因此未声称完成真实页面交互验收。

## 注释与交接边界

本次在 `CashierV3ReservationModule.php`、`CashierV3ReservationCompletionFactServices.php`、`RegisteredMetricReadServices.php`、`StoreUnifiedReportPhaseTwoServices.php`、`reservation-confirmations/index.uvue` 补充/更新业务注释，说明归因选择、快照传播、旧数据边界及市场口径。

待产品经理确认此固定版本验收后，才可按项目规则逐文件暂存并本地提交。迁移到任何远程客户实例、部署、线上验证和 push 均未获本次授权，也不属于本次已完成事项。
