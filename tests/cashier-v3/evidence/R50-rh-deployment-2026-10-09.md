# R50 纯权益体验标签瑞昊发布与验证

## 验收和提交

产品经理明确确认本轮测试无问题，并授权 commit、部署瑞昊、push。功能提交 `948fb5eb6908018ec578f54598c735c584ca7ee2`，信息 `R50-00001 纯权益体验标签保存闭环`，父版本 `cdb8fbdf3862381e5540781b5936dea4fa7b4b16`。固定验收指纹全部一致，46 项动态回归复跑通过。

功能提交仅含本轮 8 个文件：前端源码 1、自动化测试 1、验收证据 6。无后端、迁移或永久工程脚本；既有 9 个无关暂存文件及其他脏改动保持原状，暂存 diff SHA-256 前后均为 `dc31b057aca12a17ee75ed7a86e023bfeba79933849b91605faa54e209c95b46`。原固定清单、自测的待验收状态保留历史事实，实际验收以本轮授权记录为准。

## 构建和目标

仅发布瑞昊 `rh.cc3798.com`，主机 `iZbp1fif4p5s7gj96nnec0Z`。正式静态目录 `/www/wwwroot/rh.cc3798.com/public/view_cashier_v3`，预览静态目录 `/www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3`。

从功能提交 Git archive 提取 18091 对应的 cashier-v3 及 inventory-vue3、shared 依赖进行一次性构建，没有切换主工作区或取用脏源码。与父版本比较，业务源码差异仅为工作台 4 行修复与业务注释。初次归档构建缺少 inventory-vue3 的依赖链接，失败后补充既有 node_modules 链接，未安装或更换依赖；重新构建父版和新版本均成功，1994 模块，仅保留既有大 chunk 提示。父版 HTML、JS、CSS 与部署前现网逐字节指纹一致。

这次是门店前端静态发布，不是重建整个 8080 集成包；未打平台、库存独立工程或费用独立工程，未更换服务器后端。临时归档仅作为受控构建输入，发布包保留为生成物，源码正本仍是美容源码。

构建指纹：
- HTML `35776e8c3961b2fed43767a7ac711f6257ddee489700f1f2755832ab54e12258`。
- `index-b3eaf52f.js` `bd3a07388ec9f478f4539eb295e6d83553ed22fab2ff77e7cebbaa996f8b7fe4`。
- `index-b070862b.css` `b070862b8048a7ba2f8edbc82f805e3c62c402ce26c4e35adc063dc3fc7696c7`（未变）。
- `/tmp/mohe-r50-release-948fb5eb/cashier-dist.tar.gz` `3b87b24946586b6a8224511d919c3229685c542e09bcecfd6af42dc6f4cce8bc`，仅本地生成物，不纳入源码提交。

## 备份和切换

独立备份 `/www/backups/rh.cc3798.com/20261009-r50-948fb5eb/`，权限 0700。
- `formal-before.tar.gz` `e2dda81aaa36a8558622ce206200b3320fc6ed8cd1cf5f0ccdfe5c851662c06f`。
- `preview-before.tar.gz` `e325ca559799a475f5cdc09d9fa227c5f2b63e5dc55899b6e263529c0b8c7d59`。

两份归档均通过 gzip 与完整 tar 列表检查。旧 HTML 指纹均为 `9f71e5acdaa97e9d67409b662f9714711d02c4e22539add174a456952e7714ba`；先追加新资源，再校验旧入口未变化、候选 HTML 和 JS 指纹正确，于 2026-10-09 23:23:48（Asia/Shanghai）原子切换两个入口。旧哈希资源未删除，脚本带失败恢复旧 HTML 的保护，实际未触发回滚。

回滚应先检查无后续发布，再仅恢复该备份中正式与预览各自的 index.html；保留旧资源可直接恢复原版本。不恢复数据库或实例配置。

## 线上验证

1. 正式与预览 HTML、JS、CSS 共 6 个 HTTPS 请求全部 HTTP 200，响应内容与构建逐字节一致。预览代理仅将 `/view_cashier_v3/` 重写为 `/preview-8080/view_cashier_v3/`，传输 HTML SHA-256 为 `9c66e0e60696593fe5d7795d0ad8218cdba9ab8cc8b907eaf4270dbc91627749`，已按该固定重写核对，不是旧包。
2. 刷新原已登录瑞昊页面，DOM script src 为 `/preview-8080/view_cashier_v3/assets/index-b3eaf52f.js`，订单中心正常读取旧单。
3. 原账号港汇店只读打开市场业绩表、市场明细表（2026-10-01 至 10-09），两表正常展示。A 来源人次与明细合计均为 81，有效人员均为 1，金额均为 3000 元。截图只作为发布后读取冒烟，不冒充线上新结账业务验收；明细截图仅保留合计区，避免记录客户手机号。
4. 生产 PHP 在 READ ONLY 事务查询两表正常，版本均 `market-visits-experience-only-v3`，市场业绩 1 行、明细当前页 10 行；检查后 ROLLBACK。数据库 `ruihao`、MySQL `5.7.44-log`，迁移最大 id 部署前后均为 215。
5. 两处 .env 及市场报表服务、统一门店人次资格类指纹与发布前一致。Swoole manager 数为 1，20800 监听正常，config 接口正常返回未登录业务状态 410000。最近 100 行错误日志 Fatal/Parse/Uncaught 匹配为 0。
6. 无生产写库、迁移、历史标签补录、真实结账或支付；未重启后端、未调整 Redis/队列/计划任务/AI 进程，007/008/012 未操作。本轮完整结账业务依据仍是已验收的本地 FW100900005 与原动态/集成测试。

截图证据：`R50-rh-market-performance-online-2026-10-09.png`（SHA-256 `dba3fcecb4de379360203f58f1b1435a8aed3fe1442eca0bbb321385112700cc`）、`R50-rh-market-detail-online-2026-10-09.png`（`d1ef61f3e1f7be97963097ea80765fc4485b5a27268e74ceae04f662da3db5ae`）。

## 推送边界

以上线上验证通过后，按授权普通 fast-forward 推送 origin（Gitee）`release/store-cashier-c14-main`。推送前远端为 `cdb8fbdf`；仅推本轮功能及本发布证据，使用明确提交 SHA，避免并行任务移动 HEAD 时夹带。不得 force push，不推 github。实际推送回执与远端 SHA 在交付时报告。
