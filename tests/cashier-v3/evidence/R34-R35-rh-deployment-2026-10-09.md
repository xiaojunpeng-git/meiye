# R34/R35 瑞昊门店端静态发布记录

## 授权与版本

- 2026-10-09 产品经理确认本轮测试无问题，明确授权本轮 commit、部署瑞昊、push。
- 两个独立闭环提交：`9715eed5`（充值 0 元收款方式）和 `6b51131b`（使用权益加载弹窗），共同基线 `c0d9f7dc`。各提交仅包含本闭环 4 个文件；其他任务原有工作区及暂存改动未提交。
- 固定源码指纹、业务边界与本地验证分别见 `R34-zero-recharge-payment-local-selftest-2026-10-09.md` 和 `R35-entitlement-loading-dialog-local-selftest-2026-10-09.md`。提交后复跑充值及权益专项测试通过；从 `6b51131b` 归档源码和锁定依赖构建门店端 1991 模块通过。旧 C2 权益综合测试仍有 7 项与本轮无关的失败，不记为通过。

## 实例与备份

- 唯一目标瑞昊 `rh.cc3798.com`；正式 `/www/wwwroot/rh.cc3798.com/public/view_cashier_v3` 与同实例预览 `/www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3`。`007/008/012` 未操作。
- 本轮仅替换门店端静态资源，无 PHP、数据库迁移、配置、Redis、队列或计划任务变更；生产库 `ruihao` 未写入，不需要数据库回滚。构建源是 `18091/view_cashier_v3` 对应的 `前端代码/cashier-v3/`，依赖来自同一提交归档的 `inventory-vue3` 与 `shared/unified-query-vue3`，未使用工作区未提交的 `CashierShell.vue` 修改。
- 发布前正式和预览 `index.html` SHA-256 均为 `d738fe5ad3391fe618ceab16092f6447583f20720a98e26d3d3afc4efab692aa`。独立备份目录 `/www/backups/rh.cc3798.com/20261009-r34-r35-6b51131b-rhwaNh/`；`formal-before.tar.gz` SHA-256 `9d2ba4f7c15e1b688d9d9c128d6ffd73c757664dc34b13e2bd3b6161a89da94c`，`preview-before.tar.gz` SHA-256 `6ae416e9319136dc0f4499684810cbee02fa64cf6101b4e54aaed1e963f5bc59`。两包通过 `gzip -t`，归档均含原入口及旧资源。发布前磁盘余量约 203 GB。
- 回滚前先确认没有后续发布；只恢复本次备份的正式与预览静态目录，不回滚数据库或其他客户。旧哈希资源在发布时保留，避免已打开的页面短时资源缺失。

## 切换与验证

- 候选包先上传至上述备份目录的 `candidate/`，入口 SHA-256 `81fe9269d5a463140cf1ead8d1583b81a297aac25703f2e4f0166937004d04fe`，主 JS `323e95d837eeb3b35c7083e742ae35e8878b7a5d6faf85a3d8f95c767e7b2893`，主 CSS `bf6609ebcb51f32d1184b7eb121b1c6d0a490ba8929a80a5c81bd4a2c43055b9`；服务器候选与本地提交构建一致。
- 切换前复核两套旧入口 SHA 未变化。先同步候选哈希资源，后分别原子替换 `index.html`。切换后两套入口、主 JS、主 CSS 的服务器 SHA 均与候选一致。
- 线上 HTTPS 冒烟：正式与预览入口、各自主 JS/CSS 均返回 HTTP 200；预览反向代理按其路径重写资源 URL。瑞昊 Swoole 20800 仍监听，`manager_count=1`，本轮静态发布无需重启。
- 提交构建的 JS 含“正在加载会员权益”，不含旧的“每种收款方式必须大于 0”提示。线上未执行真实充值或权益核销；静态文件与服务存活验证不冒充生产交易验收。

前端源码 4、自动化测试 2、自测证据 2、发布证据 1；后端源码、数据库迁移及工程脚本无变更。正式/预览 `dist` 仅为派生部署产物，不进入 Git。
