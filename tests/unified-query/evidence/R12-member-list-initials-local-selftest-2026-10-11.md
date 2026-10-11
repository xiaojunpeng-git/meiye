# R12 会员列表、订单中心与预售领用查询及分页：本地自测

- 状态：开发与自测完成，待产品经理对本固定版本确认验收；未 commit、未部署、未 push、未改线上数据。
- 基线 HEAD：`2bb1eb7dc6bc0b1b3a11342422918bd7a9f3b996`。
- 补丁指纹（以下四个后端文件的 `git diff` SHA-256）：`aa70b402dbafdae277f5df25990d4dec3d77d6ca201cd898bb8fafc2e9094f71`。
- 领用页前端补丁指纹（`git diff` SHA-256）：`30ac6f86ec065e788eecf09ed06d2e3461b5dcf336b84624506dc1cf7fd1704e`。

## 范围与口径

- 前端源码：`PresaleClaimView.vue`；会员列表与订单中心原查询输入框、筛选项与布局不变。领用页保持服务端每页 20 条查询，列表独立纵向滚动，分页栏固定可见并显示总数与总页数；预售/赠送共用。
- 后端源码：`UnifiedQueryExecutionServices.php`、`MemberUnifiedQueryProvider.php`、`AbstractCashierV3OrderCenterUnifiedQueryProvider.php`、`CashierV3PresaleClaimServices.php`。复用既有 `member_name_initial_search`，纯英文字母关键词额外匹配会员姓名首字母；姓名/电话/编号/订单内容等原有关键词仍可命中。会员列表的 SQL 分页和二次过滤一致；订单中心 8 类记录在权威 Reader 裁剪后批量关联会员索引，列表、筛选与冻结关键词导出共用口径。预售与赠送资格按已授权门店、来源、状态、日期过滤后，额外用当前会员姓名首字母定位资格，总数与分页使用同一 SQL 条件。历史姓名快照只展示、不覆盖。
- 数据库迁移：无；依赖已存在的 2026-10-06 姓名首字母索引迁移，不更改会员姓名权威主表。
- 自动化测试：`tests/unified-query/php/member-name-initial-list-integration.php`、`tests/cashier-v3/php/order-center-member-initials-integration.php`、`tests/cashier-v3/php/presale-claim-member-initials-integration.php`；只读本地 `ruihao` 数据库。
- 工程脚本、生成物：无。
- 验收证据：本文件；未进行瑞昊线上操作。

## 固定文件 SHA-256

| 文件 | SHA-256 |
| --- | --- |
| `前端代码/cashier-v3/src/views/PresaleClaimView.vue` | `4099d9dd1fded59adb626aaf3729740c76c0b0868268ca2b0cbb3c98457cc230` |
| `后端代码/app/services/query/UnifiedQueryExecutionServices.php` | `1a019e545cdf10cdba281ea021d7347a2b79b437328974410b14900a627cae71` |
| `后端代码/app/services/query/provider/MemberUnifiedQueryProvider.php` | `f90ace0e9b3ba9f964e9706e26f12c7cb533fffdea3df8ccfff601b1695d5d70` |
| `后端代码/app/services/cashier/v3/order/AbstractCashierV3OrderCenterUnifiedQueryProvider.php` | `797f221410e1dca34e4e438e21218db7b8bcebd4fca65ead43f1a31cb097b528` |
| `后端代码/app/services/cashier/v3/presale/CashierV3PresaleClaimServices.php` | `acd261436d7dd0eba45b566146e417af49ff3698730da73b34780c36451835eb` |
| `tests/unified-query/php/member-name-initial-list-integration.php` | `aafaf12b445ac79d273e81b416f92e5042f44cc614e80c89846113f070d79dc8` |
| `tests/cashier-v3/php/order-center-member-initials-integration.php` | `c20dd16e4ebf7c35c92a384b10ae6004357f792c684e7f23c1e9643d9f1cacfe` |
| `tests/cashier-v3/php/presale-claim-member-initials-integration.php` | `1d9e41001eee912f322fbd98d307c1ccd4d48ce9197d7e94937db6b7ae77f998` |

## 验证

- `docker exec -i mohe-cashier-app php < tests/unified-query/php/member-name-initial-list-integration.php`：7 项 PASS；覆盖索引命中、门店隔离、原会员编号匹配、执行器二次筛选、非字母词、默认列表、高级筛选与导出。
- `docker exec -i mohe-cashier-app php < tests/cashier-v3/php/order-center-member-initials-integration.php`：33 项 PASS；覆盖 8 个页签的首字母/原单号并存、日期范围、冻结关键词导出、非标准关键词排除；另从本地真实销售单验证了授权门店内端到端命中。
- `docker exec -i mohe-cashier-app php < tests/cashier-v3/php/presale-claim-member-initials-integration.php`：19 项 PASS；本地真实预售、赠送资格分别验证首字母、原订单号、手机号、商品名、状态/日期/门店范围与分页总数。
- 本地 18091 页面实测：241 条、每页 20 条、共 13 页；列表内部可纵向滚动到末行，分页栏保持在视口底部；第 1→2→1 页切换后每页均为 20 条。Vue SFC 模板/样式编译及该前端文件 `git diff --check` 通过。
- 7 个涉及的 PHP 文件 `php -l` 通过，4 个后端文件 `git diff --check` 通过。
- 既有 `tests/cashier-v3/js/member-name-initial-search-contract.mjs` 有 2 项旧断言失败：该测试仍匹配早期选择弹窗文案及调用格式；被断言的 `MemberSelectorOverlay.vue` 和 `CashierV3MemberModule.php` 在本轮均未改动。其余 6 项通过，此问题不属于本次列表改动的回归。
- 既有订单中心测试 `r37-order-center-unified-filters.php` 的 8 类“不可能筛选”均通过，后续固定样例 `salesperson identity mismatch` 失败；本轮无关键词时新增分支不运行，固定样例断言与当前本地结果不符，未判定为本次改动的回归。`order-center-unified-export-contract.php` 有 3 项旧页面/日期文本断言失败；本轮未修改其断言的前端、订单 Reader 或字段合同。两套旧测试均不能记为通过。

## 数据架构自检

- 权威源与粒度：会员姓名仍来自 `user`，首字母表是由触发器同步的可重建投影；订单仍按原销售/充值/退款等权威 Reader 的记录粒度查询，预售领用仍按每条可领用资格查询，不新增事实。
- 事件、时间与事务：只读检索，不生成业务事件、收款、业绩或权益；原业务日期和操作时间筛选不变，不涉及新事务、并发写入或 Outbox。
- 幂等、冲销与恢复：没有写操作或历史回填；订单作废、退款及调整状态仍由原 Reader 判定，本次不改变原单据与事实。
- 快照与权限：历史订单及预售资格的会员姓名快照不被当前姓名覆盖；首字母仅按已授权候选记录的会员 ID 查找，会员列表仍先按 `store_user` 注入 DataScope；预售资格的门店、来源、状态和日期条件维持在外层 SQL；列表与导出均在后端执行。
- 性能与历史兼容：复用已建的 `(initials,uid)` 索引和按 `uid` 唯一的投影；订单最多 10000 个授权候选、每批 500 个会员 ID 只读；非字母关键词不读取索引，缺失投影的记录仍保留原搜索行为。其他实例部署前应核实迁移。
- 对账与测试：本地索引命中与会员 SQL 范围、订单 Reader 和真实预售/赠送资格分别对照；测试覆盖分页、高级筛选、日期、导出、门店权限及真实销售单。无新增金额或统计指标，因此不改变报表合计。

## 注释与限制

- 补充/更新业务注释：`UnifiedQueryExecutionServices.php`（受控关键词扩展点及 DataScope 边界）、`MemberUnifiedQueryProvider.php`（索引复用、SQL 分页与导出口径）、`AbstractCashierV3OrderCenterUnifiedQueryProvider.php`（标准关键词、授权候选和历史快照边界）、`CashierV3PresaleClaimServices.php`（资格范围与历史快照边界）、`PresaleClaimView.vue`（外壳裁切与分页可达性）、三个测试文件（只读测试职责）。
- 尚未完成产品经理在本地页面的真人验收；线上预览仍为旧版本。其他客户实例的索引迁移状态不在本地开发范围内，后续如授权部署须逐实例核对。
