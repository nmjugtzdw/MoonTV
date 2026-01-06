<?php
namespace app\controller\api;

use app\BaseController;
use app\model\Danmaku as DanmakuModel;
use app\model\SystemConfig;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Config;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Danmaku extends BaseController
{
    /**
     * 获取弹幕列表 - 全站共享模式
     */
    public function index()
    {
        $title = input('get.title', '');
        $nid = input('get.nid', 0);
        
        // 如果没有标题，返回空
        if (empty($title)) {
            return json(['code' => 0, 'data' => []]);
        }
        
        // 清理标题，去除特殊字符和多余空格
        $title = trim($title);
        
        // 按照 视频标题 + 集数 查询（全站共享）
        $list = DanmakuModel::where([
            'video_name' => $title,
            'nid' => (int)$nid,
            'status' => 1
        ])->order('time', 'asc')->select();
        
        $formatted = [];
        foreach ($list as $item) {
            // 获取用户信息以确定角色和VIP状态
            $userRole = 'guest';
            $isVip = 0;
            $username = '';
            
            if ($item['user_id'] && $item['user_id'] > 0) {
                $user = \app\model\User::find($item['user_id']);
                if ($user) {
                    $userRole = $user->role ?: 'user';
                    // 使用模型的方法判断VIP是否有效（检查过期时间）
                    $isVip = $user->isVip() ? 1 : 0;
                    $username = $user->username;
                }
            }
            
            $formatted[] = [
                (float)$item['time'],
                ($item['type'] == 'top' ? 1 : ($item['type'] == 'bottom' ? 2 : 0)),
                $item['color'] ?: '#ffffff',
                (string)($item['user_id'] ?: 'guest'),
                $item['text'],
                $userRole,      // 索引5: 用户角色
                $isVip,         // 索引6: 是否VIP
                $username       // 索引7: 用户名
            ];
        }
        
        return json([
            'code' => 0,
            'data' => $formatted
        ]);
    }
    
    /**
     * 发送弹幕 - 全站共享模式
     */
    public function send()
    {
        $userId = 0;
        
        // 支持 POST 和 JSON
        $params = input('post.');
        if (empty($params)) {
            $rawInput = file_get_contents('php://input');
            $params = json_decode($rawInput, true) ?: [];
        }
        
        // 尝试获取当前登录用户
        try {
            // 优先从 JSON 参数中获取 (最可靠)，其次 Header，最后 Cookie
            $token = ($params['token'] ?? '')
                ?: request()->header('Authorization')
                ?: request()->cookie('auth_token')
                ?: input('token');
                
            if ($token) {
                 if (strpos($token, 'Bearer ') === 0) {
                    $token = substr($token, 7);
                }
                $key = env('jwt.secret', 'moontv_secret_key_default');
                $decoded = JWT::decode($token, new Key($key, 'HS256'));
                $userId = $decoded->uid ?? 0;
            }
        } catch (\Exception $e) {
            // token 无效或过期，视为游客，不报错，继续执行
        }

        $ip = request()->ip();

        $title = trim($params['title'] ?? '');
        $text = trim($params['text'] ?? '');
        $color = $params['color'] ?? '#ffffff';
        $time = (float)($params['time'] ?? 0);
        $type = (int)($params['type'] ?? 0);
        $nid = (int)($params['nid'] ?? 0);

        // 验证必填参数
        if (empty($title)) {
            return json(['code' => 1, 'msg' => '缺少视频标题']);
        }
        
        if (empty($text)) {
            return json(['code' => 1, 'msg' => '弹幕内容不能为空']);
        }
        
        if (mb_strlen($text) > 100) {
            return json(['code' => 1, 'msg' => '弹幕内容过长（最多100字）']);
        }
        
        // 敏感词过滤
        if ($this->checkSensitive($text)) {
            return json(['code' => 1, 'msg' => '弹幕包含敏感词，请修改后重试']);
        }
        
        // HTML 转义防止 XSS
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        
        // 弹幕类型映射
        $typeMap = [0 => 'right', 1 => 'top', 2 => 'bottom'];
        $dbType = $typeMap[$type] ?? 'right';
        
        try {
            DanmakuModel::create([
                'source' => 'shared', // 标记为共享弹幕
                'source_id' => '0',
                'video_name' => $title,
                'sid' => 0,
                'nid' => $nid,
                'time' => $time,
                'text' => $text,
                'color' => $color,
                'type' => $dbType,
                'user_id' => $userId,
                'ip' => $ip,
                'status' => 1
            ]);
            
            return json(['code' => 0, 'msg' => '发送成功', 'data' => []]);
            
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '发送失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 检查敏感词 (私有方法)
     */
    protected function checkSensitive($text)
    {
        $keywords = [
            '傻逼', 'sb', 'SB', '弱智', '脑残', 'CNM', 'cnm', 'cao', '操你', '日你', 
            '尼玛', '死妈', '垃圾', '废物', 'fuck', 'shit', 'nigger', 'bitch', '色情', 
            '赌博', '兼职', '加微', 'V信', 'QQ', '群'
        ];
        
        foreach ($keywords as $word) {
            if (mb_stripos($text, $word) !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 更新数据库结构 (一次性工具)
     */
    public function update_schema()
    {
        try {
            $prefix = Config::get('database.connections.mysql.prefix', '');
            $hasColumn = Db::query("SHOW COLUMNS FROM `{$prefix}danmaku` LIKE 'video_name'");
            
            if (empty($hasColumn)) {
                Db::execute("ALTER TABLE `{$prefix}danmaku` ADD COLUMN `video_name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '视频标题' AFTER `source_id`");
                Db::execute("ALTER TABLE `{$prefix}danmaku` ADD INDEX `idx_video_name_nid` (`video_name`, `nid`)");
                return json(['code' => 0, 'msg' => '成功添加 video_name 字段']);
            } else {
                return json(['code' => 0, 'msg' => 'video_name 字段已存在']);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '更新失败: ' . $e->getMessage()]);
        }
    }
}