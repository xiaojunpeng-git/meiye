# 商家端日期与数据页排行页签本地自测

- 基线 HEAD：`d4df41f32855d929345fa9b6708afe8a12c0b5b8`。
- 闭环既有文件补丁指纹：`5f6e4a4c999ddfb9b41be7dc1ae66c629b8471ff5e9fe5c8b6d802392b02994e`。
- 状态：开发及本地自测完成，待产品经理对本固定版本确认验收；未 commit、未部署、未 push。

## 变更与业务口径

- 经营首页、数据页首次进入均默认“今天”。两个页面的日期切换仍分别作用于自身查询。
- 门店排行与上方日期筛选共用同一个服务端 `period`，包括今天、昨天、本月、上月和自定义；首批及续页都按该区间计算。列表显示实际起止日期，仍按授权范围每批 10 家加载。
- 数据页排行名称改为“员工销售”“员工劳动”“服务项目”；组织排行、门店排行、员工点客保留原名。改名不改变指标字典代码和后端计算口径。
- 经营首页的相对日期缓存跨上海自然日失效，避免零点后将前一日结果作为“今天”展示。
- 门店排行摘要分两行显示统计期与完整门店数量，小屏仍可看到排序按钮。

## 固定文件清单与 SHA-256

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/mobile-vue3/src/merchant/pages/home/index.uvue` | `a91bed81842644663bda1d5b4cc8f15c4dc31a8e0d58c9076c3208ce89c7ebc2` |
| 前端源码 | `前端代码/mobile-vue3/src/merchant/pages/warehouse/index.uvue` | `7b6b0f9c61f71c1c7743101c8c8eb0f0786290104482b1720053a65b81aca575` |
| 前端源码 | `前端代码/mobile-vue3/src/shared/api/mobile-home-client.uts` | `b20ef151239cfd32e9e92539a4a8003929f63a778974224a6b6202439abc06a3` |
| 前端契约 | `前端代码/mobile-vue3/src/shared/contracts/mobile-merchant-v1.contract.json` | `e2d30ee35870269335c698a172778b32b3173cd0ed697afb6adaac761da24a5f` |
| 后端源码 | `后端代码/app/services/mobile/merchant/MobileMerchantAnalyticsEntryPolicy.php` | `61f7f29c690fc748f9634d48bc8a03d9e9f7c7e5a0a02e6ac168011370d0b832` |
| 后端源码 | `后端代码/app/services/mobile/warehouse/MobileWarehouseServices.php` | `5b005523fb88ac6ac293c23cdc8408ebd785dcb5e5e4cc8da85b116aa4abf5ae` |
| 自动化测试 | `tests/mobile-warehouse/js/page-contract.mjs` | `43bf1e2b1d025fa039bdda973c7b7bb128b2e4fd08489e278c6bfbdd9b59cbbd` |
| 自动化测试 | `tests/mobile-warehouse/run-contract.sh` | `e7882afe34b6218adaed58406bd052c28005760f3dbb2830246b2e3719acfd8f` |
| 自动化测试 | `tests/mobile-warehouse/php/ranking-scope-contract.php` | `890af01a47c9d7a9fb27f9e033b79f6921aabaca4a8a07e72f7ad50cff9dd492` |
| 验收证据 | `tests/mobile-warehouse/evidence/R5-ranking-tabs-today-2026-10-13.png` | `d159a7416c8193b5aaa9ddff627ba11a31d511b988aff9ca23edb29d8a307eeb` |
| 验收证据 | `tests/mobile-warehouse/evidence/R5-store-ranking-month-2026-10-13.png` | `7d80f8f4a94ca49dce37ca218d6d83bd7e67f4f41ea0b1847070f3f1ec7f105e` |
| 验收证据 | `tests/mobile-warehouse/evidence/R5-store-ranking-today-final-2026-10-13.png` | `2ab27073a364f56ea1b6b0db2ac153b6a4ac59a71870c40ed0d7ec6651c09f3c` |
| 验收证据 | `tests/mobile-warehouse/evidence/R5-home-today-2026-10-13.png` | `e1d79a72f319813bcc5dc65f1e0436032d1f7151ef33272a08cbaa4be22953a1` |

数据库迁移、工程脚本：无。生成物：本地 H5 热更新产物，不作源码交付。此次业务注释更新于 `home/index.uvue`、`warehouse/index.uvue`、`mobile-home-client.uts`、`MobileWarehouseServices.php`。

## 测试结果

- `bash tests/mobile-warehouse/run-contract.sh`、PHP 语法及 `git diff --check`：通过。
- 本地 H5 账号下，经营首页、数据页首次进入及从其他主页签返回，均选中“今天”；今日现金业绩为 3,000 元。客户、工作台、我的三个底部页签均可进入；客户与工作台沿用当前门店上下文，经营与数据按授权组织范围展示。
- 数据页六个排行页签逐一点击：组织排行、门店排行、员工销售、员工劳动、员工点客、服务项目均返回结果，无报错或残留上一页签的内容。本月服务项目显示月度数据，切回今天后变为今日两项服务记录。
- 门店排行今天为 `2026-10-13`、首批 `10/102`；本月为 `2026-10-01 至 2026-10-31`、首批 `10/102`，下滑后 `20/102`，前 10 家保留，排序结果随时间区间变化。
- 573px 小屏截图中，门店排行摘要的统计期、`10/102` 和“反序”均完整可见。
- 组织排行下的趋势图保留既有整月趋势上下文，图例明确标注“本期 2026年10月”；排行值和经营概览按上方选择的统计区间计算。本轮未调整趋势图的比较口径。
- 指定门店权限已做纯策略/组织投影测试，尚未使用指定门店员工账号在真实 H5 页面操作。商家端全量历史套件含其他页面旧路径失败，本轮未宣称全量通过。
