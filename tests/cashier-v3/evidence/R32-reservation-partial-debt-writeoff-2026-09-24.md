# R32 预约部分欠款可用次数扣次修复验证

## 修复结论

旧逻辑只要权益来源订单存在任何未结欠款，预约结束服务就整行禁止扣次，因此把“已付金额足够覆盖本次服务”误判为“欠款未扣权益”。

现已调整为复用收银权益核销的统一折算函数：先把实际已付金额折算为可用次数，只有折算后可用次数小于本次需扣次数时，才阻止扣次并提示人工处理。物理剩余次数不足的保护保持不变。

## 本地真实业务记录（保留）

- 门店：政和朝阳店
- 会员：原120吴学莲，`13382727491`
- 预约单：`YY2609240011`（数据库 ID `118`）
- 服务单：`FW092400015`
- 项目／卡项：舒缓灸
- 预约时间：2026-09-24 20:00–21:00
- 手艺人：黄文弟
- 备注：`R32欠款折算单次扣次测试`
- 权益明细 ID：`2416885`，来源订单 ID：`1263669`，持有者 ID：`1069924`
- 卡项金额：¥2000，未结欠款：¥1900，已付覆盖额：¥100
- 总次数：20，欠款折算后可用次数：1

### 操作路径

1. 新建预约并选择“舒缓灸”卡内项目。
2. 保存预约，进入详情点击“开始服务”。
3. 点击“结束服务”，等待事务完成。
4. 重新查看详情和后端权威事实。

### 验证结果

- 预约状态：`COMPLETED`，页面显示“已结束”。
- 项目结果：页面显示“已扣权益——已从卡项「舒缓灸」扣除 1 次”，未出现“欠款未扣权益”。
- 权益明细剩余次数：`20 -> 19`。
- 卡项持有者剩余次数：`20 -> 19`。
- 核销事实：1 条，数量 1，状态 `effective`。
- 服务事实：1 条，状态 `completed`。
- 业绩事实：2 条（消耗业绩 1 条、劳动业绩 1 条）。
- 结束服务操作事实：`writeoff=1`、`manualWriteoffRequired=false`、`debtBlockedEntitlementCount=0`、`insufficientEntitlementCount=0`。
- 测试记录未删除、未回滚，产品经理可直接在本地预约页查看 `YY2609240011`。

## 自动化校验

- PHP 语法检查：通过。
- `reservation-lifecycle-contract.php`：全部通过，`RESERVATION_LIFECYCLE_CONTRACT=PASS`。
- `reservation-debt-limited-writeoff-contract.php`：全部通过，覆盖“部分欠款仍可扣 1 次”和“欠款覆盖全额仍阻止”。
- `git diff --check`：通过。
- 本地依赖的旧版组件会输出 PHP Deprecated 警告，不影响本次断言结果。

## 固定验收版本

- 基线 HEAD：`cbe7c928a2e1ffd11af45b9d787a16feb09040ca`
- 业务补丁指纹：`c8a3d401fd92ae29bae6dd63b52dc2c3ad0b4dd61503fc541c28c46be1e4e0da`
- 业务文件 SHA-256：
  - `CashierV3ReservationLifecycleServices.php`: `c2d7dc8b567079053b86fb9ec84f4822c0f38d74e81541ea84fb0519fb930d30`
  - `reservation-lifecycle-contract.php`: `4a2cc9880e98a486856626bceed21e1f567bb7bc146a990d570b6b1d4b7f58a1`
  - `reservation-debt-limited-writeoff-contract.php`: `8b55195e66abebbd53eb485d8eab8f59d7a631fc2b316e374377c3b88476d8a6`
- 本次业务代码注释已补充：说明了统一欠款折算的责任、输入边界及不得偏离收银核销口径的约束。

### 闭环文件

- `后端代码/app/services/cashier/v3/reservation/CashierV3ReservationLifecycleServices.php`
- `tests/cashier-v3/php/reservation-lifecycle-contract.php`
- `tests/cashier-v3/php/reservation-debt-limited-writeoff-contract.php`
- `tests/cashier-v3/evidence/R32-reservation-partial-debt-writeoff-2026-09-24.md`

产品经理已于 2026-09-24 确认本地测试无问题，并明确授权 commit、部署瑞昊和线上验证后 push。

## 瑞昊部署与线上验证

- 业务提交：`ecd3176f R32 预约部分欠款可用次数扣次闭环`。
- 目标仅为瑞昊 `rh.cc3798.com`，站点 `/www/wwwroot/rh.cc3798.com`，数据库只读核对为 `ruihao`。`007`、`008`、`012` 未连接、未部署。
- 本次无 SQL、无配置、无前端构建变更；仅从固定提交精准导出并同步 `CashierV3ReservationLifecycleServices.php`，没有上传工作区的其他未提交改动。
- 部署前运行文件 SHA-256：`88c44cdf327d73e43b2e33a4783d8b57a7fdef0e848caadf61e56f7f02194c1b`。
- 独立回滚备份：`/www/backups/rh.cc3798.com/20260924-r32-ecd3176f-partial-debt-TWJbgewc/backend-before.tar.gz`，SHA-256 `5d72bd29aa1661dfb0b707d5b2a469603114b82ca04a9e1ffb094bda480c234a`。
- 部署后运行文件 SHA-256：`c2d7dc8b567079053b86fb9ec84f4822c0f38d74e81541ea84fb0519fb930d30`，与固定提交一致；线上 PHP 语法检查通过。
- 瑞昊 Swoole 重启后 `manager_count=1`；AI execution worker、Excel worker 和 supervisor 均为 `active`；近期日志 `Fatal error / Parse error / Uncaught` 计数为 0。
- 正式入口 `/view_cashier_v3/?release=ecd3176f#/reservation` 与 8080 预览入口 `/preview-8080/view_cashier_v3/?release=ecd3176f#/reservation` 均返回 HTTP 200。
- 对原问题单 `YY2609240076` 做瑞昊线上只读计算：权益明细 `3106913` 物理剩余 30 次，未结欠款 ¥5480，统一折算结果为可用 6 次，`one_use_allowed=true`。这证明新逻辑对该原问题数据不再阻止单次扣次。
- 线上验证全程只读，未新增、修改或删除瑞昊业务数据；未重写原历史服务结果。

如需回滚，仅恢复上述备份中的后端文件，清理瑞昊运行缓存并重启瑞昊 Swoole 及相关常驻服务；不涉及数据库回滚。
