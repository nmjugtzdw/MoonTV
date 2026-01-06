<?php

namespace app\middleware;

use app\common\Ret;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use think\Response;

class AuthCheck
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
        // 尝试从多个来源获取 Token，增强兼容性
        $token = $request->cookie('auth_token')
            ?: $request->header('Authorization')
            ?: $request->server('HTTP_AUTHORIZATION')
            ?: $request->server('REDIRECT_HTTP_AUTHORIZATION'); // 某些 FastCGI 配置
        
        // 尝试 getallheaders 回退方案
        if (!$token && function_exists('getallheaders')) {
            $headers = getallheaders();
            if (isset($headers['Authorization'])) {
                $token = $headers['Authorization'];
            }
        }

        if (!$token) {
            // 最后尝试 URL 参数，作为最后的救命稻草
            $token = $request->param('token');
        }
        
        if (!$token) {
            return Ret::error('Unauthorized: No token provided', 401);
        }

        // 处理 Bearer token
        if (strpos($token, 'Bearer ') === 0) {
            $token = substr($token, 7);
        }

        try {
            $key = env('jwt.secret', 'moontv_secret_key_default'); // 生产环境请修改密钥
            $decoded = JWT::decode($token, new Key($key, 'HS256'));
            
            // 将用户信息注入 request
            $request->user = (array) $decoded;
            $request->uid = $decoded->uid ?? 0;
            $request->username = $decoded->username ?? '';
            $request->role = $decoded->role ?? 'user';

        } catch (\Exception $e) {
            return Ret::error('Invalid Token: ' . $e->getMessage(), 401);
        }

        return $next($request);
    }
}