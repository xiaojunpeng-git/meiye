-- 姓名首字母只是可重建的检索投影；会员姓名仍以 eb_user 为准。
-- 每个客户实例先执行 01-汉字首字母映射.sql；再建同步触发器并回填。
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS eb_member_name_initial_search (
  uid INT UNSIGNED NOT NULL PRIMARY KEY,
  source_name VARCHAR(60) NOT NULL DEFAULT '',
  initials VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  KEY idx_member_name_initials (initials, uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELIMITER $$
DROP FUNCTION IF EXISTS eb_member_name_initials$$
CREATE FUNCTION eb_member_name_initials(p_name VARCHAR(60) CHARACTER SET utf8mb4) RETURNS VARCHAR(60)
  CHARACTER SET ascii DETERMINISTIC READS SQL DATA
BEGIN
  DECLARE v_pos INT DEFAULT 1;
  DECLARE v_length INT DEFAULT CHAR_LENGTH(COALESCE(p_name, ''));
  DECLARE v_char VARCHAR(4) CHARACTER SET utf8mb4;
  DECLARE v_initial VARCHAR(1);
  DECLARE v_result VARCHAR(60) DEFAULT '';
  WHILE v_pos <= v_length DO
    SET v_char = SUBSTRING(p_name, v_pos, 1);
    -- 单/解/查/区/仇作姓氏时，GBK 通用读音与人名习惯不同。
    IF v_pos = 1 AND v_char = '单' THEN SET v_initial = 'S';
    ELSEIF v_pos = 1 AND v_char = '解' THEN SET v_initial = 'X';
    ELSEIF v_pos = 1 AND v_char = '查' THEN SET v_initial = 'Z';
    ELSEIF v_pos = 1 AND v_char = '区' THEN SET v_initial = 'O';
    ELSEIF v_pos = 1 AND v_char = '仇' THEN SET v_initial = 'Q';
    ELSEIF v_char REGEXP '^[A-Za-z]$' THEN SET v_initial = UPPER(v_char);
    ELSEIF v_char IN (' ', '　', '·', '・') THEN SET v_initial = '';
    ELSE
      -- 静态汉字表覆盖常用及扩展 A 区；未知字符占位，不能误当作已匹配的字母。
      SET v_initial = COALESCE((SELECT initial FROM eb_member_pinyin_char_initial
        WHERE hanzi=v_char LIMIT 1), '?');
    END IF;
    SET v_result = CONCAT(v_result, v_initial);
    SET v_pos = v_pos + 1;
  END WHILE;
  RETURN v_result;
END$$

DROP TRIGGER IF EXISTS eb_member_name_initial_ai$$
CREATE TRIGGER eb_member_name_initial_ai AFTER INSERT ON eb_user FOR EACH ROW
BEGIN
  INSERT INTO eb_member_name_initial_search(uid,source_name,initials)
  VALUES(NEW.uid,COALESCE(NULLIF(TRIM(NEW.real_name),''),TRIM(NEW.nickname)),
    eb_member_name_initials(COALESCE(NULLIF(TRIM(NEW.real_name),''),TRIM(NEW.nickname))))
  ON DUPLICATE KEY UPDATE source_name=VALUES(source_name),initials=VALUES(initials);
END$$

DROP TRIGGER IF EXISTS eb_member_name_initial_au$$
CREATE TRIGGER eb_member_name_initial_au AFTER UPDATE ON eb_user FOR EACH ROW
BEGIN
  -- 所有建档/导入/改名入口最终写主表，在同一事务维护索引；非姓名更新不增加写负担。
  IF NOT (OLD.real_name <=> NEW.real_name) OR NOT (OLD.nickname <=> NEW.nickname) THEN
    INSERT INTO eb_member_name_initial_search(uid,source_name,initials)
    VALUES(NEW.uid,COALESCE(NULLIF(TRIM(NEW.real_name),''),TRIM(NEW.nickname)),
      eb_member_name_initials(COALESCE(NULLIF(TRIM(NEW.real_name),''),TRIM(NEW.nickname))))
    ON DUPLICATE KEY UPDATE source_name=VALUES(source_name),initials=VALUES(initials);
  END IF;
END$$

DROP TRIGGER IF EXISTS eb_member_name_initial_ad$$
CREATE TRIGGER eb_member_name_initial_ad AFTER DELETE ON eb_user FOR EACH ROW
BEGIN
  DELETE FROM eb_member_name_initial_search WHERE uid=OLD.uid;
END$$
DELIMITER ;

-- INSERT IGNORE 不覆盖触发器已写入的新值；第二步修复部分重跑留下的旧投影。
INSERT IGNORE INTO eb_member_name_initial_search(uid,source_name,initials)
SELECT uid,COALESCE(NULLIF(TRIM(real_name),''),TRIM(nickname)),
  eb_member_name_initials(COALESCE(NULLIF(TRIM(real_name),''),TRIM(nickname))) FROM eb_user;
UPDATE eb_member_name_initial_search s JOIN eb_user u ON u.uid=s.uid
SET s.source_name=COALESCE(NULLIF(TRIM(u.real_name),''),TRIM(u.nickname)),
  s.initials=eb_member_name_initials(COALESCE(NULLIF(TRIM(u.real_name),''),TRIM(u.nickname)))
WHERE NOT (s.source_name <=> COALESCE(NULLIF(TRIM(u.real_name),''),TRIM(u.nickname)))
   OR NOT (s.initials <=> eb_member_name_initials(COALESCE(NULLIF(TRIM(u.real_name),''),TRIM(u.nickname))));
