# R7 门店地址编辑回显修复

## 范围与状态

- 对应本轮用户反馈：瑞昊门店 179 列表有完整地址，编辑页省市区与详细地址没有回显。
- 开发与自测完成，待产品经理确认验收；未 commit、未部署、未 push。本轮无线上数据写入。
- 唯一平台端源码为 `前端代码/admin/`，由 `mohe-admin-src` 挂载 `/app`，本地入口为 `http://127.0.0.1:18081/`。
- 已完整阅读统一数据底层架构及客户独立实例与统一发布治理。本轮仅本地开发验证，线上迁移、备份与回滚不适用；没有数据库结构变更或 8080 构建。

## 原因与处理

此前线上只读核实：门店 179 的 `address` 是“江苏省扬州市邗江区竹西街道大润发(维扬店)二楼诺曼姿”，`detailed_address` 为空，四个地区 ID 都是 0。编辑器不能用 `[0,0,0,0]` 回显级联选择。前端原来又只加载根地区选项，没有补齐已保存 ID 的子节点路径。

现在优先使用门店主表已有地区 ID。只有全部 ID 缺失时，才按本地权威地区字典逐级、唯一、完整前缀匹配省市区，街道可选；匹配后将剩余门牌投影到详细地址。查询不回填主表，不调用外部地图，不改变定位坐标。不能完整匹配、出现歧义或两个字段同时包含详细内容时保留原文，禁止猜测。

前端逐级加载已选路径，并用请求版本保护切换门店、新建页面及用户主动改选，防止旧请求覆盖当前选择。列表兼容整串与拆分地址，显示地区加详细地址，已有相同门牌后缀不重复拼接。

权威数据源仍为门店主表和地区字典；沿用既有授权接口及保存事务。没有新增业务事件、事实粒度、统计时点、冲销、Outbox 或权限计算。投影每次至多四次父级字典查询，重复保存无地址差异，不自动批量回填历史资料。

## 自测与真实页面验证

| 项目 | 结果 |
| --- | --- |
| `php/store-address-read.php` | 12 PASS：唯一匹配、既有 ID 优先、歧义与未知原文保留、坐标不变、查询次数限制 |
| `php/store-address-local-transaction.php` | 11 PASS：真实字典、真实读写服务、读取无写入、保存再读、重复保存、其他资料不变、事务回滚 |
| `js/store-address-hydration.mjs` | 7 PASS：逐级加载、数字 ID、请求竞态、失败保留原文、列表完整地址 |
| 两个后端业务文件 PHP 语法检查 | PASS |
| `js/platform-selected-store-entry-contract.mjs` | 1 PASS |
| `js/workspace-tabs-contract.mjs` | 5 PASS、1 既有 FAIL，见下文 |
| 精确业务文件 `git diff --check` | PASS |
| 18081 当前源码热编译 | Compiled successfully |

真实页面使用本地独立测试门店 181，名称及简介均带测试标识；未拿真实门店保存测试。按旧数据形态准备整串地址和零 ID，从根入口进入门店管理再编辑，地区回显为“江苏省 / 扬州市 / 邗江区 / 竹西街道”，门牌回显为“大润发(维扬店)二楼诺曼姿”。点击真实保存，再从列表重新打开确认一致。落库 ID 为 10845 / 12051 / 12065 / 12073，地区与门牌分别保存，列表仍显示完整地址。

测试夹具的既有配送配置需要选择“到店自提”才能通过原有保存校验，该操作仅作用于测试门店。验证结束后已按名称及简介双标识软归档测试门店；核实活动夹具数为 0。本地真实门店 179 的空地址和零 ID 保持原样。默认数据库自动化测试均事务回滚。

保存后重新打开截图：`R7-store-address-persisted-2026-10-10.png`。截图为本地测试门店，不代表线上已修复。

## 已知限制

- 地区恢复只接受权威字典的精确唯一前缀，不猜测省份简称、省市缺失或不规则地址；未匹配数据原文保留。
- 本地开发服务直接请求编辑深路径返回 404，属于现有历史路由回退问题；本次通过根入口和真实页面导航验证保存后重新打开，没有将其写成浏览器整页刷新通过。未改动其他任务的 `vue.config.js`。
- 既有导航合同失败项为 `legacy store and people entries keep original list routes in both menu data flows`。已核实测试本身及它读取的 workspace、menus、menu、organizationWorkspaceMenu 文件与 HEAD 完全一致。本轮没有修改这些文件，也不把这项失败隐去或扩展为导航整改。

## 交付分组与注释

- 前端源码：`前端代码/admin/src/pages/store/addStore/index.vue`、`前端代码/admin/src/pages/store/storeList/index.vue`。
- 后端源码：`后端代码/app/services/store/SystemStoreServices.php`、`后端代码/app/services/store/StoreAddressReadServices.php`。
- 数据库迁移：无。
- 自动化测试：下方指纹清单中的三个 `tests/organization-store-v3/` 测试文件。
- 工程脚本：无。
- 生成物：仅开发服务热编译缓存，没有交付新的集成包或修改派生业务源码。
- 验收证据：本文与保存后重开截图，单独归类，不混入业务源码。
- 四个业务文件均补充职责或关键边界注释，说明只读投影、权威 ID、歧义保护、请求版本及完整地址拼接。旧直接组装零 ID 分支已由新服务替代，不保留平行实现。

## 固定验收版本

基线 HEAD：`785b2a841916e3f72869968b5255e4707002c461`。

补丁 SHA-256：`fc9a5dfa1ec452932e005731782a062e155270ab9d583f1b609fe52f99c8efbe`。按下列顺序拼接各文件相对 HEAD 的 diff；新文件使用 `/dev/null` 的 no-index diff。证据文件不纳入业务补丁指纹，避免自引用。

```text
951110c47d61fcc25953b3358f5d27e255ed090bc0ab1813b4069204f4a9779a  前端代码/admin/src/pages/store/addStore/index.vue
fe2faa940bb6316c70224ca1034c95a8f769fab516b2542ec792f69d3c8e7c23  前端代码/admin/src/pages/store/storeList/index.vue
d85807f789118c6a0d47d4e3d007ffa76b9a96a50e515868944379ea87f37250  后端代码/app/services/store/SystemStoreServices.php
f4f6b6a806b4176e6f160a6c81e2557a16b868c3dcfe36b160e58b5a56b508b5  后端代码/app/services/store/StoreAddressReadServices.php
3a1e1ae2d34a1458001e72490603edbf8de57f1a1105e4e4d507b3aad646df48  tests/organization-store-v3/js/store-address-hydration.mjs
2c307c7aaad2db7953c6f9c971ea83f2c455a857b4fe1cc848009c2db52f6d24  tests/organization-store-v3/php/store-address-read.php
c9aa1fbbeb4be1579d6d5c7514df38c1d823e53d92ce1e58960f21f4ed61bd3b  tests/organization-store-v3/php/store-address-local-transaction.php
```

工作区其余既有改动及暂存区保持原状；本轮未执行任何暂存、提交或发布操作。

## 产品经理验收

产品经理随后明确“我测试没有问题，本轮 commit，部署瑞昊，push”。发布前七个源码/测试文件与上述固定指纹逐项一致，30 项专项检查再次通过。该确认仅对应本轮地址修复；后续实际提交、独立备份、部署、线上只读验证及推送结果另记发布证据，不改写上述自测时点状态。
