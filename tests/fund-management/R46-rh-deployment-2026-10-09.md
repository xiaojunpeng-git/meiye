# R46 瑞昊门店费用审核编辑删除发布记录

## 授权与固定提交

- 产品经理于2026-10-09明确确认“我测试没有问题,本轮commit,部署瑞昊,push”，验收对应 `R46-fund-approved-hide-edit-20261009`，核对其源码、证据、补丁和构建指纹一致后提交。
- 功能提交 `4cf87e84d09e640e5cd3fb7a9d712f12694edcd6`，信息 `R46-00001 门店费用审核编辑删除闭环`，父提交 `b07fcf4f549b7c52edcc197a2e92901a53350bca`。
- 实际提交17文件：前端源码1、后端源码3、自动化测试2、验收证据11；无迁移和工程脚本。前端为fund-vue3 App.vue；后端为门店fund/StoreFund控制器、StoreFundServices服务、route/store.php；测试为费用前端契约和本地回滚集成测试，证据为R46两份历史/累计自测、两份固定清单及7张截图。
- 通过精确文件清单暂存及 `git commit --only` 提交，其他任务原有9个暂存文件保持原状，未使用全量暂存、重置或切换工作区。本地78项集成回归、前后端契约、语法和限定diff检查再次通过；业务自测仍以本地实操及产品经理验收为准。

## 目标与发布前核对

仅瑞昊正式实例：`rh.cc3798.com`，服务器 `iZbp1fif4p5s7gj96nnec0Z`，正式根 `/www/wwwroot/rh.cc3798.com/`，预览静态根 `/www/wwwroot/rh.cc3798.com-8080/public/`。PHP7.4.33、MySQL5.7.44-log，数据库实查为ruihao，约203G可用空间。未连接或改变007/008/012。

目标三份PHP部署前指纹与功能提交父版本逐字一致；费用依赖 CashierV3FeatureResolver 的服务器指纹与固定源码一致（`c1fbd05fe9812501a5aee8c240da52eacac46a699926a67e74840d85f3127de3`）。原正式/预览费用HTML均为 `b19a22d60e5ba05692aaa2913d00711e4e82d321ff09954c11e6407250f611d9`，预览链接原指向 `.local-8080-integration-releases/r24-7a56739f/view_fund_v3`。

三张费用表均为InnoDB，document_status为varchar(16)，audit_revision为int unsigned，现有结构可直接承载DELETED，无SQL升级。本轮前后最新升级日志id均为215（R32无原单退款迁移），未改迁移记录、数据库或配置凭证；APPROVED989、DRAFT359的状态计数核对相同。

## 构建与独立备份

部署复用已验收固定构建，不在服务器构建：功能提交中每份交付文件与验收SHA匹配，当前fund-vue3和shared与该提交无差异，构建三文件指纹也与固定清单一致。费用源码树 `affcfcc4cbe03dc52917d03d2eea7a7d7d1b95bc`，shared树 `48b1d49a1d66da24c9f4e7036426e6f585b83eac`。这只是费用前端定向发布，不称为完整8080集成打包。

后端包从功能commit的Git archive产生，仅3文件，SHA-256 `444153b7deb7c471c6bc010381736e14977e4566546805dc2dc5defc851326a6`；已验收费用dist归档SHA-256 `064f12c00746ceb403c549a2fed9cbd23698bb8dda35b49613b873ddcccc8633`。上传后核对包及候选逐文件指纹，PHP7.4语法3/3通过。

独立备份 `/www/backups/rh.cc3798.com/20261009-r46-4cf87e84/`，三份均通过gzip校验和归档枚举：

| 备份 | SHA-256 |
| --- | --- |
| backend-before.tar.gz | 25c223b4c153aebb2446036417a5dacbd4f050a6a619ba92cea9395236c9a42f |
| frontend-formal-before.tar.gz | 11e35577245149a0529255c74ebb6a17671fe25058830a7e721f91d1a1eedf33 |
| frontend-preview-before.tar.gz | 25d82c6fb7c16d95cfb22807c59c6043afe0929263eede488fab6a4a04eaab4f |

## 部署与验证

16:33:02完成切换（Asia/Shanghai）。切换前二次核对运行指纹和原预览链接未漂移。先追加新静态资源并创建预览版本目录，逐文件原子替换三份PHP，重启瑞昊Swoole，再原子替换正式HTML和预览链接。预览新目标为 `.local-8080-integration-releases/r46-4cf87e84/view_fund_v3`。不全量同步后端、不删除旧哈希资源、不改Nginx或其他实例配置。服务只由门店/平台HTTP控制器引用，本项不重启无关AI worker。

三份运行PHP均与固定候选一致。两套费用HTML磁盘SHA-256均为 `6e3708c37857664f08337b28f1d5b9b5b4506da7ae80b23db2ff1ec8640a030f`；主JS `index-228d4b8a.js` SHA-256 `d937a94eab534adbac9111ceb52fa93895d904249d171a3f9f261410910bd6ea`；CSS `index-4638cc3d.css` SHA-256 `4638cc3dd321101e1835697fca046bd342754072d1655753faca1edd1fe94b55`。

正式和预览公网HTTPS入口、各自JS/CSS均200，证书校验正常，资源内容与构建完全一致；预览HTML因Nginx前缀改写，公网HTML指纹与磁盘不同，但引用及资源逐份核对正确。Swoole manager_count=1（pid8342），20800正常监听。

生产仅只读查询：门店23本月费用列表2条，收入36415分、支出125233分、净额-88818分、33条明细，台账余额61182分；DELETED筛选计数0，新删除控制器方法可加载。以已知组织员工490、门店23条件读取岗位权限，费用能力true，delegated只读能力false。未签发令牌，不把该权限解析当成已登录HTTP写操作。

线上原标签仍在现金结账草稿收款信息步骤，未导航、提交或关闭。临时新标签缺少登录态，正常转到登录页后关闭；未在生产审核、撤销、修改或删除单据，不把上线健康核对冒充生产交易验证。

匿名HTTP探针两次触发既有 `Undefined offset: 1`，认证中间件会直接解构空token解析结果；认证文件线上SHA `6789e45f8f160e80bf602bfc003b0a7cc69e7b25a3965ffc049b225af647372f` 与发布父版本一致，本轮未改它。两次日志时间16:34:59/16:35:48与探针对应，此项不算业务验证PASS，也未扩散修复认证。公网资源和服务端只读结果另见 `R46-rh-online-checks-2026-10-09.json`。

## 回滚与推送

回滚前必须核对没有后续发布，目标运行文件仍为本轮指纹；恢复同批三份PHP、正式旧HTML、预览原链接并重启瑞昊Swoole。旧静态资源和旧预览目标保留。若上线后已产生DELETED业务记录，禁止直接回退不识别删除终态的旧服务，应保留删除查询排除及禁止复活保护、采用前向修复；不得通过恢复数据库或改回单据状态抹掉真实业务。本轮无数据库回滚步骤。

上述线上核对后，已快进推送 `4cf87e84` 至 Gitee `origin/release/store-cashier-c14-main`，远端实查完整SHA一致；未推送github镜像。发布记录作为后续独立文档归档提交，不改变已部署业务源码。并行任务的其他工作区及暂存改动仍保留。
