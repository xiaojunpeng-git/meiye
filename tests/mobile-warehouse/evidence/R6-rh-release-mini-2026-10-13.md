# R6 瑞昊发布与小程序上传记录

- 时间：2026-10-13 02:30（Asia/Shanghai）。产品经理已对 `R6-ranking-list-local-selftest-2026-10-13.md` 的固定范围确认验收，并明确授权 commit、部署瑞昊、push 和上传体验版。
- 本地业务闭环提交：`e3e2ca2aa9e07ee8ea73a91e94fedc339916941d`（`OPT5-00007 商家端排行列表续页与排序闭环`），仅含该自测记录列出的 8 个文件；并行任务的暂存和未提交改动未纳入。瑞昊隔离发布提交：`44335662295b957c4e842c86ae7d847873d1f885`，以瑞昊上一发布版本为父提交，保留线上既有的商品分类 Reader 逻辑，同时叠加本轮 offset 查询。
- 目标实例：`rh.cc3798.com`，站点 `/www/wwwroot/rh.cc3798.com`，数据库 `ruihao`。本轮无数据库迁移；发布前核对最新升级记录为 `20261012-mohe-ai-feedback`。只部署 `app/services/mobile/warehouse/MobileWarehouseServices.php` 和 `app/services/query/metric/RegisteredMetricReadServices.php`，未改动其他客户实例。
- 发布前两份 PHP 的可读回滚包：`/www/backups/rh.cc3798.com/opt5-r6-e3e2ca2a.ptaO4y/predeploy-files.tar.gz`，SHA-256 `08520239726471146da1d3345ac77d92aa428dab2b7f1429fe049b705d8d923a`。远端暂存文件通过 `php -l` 后原子替换；清缓存并重启瑞昊 Swoole、魔核 AI 执行／导出／监督／权益索引服务，部署脚本返回 `DEPLOY_RH_OPT5_R6_OK`。部署后两份文件 SHA-256 分别为 `4ea7e529ec6fab907aa0e1176e952a4f2617ffaf2072ab6bf84b123515adab5b`、`2c09d74ede1ecfdeae8b99455f0dc0d5e6dc1bb7a7b0d94698e33fe489775a4f`。
- 瑞昊首页和后台登录入口均返回 HTTP 200。线上真实数据库、授权组织员工只读业务查询覆盖门店、员工销售、员工劳动、员工点客、服务项目五类排行：每类首批 10、第二批 10、前 20 条实体不重复、正序首批 10；脚本返回 `RH_R6_ONLINE_SMOKE_OK`。微信开发者工具模拟器使用本轮隔离构建登录瑞昊，进入“数据 → 员工销售”，本月反序首批 10 条、下滑出现第 11—20 条，切换正序后重新显示首批 10 条。该项为开发者工具模拟器真人路径，不冒充实体手机体验版测试。
- 线上验证后才推送 `release/opt5-ranking-list-20261013`；远端 `refs/heads/release/opt5-ranking-list-20261013` 核对为 `44335662295b957c4e842c86ae7d847873d1f885`。未推送含其他任务改动的本地主分支。
- 小程序从上述隔离发布提交导出到 `/tmp/opt5-r6-mini.p7vhhJ/`，临时构建环境指向 `https://rh.cc3798.com`，HBuilderX 5.26 编译微信小程序成功。官方微信开发者工具 CLI 对 AppID `wx29d9b63c92555701` 上传版本 `0.15.19`，备注 `OPT5 R6 排行列表续页排序；瑞昊发布 44335662`，返回 `✔ upload`，包大小 1,867,943 字节；编译后数仓页面 JS SHA-256 `11646766e4d87cb33bc687a9d2c474a7394901ea4dbcbb5c7d4e88e9a71394f4`。
- 上传回执确认代码已提交到该 AppID。开发者工具 GUI 此次未显示“覆盖体验版”提示，未在微信公众平台版本管理独立核实体验版绑定；因此不能把 CLI 上传回执等同于已核实的体验版绑定，也未提交审核或发布正式版。

## 文件分类

- 前端源码、后端源码、自动化测试：见本地业务提交 `e3e2ca2a`；本记录未改动业务代码。数据库迁移：无。工程脚本：无新增。
- 生成物：隔离小程序构建产物与远端 PHP 发布包，均不作为源码正本。验收证据：本文件及 `R6-ranking-list-local-selftest-2026-10-13.md`。
