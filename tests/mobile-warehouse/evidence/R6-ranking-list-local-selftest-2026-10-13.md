# R6 数据页排行列表本地自测与产品经理验收

## 固定版本

- 基线 HEAD：`7d0186502c9797dbb188dca889c804aac32723d5`
- 范围：移除员工销售、员工劳动、员工点客、服务项目的重复柱状图；这四项与门店排行首批显示 10 行，下滑每次追加 10 行；六个排行均可切换正序与反序；去掉门店排行顶部的期间和已显示数量摘要。
- 六个源码／测试文件的工作区补丁 SHA-256：`ff6d327d4844f73e9a05870c4c5e9c6baf718a91b71800070c99b956194e5157`。该指纹按下列六个路径顺序对 `git diff --binary --` 计算。

| 类别 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/mobile-vue3/src/merchant/pages/warehouse/index.uvue` | `8b247cae89e32a6133e2bb3553329501bfb808464b424988d8807f2b218f60d3` |
| 前端契约 | `前端代码/mobile-vue3/src/shared/contracts/mobile-merchant-v1.contract.json` | `400b79755c2cb5cae8fbbc5d9d6ff76c465bbd045be4b75c600cfbc7e153fdeb` |
| 后端源码 | `后端代码/app/services/mobile/warehouse/MobileWarehouseServices.php` | `4ea7e529ec6fab907aa0e1176e952a4f2617ffaf2072ab6bf84b123515adab5b` |
| 统一指标 Reader | `后端代码/app/services/query/metric/RegisteredMetricReadServices.php` | `14f93b56dd9248325166b0273b1e72cf6894a3632eea34377546089207746134` |
| PHP 测试 | `tests/mobile-warehouse/php/ranking-scope-contract.php` | `a402a4cfca47c2b38b6db77a4ded644267b9479026b4b6ff1dcd11628c8bb6cf` |
| JS 测试 | `tests/mobile-warehouse/js/page-contract.mjs` | `14ddbfc93df9d81c657365ed2b637adaeaa52f18eb1134c680dc41f7c4350afe` |

`RegisteredMetricReadServices.php` 同时存在其他任务未提交改动；本闭环只涉及 `dimensionRanking` 的可选 offset 与两个事实分组查询的 offset 传递。后续提交必须逐块暂存，不能把该文件其他差异带入本闭环。

## 自测结果

- `bash tests/mobile-warehouse/run-contract.sh`：PHP 层级及排行权限合同通过，JS 页面合同 1/1 通过。
- 两个修改的 PHP 文件 `php -l` 通过；六个文件的 `git diff --check` 通过。
- 从基线 `HEAD` 导出隔离的 `mobile-vue3` 临时项目，只覆盖本轮页面和契约文件；HBuilderX 5.26 `launch mp-weixin --compile true` 报“编译成功”。临时编译目录 `/tmp/warehouse-r6-compile.im8eTi`，仅为生成物，不是开发源码或体验版。
- 本地 H5 真人路径：员工销售、员工劳动、员工点客、服务项目及门店排行均首批 10 条，下滑后显示 20 条；员工销售、服务项目和门店切换正序后重新取首批 10 条。组织排行进入有 8 个下级组织的层级，切正序后首项从“皖北二分公司 607913”变为“江苏分公司 104608”。
- 最终复核移除了组织排行按展示整数元二次排序的旧函数；热更新后再次在该 8 个组织层级验证正序首项为“江苏分公司 104608”，沿用后端原始精度顺序。
- 产品经理于 2026-10-13 回复“我测试没有问题”，明确确认本轮固定版本验收通过，并授权 commit、部署瑞昊、push 与上传体验版。
- 本地截图 `R6-ranking-list-local-2026-10-13.png`：员工销售页仅有排行列表和统一排序入口，柱状图未出现；门店页另经本地 H5 操作确认摘要文字已去掉。

## 交付边界

- 前端源码：上表页面与契约。后端源码：上表两个服务。数据库迁移：无。自动化测试：上表两个文件。工程脚本：无。生成物：隔离小程序编译产物。验收证据：本文与本地截图。
- 新增或更新业务注释的文件：`index.uvue`、`MobileWarehouseServices.php`、`RegisteredMetricReadServices.php`。已扫描并删除本轮替代的柱状图模板、计算函数和样式；保留组织排行趋势与占比图，因为它们仍在使用。
- 本文记录本地固定版本与验收；提交、部署、推送和体验版上传的实际结果另记发布证据，不以授权代替完成。
