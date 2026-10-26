# R12-00012 长小票打印修复瑞昊发布记录

## 授权与版本

产品经理对最终横向约束修正版明确确认："我测试没有问题,commit,部署瑞昊,push"。验收指纹与范围见同目录 `R12-long-receipt-pm-acceptance-2026-10-11.md`。

- 本地业务提交：`b50d36e1b7dcf892603fcec0b463cdfd051aea08`，25 个闭环文件。
- Gitee 已发布基线：`2179a04f08335213fb536885fa44ff106750f078`。
- 本轮精确发布源码提交：`d548c00b6e50f1da89f6f38f6e7c9f007973f401`，父提交为上述基线，仅集成这 25 个文件。
- 使用临时 Git index 和 commit-tree 集成，不切换当前工作区、不创建 worktree。开发线其他已提交 AI 代码及其他任务脏文件均未混入发布。
- 当前工作区其他任务已暂存补丁 SHA-256：`dc31b057aca12a17ee75ed7a86e023bfeba79933849b91605faa54e209c95b46`，业务提交前后保持不变。

## 范围与分类

- 前端源码：`前端代码/cashier-v3/src/services/salesOrderReceiptPrint.js`。仅解除打印根节点纵向高度及裁剪限制，恢复原样式；保留横向布局约束和原生缩放条件。职责与兼容边界注释已随业务提交补充。
- 后端源码、数据库迁移、工程脚本：无修改。
- 自动化测试：`tests/cashier-v3/js/sales-order-receipt-print-contract.mjs`、`receipt-pagination-fixture.mjs`。
- 生成物：冻结源码生成的 8080 门店端静态包，未作为可编辑源码或反向覆盖正本。
- 验收证据：本轮自测、横向修正、产品经理确认、PDF/PNG、构建清单、线上资源检查 JSON、本记录和线上页面截图。完整业务闭环文件清单及源码指纹见 `R12-long-receipt-release-manifest-2026-10-11.json`。

## 构建与自测

核对 18091 容器 `/app` 对应唯一正本 `前端代码/cashier-v3/`。从精确发布提交冻结 cashier-v3、inventory-vue3 和 shared 依赖树构建，195 个源文件构建后仍与发布提交逐字节一致。没有维护第二份可编辑业务源码。

Node v16.20.2 arm64；Vite 4.5.14、Vue 3.5.40、plugin-vue 4.6.2、lucide/vue 1.27.0，与当前调试依赖一致。构建成功，仅已有依赖弃用/旧 lockfile 及大包告警。

- 小票契约：21 PASS / 0 FAIL；作废小票恢复：两组 PASS；语法与 diff check 通过。
- 混合单最终修正版 PDF 两页，三行签字齐全；长单四页、纯权益两页已检查。浏览器验证横向 min-width 保留、纵向可完整撑开。
- Chrome PDF 原先已能分页，未宣称 Codex 在 Chrome 复现了实体打印机截断；实体打印结果以产品经理实际测试通过为准。
- 字体、纸张、20mm 签字行及 64mm 撕纸留白未改；CSS、Excel 与图片资源和上次生产包指纹完全相同。本轮主 JS 仅增加 672 字节。

## 目标、备份与发布

仅瑞昊 `47.96.103.107`，主机 `iZbp1fif4p5s7gj96nnec0Z`。正式端与既有 preview-8080 门店端同步更新，无其他客户发布、数据库写入、服务重启、Nginx 配置或打印机驱动更改。

正式路径：`/www/wwwroot/rh.cc3798.com/public/view_cashier_v3`。
预览路径：`/www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3`。

备份目录：`/www/backups/rh.cc3798.com/20261011-r12-long-print-d548c00b`，权限 700。

| 文件 | 字节 | SHA-256 |
| --- | ---: | --- |
| formal-before.tar.gz | 46436499 | 9b47d055dc13f925063193a47c464dc440ad98c82c1dc6eefaf3cea50a403b1d |
| preview-before.tar.gz | 46436351 | 06fd625b37a9ee80dec80316ecb46084105ce2b77ac1118fc62a1bb822ec2dd3 |
| cashier-release.tar.gz | 构建包 | 289c32ca6e37042bda3bbbe34f57edb1128dba4dc0ee0ec73ef386d2ab61a256 |

备份 gzip 校验及上传包 SHA 校验通过。先安装内容寻址资源，再原子替换 index.html；替换前断言旧入口指纹未变化。旧资源全部保留，失败回滚只恢复本轮新指纹入口，不覆盖后续发布。

替换完成：2026-10-11 23:46:59 +08:00。

- 旧 HTML SHA：`dfb9b43e059536e801e27db5a11694abdbbea585bd8012354c4891aedd81880e`。
- 两端磁盘新 HTML SHA：`e1686d971fe948a6c2fb1fdc42b9f6d5469fd4f4e450b80da9d848367a3116c7`。
- 新主资源：`assets/index-a4f7fed7.js`，2071887 字节，SHA `0705237f96bbfed2f0da18ce56cef2429484e2849cfef5d9c369839f351b3694`。

回滚时先确认没有后续发布，再将对应备份中的旧 index.html 原子恢复至各自目录；原哈希资源仍在，无数据库回滚步骤。

## 线上只读验证

正式、预览各 5 项资源均 HTTP 200，解压后指纹匹配，共 10 PASS。预览 HTML 按既有 Nginx sub_filter 将资源前缀替换为 `/preview-8080/view_cashier_v3/` 后匹配，不把正常路径重写当成产物差异。首次 Node HTTPS 检查连接中断，改为带超时的 curl 下载后完整通过，未重复部署。

匿名 `cashierapi/config` 返回 HTTP 200、业务码 410000 和"请登录"，权限门禁正常。两个新浏览器页面均正常显示登录页，实际引用新版 `index-a4f7fed7.js` 和既有 CSS，未捕获控制台 error。截图为登录页加载证据，不冒充登录后的订单或实体打印验收；本轮线上验证没有业务写入或实体打印。

部署前后 Swoole manager 均 PID 694，启动时间 Sun Oct 11 22:26:28 2026；20800 正常监听。数据库 `ruihao` 的只读升级日志前后均为 id 221、`20261011-003-inventory-no-expiry`、版本 `c1ad95d4fca704fdd99825ebb5b973b47d0a2edf`、时间 2026-10-11 17:04:23。本轮没有迁移或远程改库。

资源结果见 `R12-long-receipt-online-assets-2026-10-11.json`；页面证据见 `R12-long-receipt-rh-online-2026-10-11.png`。线上资源、服务及加载核验通过后，推送目标仅 `origin/release/store-cashier-c14-main`，不推送开发线全部历史，也不推送 GitHub。
