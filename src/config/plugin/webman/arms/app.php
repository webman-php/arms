<?php
return [
    'enable' => true,
    'app_name' => '你的应用名称', // 应用名称
    'endpoint_url' => '接入点url', // 进入后台 https://tracing.console.aliyun.com/ 获取
    'time_interval' => 30, //30秒上报一次，尽量将上报对业务的影响减少到最低
    'enable_request_params' => true, //是否记录入参，json格式呈现
    'enable_response_body' => true, //是否记录响应内容，如果存在响应数据太大或二进制，不建议开启
];