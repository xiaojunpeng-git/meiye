# R30 收银与订单闭环瑞昊最终部署记录

## 固定版本与授权

- 产品经理已完成第 30 轮验收，并于 2026-09-24 明确授权部署瑞昊和 push。
- 本次最终部署固定提交：`b678e469233950ebdc80cc06a4a6b3f016ac7145`。
- 目标实例仅为瑞昊 `rh.cc3798.com`；数据库为 `ruihao`，本次未执行 SQL、未修改配置和历史业务数据。
- 工作区内其他任务的未提交文件未进入构建、后端同步或提交。

## 第 30 轮上线范围

- 门店品项分析按成交时冻结事实显示分类“分成后业绩”。
- 收银改价允许改为 0 元，并支持零元订单正常结账和事实留痕。
- 点击“定制卡”直接进入定制卡创建；购物车冲突弹窗移除“先挂当前订单”，“清空并继续”在确认清空成功后进入创建流程。
- 订单中心修改销售人或手艺人时，手动填写的业绩金额按输入值真实保存并在重新打开时回显；本轮固定验证值为 666 元。

## 部署前验证

- `r30-custom-card-entry-contract.mjs`：通过。
- `personnel-performance-overlay-contract.mjs`：通过。
- `order-personnel-manual-performance-amount.php`：通过，验证 666 元保存为 66600 分且不触发自动重分配。
- `order-lifecycle-contract.php`：通过。
- `CashierV3OrderLifecycleServices.php` PHP 语法：通过。
- 门店端生产构建：Vite 处理 1972 个模块并成功产出，仅有既有 chunk 大小提示。

## 构建与文件指纹

- 门店端构建源：`前端代码/cashier-v3/`，源码树指纹 `12cbe62e21bfbbc48d66e7385d0a08193d7b3c22e638b2ce7517b9bed5179918`。
- 前端发布包相对路径清单指纹：`8dccf8c218a4291871ee175bbfd3d081cadc11cdffb337a4670bb36515bf0c36`。
- `index.html` SHA-256：`5dfa7a5230367c7ebfd1c6f8cd03c315a74f9564463e9fdc3dd3fc82fc998ce5`。
- `CashierV3OrderLifecycleServices.php` SHA-256：`6459e520e9847fe0f3c5ae92decad68a8e94d6042417870018418604b56c033f`。
- 线上前端发布包、入口文件和后端文件均与本地固定版本逐项一致。

## 备份与回滚点

部署前备份目录：

`/www/backups/rh.cc3798.com/20260924-r30-b678e469-ZFKeRL`

| 备份 | SHA-256 |
| --- | --- |
| `view_cashier_v3-before.tar.gz` | `cf20a2dc764f5e38260810a1ad45e0a2328d92e7f953952779e7b45e08f6755a` |
| `backend-before.tar.gz` | `4fb9ee1fe32272d232e4418756b76de8c5aca30f888fe8cc5d19cdea7a89c71f` |

备份目录同时保留切换前的完整 `view_cashier_v3-live-before` 目录，可用于门店端静态资源快速回滚；后端可从压缩备份恢复后重启瑞昊单实例 Swoole。

## 线上验证

- 主入口 `/view_cashier_v3/?release=b678e469`：HTTP 200。
- 8080 预览入口 `/preview-8080/view_cashier_v3/?release=b678e469`：HTTP 200。
- 新 JS 资源：HTTP 200，包含“清空并继续”和订单业绩金额功能，不包含已移除的“先挂当前订单”。
- 门店登录接口返回 HTTP 200 和预期业务校验响应，无 5xx。
- Swoole 重启成功，管理脚本确认 `manager_count=1`，端口 20800 正常监听。
- Swoole 重启后日志未发现新的 `Fatal error`、`Parse error` 或 `Uncaught`。
- 第 30 轮此前已部署的零元结账三个后端服务和门店品项分析服务，线上 SHA-256 仍与当前提交一致。

## 注释与发布边界

本轮修改的金额输入边界、手动业绩事实保存和定制卡清空前置条件均保留了对应业务注释。部署仅替换门店端静态包和订单生命周期服务文件；未修改平台端、会员端、数据库及其他客户实例。
