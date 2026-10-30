<?php
// 各实例明确选择二维码入口；未开通会员小程序的既有站点沿用会员 H5。
return ['qr_mode'=>env('MEMBER_SURVEY_QR_MODE','h5'),
    'member_h5_base_url'=>env('MEMBER_SURVEY_MEMBER_H5_URL',''),
    'mini_env_version'=>env('MEMBER_SURVEY_MINI_ENV_VERSION','release')];
