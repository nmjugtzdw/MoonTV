<?php

namespace app\controller\api;

use app\BaseController;
use app\common\Ret;
use app\model\User;
use app\service\ConfigService;
use Firebase\JWT\JWT;
use think\facade\Request;
use think\facade\Cache;

class Auth extends BaseController
{
    /**
     * 简单的频率限制检查
     * @param string $action 动作标识
     * @param int $limit 次数限制
     * @param int $time 时间窗口(秒)
     * @return bool|string true=通过, string=错误信息
     */
    private function checkRateLimit($action, $limit = 10, $time = 60)
    {
        $ip = $this->request->ip();
        $key = 'rate_limit_' . $action . '_' . $ip;
        
        $count = Cache::get($key, 0);
        if ($count >= $limit) {
            return '操作过于频繁，请稍后再试';
        }
        
        Cache::inc($key);
        // 如果是第一次，设置过期时间
        if ($count === 0) {
            // thinkphp Cache::inc 不会自动设置 TTL，需要单独 set
            Cache::set($key, 1, $time);
        }
        
        return true;
    }

    /**
     * 用户注册
     */
    public function register()
    {
        // 频率限制：同一IP 1分钟内只能尝试注册3次
        $rateCheck = $this->checkRateLimit('register', 3, 60);
        if ($rateCheck !== true) {
            return Ret::error($rateCheck, 429);
        }

        try {
            // 获取配置
            $config = ConfigService::getConfig();
            
            // 检查注册功能是否开启（如果配置不存在，默认允许注册）
            $allowRegister = $config['UserConfig']['AllowRegister'] ?? true;
            if (!$allowRegister) {
                return Ret::error('注册功能已关闭', 403);
            }

            // 支持 JSON 和表单两种格式
            $contentType = $this->request->contentType() ?? '';
            $username = '';
            $password = '';
            
            // 优先尝试从 php://input 读取（最可靠的方式）
            $rawInput = file_get_contents('php://input');
            
            if (!empty($rawInput) && strpos($contentType, 'application/json') !== false) {
                // JSON 格式
                $data = json_decode($rawInput, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                    $username = $data['username'] ?? '';
                    $password = $data['password'] ?? '';
                }
            }
            
            // 如果 php://input 失败，尝试 getContent()
            if (empty($username) && empty($password)) {
                $rawContent = $this->request->getContent();
                if (!empty($rawContent) && strpos($contentType, 'application/json') !== false) {
                    $data = json_decode($rawContent, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                        $username = $data['username'] ?? '';
                        $password = $data['password'] ?? '';
                    }
                }
            }
            
            // 如果 JSON 解析失败，尝试表单格式
            if (empty($username) && empty($password)) {
                $username = input('post.username', '');
                $password = input('post.password', '');
            }
            
            // 调试日志（生产环境可以注释掉）
            if (empty($username) && empty($password)) {
                trace('注册接口无法获取数据 - ContentType: ' . $contentType . ', RawInput: ' . substr($rawInput, 0, 200), 'error');
            }

            if (empty($username) || empty($password)) {
                return Ret::error('用户名或密码不能为空', 400);
            }

            if (strlen($password) < 6) {
                return Ret::error('密码长度不能少于6位', 400);
            }

            // 检查用户名是否存在
            $exist = User::where('username', $username)->find();
            if ($exist) {
                return Ret::error('用户名已存在', 400);
            }

            // 创建用户
            $user = new User();
            $user->username = $username;
            $user->password = password_hash($password, PASSWORD_DEFAULT);
            $user->role = 'user';
            $user->save();
            
            // 注册赠送VIP (默认3天)
            $registerGiftDays = $config['PaymentConfig']['registerGiftDays'] ?? 3;
            if ($registerGiftDays > 0) {
                $user->is_vip = 1;
                $user->vip_expire_time = date('Y-m-d H:i:s', strtotime("+{$registerGiftDays} days"));
                $user->save();
            }

            return Ret::success([], '注册成功');
        } catch (\Exception $e) {
            // 记录详细错误日志
            $errorMsg = '注册失败: ' . $e->getMessage();
            $errorTrace = $e->getTraceAsString();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();
            
            trace($errorMsg . "\n文件: {$errorFile}\n行号: {$errorLine}\n堆栈:\n{$errorTrace}", 'error');
            
            // 同时记录到 PHP 错误日志
            error_log("注册接口错误: {$errorMsg} in {$errorFile}:{$errorLine}");
            error_log("堆栈跟踪: {$errorTrace}");
            
            return Ret::error('注册失败: ' . $e->getMessage(), 500);
        } catch (\Throwable $e) {
            // 捕获所有可抛出对象（包括 Error）
            $errorMsg = '注册失败: ' . $e->getMessage();
            $errorTrace = $e->getTraceAsString();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();
            
            trace($errorMsg . "\n文件: {$errorFile}\n行号: {$errorLine}\n堆栈:\n{$errorTrace}", 'error');
            error_log("注册接口严重错误: {$errorMsg} in {$errorFile}:{$errorLine}");
            error_log("堆栈跟踪: {$errorTrace}");
            
            return Ret::error('注册失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 用户登录
     */
    public function login()
    {
        // 频率限制：同一IP 1分钟内只能尝试登录10次
        $rateCheck = $this->checkRateLimit('login', 10, 60);
        if ($rateCheck !== true) {
            return Ret::error($rateCheck, 429);
        }

        // 支持 JSON 和表单两种格式
        $contentType = $this->request->contentType() ?? '';
        $username = '';
        $password = '';
        
        // 优先尝试从 php://input 读取（最可靠的方式）
        $rawInput = file_get_contents('php://input');
        
        if (!empty($rawInput) && strpos($contentType, 'application/json') !== false) {
            // JSON 格式
            $data = json_decode($rawInput, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                $username = $data['username'] ?? '';
                $password = $data['password'] ?? '';
            }
        }
        
        // 如果 php://input 失败，尝试 getContent()
        if (empty($username) && empty($password)) {
            $rawContent = $this->request->getContent();
            if (!empty($rawContent) && strpos($contentType, 'application/json') !== false) {
                $data = json_decode($rawContent, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                    $username = $data['username'] ?? '';
                    $password = $data['password'] ?? '';
                }
            }
        }
        
        // 如果 JSON 解析失败，尝试表单格式
        if (empty($username) && empty($password)) {
            $username = input('post.username', '');
            $password = input('post.password', '');
        }

        if (empty($username) || empty($password)) {
            return Ret::error('用户名或密码不能为空', 400);
        }

        $user = User::where('username', $username)->find();
        
        // 验证密码
        // 兼容旧数据（如果是明文）和新数据（hash）
        // 假设旧系统可能是明文存储，这里做一个简单的兼容判断
        $passwordValid = false;
        if ($user) {
            if (password_verify($password, $user->password)) {
                $passwordValid = true;
            } elseif ($user->password === $password) {
                // 明文匹配，为了安全，更新为 hash
                $user->password = password_hash($password, PASSWORD_DEFAULT);
                $user->save();
                $passwordValid = true;
            }
        }

        if (!$user || !$passwordValid) {
            return Ret::error('用户名或密码错误', 401);
        }

        if (isset($user['banned']) && $user['banned']) {
            return Ret::error('账号已被封禁', 403);
        }

        // 生成 JWT
        $payload = [
            'uid' => $user->id,
            'username' => $user->username,
            'role' => $user->role,
            'iat' => time(),
            'exp' => time() + (7 * 24 * 3600) // 7天过期
        ];

        $key = env('jwt.secret', 'moontv_secret_key_default');
        $token = JWT::encode($payload, $key, 'HS256');

        // 同时设置Cookie，确保跨页面认证
        cookie('auth_token', $token, [
            'expire' => 7 * 24 * 3600, // 7天
            'path' => '/',
            'httponly' => false, // 允许JavaScript访问
            'samesite' => 'Lax' // 防止CSRF攻击
        ]);

        return Ret::success([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'role' => $user->role,
                'is_vip' => $user->isVip(),
                'vip_expire_time' => $user->vip_expire_time,
                'created_at' => $user->created_at
            ]
        ], '登录成功');
    }

    /**
     * 获取用户信息
     */
    public function info()
    {
        $uid = $this->request->uid; // 由中间件注入
        $user = User::find($uid);
        
        if (!$user) {
            return Ret::error('用户不存在', 404);
        }

        return Ret::success([
            'id' => $user->id,
            'username' => $user->username,
            'role' => $user->role,
            'is_vip' => $user->isVip(),
            'vip_expire_time' => $user->vip_expire_time,
            'created_at' => $user->created_at
        ]);
    }

    /**
     * 修改密码
     */
    public function updatePassword()
    {
        $uid = $this->request->uid;
        $oldPassword = input('post.old_password');
        $newPassword = input('post.new_password');

        if (empty($oldPassword) || empty($newPassword)) {
            return Ret::error('参数不完整', 400);
        }

        if (strlen($newPassword) < 6) {
            return Ret::error('新密码长度不能少于6位', 400);
        }

        $user = User::find($uid);
        if (!$user) {
            return Ret::error('用户不存在', 404);
        }

        // 验证旧密码
        if (!password_verify($oldPassword, $user->password) && $user->password !== $oldPassword) {
            return Ret::error('旧密码错误', 400);
        }

        $user->password = password_hash($newPassword, PASSWORD_DEFAULT);
        $user->save();

        return Ret::success([], '密码修改成功');
    }
}