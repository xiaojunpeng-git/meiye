<?php
// 仅由实例配置指定会员H5入口，空值在业务层沿用站点地址，不在配置加载阶段访问数据库。
return ['member_h5_base_url'=>env('MEMBER_SURVEY_MEMBER_H5_URL','')];
