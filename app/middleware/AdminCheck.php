<?php
namespace app\middleware;

use app\common\Ret;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AdminCheck
{
    public function handle($request, \Closure $next)
    {
        // 尝试从多个来源获取 Token
        $token = $request->cookie('admin_token') 
            ?: $request->header('Authorization') 
            ?: $request->param('token'); // URL兼容

        if (!$token) {
            if ($request->isAjax()) {
                return Ret::error('Unauthorized', 401);
            }
            return redirect('/admin/login');
        }

        // 处理 Bearer
        if (strpos($token, 'Bearer ') === 0) {
            $token = substr($token, 7);
        }

        try {
            $key = env('jwt.secret', 'moontv_secret_key_default');
            $decoded = JWT::decode($token, new Key($key, 'HS256'));
            
            // 检查角色
            if (($decoded->role ?? '') !== 'admin') {
                if ($request->isAjax()) {
                    return Ret::error('Forbidden: Admin access required', 403);
                }
                return redirect('/admin/login');
            }

            $request->admin = (array) $decoded;
            
        } catch (\Exception $e) {
            if ($request->isAjax()) {
                return Ret::error('Invalid Admin Token', 401);
            }
            return redirect('/admin/login');
        }

        return $next($request);
    }
}