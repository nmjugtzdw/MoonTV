<?php

namespace app\middleware;

use think\Response;

/**
 * CORS 跨域中间件
 * 用于支持 Next.js 前端跨域请求
 */
class Cors
{
    /**
     * 处理请求
     *
     * @param \think\Request $request
     * @param \Closure       $next
     * @return Response
     */
    public function handle($request, \Closure $next)
    {
        // 处理 OPTIONS 预检请求
        if ($request->isOptions()) {
            $response = Response::create('', 'html', 200);
        } else {
            $response = $next($request);
        }

        // 设置 CORS 响应头
        $origin = $request->header('Origin', '*');
        
        // 允许的源（生产环境建议配置具体域名）
        $allowedOrigins = [
            'http://localhost:3000',
            'http://127.0.0.1:3000',
            'http://moontv.zzzmxxkj.com',
            'https://moontv.zzzmxxkj.com',
            // 添加你的前端域名
        ];

        // 如果 Origin 在允许列表中，使用它；否则使用 *
        $allowOrigin = '*';
        if (in_array($origin, $allowedOrigins) || empty($origin)) {
            $allowOrigin = $origin ?: '*';
        }

        // ThinkPHP 6 使用 header 方法设置响应头（数组格式）
        $response->header([
            'Access-Control-Allow-Origin' => $allowOrigin,
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS, PATCH',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, Accept, Origin',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Max-Age' => '86400'
        ]);

        return $response;
    }
}

