# 第 2 轮：充值与补交客户来源本地自测

## 固定范围

- 基线 HEAD：`7d806e1117446ec4495df7796af8aab4b165df4f`。
- 闭环文件清单按下表顺序计算的 SHA-256 清单指纹：`28e8ee7fed00bfbdee1ff5180cac63886fc58a58513b1af755464f464228249c`。
- 前端源码：`CashierShell.vue`、`CashierWorkbenchView.vue`、`OrderCenterView.vue`；仅补交交接和订单列表来源，普通结账提交路径未改。
- 后端源码：`CashierV3RechargeModule.php`、`CashierV3OrderCenterRecordQueryServices.php`、`CashierV3OrderCenterUnifiedQueryContract.php`、`CashierV3DebtRepaymentServices.php`、`CashierV3RechargeDebtRepaymentServices.php`。
- 数据库迁移：`后端代码/database/upgrades/2026-10-03-收银V3补交来源快照/`，仅在本地 `ruihao` 执行；新增补交主记录的五个来源快照列。
- 自动化测试：`order-source-snapshot-contract.php`、`repayment-source-snapshot-contract.php`、`debt-repayment-unique-snapshot-contract.mjs`。
- 工程脚本：无。生成物：本地 `cashier-v3/dist/` 构建产物，不作为源码提交。

## 文件指纹 SHA-256

| 文件 | SHA-256 |
| --- | --- |
| `前端代码/cashier-v3/src/layouts/CashierShell.vue` | `e912e9123885ee63ebe68c68539939bc9f156d3e2c99c5ebf80dd3d23ea0de7d` |
| `前端代码/cashier-v3/src/views/CashierWorkbenchView.vue` | `6bccc547dcfcb62211657ecd553bef464ec9dfa1b8f5e5090c04e5b69abfcd06` |
| `前端代码/cashier-v3/src/views/OrderCenterView.vue` | `1b2bd9f0bb72dd4a52b80c659511110b7f21b2f8c5923dcdf9a23facb6e69a2c` |
| `后端代码/app/services/cashier/v3/member/CashierV3RechargeModule.php` | `21ff28ff80de482ca4c1a55a9b46184dde61bacbbcc9b646ecbecf5b27eec6b3` |
| `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php` | `408c2d434cd8c0fd552929cb1f435bea23adc83827f9578abaf26f54836e0d63` |
| `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterUnifiedQueryContract.php` | `a9290e64b94709a98fcbe612bd6b1f1664f926f83e9d56fef8f58065b985c7b0` |
| `后端代码/app/services/cashier/v3/settlement/CashierV3DebtRepaymentServices.php` | `b3a794074ddebac95a437ac059e375a6f9f79dfdf2909fe7631ea2483fda716d` |
| `后端代码/app/services/cashier/v3/settlement/CashierV3RechargeDebtRepaymentServices.php` | `89dbd6b4dbdad9279682f3b6eac1db730cf17c02e0dbbca7ccb14807fb8caed2` |
| `01-升级前检查.sql` | `0c89df03fbf83479e267130a21316bf19fe1fef7e7718278a82e175c51e3bdcb` |
| `02-正式升级.sql` | `54b05a7ce86a7ea01c7a25915821fd021e87083a15484344b3d1e4077c753cd4` |
| `03-升级后验证.sql` | `f4797007075054bfaf7b2040c6b2adfd0bc3f0c60d4f203e137b90ad19e08af9` |
| `tests/cashier-v3/php/order-source-snapshot-contract.php` | `e7f5b993767f3c3ea97ef84995aeb050583087ff2c7700481795c4321be09281` |
| `tests/cashier-v3/php/repayment-source-snapshot-contract.php` | `dd5c2dda2e9b58a6ae07e3283bfd0517f52c861071bbd2d9a980a42f53c265b1` |
| `tests/cashier-v3/js/debt-repayment-unique-snapshot-contract.mjs` | `0535a3bbbff209652576f842e5e42ecbb7a114202e5d61ee93ffeb027793798c` |

## 验证

- 本地 Vite `npm run build` 通过；`git diff --check` 通过。
- 两项来源 PHP 契约、补交唯一快照 JS 契约、正常结账来源 JS 契约通过；订单中心只读契约 42/42 通过。
- 本地 `ruihao` 充值 `CZ26100300004`：本金 20 元，本次收款 10 元，来源 `G异业收客`；主记录和支付事实来源一致。
- 本地 `ruihao` 补交 `BJ26100300004`：补交 10 元，来源 `H沉睡唤醒`；提交请求、主记录、支付事实及订单中心列表一致，且不借用原充值来源。
- 两笔均为测试会员 `来源快照测试1003`，使用其他收款，显示“待确认”，没有发起外部支付。
- 旧 Vite 缓存导致的三笔本地试错补交 `BJ26100300001` 至 `BJ26100300003` 来源为空；保留审计原貌，未回填或删除。最终同会员欠款提醒来源回退分支已通过代码契约与构建，尚未单独再录一笔真实交互验证。

## 状态

本地开发与自测完成，待产品经理针对本固定版本确认验收。未 commit、未部署瑞昊、未 push，未操作远程数据库。业务代码说明已补充于本轮所有修改的前后端源文件。
