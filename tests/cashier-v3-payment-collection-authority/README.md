# 收银 V3 正式记账收款权威永久测试

本测试保护 `cashier-v3-payment-collection-authority-v1` 的隔离底座：

- 输入只能是最终加锁的 `ready_for_submit + sale_only/mixed` 结账聚合和同一命令、同一 composition 生成的正式销售订单计划；`entitlement_only` 不生成零金额收款批次。
- 七种固定记账收款一条 payment draft 对应一条 collection；旧卡录入和纸币现金拒绝。
- 收款、现金业绩、应收三项总额一致，余额和欠款在本 V1 必须为 0。
- 历史方式、业务时间、最终成功时间、操作人、门店组织、来源单和参考号均保存快照。
- 服务端 HMAC ID、自然键、请求级 batch、数据库唯一约束、不可变重放与 DataScope 防止重复落账。
- writer 只参与 caller-owned transaction；不接 Gateway、不激活 `submit-checkout`，也不单独发布事实/事件/Outbox。
- 迁移只创建两张 MySQL 5.6.51 兼容新表，包含前检、后检、部分创表只读恢复审计和逐实例边界。

执行：

```bash
bash tests/cashier-v3-payment-collection-authority/run-static.sh
```

无本机 PHP 命令且任务禁止启动 Docker 时，runner 会明确跳过 PHP 运行时合同；Node 仍检查 PHP 词法结构、业务合同、迁移形状和校验和。
