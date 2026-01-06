<?php

// [ 应用入口文件 ]
namespace think;

// 捕获所有错误和异常
error_reporting(E_ALL);
ini_set('display_errors', '0'); // 生产环境不显示错误，记录到日志
ini_set('log_errors', '1');

// 设置异常处理
set_exception_handler(function($exception) {
    // 记录到日志
    error_log('Uncaught Exception: ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());
    error_log('Stack trace: ' . $exception->getTraceAsString());
    
    // 返回 JSON 错误响应
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'code' => 500,
            'msg' => '服务器内部错误',
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
});

// 设置错误处理
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // 只处理严重错误
    if (!(error_reporting() & $errno)) {
        return false;
    }
    
    // 记录到日志
    error_log("PHP Error [{$errno}]: {$errstr} in {$errfile}:{$errline}");
    
    // 如果是致命错误，返回 JSON 响应
    if ($errno === E_ERROR || $errno === E_PARSE || $errno === E_CORE_ERROR || $errno === E_COMPILE_ERROR) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            echo json_encode([
                'code' => 500,
                'msg' => '服务器内部错误: ' . $errstr,
                'data' => []
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
    
    return false; // 继续执行 PHP 的错误处理
});

require __DIR__ . '/../vendor/autoload.php';

try {
    // 执行HTTP应用并响应
    $http = (new App())->http;
    
    $response = $http->run();
    
    $response->send();
    
    $http->end($response);
} catch (\Throwable $e) {
    // 捕获所有未处理的异常
    error_log('ThinkPHP Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'code' => 500,
            'msg' => '服务器内部错误: ' . $e->getMessage(),
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}