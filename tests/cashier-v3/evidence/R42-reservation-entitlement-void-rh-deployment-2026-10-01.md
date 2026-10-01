# R42 预约来源权益单作废：瑞昊发布记录

- 产品经理确认本地固定版本验收并授权 commit、部署瑞昊及 push。业务提交 `817daf17`，仅含 9 个本闭环文件；从瑞昊上一发布版 `2716c3d0` 形成等价独立发布提交 `66dafc0a`，推送至 `origin/release/r42-reservation-entitlement-void-20261001`。发布提交与业务提交的闭环文件逐一比对无差异，未推送开发分支其他历史。
- 唯一目标为瑞昊 `rh.cc3798.com`，数据库只读核实为 `ruihao`。本次无迁移、配置变更或生产业务数据写入；其他客户实例未操作。门店端构建源为当前 18091 热更新入口绑定的 `前端代码/cashier-v3/`，`npm run build` 通过。
- 备份与回滚点：`/www/backups/rh.cc3798.com/20261001-r42-reservation-entitlement-817daf17/`，含三份旧 PHP 文件和正式、预览两套门店端完整静态包；压缩包已读取校验。新标识类部署前不存在。回滚时恢复三份旧 PHP 和两套静态包、移除本次新增的精确文件 `CashierV3EntitlementCheckoutIdentity.php`、重启瑞昊单实例 Swoole；本次无数据库回滚。
- 暂存及运行的四个 PHP 文件均通过 PHP 7.4 语法检查，指纹依次为：标识类 `fc6e5cfefde624626cc9d5c687d56f6401eca4339c8f5aea5afc572215d6544b`、生命周期入口 `3c4c5aa8b48864e58ded4cb610626b13dbbbd7fde5c99547fbe65da39143d94a`、销售订单查询 `94cd76da6d4c537c6c0831fb5ab024185e3b3b42db9c0569e550d84a082f79f9`、服务作废 `39e9987cff4180439e8734d12be63af7cd7d22fd2b7f99111fcf9def0672b986`，均与验收版一致。
- 正式和预览门店端 `index.html` 均为 `00dfffcb0e22f53a4fe52007a1374c7bf633cba9d6a783a33629852a2d7f9ce2`；经预览公网 URL 获取的主 JS 为 `dba965d6b379f233ebd8e6fc591b347397ca68e2180dbde146251e36495eae4e`，与本地构建相同。保留旧哈希静态资源以保护已打开的浏览器标签。
- `/etc/init.d/ruihao_swoole restart` 成功，20800 端口监听；正式及预览入口均 HTTP 200，最近错误日志未检出相关 Fatal、Parse、Uncaught。线上只读类加载检查确认 `reservation:447` 被接受、`reservation:447:other` 被拒绝。未为验收而作废真实订单或改变预约；权益返还、业绩冲销和预约六类表不变由本地事务回滚测试证明。
- 分组：前端源码 1、后端源码 4、数据库迁移 0、自动化测试 3、工程脚本 0；生成物为门店端静态包，自测证据见 `R42-reservation-entitlement-void-local-2026-10-01.md`。本文件是发布后证据，不属于已验收业务提交。
