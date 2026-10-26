-- 010运行账号须能核验余额版本触发器，MySQL仅向具有TRIGGER权限的账号展示该表触发器元数据。
-- 仅授权010的eb_user表；不改变触发器定义或会员金额，不赋予其他表/数据库的TRIGGER权限。
GRANT TRIGGER ON `mohe_010_app`.`eb_user` TO 'mohe_010_app'@'127.0.0.1';
-- 回滚：REVOKE TRIGGER ON `mohe_010_app`.`eb_user` FROM 'mohe_010_app'@'127.0.0.1';
