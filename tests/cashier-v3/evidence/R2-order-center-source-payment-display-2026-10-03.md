# 第 2 轮：订单中心来源列与收款方式显示

## 固定范围

- 基线 HEAD：`7d806e1117446ec4495df7796af8aab4b165df4f`；承接同轮尚未提交的充值、补交来源快照实现。
- 前端源码：`前端代码/cashier-v3/src/views/OrderCenterView.vue`。充值订单、补交记录的客户来源固定在单号右侧，旧账号保存的列序也按此呈现。
- 后端源码：`后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php`、`后端代码/app/services/cashier/v3/order/CashierV3OrderCenterUnifiedQueryContract.php`。统一查询及导出列序同步；收款代码统一转中文，不改写数据库快照。
- 自动化测试：`tests/cashier-v3/php/order-source-snapshot-contract.php`。工程脚本和数据库迁移：无新增。生成物：本地 `前端代码/cashier-v3/dist/`，不作为源码提交。
- 正常结账流程、收银页、充值和补交写入链路未在本次展示调整中修改。

## 文件指纹 SHA-256

| 文件 | SHA-256 |
| --- | --- |
| `前端代码/cashier-v3/src/views/OrderCenterView.vue` | `6577a702dfadedfe39193d9a20e429ba2982a21c688aff32776fbc345bebf007` |
| `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php` | `580044c7a93ee4beaf43b283f1d0c2f5828a5aff9fee5ac1b5b6996d57e1d440` |
| `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterUnifiedQueryContract.php` | `6cefa95155d701772ae0c56ec6a414b1934a07f8c0b92037fe403935f2a8dfad` |
| `tests/cashier-v3/php/order-source-snapshot-contract.php` | `387f28f4b2504835aab5cabc60ce7d0094311c93710c1aa3e172f72ddc859408` |

## 自测

- `php tests/cashier-v3/php/order-source-snapshot-contract.php` 通过；覆盖充值/补交单号紧邻来源及新旧收款代码中文映射。
- `php tests/cashier-v3/php/order-center-records-readonly.php` 42/42 通过。
- 两个后端源文件 `php -l` 通过；`npm run build` 通过；`git diff --check` 通过。
- 本地热更新服务已经重启，Vite 模块请求成功。浏览器控制接口超时，未取得本次点击截图，页面交互仍待产品经理查看。
- 产品经理原标签页截图仍为“客户来源”第 9 列，属于旧前端运行态，不能视为本次列序验收通过；须以新参数重新打开或强制刷新后核对“补交单号→客户来源”及“充值订单号→客户来源”。
- 留存样例：充值 `CZ26100300004`（来源 G异业收客）、补交 `BJ26100300004`（来源 H沉睡唤醒），均为本地 `ruihao` 测试会员“来源快照测试1003”；收款代码 `other_collection`，页面应显示“其他收款”。

## 状态

本地开发与自动化自测完成，待产品经理按此固定版本确认验收。未 commit、未部署瑞昊、未 push，未操作远程数据库。本次修改的业务约束注释位于订单中心前后端源文件。
