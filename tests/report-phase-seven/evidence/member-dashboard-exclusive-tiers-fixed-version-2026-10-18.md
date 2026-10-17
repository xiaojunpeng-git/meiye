# 会员看板双分层互斥区间 · 固定验收版本（2026-10-18）

## 范围与基线

- 任务开始唯一源码正本 `美容源码/` HEAD：`c5df15aa0247f5d9725d488f2b5076d7dc742819`；固定时 HEAD：`4e91594c5bf7661e0290242e37aaa779a80f4e4d`。期间其他任务推进提交，上述两点之间本轮相关已追踪文件无提交差异；本轮没有暂存或提交。后续库存任务提交涉及门店端共享依赖，已在该 HEAD 下重新构建，主资源指纹不变。
- 本轮仅本地源码、自测及本地 Docker `ruihao` 数据库只读事务。线上实例版本、迁移、备份、回滚、正式与预览入口指纹均不适用；截图中的瑞昊正式站未被本轮修改。
- 权威来源为注册指标 Reader 的 `actual_performance` 带符号事实，按查询日期和后端授权门店范围读取。一个会员在本期所有正负事实合成一笔净额；先用正向事实判定与概况一致的消费会员集合，再按配置的左闭右开区间归入唯一档位。净额为负但有正向事实的会员归最低档，金额保留负号；仅有零额或负向事实的会员不进入分层。新客列再排除来源 A。
- 会员分层各档人数可合计为概况消费人数；各档业绩合计为这批会员的净额。概况金额还可能含无会员关联及只含非正向事实的净额，不能把差额虚构进某会员档位。本地样本差额在下文单独对账。

## 源码、测试与生成物

| 类别 | 文件 | SHA-256 |
|---|---|---|
| 后端源码 | `后端代码/app/services/report/MemberManagementDashboardServices.php` | `acf1b3708ab8f7bc90aa7ae2cd872d77b8a0beceb37902445574b9cefe919f6d` |
| 前端源码 | `前端代码/cashier-v3/src/views/MemberDashboardView.vue` | `f2ad19171ff31c59f1117ab0371801d0ffc36d37629f7fbe68c2673261a1e696` |
| 自动化测试 | `tests/report-phase-seven/php/member-dashboard-contract.php` | `3526e296ffeddb641412cfd0f03c9e559f16c0654a6878e857f88079216f005f` |
| 自动化测试 | `tests/report-phase-seven/php/member-dashboard-exclusive-tiers-contract.php` | `7a9ec47a5fdd2c04cf816dd5ed6bc268fa4d6b5623177786a2b94f4724128afa` |
| 本地只读对账 | `tests/report-phase-seven/php/member-dashboard-exclusive-tiers-local-readonly.php` | `e0dc87c20d46645039f703ff2b5f9a6814bf3aa9e1686aee6ea8b0a58257e5ab` |
| 工程脚本 | `tests/report-phase-seven/run-static.sh` | `d603cef941331e8bcc1611bfbb11d52ca122aa3ebca1863a05adebcec5eaca89` |

- 完整替代了旧测试 `tests/report-phase-seven/php/member-dashboard-cumulative-tiers-contract.php`，经引用扫描仅历史验收记录引用该旧路径；历史记录保留原样。
- 已追踪目标文件工作区补丁 SHA-256：`2051f86bbf17b6591485f897cc7aab765a167ebccde89b5a999501a350354e59`。新测试文件按上表 SHA 单独固定。
- 本地构建入口：`前端代码/cashier-v3/`，对应 `/view_cashier_v3/` 源码。`dist/index.html` SHA-256 `3e95cc453192197b74d053ea25c2e8f399d4d2666408ab3d3b6bdef8d31552e6`；主 JS `assets/index-ef0bb8a0.js` SHA-256 `adb7027bdf3d3eb9040dc39e05db75dad8ae39dde390c622ea8d0683a6530e76`；主 CSS `assets/index-90e88fa2.css` SHA-256 `90e88fa21fffaf9380634648a00a51b2d386ccea5561384f177f481673d41dd2`。这些是本地生成物，仅用于构建自测，未制成 `8080` 集成包。
- 数据库迁移：无。构建生成物与本验收记录均不属于业务源码。

## 自测与对账

- `member-dashboard-exclusive-tiers-contract.php`：12 项通过，覆盖边界、退款后负净额、只退款或零额会员排除、来源 A、人数与金额守恒、占比、重叠配置不重复及配置缺口失败。
- `member-dashboard-contract.php`：16 项通过；PHP 语法和 `git diff --check` 通过；门店端 `npm run build` 通过，前置权益快照 70 项通过。
- 本地瑞昊 Docker 数据库只读事务，`2026-10-01..2026-10-18`、102 家门店：概况消费人数 1,088 = 会员分层各档人数合计；会员分层净业绩合计 523,036,800 分；新客分层 201 人、净业绩合计 32,991,200 分，各自与对应会员集合逐人汇总一致。未进入会员分层的事实合计为 -6,684,300 分，概况总额与“分层会员金额 + 未归档事实金额”一致，未改写或丢弃原事实。
- 浏览器打开本地构建的 `?preview=1` 页面，核对双列表头、说明、区间数据和布局；示例数据也改为互斥且区间金额合理。本地热更新 `18091` 当时未启动，因此这里只证明构建预览，不声称热更新入口或正式站验收。
- 完整 `tests/report-phase-seven/run-static.sh` 在会员看板 16 项、互斥分层 12 项及集团看板稳定性测试通过后，停在另一任务已有未提交改动的 `CashierV3StoreTargetDashboardReadModel.php` 合同测试；该测试报告 8 项断言失败，与本轮会员分层文件无关。没有改动门店目标看板。
- 本轮补充或更新业务注释：`MemberManagementDashboardServices.php` 的会员口径、唯一归档和负净额边界；`MemberDashboardView.vue` 的预览数据与占比边界。字段说明和页面文字同步更新，指标版本升为 `member-management-dashboard-actual-v6`。

## 验收与交付状态

开发与本地自测完成。产品经理随后明确表示“我测试没有问题”，并授权本轮按本地 commit、瑞昊部署与验证、push、渼约 008 部署与验证的顺序发布。`member-dashboard-contract.php` 开工前已有另一任务未提交的改动；本轮暂存只包含分层相关的断言，既有改动留在工作区。此记录是验收时的固定版本快照，提交和逐实例发布结果另行登记。
