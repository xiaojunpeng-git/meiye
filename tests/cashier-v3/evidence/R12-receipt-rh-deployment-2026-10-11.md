# R12-00012 小票字号与三方签字栏瑞昊发布记录

## 验收、提交与边界

- 产品经理明确回复“我测试没有问题,commit,部署瑞昊,push”，固定版本见 `R12-receipt-pm-acceptance-2026-10-11.md`。
- 本地业务 commit：`9f27cc4b4ee96442bede1f25b3c2f9836c5a4525`，`R12-00012 小票字号与三方签字栏闭环`，仅 1 个前端源码、1 个测试、3 个验收证据文件。
- 精确发布 commit：`633559948cd585e039b88e0e0764625d66ae2168`，父提交为远程发布基线 `54c36a0e3b0d369c8588780a059069deb466c599`。只集成本轮 5 个文件，不推送本地其他任务的提交历史。
- 未切换当前 checkout 或创建 worktree；其他任务原暂存区 SHA-256 保持 `dc31b057aca12a17ee75ed7a86e023bfeba79933849b91605faa54e209c95b46`。
- 源码只来自本机 `美容源码/`。远程只承载部署结果，未改服务器 Git 状态、配置、后端、数据库或其他客户。

## 构建

- 18091 容器 `mohe-cashier-v3-src` 挂载本机 `前端代码/cashier-v3/` 至 `/app`，库存及 shared 使用同一正本；固定验收版本仍与该开发源一致，仅排除其他任务脏文件。
- 从精确发布提交 Git archive 构建，cashier、inventory、shared 三棵树与本地业务提交一致；195 个归档源文件逐一 SHA-256 校验通过。
- inventory/cashier 分别 `npm ci`，cashier `npm run build` 成功，1999 模块，Vite 4.5.14；仅既有大分包警告。依赖与热更新容器一致：Vue 3.5.40、plugin-vue 4.6.2、@lucide/vue 1.27.0、vue-router 4.6.4。
- 发布包 SHA-256：`1a3821e6ea1bf8ea26a06a404b744843e76b7da300ed5cce89b4ca5932cbb28d`。
- 产物 5 个：入口 HTML、`index-97b28b91.js`、原 CSS、登录图片、Excel 分包。只有入口和主 JS 内容变化。清单与源指纹见 `R12-receipt-release-manifest-2026-10-11.json`。
- 构建快照只用于冻结构建，非第二份可编辑业务源码，发布验证后清理；产物不作为源码提交。

## 实例核对、备份与部署

- 目标：瑞昊 `rh.cc3798.com`，主机 `iZbp1fif4p5s7gj96nnec0Z`，正式目录 `/www/wwwroot/rh.cc3798.com/public/view_cashier_v3`。
- 同步该实例既有 8080 集成静态目录 `/www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3`，不修改预览后端或配置；20801 既有未监听限制保持，不声称预览业务全功能可用。
- 只读事务核对数据库 `ruihao` / MySQL 5.7.44-log，升级记录仍为 221 / `20261011-003-inventory-no-expiry`。本次无迁移或业务写入，不新建数据库备份，保留既有实例备份策略。
- 正式与预览旧 HTML SHA-256 均为 `272cf8f820b28b4d07fba510fcd12edd7c533897d25c8e3ee05b0fc1138c0933`，与上一轮正式发布一致；安装前重复校验防止覆盖并发发布。
- 独立文件备份 `/www/backups/rh.cc3798.com/20261011-r12-63355994`，gzip 完整性校验通过：
  - `formal-before.tar.gz`，45287976 字节，SHA-256 `e6954356be31834d49a952758f416b4ff6748e157094861030f047eceaacbf1e`。
  - `preview-before.tar.gz`，45288033 字节，SHA-256 `5d69e871268d8cd78ce136f708e2c38090f4fc8af1e5804f306018ab295a5ca2`。
- 候选包及 5 个产物校验通过后先安装哈希资源，再原子切换两个入口 HTML。旧资源未删除，同名已有资源必须逐字节一致；失败时具备只恢复入口的回滚保护。本轮未触发回滚。
- 完成时间：`2026-10-11T21:59:05+08:00`。纯静态发布，无需重启 Swoole/Nginx。
- 如需回滚，先确认没有后续发布，再从两个对应备份恢复 `./index.html` 并原子替换入口即可；新哈希资源可保留，不执行数据恢复，不覆盖其他文件。

## 验证与限制

- 提交前小票合同 20 PASS，作废小票恢复回归 PASS，diff check 通过；固定验收指纹未变化。
- 正式与预览 HTTPS 共 10 项 HTML/JS/CSS/图片/Excel 资源全部 HTTP 200、SHA-256 匹配；预览 HTML 按既有 Nginx sub_filter 路径转换核对。
- 实际下载的正式主 JS 包含按顺序的三方签字标签、10mm 留白、76mm 打印 21px 设置。
- 正式 manager PID 21836、20800 监听保持，匿名 `/cashierapi/config` 返回预期鉴权状态 410000。后端预约模块仍为上一轮指纹 `061a89583e6110f490196ceee5955020f763d3791d99fcc243408629faa99efa`。
- 浏览器正式登录入口渲染正常，脚本为 `/view_cashier_v3/assets/index-97b28b91.js`，控制台错误/警告为 0，截图已保存。没有生产门店登录会话，未声称完成登录后订单小票 UI 或实体打印验证；生产未创建订单或发送实体打印任务。
- 线上验证证据：`R12-receipt-online-assets-2026-10-11.json`、`R12-receipt-rh-entry-2026-10-11.png`；本地实际订单预览及产品经理验收见此前 R12 记录。

## 归档与 push

本轮前端源码 1 个、自动化测试 1 个；后端源码、数据库迁移、永久工程脚本为零。生成物与源码清单分开管理。
线上验证通过，按授权仅向 Gitee `origin/release/store-cashier-c14-main` 快进推送本轮精确发布与证据归档，不强推，不推其他远程。本文生成时 push 待执行，实际结果以之后远程分支哈希核对为准。
