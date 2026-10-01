# R47 纯赠金充值调整销售人后列表与编辑器回显——本地自测

基线 HEAD：`fd82afc02f76c8a70b3f81c909ef9af8bcdb767d`。已跟踪文件补丁 SHA-256：`f76099f5600a5052cd35694aaace66273cf1d22027d8f092400d6971e7e9127e`。新增文件逐项 SHA-256 见下。

## 问题与修复

本地 `ruihao`、门店 16、充值单 `CZ26100200001`（主单 ID 198）为本金 0、赠金 300。原单销售人数组为空，但用户之后两次调整销售人均成功，最新操作 `TZ261002BA8D0` 保存了“韩海燕”的人员快照。该单没有记账收款，销售现金业绩事实为 0 条；旧列表只从现金业绩事实读姓名，因此显示“—”。

列表及编辑器现在共用同租户、同门店、同充值单的最近一次成功人员调整冻结快照。列表回显姓名，编辑器回显已选人员和分配比例；金额仍只取有效销售业绩事实，未调整的充值继续沿用原事实。不回写充值主单，不新增虚假销售业绩，也不删除历史调整记录。

## 固定文件与验证

- 后端源码 `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php`，SHA-256 `aa6afe827192f27d73abf06fa69caf7a70efb070edeb452f9472f8dc3b4d1c35`。
- 后端源码 `后端代码/app/services/cashier/v3/order/CashierV3RechargePersonnelAdjustmentServices.php`，SHA-256 `5e867eb837695521517e0b87e5aeb6b3cd8ae37439d1754007bda2fb3326ad8f`。
- 后端源码 `后端代码/app/services/cashier/v3/order/CashierV3RechargePersonnelSnapshotReader.php`，SHA-256 `61fb591d13fe1097887310f63c268e6d6972a0b4004331e1a5b552c8c14950f4`。
- 自动化测试 `tests/cashier-v3/php/order-center-records-readonly.php`，SHA-256 `ddbb031eb839d69568174c73e3b5e1cfbcc5077882faa43932f675d85c0d4ed8`。
- 前端源码、数据库迁移、工程脚本、生成物：无。本文件为验收证据。
- 三个 PHP 文件语法检查 PASS；订单中心只读合同 40 PASS、0 FAIL；`git diff --check` PASS。
- 本地权威查询：该单 `salespersonName=韩海燕`，`rechargeAmount=0.00`，`actualReceivedAmount=0.00`；最新成功调整 `amount_cents=0`，销售现金业绩事实数仍为 0。
- 本地门店端只读接口 `open-recharge-personnel-adjustment` 返回订单 `CZ26100200001`、`cashPerformanceCents=0`、`currentSalespeople=[韩海燕]`，其员工 ID 为 1102、分配比例 100、业绩金额 0。权限上下文为江苏淮盛店实际登录门店 16，没有执行写命令。
- 刷新 `18091` 后在订单中心点击销售人“韩海燕”，页面编辑器显示“已选择 1 人”、人员“韩海燕”、比例 100%、业绩金额 0。未点击确认或产生新业务记录。

## 数据架构自检与限制

充值主单与追加式人员调整操作是人员归属的业务权威；仅有真实现金业绩时才有销售业绩事实。事件/操作保留业务时间、记录时间及发生时员工姓名快照，列表取最近成功操作，不回读员工当前名称。此次没有新写命令、事务、幂等键、冲销、余额、收款、报表指标或历史回填；权限由原订单列表的租户和门店范围收紧，补充操作读取再以同租户、同门店和当前页充值 ID 关联，最多按当前页查询。真实单据和 40 项合同用于对账与回归。

最新人员调整曾手填 300 元，但该单现金业绩基数为 0；本次只让已保存的销售人姓名可见，**不把这 300 元计为现金业绩**。若产品需要把赠金发放对应其他非现金人员指标，应另行明确口径。产品经理于 2026-10-02 汇总本轮后明确“我测试没有问题,commit,部署瑞昊,push”，已验收当前固定版本并授权瑞昊发布与推送；具体执行结果另记发布记录。

代码注释：已在 `CashierV3OrderCenterRecordQueryServices.php`、`CashierV3RechargePersonnelAdjustmentServices.php` 和新增的 `CashierV3RechargePersonnelSnapshotReader.php` 解释调整快照优先、权限范围和纯赠金不制造业绩事实的边界，并在订单中心合同测试中同步说明。
