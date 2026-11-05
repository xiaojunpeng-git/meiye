<?php
// 导入的历史图片可继续使用原媒体域名；这里只授权读取，不创建上传桶或复制客户密钥。
// 实例以逗号分隔的完整 origin 配置，例如 https://media.example.com。
return ['image_origins' => array_filter(array_map('trim', explode(',', (string)env('POSTER_IMAGE_ORIGINS', ''))))];
