# M008-R2-20261019-LINK-CATALOG 固定候选

状态：开发与自测完成，待产品经理确认固定版本验收。未提交、未写线上、未 push。

## 双基线与来源

- 唯一源码正本 HEAD：3faea328d6077e9eca7d52805464c826fc3051a7。
- 工作区有大量其他任务未提交/已暂存文件，本闭环不修改、不暂存它们。
- 用户明确指定来源为瑞昊服务器 ruihao；仅对其进行 READ ONLY 事务 SELECT，结束回滚，不修改源库、源站或代码。
- 源库身份：ruihao，端口3306；eb_page_category 17行、eb_page_link 60行。所有 url/example 都是 /pages/ 相对路径，无外部域名、小程序凭证或客户业务实体记录。
- 目标：008.cc3798.com，mohe_008，独立MySQL5.7:3307；两张目录表均为0行，InnoDB；升级审计表也是InnoDB，result_note为varchar(500)。
- 目标最新迁移id42：20261019-008-store-display-r2，code_version d11e8dc45297d1a9f5a07e4be5044bb6ac98b232。不是依据该版本推定所有业务文件已上线。
- 目标 public/index.html SHA256：48c0a7e57dc001ebf9bca13eff477e21a0f774a2930604aed10fa5da3acb1cbc。
- 本地和目标 PageLink.php、PageCategoryServices.php、PageLinkServices.php逐文件指纹一致，分别为 de88f5c6156e23ee0c8d378eb39605ffa5847099675bad063b68a7fc80fa4b13、ac2bdb93c8d1e878471dfde7d0e76f53e802338fc5cb6a0fb2173f68fc6b0c9e、155c2dfa2c33e280af69b7a7abcf9238ba264d38e61410871ce715a5102feac6。

## 新增与保留边界

本轮新增：将两张空目录补为瑞昊只读快照的17分类、60链接，ID、原字段及名称不变；清理008单个分类树缓存。

保留既有能力：链接选择、营销分组、人员列表和岗位筛选；商品、文章、门店、装修专题、抽奖等动态实体仍从当前008服务取数，绝不导入瑞昊业务表。

不包含：员工横向卡片改版、其他已验收功能发布、会员/订单/密钥/支付数据、首页装修保存、业务代码部署、瑞昊修改或push。

## 文件清单与指纹

前端/后端业务源码：无改动。数据库schema迁移：无。

- 工程脚本 scripts/import/customer_008_link_catalog.php：152e724f39e47a5c23ae578de15593379ba9e4fafc3aed3475fc8aaff77bff9d
- 数据迁移正本 后端代码/database/upgrades/2026-10-19-008链接目录补齐/ruihao-catalog.json：3d5dcec2dd5adf2c808137ec13633f88282f089475cf2533b8b938d57ed5690b
- 自动测试 tests/diy-link-catalog/mysql.php：d13078c23c78275ddfc45ef5185ddddf05c8b934695395843c4eb43273c172dd
- 自动测试 tests/diy-link-catalog/picker.cjs：2f886f35f302ef071ebb2e892533f808b9711b34f6d7b6737bf99f530fe6d3e3
- 验收证据：本文；无构建包、无第二份业务源码。

固定补丁指纹 bb9a2aeee732696b41bd3d4b60522c9cdcfde3e3a65a3f9c8fcbbac12b8fe087：按以上脚本、mysql测试、picker测试、数据JSON顺序，对路径+换行+原文件字节+换行串联做SHA256；证据本文不参与自引用指纹。

## 自测证据

命令：`node tests/diy-link-catalog/picker.cjs`；全部通过。

- 在既有本地MySQL5.7隔离容器建立随机临时库，结束删除，容器恢复原停止状态。
- 原空目录可复现；真实InnoDB两表77行插入逐字段比对源快照。
- 缺少私有备份目录拒绝写入；独占快照权限0600、目录0700。
- 注入最终审计失败后两表完整回滚；真实varchar(500)审计落库通过。
- 第二连接插入被空表范围锁阻止；重复执行、非空目标拒绝。
- 执行真实PageCategoryServices递归目录树；清理指定缓存后4个根分类、首子分类5正常；复现旧空缓存继续存在的必要性。
- 后续编辑阻止回滚，完整回滚保留历史审计；无关哨兵数据不变。
- 执行现有Vue2 SFC模板编译与真实getSort/handleCheckChange/getUrl/ok方法：分类、首屏基础链接、商城首页选择事件、营销组、人员岗位筛选均通过。
- 组件测试只替换HTTP传输和框架壳，不是线上真人弹窗验收；未刷新、保存或更改用户当前装修草稿。
- 脚本与测试的职责、事务、并发、权限、回滚约束已补充注释；未改业务源码注释。

## 数据架构自检

权威源是用户指定的瑞昊目录只读快照，不是安装SQL或其他客户业务表。最小粒度为分类/导航入口，保留源ID及add_time原值；本修复没有销售、收款、库存、会员权益或报表事实，不生成业务事件或Outbox。两表及升级审计同事务成功生效，REPEATABLE READ全范围锁防止并发插入，唯一升级键拒绝重跑。私有快照与目标读回逐字节一致，完整快照相等才允许撤销本批，不覆盖后续用户编辑。目标凭证仅在目标服务器内存读取，数据库+端口和Redis6388/008前缀强校验；固定表名、预编译参数与目标身份限制写范围。77行全读性能可控，无架构/索引调整。

## 执行和回滚边界

产品经理固定版本验收后才精准本地commit，按已明确请求仅操作008。
执行前再次核对两表仍为空、相关业务文件基线未漂移、源快照指纹及本轮文件未变；变化则停止。
私有回滚目录计划 `/www/backups/008.cc3798.com/20261019-round2-link-catalog`，先独占创建before.json后才写库。
仅将已提交脚本和数据JSON放入该目标备份目录；运行 `check`、`apply`、`verify`，绑定实际验收commit；清理键仅 `008:tree_page_categroy`。
SQL提交后缓存失败会明确输出SQL_COMMITTED，不伪报已回滚；可用`invalidate`补清指定键。
回滚入口`rollback`要求快照、审计和当前77行完全匹配，否则拒绝覆盖后续修改。无TRUNCATE、无批量覆盖、无Redis flush、无其他客户连接。
完成后重新打开选择链接弹窗核对目录及返回路径，不点击首页保存、不发布装修；未授权push。

## 已知限制

按用户要求原样复制，未顺便修订瑞昊已有目录名称、示例说明或历史页面路径。例如“积分兑换商品列表”的旧路径在当前uniapp正本没有对应Vue页面，原样保留以避免扩大本轮范围。目录恢复不等于各业务落地页可用验收；无对应008业务数据的动态分类仍可能合法显示暂无数据。
