# 第 11 轮：集团管理看板销售类型卡片本地交付

## 范围与基线

- 唯一源码正本：`美容源码/`；开发基线 HEAD `7af8b1e847230c35ab1096cb498c8a2ab827c33c`。
- 本轮只在“经营业绩”下增加四张卡片：疗程卡、体验卡、单项、产品；每张显示销售数量和现金业绩。
- 共用工作区同时存在其他任务的大量未提交改动；下面仅列本轮文件。本地构建用于自测，不作为已固定的上线包。未连接远程实例；目标实例、线上迁移、备份与回滚点均不适用。
- 本轮不新增迁移、不写业务数据。卡项统计类型依赖第 10 轮已有的 `eb_store_product.card_statistics_type`（0 普通卡、1 体验卡，旧数据默认 0）。

## 指标口径

- 销售数量：完成销售明细的商品数量，使用统一 `sales_quantity` 的事实与正常单据范围。
- 现金业绩：正向销售实收分摊；不把充值、退款或没有原销售明细关联的补交混入商品类型卡片。退款继续显示在原有退款业绩指标。
- 卡项按销售商品关联平台商品的当前统计类型分为疗程卡、体验卡；平台商品无法核实且门店商品也不是卡项时不强行归类。单项和产品按已完成销售明细的商品类型分类。
- 使用看板后端授权门店、业务日期和可选商品分类范围；分类筛选沿用已确认的当前平台商品分类关联。历史卡如果后来修改统计类型，其历史销售会随当前配置重新归类；原销售和付款事实保持不变。

## 固定文件指纹

| 文件组 | 文件 | SHA-256 |
|---|---|---|
| 前端源码 | `前端代码/cashier-v3/src/views/GroupManagementDashboardView.vue` | `5a7ebe0b3b185bac8bbbe3938a6b55e351d0c510e8dc87853c3ecfa3f7ede4f6` |
| 后端源码 | `后端代码/app/services/report/GroupManagementDashboardServices.php` | `f7fe0a9ab804f31309689e408d28d075dd883483d232c272b08999c5fb30e4c3` |
| 后端源码 | `后端代码/app/services/query/metric/RegisteredMetricReadServices.php` | `ada2a959ba76ee74cdbbf44c292cf94195d4ab02d36ebc616396462982a73546` |
| 自动化测试 | `tests/report-phase-seven/php/group-dashboard-optimization-local.php` | `3182d7049e69b728419b679aad794f79583c314487f6b03f06e009ba55a44a73` |
| 自动化测试 | `tests/report-phase-seven/php/group-dashboard-sale-type-contract.php` | `c0c45564b05f5242c9252e8fed47c14401130fac2bfaaa6d8bb07d5c8f9e2f46` |

已跟踪四文件补丁 SHA-256：`639393f9b8293cbe1ebbaba341879cedb21395422017ef804e7e80331e078c3d`。新测试文件单独以上表文件指纹固定。

## 自测与保留功能

- 后端两个修改文件 PHP 语法检查、`git diff --check` 通过；门店端 `npm run build` 通过，构建前 70 项权益快照检查通过。
- 本地只读瑞昊数据库回归通过：102 家授权门店、所选周期四卡销售数量合计 3696、现金业绩合计 177034600 分；四卡汇总未超出统一销售数量或现金收款事实；商品分类与门店筛选未放大结果。
- 卡项配置合同测试通过：门店副本旧值为普通卡、平台正本为体验卡时归入体验卡；非卡项和缺失商品不会误入。
- 本地平台入口的内嵌集团看板已显示四卡，顺序正确，原六张经营业绩卡、趋势、分类业绩与目标排行仍可见；“列名取值来源”由后端解释这四卡口径。
- 本地实库目前没有配置为体验卡的商品；体验卡非零业务数据尚待产品经理在实际商品上验收。
- 构建产物仅为生成物，不进入源码提交：`dist/index.html` SHA-256 `c2344b2cded7f0faa5d7ff607e856696e1024adf67a8f13132d63d52bcb87958`，主 JS `index-436208f6.js` SHA-256 `bd7e8f37c40dda379ae6da915b103b7b6abb690dd7bb8b4979253e246f2ca789`，主 CSS `index-9a9dc990.css` SHA-256 `9a9dc99089534cde0f1a38c99484d7fbef0429070cd1196273544c3aa4ef7a33`。

业务注释已更新：`RegisteredMetricReadServices.php`、`GroupManagementDashboardServices.php`、`GroupManagementDashboardView.vue`；测试注释解释关键断言。状态：开发与自测完成，待产品经理对以上固定范围确认验收；未提交、未部署、未推送。
