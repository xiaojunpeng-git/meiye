<?php
/** 职位身份模型独立于手工费算法；实例配置只选择模型，不包含客户或商品编号。 */
return ['position_model' => env('payroll.position_model', 'legacy')];
