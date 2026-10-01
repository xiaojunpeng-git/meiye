# R47 本地充值赠券数据库依赖补齐

日期：2026-10-02，Asia/Shanghai。授权：产品经理针对本地 18091 充值失败要求“修复”。源码基线 HEAD：`fd82afc02f76c8a70b3f81c909ef9af8bcdb767d`。仅处理本地 `mohe-cashier-app` 连接的 `mysql/ruihao`，数据库服务器标识 `de34b8634512`，MySQL 5.7.44；远程实例、部署、push 不适用。沿用用户本地数据库不用备份的指示。

## 原因与执行

本地日志在 2026-10-02 00:52:38 记录 `submit-recharge-checkout` 报 SQLSTATE 42S02：`eb_cashier_v3_recharge_gift_coupon_issue_mapping` 不存在。首次升级前检查另发现其依赖 `eb_cashier_v3_recharge_gift_reversal` 缺失，兼容领取表 `eb_store_coupon_issue_user` 尚无技术自增主键。

按唯一源码正本已有升级依次执行：

- `后端代码/database/upgrades/2026-08-22-收银V3充值赠送冲销审计/`：前置检查、正式升级、后置验证均 PASS。正式 SQL SHA-256：`a3dbbd682a4dc3b1a412ad52fa2a93ccfa7d1356c7e076180c204238925ed1f3`。
- `后端代码/database/upgrades/2026-08-05-收银V3充值赠券领取冲销/`：前置检查、正式升级、后置验证均 PASS。正式 SQL SHA-256：`7e3cd9ee8f9b9bcbcc4c4e3cd8cae9118be01d172c7572a9876332f5edcb0449`。

新增两张存储表，兼容领取日志新增 `id` 自增主键。没有回填历史赠券关联。DDL 幂等重跑通过。执行器第一次连接未初始化、第二次按分号分割时遇到 SQL 注释中的分号；均停止于报错位置，修正执行方式后重新按完整前置/正式/后置流程通过。未改写原 SQL。

## 验证

- 两套升级均返回 `PRECHECK_OK`、`APPLY_OK`、`POSTCHECK_OK`，包括表引擎、唯一索引、自增主键和数据状态验证。
- 领取历史 5,022 行，原字段 `uid/issue_coupon_id/add_time` 的 CRC32 汇总校验值升级前后同为 `11426057621196`。
- 现有 `recharge-gift-coupon-claim-reversal-contract.php` PASS。
- 本地事务内调用实际 `CashierV3RechargeGiftIssuanceServices` 的赠券发放、领取映射写入、重放关联校验：`COUPON_ISSUE_MAPPING_AND_REPLAY=PASS`。使用本地既有充值单 198 的会员和有效券做回滚测试；所有插入均回滚。
- 回滚后领取记录、会员券、映射表行数与测试前一致，券发行记录及库存完整相等：`ROLLBACK_COUNTS_AND_COUPON_INVENTORY_UNCHANGED=PASS`。自增技术序列可能因回滚测试保留间隙，不影响业务或唯一性。
- 未自动重提页面上的 1,888 元充值，未产生正式充值或收款测试记录。数据库依赖故障已消除，整笔页面充值仍由产品经理重新操作确认。

## 数据架构与恢复边界

充值赠送主表、赠送明细与券实例保持原权威关系；领取映射按每一张券实例保存精确领取 ID，唯一键防止重复关联。冲销表追加审计，配合原事务内事实与事件，不改变金额、业务日期、权限范围或统计口径。历史记录仅增加技术身份，未回填或覆盖业务事实。新增 InnoDB 表支持原事务回滚及唯一约束。

新增表尚无正式数据；后续一旦写入正式映射或冲销历史，不得删表/清空，应按原迁移应急说明追加修复。领取主键不得随意移除。

文件分组：前端源码、后端源码、工程脚本、自动化测试、生成物无新增或修改；数据库执行采用上述已有迁移正本；新增验收证据为本文件。此前充值销售人回显待验收改动及其他工作区改动未纳入本次操作。未 commit、未部署、未 push。
