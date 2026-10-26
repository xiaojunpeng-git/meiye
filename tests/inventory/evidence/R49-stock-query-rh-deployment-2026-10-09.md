# R49-00001 第4轮库存查询瑞昊发布

## 授权与提交

产品经理于2026-10-09明确“我测试没有问题,本轮commit,部署瑞昊,push”，验收对应V4固定版本。提交前逐文件复核14份源码/测试及V4证据SHA256一致，复跑单行导出契约、库存数量PHP10项通过。

功能提交：`1e17542ebc8ef3956d6884405871d6565928d4cc`，信息`R49-00001 门店库存数量筛选与单行操作闭环`，父提交`6165b9c10edc4918663852265b816d94fe9a11b3`。精确暂存31文件：前端源码5、后端源码2、自动化测试7、验收证据17。原9份其他任务暂存内容前后binary diff SHA256均为`dc31b057aca12a17ee75ed7a86e023bfeba79933849b91605faa54e209c95b46`，没有夹带或重置。

前端文件为inventory-vue3/src/App.vue及共享查询工具栏、数量模式组件/契约、样式；后端为InventoryBatchStockUnifiedQueryProvider.php与InventoryProductStockSummaryServices.php。实际测试和证据清单以功能提交为准，职责/业务边界注释已随源码提交。无数据库迁移或永久工程脚本。

## 目标与构建

仅瑞昊`rh.cc3798.com`，服务器`iZbp1fif4p5s7gj96nnec0Z`，正式后端`/www/wwwroot/rh.cc3798.com/`，数据库只读实查`ruihao`、MySQL5.7.44、最新迁移id215。生产遗留Git HEAD`3254ecdc`仅作历史定位，不是本轮发布版本。PHP7.4.33、磁盘202GB可用。未操作007/008/012。

从功能commit不可变Git归档构建18091对应cashier-v3及inventory-vue3/shared依赖，不使用工作区其他任务脏源码。父版本另行构建，HTML/主JS/CSS与现网旧包逐字节相同，两个旧PHP也与父版本一致。新门店8080配套路由集成构建1994 modules通过，只有既有大chunk警告；不构建平台、费用iframe或另行部署独立库存入口。

新主JS`index-436cf8e4.js`，SHA256`5a8c15e883aa329d93a3e6f21d1de62030393b8ffeb609ebe2e5a58c19de8234`；新CSS`index-b070862b.css`，SHA256`b070862b8048a7ba2f8edbc82f805e3c62c402ce26c4e35adc063dc3fc7696c7`；磁盘HTML`9f71e5acdaa97e9d67409b662f9714711d02c4e22539add174a456952e7714ba`。正式与preview-8080门店入口同版。

## 备份与切换

独立备份`/www/backups/rh.cc3798.com/20261009-r49-1e17542e/`，权限0700；含两份旧PHP、正式/预览静态目录、候选包及前后指纹。三个备份完成SHA256、gzip及归档枚举校验。后端备份SHA256`726ddb0fcb7eb1d433eb26f0da6cff43f6ad249e28974c0fd7051891bab89a90`；正式`480808eaa0ae9dc7a7edb373db536a992776609953cf89e778b4a1c198c8530c`；预览`f08de7ea89f18f0f6e183ba219fecafe3cd87eed5e307bd35187620b0b34d697`。

候选从commit导出，两份PHP在生产PHP语法检查通过。切换前再次确认旧文件未漂移；仅增量安装hash静态资源、两份PHP原子替换，重启本实例Swoole，随后原子切换两个HTML。22:02:39（Asia/Shanghai）切换成功。旧hash资源保留，不清理服务器Git、配置、缓存或其他任务部署文件。

正式/预览.env、init脚本及共享库存源/统一执行器/注册器前后SHA一致；未重启独立AI进程、修改Redis、计划任务或数据库。备份没有业务数据，因本轮无写库/迁移，不新建数据库备份。回滚须确认无后续发布，再恢复本批两份PHP及两个旧HTML，重启本实例Swoole；新增hash资源可保留。

## 线上验证

- 正式及预览HTML/新主JS/新CSS六项HTTPS200，JS/CSS与候选一致。预览HTML受既有nginx路径重写，公网SHA不同于磁盘，引用的新资产一致；未改代理。正式/cashierapi/config正常返回未登录410000。
- 两份运行PHP与功能commit指纹一致。Swoole manager_count=1，PID18689、20800监听，最近200行Swoole日志Fatal/Parse/Uncaught计数0。
- READ ONLY事务调用真实已部署库存provider，门店23：大于0共86条、等于0共2条、全部88条，正库存/全部合计1822，零库存合计0；自定义130-200共2条、合计330。此次验证未授予成本权限，金额按权限返回null，不能据此声称金额回归通过。迁移id仍215，事务结束ROLLBACK，无业务写入。
- 使用现有已登录瑞昊浏览器只读进入江苏江都店库存页：单行控件呈现正确，等于0返回4条、合计0；自定义130-200返回一次性手套150，合计150。导出在重置后且能打开原导出抽屉，显示当前4条与原字段；取消退出，未创建导出任务。页面无控制台error/warn。截图`R49-stock-query-rh-online-2026-10-09.png`。
- 浏览器验证后恢复原订单页及库存菜单收起状态；新增未登录临时标签已关闭，未登录新账号、签发令牌、变更库存或创建真实交易。

完整资产、查询及健康证据见`R49-stock-query-rh-online-checks-2026-10-09.json`。旧广域契约失败、分页/条数展示与未下载导出文件限制继续按V3/V4自测记录保留，不声称全系统测试通过。

## 推送边界

线上验证已通过。push前只读核对origin的release/store-cashier-c14-main为父提交6165b9c1，待推功能仅本轮1e17542e，另附本轮发布证据归档，不推送github、不force push。实际推送结果在交付时报告；本记录的历史待验收清单保持原样，用户最新验收由本发布记录登记。
