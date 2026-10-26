<?php

return [
    // 历史迁入的门店承载标记属于数据兼容配置，不在核心业务中判断客户或域名。
    // 仅匹配明确的来源键与门店后缀；名称、价格、普通搜索关键词均不参与分类。
    'historical_store_keyword_regexp' => '^(RH_(PROJECT|GOODS|CARDCLASS|PROJECT_PLATFORM):[^:]+|RH_SWXT:(PROJECT|GOODS|CARD):[^:]+|RH_TIMECARD_PROJECT_V1):STORE:[1-9][0-9]*$',
];
