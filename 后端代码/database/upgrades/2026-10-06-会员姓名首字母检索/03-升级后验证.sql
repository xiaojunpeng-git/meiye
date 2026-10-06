-- 两项数量均须为 0；索引表只存检索投影，不改变会员主表。
SET NAMES utf8mb4;
SELECT (SELECT COUNT(*) FROM eb_user) AS member_count,
       (SELECT COUNT(*) FROM eb_member_name_initial_search) AS indexed_count;
SELECT COUNT(*) AS missing_or_stale_count FROM eb_user u
LEFT JOIN eb_member_name_initial_search s ON s.uid=u.uid
WHERE s.uid IS NULL
   OR NOT (s.source_name <=> COALESCE(NULLIF(TRIM(u.real_name),''),TRIM(u.nickname)))
   OR NOT (s.initials <=> eb_member_name_initials(COALESCE(NULLIF(TRIM(u.real_name),''),TRIM(u.nickname))));
SELECT eb_member_name_initials('张晓燕') AS zhang,
       eb_member_name_initials('单小梅') AS shan,
       eb_member_name_initials('解芳') AS xie,
       eb_member_name_initials('李倩') AS liqian,
       eb_member_name_initials('李婷') AS liting,
       eb_member_name_initials('赵璐') AS zhaolu;
