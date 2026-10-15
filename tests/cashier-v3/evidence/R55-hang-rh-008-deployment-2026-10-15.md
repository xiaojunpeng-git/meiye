# R55 挂单仅权益结账清理：瑞昊与渼约008发布记录

- 固定提交：`65ed3fbc3aeb997b01db8ddac0de0225c5ab22ac`（`R55-20261015 仅权益结账挂单清理闭环`）。本轮只发布提交中的 4 个后端 PHP 文件；固定归档 SHA-256：`1c814201cec97ac6455663303cf29a757002a62f2f68c70db9b37ecd13cefa57`。
- 执行顺序：本地提交与复测 → 瑞昊独立备份、部署、验证 → Gitee 新分支 `release/r55-hang-checkout-20261015` 非强推成功 → 渼约008独立备份、部署、验证。该分支相对既有远端 `release/r6-member-visit-20261015` 仅新增 R55 提交；未改分叉的主发布分支。
- 两站目标文件发布前均与固定提交父版本逐字节相同；发布后均与固定提交逐字节相同。两个站点的四个文件依次 SHA-256：`6850760a085a9dd8f1bf71d322267e72a53cf6bf866e696899689cf892da0c26`、`96b2c659a5aae405daeaf799cd031cd1c9e6c806603dd8fe26c0a901c1d138d4`、`12268cfc8f0e956a1a65bc080bcfc60025e82c8a16bb27f31771a3d860058bee`、`b2a2f6f2b762fd1e029cac654eb39fc8f0cdb9e2d633a8c83e059360350e1f4b`。

## 逐实例结果

| 实例 | 独立数据库／迁移（前后未变） | 备份及回滚点 | 服务与公网入口 |
|---|---|---|---|
| 瑞昊 `rh.cc3798.com` | `ruihao:3306`，`234 / 20261015-001-craftsman-performance-default` | `/www/backups/rh.cc3798.com/20261015-r55-hang-65ed3fbc/backend-before.tar.gz`（归档 SHA-256 `29e0742a350943cb723bda4dd47709585cd31097b406c2b892f929ca4e46aa98`） | 独立 Swoole 重启成功、`:20800` 监听；正式与预览入口 HTTP 200，JS/CSS 公网与磁盘指纹一致 |
| 渼约008 `008.cc3798.com` | `mohe_008:3307`，`27 / 20261015-008-generated-project-catalog-type` | `/www/backups/008.cc3798.com/20261015-r55-hang-65ed3fbc/backend-before.tar.gz`（归档 SHA-256 `13e32caf478fa2a84834d04aada74fbc59981e7f06dd632d72548f926fc0a267`） | 专属 `mohe-008-swoole.service` 重启后 active；门店入口 HTTP 200，JS/CSS 公网与磁盘指纹一致 |

## 前端双入口及边界

- 本次没有构建或替换门店前端。瑞昊正式 `/view_cashier_v3/` 的磁盘 `index.html` 为 `66380ab4b46908e4392cbf209b4f3d1f3492d90fc1b6d45356b836331849ec78`，主 JS `index-4d6c7b33.js` 为 `18ac4837a539ac44a6d356453da8042c492317aa6a9846aefa3abcfd97822045`，CSS `index-3d65ef7d.css` 为 `3d65ef7da8c7ba2e542c83d63e487c07932f564c974260579fb1804c00b478ef`；均与发布前一致，公网资源内容同指纹。
- 瑞昊预览 `/preview-8080/view_cashier_v3/` 的磁盘 `index.html` 为 `597e39d3510d24c64e909d71bf3a0fd08dde0ca2822cb27b5af56db411166bda`，主 JS `index-b26944e6.js` 为 `d887422b716b8d194e3346b034659f20658c78f787b9cf577808a1f30929979d`，CSS `index-ecfe7a4e.css` 为 `ecfe7a4e829a34b39b39e22b9bb5e2f4c2c83529adb8b7ba016217d463ccbfa7`；均与发布前一致，公网资源内容同指纹。正式与预览原本版本不同，本次纯后端发布不主动同步静态包。
- 008 的正式入口与瑞昊正式入口的上述 index、JS、CSS 指纹相同，发布前后均未变，且已独立核对公网资源。未触及007、012或其他实例。
- 公网 HTML 实际引用分别为：瑞昊正式 `index-4d6c7b33.js`／`index-3d65ef7d.css`，瑞昊预览 `index-b26944e6.js`／`index-ecfe7a4e.css`，008 正式 `index-4d6c7b33.js`／`index-3d65ef7d.css`；三个引用的公网内容 SHA-256 均与对应站点磁盘一致。
- 本次无数据库迁移、无线上业务数据写入、无真实结账／权益核销测试。瑞昊历史残留挂单在发布后仍为 `pending_checkout`，按已确认范围未清理；新代码会阻止已完成结账的历史挂单再次提单。真实新挂单结账的线上端到端行为仍需在不制造重复权益的受控业务场景下观察，不能用静态指纹宣称完成真实收银验收。

## 数据架构自检与交付分组

- 权威源仍为结账请求及挂单主表；本轮只在结账成功事务内消费挂单草稿，不增加销售、核销、收款或业绩事实，不改业务时间、历史快照与报表口径。租户／门店／会员／请求绑定在事务内复核，重复提单由成功请求拦截；清理失败回滚本次结账，避免成功单与待结账挂单并存。历史修正必须另走可审计流程。
- 前端源码、数据库迁移、生成前端包：无。后端源码：固定提交中的 4 个 PHP 文件。自动化测试：挂单合同与本地事务回滚测试；C2 编排／生产接线合同测试重跑通过。工程脚本：无新增。生成物：仅 `/private/tmp/mohe-r55-hang-65ed3fbc/backend.tar.gz` 及两站独立暂存包，不作为源码。验收证据：本记录与 `R55-hang-entitlement-checkout-fixed-version-2026-10-15.md`。
- 如需回滚，先核对线上四文件仍为上方 R55 新指纹，再分别从对应实例的 `backend-before.tar.gz` 恢复该站四文件并重启其专属服务；不得跨站使用备份。未对已发生业务事实执行自动回滚。
