# R65 小票撕纸虚线：瑞昊发布记录

- 授权与验收：产品经理已在目标打印机确认打印无问题，并明确要求 commit、部署瑞昊、push。
- 精准范围：本地验收提交 `c9966c6880c7889d6fb8e525217082fe51c1f921` 仅包含销售小票模板与对应契约测试两个文件。当前开发分支另有此前明确未授权 push 的魔核 AI 提交，因此从 Gitee 已发布基线 `754be9bb59ec81acd331eb188b65c4b389829362` 形成等价隔离发布提交 `40fb17f97bed5bba0a1630e0703cadb12d318fae`；两个目标文件与本地验收提交逐字节一致。
- 功能结果：保留 64mm 撕纸区域和底部虚线，移除“撕纸处”文字；权益明细拆分等既有小票能力不变。契约测试 `16/16` 通过。
- 干净构建：从隔离发布提交构建 `cashier-v3`，Vite 共转换 1990 个模块并成功生成生产包。入口 `index.html` SHA-256 为 `f2a11c9b529fbff8979a208c7caf25435d6db56df736184434b716160b80b72d`，主 JS `index-baf931f0.js` 为 `24b8dc38a45e0cd98c39ce479aa148c9c8fc058219d05386dd0d508024ee4e93`，主 CSS `index-ce7c4e3b.css` 为 `ce7c4e3beb95deb2970ebff4eb49334195af19775fdbfe1f17427174b9abd1e4`。
- 发布前核对：瑞昊正式和独立 8080 预览入口文件 SHA-256 均为 `345fb78321531aa1bfc52706a2d4489358d0871821c110e7fcef6bc90bb9b475`，均引用 `index-3404ecde.js` 与 `index-ce7c4e3b.css`；服务器磁盘可用约 206GB。
- 回滚备份：`/www/backups/rh.cc3798.com/20261008-r65-receipt-tear-40fb17f9/`。正式包 `formal-before.tar.gz` SHA-256 为 `0877e4492a30be7bebeb4256f4151827a0b1d76b27f1c154edbf6043ec57af21`，预览包 `preview-before.tar.gz` 为 `aa4f5dafe3e80b263e912aeb88c415437fcb05a3572357389075a5b4e8f7d2bc`，两包均通过 `gzip -t`。回滚前须先确认没有后续发布。
- 部署方式：候选包先上传至上述备份目录的 `stage/` 并核对指纹。随后向正式和预览静态目录追加哈希资源并原子切换两处 `index.html`，旧哈希资源保留。本轮纯前端，未修改数据库、后端、配置或其他客户实例，也未重启服务。
- 线上验证：两处服务器入口与主 JS/CSS 指纹均与候选包一致。正式入口 `https://rh.cc3798.com/view_cashier_v3/` 与预览入口 `https://rh.cc3798.com/preview-8080/view_cashier_v3/` 均返回 HTTP 200、TLS 校验结果 0；两端在线主 JS SHA-256 均为 `24b8dc38a45e0cd98c39ce479aa148c9c8fc058219d05386dd0d508024ee4e93`。预览入口由 Nginx 将资源路径改写到 `/preview-8080/`，因此外部 HTML 响应指纹与源文件不同，属于既有预览路由行为。
- 推送边界：只将隔离发布分支快进推送到 Gitee `origin/release/store-cashier-c14-main`；不会推送本地开发分支中未获授权的魔核 AI 提交。
