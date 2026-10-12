# 008 渼约会员、赠金、积分及小程序身份固定版（待产品经理验收）

## 范围和权威口径

- 仅来源 `morestore:3306` → 目标 `mohe_008:3307`。来源 5,729 个未删除 UID，4 组 11 个同手机号来源 UID 合并成 4 个目标 UID，总计 5,722 个目标会员。按每组最小来源 UID 取资料主档；映射表保留全部 5,729 条来源 UID，允许多个来源 UID 对同一个目标 UID。
- 每个来源 UID 先取旧会员端显示口径 `intval(eb_user.now_money)`，再按目标 UID 求和。总渼约币/赠金 590,340 元，本金 0；当前积分取来源 `eb_user.integral` 后求和，总计 124,793 分。历史账不反算或再次增加当前积分。
- 旧积分流水 5,417 条中可关联会员的 5,410 条保留原 ID、`add_time`，映射目标 UID，设 `obtain_time=add_time`、`frozen_time=0`。7 条孤儿账 ID `42,77,79,80,81,82,83` 仅记跳过审计。
- 4,622 个有效唯一手机号各建立一条 `BOUND` 身份和同事务审计。空号及格式异常手机号不创建身份。
- 新旧小程序 AppID 已只读核实相同。旧 `eb_wechat_user` 中 4,650 条未删除 `routine` 身份按旧 UID 映射到目标 UID，保留原 ID、OpenID、UnionID、必要资料和时间。13 条已删除 `routine` 身份不导；其他微信、APP、Apple 身份不导。未复制 `session_key`、旧 token 或临时登录态。
- `eb_user.user_type`、`login_type` 复制合并代表的旧值，而不是使用 008 空字符串默认值。旧渠道分布为 `routine 4553 / wechat 894 / app 269 / h5 10 / apple 3`，合并后 `4548 / 893 / 269 / 9 / 3`，逐主档对账差异 0。小程序登录代码由 `eb_wechat_user` 的 OpenID/UnionID 查 UID；合并组的非代表来源 OpenID 仍映射到同一个目标会员，不擅自改写主档渠道。
- 2,998 个目标会员没有可靠营业门店，`belong_store_id=0`、映射表保留旧门店 ID；只在旧营业店或明确营业店关系可映射时填新门店。当前会员页 `personalHome()` 按 `belong_store_id` 显示店名。

## 固定版本

基线 HEAD：`2967c0c90539ae3975c402db8da69f876738fee1`。本闭环新增文件：

| 类别 | 文件 | SHA-256 |
| --- | --- | --- |
| 工程脚本 | `scripts/import/customer_008_morestore_member_points.php` | `510fcfd67ecf0513bfd14364565642133a8e61437eef164a3ccda3939eae6903` |
| 工程脚本 | `scripts/import/customer_008_morestore_member_points_verify.php` | `c322d8956945abcf4ad3a8779d9e91d70d886f011d828c516fc9b2d3c4bcff69` |
| 自动化测试 | `tests/customer-008/php/member-points-import-contract.php` | `1d400472937b5a04e25346e95ab4d99e70ee63ae6d18b2afbf1befb28df73bc4` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-13-008渼约会员积分导入/01-映射与审计.sql` | `79a86240b95a54d09dd4f0df87d03547f8a63bf29d6435d9b9e402ebd1a32941` |

复用商品/门店映射正本 `scripts/import/customer_008_morestore_catalog_plan.json`，SHA-256 `aeeeac75ecab64cd434357e42a12e5d0e42cd464cde08ed2c39982e072d23973`。当前**来源行、routine 身份和 22 店映射联合指纹**：`5a2e8bb7712d348a764e029a3c80c40f96bb7088610b8791eccbeb6c0b47c8e5`。此前的 `78869e...` 只含旧三类数据行、`fe6141...` 在此基础上纳入门店映射；本版又纳入 `user_type/login_type` 与 routine 身份，故指纹变化属于覆盖范围变化，成员及积分原始数量未变。

## 自测和隔离演练

1. `php -l` 检查两脚本与合同测试均通过；`php tests/customer-008/php/member-points-import-contract.php` 通过重复手机号、逐人取整、空/错号分离、停业店不猜归属、样本 UID 421 和 routine 删态测试。
2. 先在隔离 MySQL 8.4 完成全量演练，再用**MySQL 5.7.44** 复演。旧来源只读 `mysqldump --single-transaction` 通过内存管道装入独立隔离库；008 目标完整 `--no-data --routines --triggers` 结构及门店行经只读管道装入同一 5.7 实例，未落盘会员资料文件。隔离结构与线上只读统计同为 **535 张表/视图、4 个 routine、28 个 trigger**；会员姓名索引 INSERT trigger、`eb_member_name_initials` 函数和索引表全部保留启用，未做禁用。全量调用 `m008Source → m008Resolution → m008Summary → m008Apply` 成功，内置逐人、逐表和身份校验通过。结果：`5729→5722`、映射 5,729、积分账 5,410、孤儿账 7、手机号身份及审计各 4,622、小程序身份 4,650、赠金 590,340、积分 124,793。姓名索引为 5,722 行，目标会员无索引缺漏；再次执行被目标非空守卫拒绝。
3. 隔离库按代表 UID 比较旧/新 `user_type/login_type`，差异 0。样本旧 UID 421 → 目标 UID 421，赠金 13,692 元、积分 4,950 分；小程序身份存在。其 15 条可用权益和逐条余次由权益批次核验。
4. 结合 `UserBalanceAtomicServices::deductPreferBen()` 的实际字段契约，在 MySQL 5.7 隔离库用 UID 421 模拟赠金 100 元支用：原 `eb_user_money` 为 0 行，扣款后临时余额/赠金同为 13,592 元、本金 0、版本从 1 加到 2，流水记录 `give_change_amount=-100`；事务随后回滚至 13,692 元、版本 1。此验证覆盖数据结构与余额写入条件，不冒充线上真实支付或完整应用服务调用。
5. 线上只读预检：008 `eb_user`、`eb_user_bill`、`eb_user_money`、`eb_store_user`、手机号身份及审计、`eb_wechat_user` 均为 0；旧来源当前计划指纹与隔离演练一致。`--plan` 仅只读输出统计；正式脚本还要求目标实例、来源指纹和脚本 SHA 守卫，重复导入会拒绝。

## 执行边界与后续对账

- 本版尚未本地提交、部署或远程写库。固定版产品经理验收和目标实例授权/回滚检查仍适用。正式导入前重跑 `--plan`，若联合指纹不一致立即停；执行后用 `customer_008_morestore_member_points_verify.php --source-uid=421` 只读核对来源 UID→目标 UID、会员资产、积分账、手机号身份及 routine 身份。该工具只输出 UID 和资产数值，不输出手机号或 OpenID。
- 会员与权益须处于同一个旧站截面：导入窗口开始前分别重算本批会员指纹 `5a2e8b...` 和权益批次指纹 `48db3e09a4be69e0bc53f11d847d933f81e92bdc00ed4cfacd48ee20241fc052`，均须匹配各自固定版；两批导入完成后再分别重算，必须与导入前相同。最好在旧站写入暂停窗口执行；如果旧站继续发生资金、积分或权益变动，双指纹任一变化就停止后续批次，并按来源键做差异补偿。独立指纹不能代替严格的共同数据库快照，跨批次事务一致性仍依赖写入暂停窗口或受控共同快照。年度积分到期启用及并发幂等由独立闭环实现；合并后 2026 年末预估 544 人、88,972 分。
- 不在本批复制公众号/APP/Apple OAuth、历史充值/订单/支付事实。代码注释已覆盖导入脚本、对账脚本和合同测试的职责、权威值、身份边界、事务和失败守卫。
