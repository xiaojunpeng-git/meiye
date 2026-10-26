# R50 瑞昊权益金额与次数发布

产品经理明确“我测试没有问题,commit,部署瑞昊,push”。本轮范围包括已验收八列与取消金额闭环，以及共享次数和普通欠款漏扣修复。

## 提交和隔离边界

- 前一业务提交873333e5，最新业务提交63bf7189（11文件）。提交前文件指纹与交付一致，暂存区为空，仅逐文件暂存本轮清单。
- 发布代码提交721e571f59ac32fa094532455ab5c203b1579ad4，分支release/r50-entitlement-20261002，父版为现网库存发布b573aff8。
- 仅覆盖本轮24个源码/测试/证据文件；cashier-v3和inventory-vue3与当前唯一源码HEAD比较零差异；shared沿用现网版本，不夹带未授权AI R59。
- card-rule文件既有批量authority读取已在线上：部署前指纹26d06434与主分支修复前版本一致，发布保留该能力，没有回退现网AI依赖。
- 既有未提交DirectSnapshotEntitlementSettlement改动未暂存或部署，其他脏工作区保留。主开发分支、GitHub及其他客户不推送/部署。

## 实例、备份与迁移

目标仅rh.cc3798.com，正式/www/wwwroot/rh.cc3798.com，预览静态/www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3。
生产只读实查数据库ruihao；取消审计表0行、卡规则状态2853行、成交分摊事实73行，依赖表存在。本次没有迁移、回填或生产数据库写入，不需要覆盖数据库恢复。

独立备份/www/backups/rh.cc3798.com/20261002-r50-entitlement-721e571f/：

- backend-before.tar.gz：25120b4eac5ef5d4664470c444a2849c7ac24e0be1661e1671f76437096822d4
- prod-before.tar.gz：2fca76ad2e77bb0ab124b4739f21f1e0bec6dd21385675b93cf1ca886ee22d90
- preview-before.tar.gz：08dd0866d8039dfd7f0cc97ecbac2a9b9ab6a5fc60aaf2e48d34464bbcd29399

三份归档读取校验通过。回滚恢复对应后端和入口HTML，重启ruihao_swoole；不恢复数据库或删除后来产生的事实。旧静态资源保留。

## 构建和部署

从固定Git归档生成临时派生构建输入，复用既有依赖，不建立另一份可编辑业务源码。门店端1986模块构建成功，保留既有chunk体积警告。cashier-v3源码为18091对应唯一工程，库存为现网已部署版本，AI共享依赖保留现网，不称为含未授权AI改动的整套包。

- HTML：ac2d5f81fab97483d9c05c754d4b1499ca70da32f9a024e542068fcd3d1a8290
- index-353bc3a6.js：f484a0efebdf4a873d231cfe9832b4665c851762e8738e883b8ae93a3c0629ae
- index-a4cd34a2.css：a4cd34a29d1f0ac366ad43e47fec4defd2774f0e5e6801e570eb575f128e4cec

发布7个PHP文件（card-rule、来源欠款解析、会员顶部、权益投影、会员详情、取消服务注释、WriteOffOrderServices），生产PHP7.4语法检查通过，暂存与运行文件逐个cmp一致。旧文件发布前与登记基线指纹一致。
仅追加新静态assets，两处入口HTML原子替换。Swoole重启manager_count=1,pid=29223,20800监听正常。不重启或替换AI、库存、其他客户服务。
首次后端暂存rsync因目标父目录尚未建立失败，创建明确暂存目录后重传成功；失败发生在启用前，不当作成功。

## 验证结果与限制

- 正式HTTPS HTML/JS/CSS下载成功并逐项匹配构建指纹，预览JS一致；预览HTML沿既有代理改写资源前缀为/preview-8080/view_cashier_v3/，指向同一新JS/CSS。
- 生产只读共享公式验证：6980取消1500上限23次，用完23后物理7不可用。
- 生产read probe扫描100个已完成发卡回执没有找到当前待还的V3新购卡，READ_SAMPLES=0；不冒充线上已验证真实1480样本，也未为验证新建生产欠款/取消/核销。
- 本地真实会员1480页面、普通欠款7项、取消欠款15项、共享次数12项及预约公式3项通过；卡规则19项集成测试见前一固定自测。所有测试账务改动回滚。
- 发布后近期日志尾部未检出Fatal error/Parse error/Uncaught；没有执行真实资金或服务写入。
- 线上验证为文件/静态资源/运行/数据库依赖/只读公式验证，写业务验收依据为产品经理本地明确验收，不宣称生产全业务回归。

线上核验通过后按授权push到Gitee origin专用分支release/r50-entitlement-20261002，push回执和远端ref核对由本次工具结果记录。GitHub及主开发分支不push。
