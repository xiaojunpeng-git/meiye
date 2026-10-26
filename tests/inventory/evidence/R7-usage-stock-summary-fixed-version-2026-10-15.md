# 第 7 轮：院装领用选品与库存查询同接口

## 问题与口径

- 瑞昊预览站巢湖万达店（store_id 84）“丰韵霜支”库存查询显示 15 支，院装耗材领用选品显示 0。
- 只读线上核对：默认门店仓 location_id 47 的旧商品 601591 / SKU 583537 有 GOOD 库存 15；ACTIVE 批次 238 也是 15。旧商品已删除；同名现行商品 602512 / SKU 584458 无库存。旧选品目录只返回现行商品，因此显示 0。
- 产品经理本轮要求选品库存数量与库存功能使用同一个接口。门店领用现改用库存查询的 `/v3/product-summary`，保留库存事实的 SKU 唯一标识，提交仍由原有院装领用事务锁定真实库存和批次。没有改动结账流程、库存事实或生产数据。
- 库存页面有普通商品汇总与“大于 0”统一批次查询两个接口；产品经理已明确回复“商品汇总接口即可，数量一致”，本固定版本按此口径实施。
- 线上瑞昊预览 PHP 目录服务与本地正本的指纹不同，预览后端还不支持先前的批次选品参数；本轮未部署任何远程代码。

## 固定版本

- 基线 HEAD：`8f0dac55c1910bd6dcd856306be36753ba3b088a`
- 闭环补丁 SHA-256：`f59d747d43124bcff75b0a7167b708d128028b010e1c1f6e7effcb78d0ba9e5c`
- 前端源码 `前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue`：`7ba931fed5419f2f4e65065531b43306439d4cf6845d1ba7565fe25e7b596d34`
- 后端源码 `后端代码/app/controller/store/product/inventory/InventoryStoreReadModel.php`：`3b992442780f43655cf782000403ffbe70e1196533531bdee07009c9bda3677c`
- 后端源码 `后端代码/app/services/product/inventory/InventoryStoreReadModelServices.php`：`d3cf95c5145593dedecc1148ec3e73ef82678a591cae6c29686a271d57e1155f`
- 后端源码 `后端代码/app/services/product/inventory/query/InventoryBatchStockQueryProvider.php`：`cfea5748f6037bd85b9f7c072ca6b557e00dcf000805a71e61d5dc5958228497`

## 自测与限制

- PHP 三文件语法检查、闭环补丁检查及门店端 Vite 构建通过；构建源为当前 `cashier-v3` 与所依赖的 `inventory-vue3` 源码。构建仅用于验证，未作为 8080 发布包。
- 本地 `ruihao` 数据库只读调用共用商品汇总服务：巢湖万达店“丰韵霜支”仅返回旧商品 601591 / SKU 583537 / unique `0ece3b4e` / 库存 15 支；匹配分类 26335 返回 15，不匹配分类 999999 返回空。目录分类元数据可读取，11 类。
- 院装领用写服务按同一 product_id、sku_id、unique 锁默认仓 GOOD 库存，再按批次 FEFO 扣减；本轮没有提交真实领用单。
- 隔离库存测试库缺少 `store_product.unit_name` 字段，已有历史 SKU 集成测试在读取目录时中止，因此未把它计为通过。已用本地 `ruihao` 只读真实服务查询核对本轮核心场景。
- 当前本地浏览器新开收银页落到登录页，未使用用户账号登录做界面点击；正式预览入口尚未部署本轮版本，须由产品经理对本地固定版本验收后再按单独授权发布并线上复核。

## 文件分组

- 前端源码：上述 1 文件。
- 后端源码：上述 3 文件。
- 数据库迁移、自动化测试、工程脚本：无。
- 生成物：本地 Vite 构建产物，未纳入 Git。
- 验收证据：本文件。

本轮新增或更新的业务注释位于上述四个源码文件，分别说明领用选品的库存事实边界、分类权限及历史 SKU 身份。
