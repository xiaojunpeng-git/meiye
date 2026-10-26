# R11 商家端未开始预约删除入口：本地固定版本

- 基线 HEAD：`fe667d9aa3ffb7b6489016a8e7c57b13281c9518`。
- 当前状态：产品经理已对 R10、R11 固定版本明确确认验收，并授权本轮提交、瑞昊部署、推送与体验版上传；下列指纹锁定提交范围，发布结果另行记录。
- 补丁 SHA-256（下列四个文件相对基线的工作区差异）：`935a3e25bab6847015d3430caded982daf46a2857f85f7e14b8f3d3f5ad34514`。
- 同页还包含此前 R10 会话失效回登录改动；文件级固定版本同时覆盖 R10 与 R11，产品经理已确认两项均验收通过。

## 文件清单与 SHA-256

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/mobile-vue3/src/merchant/pages/reservations/index.uvue` | `4cb49ea35218b1c385613f2289aba00919a6fff497659bba8d55a69a9184eef8` |
| 前端源码 | `前端代码/mobile-vue3/src/shared/api/mobile-reservation-client.uts` | `402566beac3eee2b8e8be439e861e773965b2cdc6801c0ce1a0cabc80befc718` |
| 前端源码 | `前端代码/mobile-vue3/src/shared/contracts/mobile-merchant-v1.contract.json` | `50255741af40c8bd89b518b62b6c4afa7b7e288d717d3e5eeea4fe170183dac5` |
| 自动化测试 | `tests/mobile-vue3/js/reservation-page.test.mjs` | `cdeb73a05a2967ea107b651c1752b529bb481f5d882aecd5a09e1d829cbac992` |

后端源码、数据库迁移、工程脚本、生成物：本轮无改动。验收证据：本文件；R10 的独立记录见 `R10-reservation-expired-session-local-2026-10-13.md`。

## 自测

- 本地 H5 的未开始预约详情显示“开始服务｜编辑｜删除”；点击删除出现逻辑取消说明与二次确认，点取消后原预约仍在，未执行实际删除。
- `node tests/mobile-vue3/js/reservation-page.test.mjs`：12/12 通过；核对删除动作、版本上下文和 DELETE 路由契约。
- `php tests/mobile-auth/php/mobile-reservation-delegated-permission.php`：权限边界通过，包含 `cancel-reservation`。
- `php tests/cashier-v3/php/reservation-lifecycle-contract.php`：预约逻辑取消与审计合同通过。
- `git diff --check --` 限本轮四个文件：通过。全仓 `git diff --check` 另有不属本轮的 `uniapp/pages/admin/staff_center/index.vue` 行尾空格。
- 微信开发者工具以 `A15358302092` 登录线上后，预约服务可读取待服务 1 条、服务中 0 条、已结束 0 条；该工具当前仍打开旧 R6 编译包，因此这项检查只证明旧包的会话及列表读取恢复，不证明 R11 按钮已经进入小程序包。

## 注释与边界

本轮更新了 `index.uvue` 中删除入口的状态/权限边界与取消命令说明，更新了 `mobile-reservation-client.uts` 的 DELETE 路由说明。删除复用已有 V3 `cancel-reservation`，保留预约主表、事件和操作记录，服务端再次校验权限、状态与版本。未对线上预约执行删除。
