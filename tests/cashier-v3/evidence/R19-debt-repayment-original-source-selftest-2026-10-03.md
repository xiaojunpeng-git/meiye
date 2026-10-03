# R19 欠款补交继承原单来源：本地自测与发布待办

- 基线 HEAD：`1e2ceff3bc79aab350c036c040a82888a9440561`。
- 已跟踪文件的范围补丁 SHA-256：`462e4e89b8edd6969011af974a0f7ae3d2586f466d7c24938ed6bd652916b973`；新测试文件另见下方文件指纹。
- 状态：开发与自测完成，待产品经理对本固定版本确认验收；未 commit、未部署、未 push、未写本地或线上历史数据。

## 本地实现范围与业务边界

新发生的销售欠款和充值欠款“去还款”从原销售／充值单的来源快照只读带入收款页；最终提交在事务内重新锁定原单核对，补交主记录及收款事实使用同一原单来源。原单无可靠来源时，新补交保持来源为空，但允许还款；不以当前工具栏来源填充。已完成补交的幂等回放仍走原有分支，不重写旧记录。页面只增加补交时的只读来源展示并禁止修改，不影响普通销售的来源选择。

权威来源为原销售单 `cashier_v3_sales_order` 或原充值单 `user_recharge` 的五项来源快照；会员欠款列表的来源只是交接提示，不作为最终写入权威。补交记录与统一收款事实必须一致，报表仍从收款事实读取来源。金额、收款方式、欠款余额和会员归属未改变。

## 文件与指纹

| 类别 | 文件（相对源码正本） | SHA-256 |
|---|---|---|
| 后端源码 | `后端代码/app/services/cashier/v3/cashier/CashierV3MemberDebtProjectionServices.php` | `dbf5816f4c62d22eacc55025ba496ea1d1976bc349fa544d19ffa69e97db0ac1` |
| 后端源码 | `后端代码/app/services/cashier/v3/settlement/CashierV3DebtRepaymentServices.php` | `07fe7a0aab5a8d563d191b5715ae60604cbca3b08d86b55abe65cad537658598` |
| 后端源码 | `后端代码/app/services/cashier/v3/settlement/CashierV3RechargeDebtRepaymentServices.php` | `f9ec26c9cb087a6ece8ec8ba86e23d1b98bfa49e1d525fc1fec1e473685559cc` |
| 前端源码 | `前端代码/cashier-v3/src/layouts/CashierShell.vue` | `cb9e79f8ce7cdd375cc7e344da303ea6ac9c1c6c1caabebd4739fc0a807dab76` |
| 前端源码 | `前端代码/cashier-v3/src/views/CashierWorkbenchView.vue` | `67590fe68c84e0ee6095148b777899f448642c3e061c1b552cefd72acfca0964` |
| 前端源码 | `前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue` | `655da17e23d96c41bd203fa8bf3ff4753fa35f6550d8631d910ae647b1fcc375` |
| 自动化测试 | `tests/cashier-v3/php/repayment-source-snapshot-contract.php` | `39761fe619d19162c058751a351581c876dbc601d0547e34205517f5919ed93e` |
| 自动化测试 | `tests/cashier-v3/php/order-source-snapshot-contract.php` | `78e81bfecad9940fbe19749d7601c9f4609647680f762f60569fbd775bd916aa` |
| 自动化测试 | `tests/cashier-v3/php/member-debt-projection-contract.php` | `31a679a031e65bdb5505fdb2c63a29f2c7c919a216377e9a9c6ace8507d147e7` |
| 自动化测试 | `tests/cashier-v3/js/debt-repayment-original-source-contract.mjs` | `b787088a960ff1e98d8d05f3cb7cf766a12a5c1b2ba52e3954bc8af3dd90b758` |
| 自动化测试 | `tests/cashier-v3/js/recharge-debt-repayment-frontend-contract.mjs` | `aab0df619ccb0c38c1b3b734b3f2ba9d95441d6cdd795a1225a1ab62b4e4a47a` |
| 自动化测试 | `tests/cashier-v3/php/debt-repayment-original-source-readonly.php` | `98f099641592cc20085a7c417e7ba024faded5cef37b540a64578a89d1cfe666` |

本次在三处后端业务服务和三个前端组件中补充／更新了原单权威、只读交接、历史缺失与防篡改约束注释；测试中的旧精确字符串断言同步更新。数据库迁移、工程脚本：本次无新增；生成物：本地 Vite `dist/`，未纳入源码；验收证据：本文件。

## 已执行验证

- PHP 语法：上述三个后端服务均通过 `php -l`。
- PHP 契约：补交来源、原单来源、会员欠款投影、销售欠款和充值欠款测试均通过。销售欠款测试仅出现既有第三方依赖的 PHP Deprecated 提示。
- JS 契约：原单来源、充值欠款前端、补交唯一快照测试均通过。
- 本地只读投影核对：销售原单 `XS26100300005` 的来源为 `B导购引流`，充值样本来源为 `A老客首护`，欠款浮层投影与原单一致。该测试只 SELECT，未提交还款。
- `npm run build` 通过（Vite 原有大 chunk 提示）；`git diff --check` 通过。
- 尚未进行真实收款业务验收；页面与补交记录需由产品经理测试固定版本。

## 瑞昊线上历史记录待办（本次不执行）

产品经理明确：本地历史数据不处理；在未来获授权部署瑞昊时，线上已有补交记录的来源还需按原单核对并修正。由于补交主记录与收款事实分别保存来源，只改一处会使报表口径漂移。发布前须先只读盘点线上可关联的原单、已完成补交、收款事实与差异数；对无可靠原单来源或关联不唯一者跳过并报告，不猜测、不补原单或充值主数据。随后取得对应固定版本验收和部署／生产写入授权，独立备份、记录逐行审计与回滚点，才可同步修正适用的补交主记录和收款事实并对账。不得在本地或其他客户实例执行该历史修正；本文件不构成执行授权。
