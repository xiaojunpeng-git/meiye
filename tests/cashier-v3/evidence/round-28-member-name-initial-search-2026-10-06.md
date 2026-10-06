# 第 28 轮：会员姓名首字母检索本地自测

基线 HEAD：`fb8a3060c768f44d49e36a30a63b9bc841d7c0a0`。本记录是开发与自测证据，不代表产品经理验收、提交或部署。

闭环补丁指纹：按下表文件顺序拼接 `shasum -a 256` 输出后再次 SHA-256，结果为 `bf32e2316ec8f46bc89e7391869259db7ae85dd3ef49daad9427c8cf91d77625`。

## 范围与权威源

- `eb_user.real_name/nickname` 仍是姓名权威源；新表只保存可重建的检索首字母，按 `uid` 一对一关联。
- 新增、改名、物理删除均由主表触发器在原事务内同步，覆盖收银建档、会员端建档、其他会员服务和历史导入等不同写入路径；回填覆盖已有会员。
- 收银/预约共用选择器可按首字母前缀查询且不区分大小写。查询仍在原 `store_user` 权限范围内计数和分页；其他选择器未扩展范围。
- 本次只改收银选择器输入提示；未改其他收银布局、按钮或流程。复杂检索分支与迁移同步边界均补充业务注释。

## 文件指纹（SHA-256）

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/cashier-v3/src/components/common/MemberSelectorOverlay.vue` | `217304533639cd84f2e93a5f047e9080c2940172713c77b3aa5dac05d1acaaee` |
| 后端源码 | `后端代码/app/services/cashier/v3/member/CashierV3MemberModule.php` | `0d03f4a0f8d10be20fb4664e6e9e14745c1515d9c764dc258c20c9d7ea9ccd40` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-06-会员姓名首字母检索/00-升级清单.md` | `2db5b57bb4b785b6f42adf0c0334890cbe11b738a4c686bcf9b7961c3d0dead0` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-06-会员姓名首字母检索/01-汉字首字母映射.sql` | `ed492a3422158414c2845c157d96567391e7ba96fa4cfa798c9d5a42724b54c1` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-06-会员姓名首字母检索/02-正式升级.sql` | `9139201b6fd8ae341a0b6cd13be590343ea80e90ca7b879420ec76c7b37441e9` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-06-会员姓名首字母检索/03-升级后验证.sql` | `a20158b48643b9bf46caf46889bdddf5e655577382d5b813d527bf245041d642` |
| 自动化测试 | `tests/cashier-v3/js/member-name-initial-search-contract.mjs` | `fafa77c341f93f0ebd89d7fd9a54500e66815f8e648b330ba180c7e31dc0845e` |
| 工程脚本 | `scripts/generate-member-pinyin-initial-map.php` | `3b04c999bebe681a84cbf4d354209dd251191df3df229dd377fe93667fc3d403` |

生成物：本地 Vite `dist/`（未纳入源码）。

## 本地验证

- 本地 `ruihao` 开发数据库：`eb_user` 151,602、首字母投影 151,602、缺失或过期 0；字典 26,711 字；`张晓燕/ZXY`、`单小梅/SXM`、`解芳/XF`、`李倩/LQ`、`李婷/LT`、`赵璐/ZL`。
- 事务内新增仅昵称 `李倩` 得 `LQ`、改名 `李婷` 得 `LT`、删除后投影清理；事务回滚。已有会员改名 `杨林荣→张晓燕` 后投影变 `ZXY`，回滚恢复 `YLR`。
- 本地收银页面实际操作：本店 `zxy` 与 `ZXY` 均返回同样 2 位会员；切换“全部”返回 438 位；随后恢复本店空搜索。
- `php -l`、新旧选择器契约测试、`git diff --check` 与 `npm run build` 均通过。Vite 仅报告既有大块体积警告。

状态：本地开发与自测完成，待产品经理对上述固定范围确认验收；未 commit、未部署客户实例、未 push。线上各实例仍需逐一完成数据库备份、迁移、构建发布与验证，不能以本地数据库结果代替。
