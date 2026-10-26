# 008 商城首页优惠券入口兼容修复

- 原导入版本：`d54bb8bc6db46a6b6f243ae5d040e37d254ecefb`，008 DIY `r3` 页面哈希 `e01d19b530667076159c44385c5505db8766d448e800cafefc0f7be4657299dd`。
- 现象：旧配置中的“优惠券”链接 `/pages/users/user_get_coupon/index` 在当前 Vue2 会员端不存在。当前等效入口是 `/pages/activity/coupon/index`（源码页面标题“领券中心”，且首页优惠券组件已使用该路径）。
- 修复：仅将首页快捷入口的这一个路由换成现行路径，版本变为 `008-morestore-mall-20261013-r4`；原 8 模块顺序、其他链接、16 张图片及美丽日志不变。数据库修复设有 r3 版本及完整页面 SHA-256 守卫，写入前保存 008 DIY 私有快照；事务内比较并修改 `value/default_value/version/update_time`，仅清理 008 DIY 缓存键。
- 文件清单 SHA-256：`901295e4e344ac35a202bc39c64847e15e3d8defe67e3d0396e428ad6d23c36a`，逐文件见同名 `-files.sha256`。
- 本地验证：PHP 语法与计划校验通过；MySQL 5.7 隔离库基于 r3 原页面执行 `mallRepairPreflight`，证实修改仅含该一个链接。线上真实页面检查发现问题后立即修复，不涉及优惠券业务数据迁移。
- 代码注释：导入脚本新分支说明修复范围、r3 字节守卫、事务、快照和缓存约束。
