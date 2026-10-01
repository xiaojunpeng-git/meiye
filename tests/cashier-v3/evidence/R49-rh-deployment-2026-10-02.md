# R49 瑞昊取消欠款发布进度

## 授权、提交与边界

产品经理于 2026-10-02 明确本轮测试通过，授权 commit、部署瑞昊、push。
本地业务提交：68cfc09cd9921002b3eca956142bc0e43e7fa09b（R49 独立取消剩余欠款闭环），16 个文件。
固定验收文件指纹全部核对一致；19 项真实 MySQL 事务回滚测试重新通过。一次重跑被环境结束（exit 137），随后原命令重跑全部 PASS，不将前一次视为通过。
其他任务工作区改动未暂存、提交或部署。未推送主开发分支。

## 已完成的瑞昊操作

仅瑞昊：rh.cc3798.com，正式根 /www/wwwroot/rh.cc3798.com，预览静态根 /www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3，数据库实查 ruihao。其他客户未操作。
部署前 5 个既有后端文件 SHA-256 与业务提交父版本逐个一致；正式与预览 HTML 旧指纹均为 1771a2edb5086f0e7bd8db7c50e29ea7a908da67982cc5faf684bf748f6fd295。

独立备份目录：/www/backups/rh.cc3798.com/20261002-r49-debt-cancel-68cfc09c/。
- backend-before.tar.gz：4d678f628017983bb5e3cca5e59d9052fa875a899b812fd084de5af0ae70448a。
- prod-before.tar.gz、preview-before.tar.gz：357dda938943203ea9c0d6e8492c97f6de4dd21c948d05c820a4da68ff7350e2。
- 三个 tar.gz 读取校验通过。
- debt-before.sql：40,504,720 字节，0600；8b56b423fcbc16ee05a17b473dd35ef8bc47465de8770e819e2dc4fcd635778d；包含 store_debt、cashier_v3_debt_repayment、cashier_v3_command_receipt、cashier_v3_business_event。凭证临时文件已删除，没有输出、上传到 Git 或写入报告。

执行正本 01-升级.sql，新增 eb_cashier_v3_debt_cancellation，InnoDB。未回填或改变历史欠款。前后 debt rows 均为 65490；新审计表 0 行，invalid_amount=0，inconsistent_state=0。
仅安装 6 个业务提交中的 PHP 文件（新增取消服务、生命周期模块、订单中心查询、补交作废、动作清单、会员订单模块），线上 SHA-256 与固定验收一致；发布前 PHP 7.4 语法检查全通过。实例配置、AI 源码、库存后端均未替换。
重启 ruihao_swoole，manager_count=1，pid=14326，20800 监听正常。

线上只读验证：MANIFEST_PROBLEMS=0，ACTIVATION_PROBLEMS=0；取消命令 HANDLER=1、POLICY=1；正常/全部欠款查询均正常读取 10 行；正式及预览入口 HTTP 200；发布后错误日志末尾未检出 Fatal error / Parse error / Uncaught。未在线执行取消欠款、收款或其他真实业务写入。

## 前端构建已完成，但未启用

门店 Vue3 源码来自当前热更新入口对应工程的固定提交 68cfc09c，以 Git archive 生成临时只读构建输入，复用既有依赖，无第二份可编辑业务源码。1986 模块构建成功；保留既有大 chunk 警告。
- HTML：ddb81f0bd4e381267c605ffaa9c01e52c86cbe16b19ddeb2e01e5fc3607ba7ea。
- index-917b206a.js：2ba174560cd7bad8feb2ce9a982150b923ca7bfc63d2438cdfa57b0388a334a7。
- index-f1934944.css：f19349447961715842446a77c7007d8c27cdd448a2cbc1aaeee98135987378a0。

新哈希资源已追加到正式及预览静态目录，旧资源完整保留；新 HTML 仅保存到独立备份目录 index-next.html，未替换任何线上入口，因此用户仍使用此前前端包。

原因：当前门店工程嵌入的 inventory-vue3 含另一任务已提交的 R52/R53 改动。其 InventoryStockRequestQueryServices 与 InventoryOperationalUnifiedQueryProvider 线上指纹均不等于该固定提交的对应指纹，不能在本轮授权内静默把库存新前端一并激活或补发未获本轮授权的库存后端。
需要产品经理决定：只发布本轮取消欠款并保持现网库存构建依赖，或另行授权库存 R52/R53 一起发布并完成对应实例迁移/验证。前一种选择需明确允许隔离发布构建沿用现网库存依赖，不把它称为未经变更的整个最新门店包。

## 尚未完成与回滚边界

前端 HTML 启用及完整线上资源验证尚未完成，因此 push 暂未执行，不能称为本轮完全上线。
已上线后端和新增表可继续运行，旧前端尚无取消入口。若需回滚，可恢复 backend-before.tar.gz 并重启 Swoole；仅在新审计表仍为空、没有实际取消事实时允许恢复旧查询/冲销逻辑。若已产生真实取消，不删除审计或把状态3改回0，应保留终态查询、补交拦截及历史记录，采用前向修复。
