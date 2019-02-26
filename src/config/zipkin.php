<?php

return [
    'service_name' => env('ZIPKIN_SERVICE_NAME', 'laravel-zipkin'),
    'endpoint_url' => env('ZIPKIN_ENDPOINT_URL', 'http://localhost:9411/api/v2/spans'),
    'sample_rate' => doubleval(env('ZIPKIN_SAMPLE_RATE', 0)),
    'body_size' => intval(env('ZIPKIN_BODY_SIZE', 5000)), //记录http body长度，单位字节
    'curl_timeout' => intval(env('ZIPKIN_CURL_TIMEOUT', 1)), //超时时间，单位秒
];
