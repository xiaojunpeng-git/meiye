# R32 历史更正后再次调整手艺人：读取补丁固定版本

- 状态：本地修复与自测完成；产品经理已明确确认本补丁作为本轮修复一并验收、部署。此记录固定提交前版本，部署与推送结果另行核对。
- 基线 HEAD：`7dcea449`。仅修改 `后端代码/app/services/cashier/v3/order/CashierV3ServiceRecordCraftsmanAdjustmentServices.php`，完整文件 SHA-256 `3ffa12585ecac062132ddfaf45ea3e9121e01f48f1fe48bfd72a984719b0693e`。
- 原因：R32 保留不可覆盖的历史核销快照，追加业绩更正后，服务记录显示已是更正的 ¥66；但再次确认手艺人时旧方法仍优先取原快照 ¥28.22，会拒绝更正金额或把旧值写回。补丁只在确有本批 `SHARED-CARD-PURCHASE-REPAIR-R32` 正向事实时取更正额，否则沿用原有读取顺序。
- 无页面布局、字段或交互变更；无 DDL、数据迁移、工程脚本或生成物。
- 瑞昊生产只读测试：从隔离候选文件加载此类，反射调用同一业务方法，10 条生效历史核销的目标金额 10/10 正确；`FW100801710` 返回 6,600 分。线上正式 PHP 7.4 `php -l` 通过。本地 PHP 语法与 `git diff --check` 通过；未提交任何真实服务调整。
- 提交前线上仍使用旧读取类；候选只读测试不冒充上线验收。正式源文件原指纹为 `3d4d7a1d1616e15a909c06fe68bf22c9f93c26d89e2baedcf4aa75037eeaa1a0`；需独立备份该文件，再精确部署和重启瑞昊 Swoole，线上复测后方可 push 对应提交。

## 发布结果

- 产品经理确认后精确提交 `fa1c9826`，仅含本文件与一份后端读取类。线上原文件独立备份于 `/www/backups/rh.cc3798.com/20261008-r32-8d5b1477/CashierV3ServiceRecordCraftsmanAdjustmentServices.before-fa1c9826.php`，哈希与原指纹一致。
- 仅替换瑞昊正式后端该文件，线上新哈希与固定文件一致；瑞昊 Swoole 重启后 20800 正常。线上真实业务方法只读验证：10 条更正额全部正确，1 条未更正旧记录仍走原回退，预览 HTTPS 入口返回 200。
- 线上验证通过后，Gitee `origin/release/store-cashier-c14-main` 从 `7dcea449` 普通快进至 `fa1c9826`；未强推、未改其他客户、未做新的业务写入。
