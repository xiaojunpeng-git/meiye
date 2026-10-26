# R32 提单会员恢复与结账清理：瑞昊部署记录

## 固定版本与发布范围

- 产品经理已验收本地固定版本，并明确授权 commit、部署瑞昊和 push。
- 本轮业务提交：`f24c5a03`（`R32 提单会员恢复与结账清理闭环`）。
- 目标仅为瑞昊 `rh.cc3798.com`；未连接、未部署其他客户实例。
- 本次发布门店端 `cashier-v3/dist` 和 3 个已提交的后端服务文件；未执行 SQL、未修改配置、历史业务数据或其他工作区改动。

## 上线内容

- 提单响应使用挂单草稿同一会员 ID 返回权威会员摘要，前端先恢复会员作用域再恢复购物车。
- 保护提单路由切换期间的会员投影，避免迟到的游客根投影把挂单会员重新覆盖为游客。
- 结账快照只继承服务端工作区绑定的来源挂单，成功结账后清理该挂单；浏览器不能自行提交挂单清理标识。
- 不改变原有收款、支付、订单事实和普通游客结账流程。

## 部署前验证

- `hang-draft-save-contract.php`：通过。
- `hang-draft-checkout-decoupling-contract.php`：通过。
- `hang-sale-only-resume-snapshot-contract.php`：5/5 通过。
- 18091 真实路由复测：4 项、¥6,749 的林雅平挂单，点击“提单”后会员、商品和金额同步恢复。
- 门店端生产构建：Vite 处理 1972 个模块并成功产出；仅有既有 chunk 大小提示。

## 备份与回滚点

- 独立备份目录：`/www/backups/rh.cc3798.com/20260924-r32-f24c5a03-hang-member-restore-fewmnW`。
- 后端备份：`backend-before.tar.gz`，SHA-256 `e8deb9bf40f76cd9f97d5a623fcc9a65b6b542cc936832d6290c01e27007811a`。
- 主入口备份：`cashier-main-before.tar.gz`，SHA-256 `1f4e819982204fefb5a0ab8546a8734d3e98bb020296c291019972b082760a9d`。
- 8080 预览入口备份：`cashier-preview-before.tar.gz`，SHA-256 `1f4e819982204fefb5a0ab8546a8734d3e98bb020296c291019972b082760a9d`。
- 备份目录另保留主入口和预览入口切换前的完整运行目录；回滚后端时需重启瑞昊 Swoole。本次无数据库迁移，因此不需要数据库回滚。

## 文件一致性

- 本地、瑞昊主入口、瑞昊 8080 预览入口的发布包集合 SHA-256 均为 `217f73583b46bed2035df22449c8f150450e0ca334c139d8b7de69c7e3bc3f82`。
- `index.html` SHA-256：`bb14f465b3d3c117fa4a00e708472599023bbdfebaa5c7a708e8fd75d5d23efb`。
- 主资源 `assets/index-4849dd1a.js` SHA-256：`b479c53339d26cb298b5f210711f28322becfef1a46a71cbfcb2e79357f6a661`，两个线上入口均一致。
- `CashierV3CashierWorkspaceServices.php`：`5d4a169f82e90a5efb006cd155dd983b22e5b225551deea95bf51d6143a2c119`。
- `CashierV3HangResumeServices.php`：`df69d34a095cc7649e170025c8ced8f461f1087df76aa5c39d636c8bb72e208d`。
- `CashierV3CheckoutPreparationServices.php`：`a746cbc43cff050a44d6e171508eccc4c02461d8ef059828c2808f70c54eb7d5`。

## 线上验证

- 正式入口 `https://rh.cc3798.com/view_cashier_v3/?release=f24c5a03`：HTTP 200。
- 8080 预览入口 `https://rh.cc3798.com/preview-8080/view_cashier_v3/?release=f24c5a03`：HTTP 200。
- 两个入口均加载 `assets/index-4849dd1a.js`，资源 HTTP 200，且包含提单恢复逻辑标识。
- 3 个远端 PHP 文件语法检查通过，部署后 SHA-256 与本地固定版本一致。
- 瑞昊 Swoole 重启成功并保持 `manager_count=1`；AI Supervisor 为 `active`。
- 重启后的近期日志未发现新的 `Fatal error`、`Parse error` 或 `Uncaught`。
- 未登录工作台接口返回 HTTP 200、业务状态 `410000` 和“请登录”，鉴权边界正常，无 5xx。
- 为避免制造生产订单，本次线上不执行真实提单结账；真实写路径由产品经理本地验收和 Codex 本地完整路由复测覆盖。

## 代码与实例边界

- 修改的会员恢复、服务端挂单绑定和结账清理边界均保留职责与约束注释。
- 未部署平台端、会员端、数据库迁移或其他客户实例。
