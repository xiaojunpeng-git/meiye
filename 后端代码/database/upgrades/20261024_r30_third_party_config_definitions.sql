-- R30-00001：修复历史实例缺少第三方配置定义导致表单无标题、保存时被跳过。
-- 定义来自 public/install/crmeb.sql；空密钥不借用其他客户的值，不更改任何已有行。
-- MySQL 5.7兼容；由发布器在独立实例事务内串行执行，先备份并记录新增ID。
-- 不含DDL/COMMIT；可在调用方事务中完整回滚。重跑不得覆盖客户后来保存的值。

INSERT INTO `eb_system_config` (`is_store`,`menu_name`,`type`,`input_type`,`config_tab_id`,`parameter`,`upload_type`,`required`,`width`,`high`,`value`,`info`,`desc`,`sort`,`status`)
SELECT 0,'tengxun_map_key','text','input',68,'',0,'',100,0,'""','腾讯地图KEY','腾讯地图KEY，申请地址：https://lbs.qq.com',0,1
WHERE NOT EXISTS (SELECT 1 FROM `eb_system_config` WHERE `menu_name`='tengxun_map_key' AND `is_store`=0);

INSERT INTO `eb_system_config` (`is_store`,`menu_name`,`type`,`input_type`,`config_tab_id`,`parameter`,`upload_type`,`required`,`width`,`high`,`value`,`info`,`desc`,`sort`,`status`)
SELECT 0,'system_express_app_code','text','input',64,'',0,'',100,0,'""','快递查询密钥','阿里云云市场快递查询接口密钥购买地址：https://market.aliyun.com/products/57126001/cmapi021863.html?userCode=dligum2z',0,1
WHERE NOT EXISTS (SELECT 1 FROM `eb_system_config` WHERE `menu_name`='system_express_app_code' AND `is_store`=0);

INSERT INTO `eb_system_config` (`is_store`,`menu_name`,`type`,`input_type`,`config_tab_id`,`parameter`,`upload_type`,`required`,`width`,`high`,`value`,`info`,`desc`,`sort`,`status`)
SELECT 0,'logistics_type','radio','',64,'1=>一号通\n2=>阿里云物流查询',0,'',0,0,'1','接口选择','建议使用一号通更方便不用配置密钥，阿里云云市场购买链接：https://0x9.me/w9vnq',0,1
WHERE NOT EXISTS (SELECT 1 FROM `eb_system_config` WHERE `menu_name`='logistics_type' AND `is_store`=0);

INSERT INTO `eb_system_config` (`is_store`,`menu_name`,`type`,`input_type`,`config_tab_id`,`parameter`,`upload_type`,`required`,`width`,`high`,`value`,`info`,`desc`,`sort`,`status`)
SELECT 0,'system_product_copy_type','radio','',41,'1=>一号通\n2=>99Api',0,'',0,0,'1','接口选择','采集商品接口选择，一号通快速注册开通使用不用配置apikey，或者去99api网址：https://www.99api.com/注册帐号，推荐一号通方便快捷',999,1
WHERE NOT EXISTS (SELECT 1 FROM `eb_system_config` WHERE `menu_name`='system_product_copy_type' AND `is_store`=0);
