# R44 每人手工费按输入金额保存：本地自测

- 基线 HEAD：`01498c1d39a308f32f4e630065083e084ef18ead`
- 闭环补丁 SHA-256（下列三个已跟踪文件的 `git diff`）：`64f9eb43e11288509dace12d75bf5007cebf5505ad06283acf04687a26716afd`
- 范围：未来结账的购买项目和权益核销。每人手工费为本次人员分配输入的金额，不能再乘购买／核销数量；项目劳动业绩、服务数量、项目数及既有历史事实不回写。

## 固定文件与 SHA-256

| 类别 | 路径（相对唯一源码正本） | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/cashier/v3/settlement/CashierV3PaidProjectCraftsmanPerformanceServices.php` | `3a77c9e12f4e8c25588458265a22ff819c8891f7b0a5083f263a152a00e30e4a` |
| 后端源码 | `后端代码/app/services/cashier/v3/checkout/persistence/CashierV3EntitlementCompletionPlanV1.php` | `62c53d213466dcc40cd970ab9ed0793e7b92b9a0dc01b1c61a2843d804dcce4c` |
| 自动化测试 | `tests/cashier-v3/php/c2-entitlement-completion-persistence-contract.php` | `38ea919856c2a0a279bab86ee9585434a636f44f332bf2706b6836276656ad63` |
| 自动化测试 | `tests/cashier-v3/php/r44-paid-project-person-labor-fee-contract.php` | `d8f5c498668f2b6993ea5e6206476e194a48ff0c481a100bc99ff336782bd3e1` |

前端源码、数据库迁移、工程脚本、生成物：无。新增／更新业务注释：上述两个后端源码，说明每人已确认手工费不随数量放大。验收证据：本文件，不参与上方源码补丁指纹。

## 自测

1. 两个后端源码文件与新测试文件 `php -l` 均通过；`git diff --check` 通过。
2. `php tests/cashier-v3/php/r44-paid-project-person-labor-fee-contract.php`：购买项目数量为 1、3 时，每人输入 5 元及 3 元分别保留 500、300 分，总手工费固定 800 分；项目劳动业绩仍随购买金额正常变化。
3. `php tests/cashier-v3/php/c2-entitlement-completion-persistence-contract.php`：25/25 通过；新增用例验证核销数量 2 时，服务事实与人员事实均只保存 500 分。该旧测试在当前 PHP 8.5 出现两条 `ReflectionMethod::setAccessible()` 弃用警告，不影响结果。
4. `php tests/cashier-v3/php/manual-labor-fee-override-contract.php`：16/16 通过；`php tests/cashier-v3/php/entitlement-manual-performance-contract.php`：通过。
5. 补充运行两个现存静态测试：`zero-labor-fee-craftsman-contract.php` 的 1 条断言失败，因其仍按旧的两条件字符串匹配，而现有未修改的装配器已增加项目数条件；`labor-performance-fee-split-contract.php` 的 1 条断言失败，因其仍匹配旧报表 SQL 文字。两条测试及其目标代码均不在本次改动文件内，属于基线已有的静态断言漂移，不以其通过冒充验收。

未连接远程实例，未进行真实收款或历史事实改写；尚未进行产品经理业务验收、commit、部署或 push。
