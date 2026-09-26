# R38 混合与纯权益作废返还修复

状态：开发与本地自测完成，待产品经理对本固定版本确认验收；未提交、未部署。

## 原因与边界

本地 XS26092700001 的两条服务已有成功作废及返还事实，旧卡包与原项目剩余次数为 10，但卡规则 component 3344 的 remaining_times 为 8。旧返还实现只更新旧余额，没有同步新卡规则权威次数。混合订单服务级联和纯权益整组作废都复用该实现。

修复在同一作废事务内同步卡规则：普通/任选种类恢复项目次数，共享次数卡恢复共享池，时间卡不增加次数、不延长有效期；不解除任选种类历史选择。保留原服务锁和作废幂等账本，已转移/取消状态或超过原始总次数时拒绝并交由外层回滚。删除原独立时间卡查询，统一由规则服务判断，避免维护两套规则识别。

未修改页面、资金退款、手艺人/销售人修改逻辑；未补改历史余额、未执行远程操作。

## 文件分组与固定版本

基线 HEAD：6062359220458738987d238c6100528eb13189c1。

后端源码（两文件均补充职责与事务/返还边界注释）：

- app/services/cashier/v3/card/CashierV3CardRuleEntitlementAuthorityServices.php，SHA256 `52740ba10b951578237eec40f03d60f758a43b1dfdbd191a147f818bdfe4073d`
- app/services/cashier/v3/order/CashierV3ServiceRecordVoidServices.php，SHA256 `56125c279806d3a1b0e39ed2e7ae1e913c35c6ab550f75f048205534e2f87d58`

自动化测试：

- tests/cashier-v3/php/card-rule-entitlement-authority-integration.php，SHA256 `747dd123f3436859eef1c3c019cac4bd5ea4f1bacbae0e165ee5bb5fbca7de50`
- tests/cashier-v3/php/r38-service-void-rule-mysql.php（新增），SHA256 `b56f434a6b1e1efe973195e80595875837a4028c6f00f038797b6643cae24c78`

上述已跟踪三文件 git diff 补丁指纹：`e29ae2c1af8e6ec467de91135171dfbb3b99bd7362d8ea88146539ec626fe470`；新增测试以独立文件指纹固定。

前端、数据库迁移、工程脚本、构建生成物：无。验收证据：本文。工作区其他既有改动未纳入本闭环。

## 实际验证

环境：本地 mohe-cashier-app PHP 7.4，真实本地 MySQL，测试事务最终回滚。没有生产库连接。

1. 两个后端文件 PHP lint 通过，git diff --check 通过。
2. card-rule-entitlement-authority-integration.php：19 个断言通过、0 失败。原扣减、升级、替换、重放、时间卡、过期、指纹与版本冲突回归通过；新增四规则返还、耗尽恢复、超总次数拒绝、旧卡无规则回退验证通过；测试规则均回滚。
3. r38-service-void-rule-mysql.php：16 个断言通过。基于本地服务 2055 的结构建立隔离 checkout 夹具；混合单执行真实整单作废使用的服务级联方法，纯权益执行订单中心 checkoutRequestId 整组入口。两者规则、原项目、卡包余额均由 8 恢复到 10；重放不增次；返还事实总量为 2；业绩正反事实净额为 0；纯权益跳过先单独作废的一条；夹具回滚、原规则完整不变。

限制：混合测试覆盖整单作废的真实服务级联，但不再次触发真实收款退款；未声称浏览器端完整资金作废流程已验收。旧订单已有作废幂等账本，代码修复不会自动补回历史缺失的规则次数，历史修复须另行核对与授权。
