# R51 统一服务客数资格闭环（产品经理验收通过）

## 范围

2026-10-02，用户批准将朋友计数规则集中到底层，各报表复用，保留实际消耗和劳动金额。
本次集中的是**顾客计数资格**，不是把“人次、期间人数、体验人数、项目数量、员工分配”强行改成同一种去重粒度。各已确认粒度保留。
开发与本次核心业务自测完成。2026-10-02，产品经理针对本次扩展明确回复“我测试没有问题,commit”，验收通过；提交前已核对下列十个源码/测试文件指纹一致。本文件随本闭环本地提交，实际提交 SHA 与文件清单以包含本文件的 Git commit 为准；未部署、未 push。旧市场修复 commit `e0169655` 的验收未被冒充为本次扩展验收。

## 权威规则与接入

- 唯一资格规则：`ServiceCustomerEligibility`，版本 `service-customer-eligibility-v1`；SQL 范围与 PHP 投影共用该类，合法冻结状态的结果等价。
- 依据：完成服务事实的 `service_object`、`friend_counts_as_customer`。朋友开关为 0 不计客；本人不受该开关影响。
- 市场明细/市场业绩：移除上一闭环的局部 SQL 判断，改为统一资格。
- 会员进店分析、年度次数/人数：共用资格；年度项目模式仍保留实际核销数量。
- 品项成交分析：体验人数、活跃会员投影共用资格，购买及消耗事实不删。
- 六维数据分析：新/老客见诊人数共用资格，现金成交列及欠款不改。
- 地推明细：会员护理次序及第四次以上计数共用资格；手工费仍按原有完整服务集合计算，现金槽位不变。
- 异业明细/汇总：护理日期及首次到店时间使用可计客服务；现金、奖励、剩余卡权益不变。
- 员工薪资共用的服务客数 Reader、注册客户到店条件查询：接入同一资格，不另写朋友开关判断。
- 会员进店、体验及见诊的列解释同步，第二/三/四阶段结果返回资格版本。

服务审计、消耗、劳动、手工费、购买金额、收款金额、剩余卡次数不是顾客资格指标，不做全局排除。未改动前端布局、收银呈现或其他报表公式。

## 数据架构自检

1. 权威源/粒度：不可变完成服务事实，服务事实 ID 为追溯键；会员日/来源、体验项目会员、期间人数和员工分配保持各指标原有粒度。
2. 生效/时间：完成状态、原有成功作废与销售作废范围保留，归属日仍是 business_date；不伪造操作时间。
3. 事务/并发/幂等：此次仅修改读层，无业务写命令、Outbox 或回填；测试夹具在本地事务 finally 回滚。
4. 冲销：沿用原有事实有效状态和作废排除，不删除或覆盖历史服务。
5. 快照/权限：读取发生时冻结字段，保留门店/组织/员工参与范围；不按当前会员资料猜测朋友开关。
6. 统一出口：报表/导出共用查询，员工 Reader 和客户条件查询复用同一规则；不新增前端计算。
7. 性能/迁移：无迁移或构建；本地 EXPLAIN 使用 `idx_scope_date_status`，目标日预计扫描 4 行，无全表扫描。不存在新增大表关联。
8. 对账：实际数据只读核对及事务夹具跨报表验证；金额/项目数与客数分离。
9. 实例：仅本地 `mohe-cashier-app` 挂载的源码和本地数据库；远程实例、线上迁移、实例备份/回滚不适用，所有客户服务器保持原状。

## 固定版本和文件分组

基线 HEAD：`e0169655b98ff6d5048a885747ab31511369cfb0`。
本闭环已跟踪文件补丁 SHA-256：`0ae675f19477029b5626f07c2002348a44ae9b13120e8032329bd27be58e37a0`。新增文件以下列完整指纹固定。

后端源码（本次业务注释也在这些相关文件中更新）：

| 路径（相对源码正本） | SHA-256 |
|---|---|
| 后端代码/app/services/query/metric/ServiceCustomerEligibility.php | f8789cc0e4d97c1022af3dce799b2e452a3b56c1a083fb8c68ef0fe7f4ddd076 |
| 后端代码/app/services/query/metric/ServiceCustomerMetricReadServices.php | 772a6a4e469328c3b04209d84c74e9a7f0ee10633eb9636d6f2451caeb7b6495 |
| 后端代码/app/services/query/metric/RegisteredMetricReadServices.php | 0b5ca08fb04f9e70a77586b8a0b6a946e9ab27fbfa20f9624ae6e747920dbd8d |
| 后端代码/app/services/metric/MetricDictionaryServices.php | 64330149b96afbf4608eed781657c0875fa64a7169c9d6d02d706c35bf5fee2c |
| 后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php | e690bb5fd67aeb6b8cfb2463511ccc35324c31a277ad2a6fb2a8a8478d55681d |
| 后端代码/app/services/report/StoreUnifiedReportPhaseThreeServices.php | ed48bb5ec58cc105214e1c5242e4ecd69c60f5850b78cc5c7b9e342527a3686f |
| 后端代码/app/services/report/StoreUnifiedReportPhaseFourServices.php | c02e38bdac557768931ca8da866434f0c9ff01efd75d2ebbc96ace75c82708f2 |

自动化测试：

| 路径 | SHA-256 |
|---|---|
| tests/cashier-v3/php/r51-unified-service-customer.php | 307561a6955f4b10e8694e610987fff6fdf579ca2c4ab0779b67363a426e7e2d |
| tests/cashier-v3/php/r51-service-customer-authority-contract.php | afbc1f59b19c6803ff346259b646a4648602bf0b543a4a08766081291dd84c70 |
| tests/report-phase-six/php/service-contract.php | 519f35ddd638f8ccf67c61c7172100580bde9d33d11146c8fac16a032183346c |

验收证据：本文件。前端、迁移、工程脚本、生成物：无。其他任务已有脏工作区改动均保留，不纳入本闭环。

## 测试与业务核对

- 七个后端文件 Docker PHP 7.4 语法检查、`git diff --check`：PASS。
- 新统一 MySQL 回滚测试：20 项 PASS，覆盖多个报表同步开关、本人/朋友、SQL/PHP 一致、员工分配、去重、权限、导出、项目数量和现金保持。
- 新统一接入合同：8 项 PASS；原局部市场 SQL 已替换，引用扫描仅中央类保留该条件。
- R51 原市场回滚测试、R38 纯权益回滚测试：PASS（朋友、游客、同单项目、服务作废、来源、零现金与权限）。
- 六个市场合同、薪资服务合同、R54 员工分配测试：PASS。
- 真实本地数据：周梅市场人次 0，会员进店分析总进店数 0；2026-10-02 年度次数模式合计 1；市场/会员进店/年度报表查询和导出 records 一致。
- 原服务 FW100200004 未改写，仍为 friend/0/数量1/completed；U51 服务夹具剩余 0 条。
- 地推和异业接入主要通过静态合同及完整服务投影核对，未冒充真实 E/G 渠道人工页面验收。

## 已知限制

1. 既有 `tests/report-phase-three/php/service-contract.php` 在“平台注释读写显式报表权限”断言失败并提前退出，不记为全部通过。失败断言读取的是未修改的后台控制器，当前方法转调用 `canAccessReport`，合同要求在该方法直接调用 `canAccessSixDimensionReport`；控制器与基线 Git 对象一致。本次不擅自改平台权限，也不宣称平台权限整体验收通过。
2. 获取当前 IAB 页签时 `Emulation.setFocusEmulationEnabled` 超时，未获得可验证的浏览器页面结果。后端真实业务查询已完成，但不能替代产品经理固定版本页面验收。
3. 这次统一资格，不表示已重构全系统所有经营指标或平台专用报表。不同业务指标的去重粒度保留；后续新增顾客类指标必须复用中央资格。

产品经理本次自行测试并明确确认通过；该确认不改写上述 Codex 浏览器验证失败或既有合同失败的事实。仅按本闭环七个后端文件、三个测试文件及本证据文件（共十一个文件）本地提交。其他工作区改动保持未提交，没有部署或 push 授权。
