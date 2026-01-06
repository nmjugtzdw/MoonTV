<?php
namespace app\controller\api;

use app\BaseController;
use app\common\Ret;
use app\model\User;
use app\model\VipPackage;
use app\model\SystemConfig;
use app\model\RedemptionCode;
use app\model\PlayRecord;
use app\model\Order; // Assuming you have Order model
use app\model\Danmaku;
use Firebase\JWT\JWT;
use think\facade\Db;

class Admin extends BaseController
{
    /**
     * 管理员修改密码
     */
    public function updatePassword()
    {
        $oldPassword = input('post.old_password');
        $newPassword = input('post.new_password');
        $uid = $this->request->uid;

        if (empty($oldPassword) || empty($newPassword)) {
            return Ret::error('参数不完整', 400);
        }

        if (strlen($newPassword) < 6) {
            return Ret::error('新密码长度不能少于6位', 400);
        }

        // 目前暂时只支持数据库账号修改，硬编码账号 'admin' 无法通过此接口修改（或者另行处理）
        $user = User::find($uid);
        if (!$user) {
             // 如果是虚拟账号ID=1，则无法修改，提示去配置文件或数据库改
             if ($uid == 1) {
                 return Ret::error('超级管理员请直接修改数据库', 403);
             }
             return Ret::error('用户不存在', 404);
        }

        // 验证旧密码
        if (!password_verify($oldPassword, $user->password)) {
            return Ret::error('旧密码错误', 400);
        }

        $user->password = password_hash($newPassword, PASSWORD_DEFAULT);
        $user->save();

        return Ret::success([], '密码修改成功');
    }

    public function login()
    {
        $username = input('post.username');
        $password = input('post.password');

        if (empty($username) || empty($password)) {
            // Support JSON
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);
            $username = $data['username'] ?? '';
            $password = $data['password'] ?? '';
        }

        // 移除硬编码默认账户，强制使用数据库验证
        $user = User::where('username', $username)->where('role', 'admin')->find();
        if (!$user || !password_verify($password, $user->password)) {
            return Ret::error('用户名或密码错误', 401);
        }
        $uid = $user->id;

        $payload = [
            'uid' => $uid,
            'username' => $username,
            'role' => 'admin',
            'iat' => time(),
            'exp' => time() + (24 * 3600)
        ];
        
        $key = env('jwt.secret', 'moontv_secret_key_default');
        $token = JWT::encode($payload, $key, 'HS256');

        return Ret::success(['token' => $token], '登录成功');
    }

    public function dashboard()
    {
        $today = date('Y-m-d');
        
        // 统计数据
        $totalUsers = User::count();
        $todayUsers = User::where('created_at', '>', $today)->count(); // Use created_at
        
        // 今日签到 (需要 last_checkin_at 字段)
        // 兼容处理：如果字段不存在，返回0
        try {
            $todayCheckins = User::where('last_checkin_at', '>', $today)->count();
        } catch (\Exception $e) {
            $todayCheckins = 0;
        }
        
        $vipUsers = User::where('is_vip', 1)->count();

        // 最新用户
        $latestUsers = User::order('id', 'desc')->limit(5)->select();

        return Ret::success([
            'total_users' => $totalUsers,
            'today_users' => $todayUsers,
            'today_checkins' => $todayCheckins,
            'vip_users' => $vipUsers,
            'latest_users' => $latestUsers
        ]);
    }

    public function users()
    {
        $page = input('get.page', 1);
        $pageSize = input('get.limit', 10);
        $keyword = input('get.keyword', '');

        $query = User::order('id', 'desc');

        if (!empty($keyword)) {
            $query->where('username|id', 'like', '%' . $keyword . '%');
        }

        $list = $query->paginate([
            'list_rows' => $pageSize,
            'page' => $page
        ]);

        return Ret::success([
            'total' => $list->total(),
            'current_page' => $list->currentPage(),
            'last_page' => $list->lastPage(),
            'data' => $list->items()
        ]);
    }

    /**
     * 更新用户信息（封禁/解封）
     */
    public function updateUserStats()
    {
        $id = input('post.id');
        $action = input('post.action'); // ban, unban, delete

        if (!$id || !$action) return Ret::error('参数缺失', 400);

        $user = User::find($id);
        if (!$user) return Ret::error('用户不存在', 404);

        if ($action === 'ban') {
            $user->banned = 1;
        } elseif ($action === 'unban') {
            $user->banned = 0;
        } elseif ($action === 'delete') {
            $user->delete();
            return Ret::success([], '删除成功');
        }

        $user->save();
        return Ret::success([], '操作成功');
    }

    /**
     * 获取VIP套餐列表
     */
    public function vipPackages()
    {
        $list = VipPackage::order('price', 'asc')->select();
        return Ret::success($list);
    }

    /**
     * 保存VIP套餐 (新增/编辑)
     */
    public function saveVipPackage()
    {
        $id = input('post.id');
        $name = input('post.name');
        $days = input('post.days');
        $price = input('post.price');
        $original_price = input('post.original_price');

        if (empty($name) || empty($days) || empty($price)) {
            return Ret::error('参数不完整', 400);
        }

        if ($id) {
            $package = VipPackage::find($id);
            if (!$package) return Ret::error('套餐不存在', 404);
        } else {
            $package = new VipPackage();
        }

        $package->name = $name;
        $package->days = $days;
        $package->price = $price;
        $package->original_price = $original_price;
        $package->save();

        return Ret::success([], '保存成功');
    }

    /**
     * 删除VIP套餐
     */
    public function deleteVipPackage()
    {
        $id = input('post.id');
        if (!$id) return Ret::error('ID缺失', 400);

        VipPackage::destroy($id);
        return Ret::success([], '删除成功');
    }

    /**
     * 获取指定配置项
     */
    public function getConfig()
    {
        $name = input('get.name');
        if (!$name) return Ret::error('参数缺失', 400);
        
        $config = SystemConfig::get($name);
        return Ret::success($config);
    }

    /**
     * 保存指定配置项
     */
    public function setConfig()
    {
        // 优先从 php://input 获取 JSON
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        
        if (empty($data)) {
            $data = input('post.');
        }

        $name = $data['name'] ?? null;
        $value = $data['value'] ?? null;
        $group = $data['group'] ?? 'system';

        if (!$name || $value === null) {
            return Ret::error('参数缺失', 400);
        }

        SystemConfig::setValue($name, $value, $group);
        
        return Ret::success([], '配置已保存');
    }

    /**
     * 获取系统配置 (兼容旧接口)
     */
    public function systemConfig()
    {
        $payment = SystemConfig::get('payment');
        $sources = SystemConfig::get('sources') ?: [];
        $danmakuApi = SystemConfig::get('danmaku_api') ?: '';
        $siteConfig = SystemConfig::get('SiteConfig') ?: [];
        return Ret::success([
            'payment' => $payment,
            'sources' => $sources,
            'danmaku_api' => $danmakuApi,
            'siteConfig' => $siteConfig
        ]);
    }

    /**
     * 保存系统配置 (兼容旧接口)
     */
    public function saveSystemConfig()
    {
        $data = input('post.');
        
        // 支付配置
        if (isset($data['payment'])) {
            SystemConfig::setValue('payment', $data['payment'], 'payment');
        }

        // 播放源配置
        if (isset($data['sources'])) {
            // 验证 JSON 格式
            if (is_string($data['sources'])) {
                $decoded = json_decode($data['sources'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return Ret::error('播放源配置无效 JSON 格式', 400);
                }
                SystemConfig::setValue('sources', $decoded, 'system');
            } else {
                SystemConfig::setValue('sources', $data['sources'], 'system');
            }
        }

        // 弹幕API配置
        if (isset($data['danmaku_api'])) {
            SystemConfig::setValue('danmaku_api', $data['danmaku_api'], 'system');
        }
        
        return Ret::success([], '配置已保存');
    }

    /**
     * 获取卡密列表
     */
    public function cards()
    {
        $page = input('get.page', 1);
        $status = input('get.status');
        $keyword = input('get.keyword');

        $query = RedemptionCode::order('id', 'desc');

        if (!empty($status)) {
            $query->where('status', $status);
        }

        if (!empty($keyword)) {
            $query->where('code', 'like', '%' . $keyword . '%');
        }

        $list = $query->paginate([
            'list_rows' => 15,
            'page' => $page
        ]);

        return Ret::success([
            'total' => $list->total(),
            'current_page' => $list->currentPage(),
            'last_page' => $list->lastPage(),
            'data' => $list->items()
        ]);
    }

    /**
     * 生成卡密
     */
    public function generateCards()
    {
        $count = input('post.count', 10);
        $days = input('post.days', 30);
        
        if ($count > 100) return Ret::error('单次最多生成100张', 400);
        
        $data = [];
        $now = date('Y-m-d H:i:s');
        
        for ($i = 0; $i < $count; $i++) {
            $data[] = [
                'code' => $this->generateRandomCode(),
                'value' => $days,
                'status' => 'unused',
                'created_at' => $now
            ];
        }
        
        $codeModel = new RedemptionCode();
        $codeModel->saveAll($data);
        
        return Ret::success([], "成功生成 {$count} 张卡密");
    }

    /**
     * 删除卡密
     */
    public function deleteCard()
    {
        $id = input('post.id');
        if (!$id) return Ret::error('ID缺失', 400);
        
        RedemptionCode::destroy($id);
        return Ret::success([], '删除成功');
    }

    /**
     * 导出未使用卡密
     */
    public function exportCards()
    {
        $list = RedemptionCode::where('status', 'unused')->select();
        
        $str = "卡密,天数\n";
        foreach ($list as $item) {
            $str .= "{$item->code},{$item->value}\n";
        }
        
        $filename = 'unused_cards_' . date('YmdHis') . '.csv';
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        echo "\xEF\xBB\xBF"; // BOM
        echo $str;
        exit;
    }

    private function generateRandomCode($length = 16)
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $str = '';
        for ($i = 0; $i < $length; $i++) {
            $str .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $str;
    }

    /**
     * 获取订单列表
     */
    public function orders()
    {
        $page = input('get.page', 1);
        $status = input('get.status'); // 0: unpay, 1: paid
        $keyword = input('get.keyword');

        // Order by ID since create_time column might be ambiguous or different
        $query = Order::order('id', 'desc');

        if ($status !== '' && $status !== null) {
            $query->where('status', $status);
        }

        if (!empty($keyword)) {
            $query->where('order_no|username', 'like', '%' . $keyword . '%');
        }

        $list = $query->paginate([
            'list_rows' => 15,
            'page' => $page
        ]);

        return Ret::success([
            'total' => $list->total(),
            'current_page' => $list->currentPage(),
            'last_page' => $list->lastPage(),
            'data' => $list->items()
        ]);
    }

    /**
     * 获取弹幕列表（管理后台）
     */
    public function danmakuList()
    {
        $page = input('get.page', 1);
        $pageSize = input('get.limit', 20);
        $keyword = input('get.keyword', '');
        $videoName = input('get.video_name', '');
        $status = input('get.status');

        $query = Danmaku::order('id', 'desc');

        // 搜索视频名称
        if (!empty($videoName)) {
            $query->where('video_name', 'like', '%' . $videoName . '%');
        }

        // 搜索弹幕内容或IP
        if (!empty($keyword)) {
            $query->where('text|ip', 'like', '%' . $keyword . '%');
        }

        // 状态筛选
        if ($status !== '' && $status !== null) {
            $query->where('status', $status);
        }

        $list = $query->paginate([
            'list_rows' => $pageSize,
            'page' => $page
        ]);

        // 关联用户信息
        $items = [];
        foreach ($list->items() as $danmaku) {
            $item = $danmaku->toArray();
            
            // 获取用户信息
            if ($danmaku->user_id && $danmaku->user_id > 0) {
                $user = User::find($danmaku->user_id);
                if ($user) {
                    $item['username'] = $user->username;
                    $item['is_vip'] = $user->is_vip;
                    $item['user_role'] = $user->role;
                } else {
                    $item['username'] = '已删除用户';
                    $item['is_vip'] = 0;
                    $item['user_role'] = 'user';
                }
            } else {
                $item['username'] = '游客';
                $item['is_vip'] = 0;
                $item['user_role'] = 'guest';
            }
            
            $items[] = $item;
        }

        return Ret::success([
            'total' => $list->total(),
            'current_page' => $list->currentPage(),
            'last_page' => $list->lastPage(),
            'data' => $items
        ]);
    }

    /**
     * 删除弹幕
     */
    public function deleteDanmaku()
    {
        $id = input('post.id');
        if (!$id) return Ret::error('ID缺失', 400);

        $danmaku = Danmaku::find($id);
        if (!$danmaku) return Ret::error('弹幕不存在', 404);

        $danmaku->delete();
        return Ret::success([], '删除成功');
    }

    /**
     * 批量删除弹幕
     */
    public function batchDeleteDanmaku()
    {
        $ids = input('post.ids/a', []);
        if (empty($ids)) return Ret::error('请选择要删除的弹幕', 400);

        Danmaku::whereIn('id', $ids)->delete();
        return Ret::success([], '批量删除成功');
    }

    /**
     * 屏蔽/恢复弹幕
     */
    public function toggleDanmakuStatus()
    {
        $id = input('post.id');
        if (!$id) return Ret::error('ID缺失', 400);

        $danmaku = Danmaku::find($id);
        if (!$danmaku) return Ret::error('弹幕不存在', 404);

        // 切换状态：1=正常，0=屏蔽
        $danmaku->status = $danmaku->status == 1 ? 0 : 1;
        $danmaku->save();

        return Ret::success([], $danmaku->status == 1 ? '已恢复显示' : '已屏蔽');
    }

    /**
     * 获取弹幕统计信息
     */
    public function danmakuStats()
    {
        $total = Danmaku::count();
        $today = date('Y-m-d');
        $todayCount = Danmaku::where('create_time', '>=', $today)->count();
        $activeCount = Danmaku::where('status', 1)->count();
        $blockedCount = Danmaku::where('status', 0)->count();

        // 获取热门视频（按弹幕数量排序）
        $hotVideos = Danmaku::field('video_name, COUNT(*) as danmaku_count')
            ->where('status', 1)
            ->group('video_name')
            ->order('danmaku_count', 'desc')
            ->limit(5)
            ->select();

        return Ret::success([
            'total' => $total,
            'today_count' => $todayCount,
            'active_count' => $activeCount,
            'blocked_count' => $blockedCount,
            'hot_videos' => $hotVideos
        ]);
    }
}