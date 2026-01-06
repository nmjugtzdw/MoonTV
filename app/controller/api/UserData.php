<?php

namespace app\controller\api;

use app\BaseController;
use app\common\Ret;
use app\model\PlayRecord;
use app\model\Favorite;
use app\model\SearchHistory;
use app\model\Order;

class UserData extends BaseController
{
    protected $middleware = [\app\middleware\AuthCheck::class];

    /**
     * 获取播放记录（仅限已登录用户）
     * 游客的播放记录只保存在前端localStorage中，不调用此接口
     */
    public function getPlayRecords()
    {
        $uid = $this->request->username; // 注意：原版 DB 设计使用 username 关联
        $list = PlayRecord::where('username', $uid)->order('updated_at', 'desc')->select();
        
        $result = [];
        foreach ($list as $item) {
            $result[$item['record_key']] = $item['data'];
        }
        return Ret::success($result);
    }

    /**
     * 保存播放记录（仅限已登录用户：会员和管理员）
     * 游客的播放记录只保存在前端localStorage中，不调用此接口
     *
     * 业务逻辑：
     * - 会员/管理员：记录保存到数据库，同时前端也缓存到localStorage
     * - 游客：前端只保存到localStorage，不调用此接口
     */
    public function savePlayRecord()
    {
        $uid = $this->request->username;
        
        // 支持JSON和表单两种格式
        $contentType = $this->request->contentType() ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $rawInput = file_get_contents('php://input');
            $postData = json_decode($rawInput, true) ?: [];
            $source = $postData['source'] ?? '';
            $id = $postData['id'] ?? '';
            $data = $postData['record'] ?? [];
        } else {
            $source = input('post.source');
            $id = input('post.id');
            $data = input('post.record/a');
        }

        if (empty($source) || empty($id) || empty($data)) {
            return Ret::error('参数错误', 400);
        }

        $key = "{$source}+{$id}";
        
        $record = PlayRecord::where('username', $uid)->where('record_key', $key)->find();
        if ($record) {
            $record->data = $data;
            $record->save();
        } else {
            PlayRecord::create([
                'username'   => $uid,
                'record_key' => $key,
                'data'       => $data
            ]);
        }

        return Ret::success(null, '播放记录已保存');
    }

    /**
     * 删除播放记录（仅限已登录用户：会员和管理员）
     * 游客的播放记录只保存在前端localStorage中，删除也只在前端进行，不调用此接口
     *
     * 业务逻辑：
     * - 会员/管理员：删除数据库记录，前端同时删除localStorage中的记录
     * - 游客：前端只删除localStorage中的记录，不调用此接口
     */
    public function deletePlayRecord()
    {
        $uid = $this->request->username;
        
        // 支持多种方式获取参数：
        // 1. JSON body (推荐)
        // 2. URL query parameters
        // 3. POST form data
        $source = '';
        $id = '';
        
        // 优先从 JSON body 获取
        $contentType = $this->request->contentType() ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $rawInput = file_get_contents('php://input');
            if (!empty($rawInput)) {
                $data = json_decode($rawInput, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                    $source = $data['source'] ?? '';
                    $id = $data['id'] ?? '';
                }
            }
        }
        
        // 如果 JSON 获取失败，尝试从其他来源获取
        if (empty($source) || empty($id)) {
            // 尝试 GET 参数（适用于 DELETE 请求通过 URL 传参）
            $source = input('get.source') ?: input('param.source') ?: input('source');
            $id = input('get.id') ?: input('param.id') ?: input('id');
            
            // 最后尝试 POST 参数
            if (empty($source) || empty($id)) {
                $source = input('post.source', '');
                $id = input('post.id', '');
            }
        }
        
        if (empty($source) || empty($id)) {
            return Ret::error('参数错误：source和id不能为空', 400);
        }
        
        // 支持清空所有记录
        if ($source === 'all' && $id === 'all') {
            PlayRecord::where('username', $uid)->delete();
            return Ret::success(null, '已清空所有播放记录');
        }
        
        $key = "{$source}+{$id}";
        $result = PlayRecord::where('username', $uid)->where('record_key', $key)->delete();
        
        if ($result) {
            return Ret::success(null, '删除成功');
        } else {
            return Ret::success(null, '记录不存在或已删除');
        }
    }

    /**
     * 获取收藏
     */
    public function getFavorites()
    {
        $uid = $this->request->username;
        $list = Favorite::where('username', $uid)->order('updated_at', 'desc')->select();
        
        $result = [];
        foreach ($list as $item) {
            $result[$item['favorite_key']] = $item['data'];
        }
        return Ret::success($result);
    }

    /**
     * 保存收藏
     */
    public function saveFavorite()
    {
        $uid = $this->request->username;
        $source = input('post.source');
        $id = input('post.id');
        $data = input('post.favorite/a');

        if (empty($source) || empty($id) || empty($data)) {
            return Ret::error('参数错误', 400);
        }

        $key = "{$source}+{$id}";
        
        $record = Favorite::where('username', $uid)->where('favorite_key', $key)->find();
        if ($record) {
            $record->data = $data;
            $record->save();
        } else {
            Favorite::create([
                'username'     => $uid,
                'favorite_key' => $key,
                'data'         => $data
            ]);
        }

        return Ret::success();
    }

    /**
     * 删除收藏
     */
    public function deleteFavorite()
    {
        $uid = $this->request->username;
        $source = input('post.source');
        $id = input('post.id');
        
        $key = "{$source}+{$id}";
        Favorite::where('username', $uid)->where('favorite_key', $key)->delete();
        
        return Ret::success();
    }

    /**
     * 检查是否收藏
     */
    public function isFavorited()
    {
        $uid = $this->request->username;
        $source = input('get.source');
        $id = input('get.id');
        
        $key = "{$source}+{$id}";
        $exists = Favorite::where('username', $uid)->where('favorite_key', $key)->count();
        
        return Ret::success(['isFavorited' => $exists > 0]);
    }

    /**
     * 获取搜索历史
     */
    public function getSearchHistory()
    {
        $uid = $this->request->username;
        $list = SearchHistory::where('username', $uid)->order('created_at', 'desc')->limit(20)->column('keyword');
        return Ret::success($list);
    }

    /**
     * 添加搜索历史
     */
    public function addSearchHistory()
    {
        $uid = $this->request->username;
        $keyword = input('post.keyword');
        
        if (empty($keyword)) {
            return Ret::error('参数错误', 400);
        }

        // 检查是否存在
        $exists = SearchHistory::where('username', $uid)->where('keyword', $keyword)->find();
        if (!$exists) {
            SearchHistory::create([
                'username' => $uid,
                'keyword'  => $keyword
            ]);
        } else {
            // 更新时间 (如果需要，但created_at通常不变，可以删了重加)
            $exists->delete();
             SearchHistory::create([
                'username' => $uid,
                'keyword'  => $keyword
            ]);
        }

        return Ret::success();
    }

    /**
     * 删除搜索历史
     */
    public function deleteSearchHistory()
    {
        $uid = $this->request->username;
        $keyword = input('post.keyword');
        
        if ($keyword) {
            SearchHistory::where('username', $uid)->where('keyword', $keyword)->delete();
        } else {
            // 清空
            SearchHistory::where('username', $uid)->delete();
        }
        
        return Ret::success();
    }

    /**
     * 获取用户订单列表
     */
    public function getOrders()
    {
        $username = $this->request->username;
        $page = input('get.page', 1);
        $pageSize = input('get.limit', 10);
        
        // 筛选参数
        $status = input('get.status', '');
        $payType = input('get.pay_type', '');
        $date = input('get.date', '');
        
        $query = Order::where('username', $username);
        
        // 状态筛选
        if ($status !== '' && $status !== 'all') {
            $query->where('status', (int)$status);
        }
        
        // 支付方式筛选
        if ($payType !== '') {
            $query->where('pay_type', $payType);
        }
        
        // 日期筛选
        if ($date !== '' && $date !== 'all') {
            if ($date === '1month') {
                $query->whereTime('created_at', '>=', date('Y-m-d H:i:s', strtotime('-1 month')));
            } elseif ($date === '3months') {
                $query->whereTime('created_at', '>=', date('Y-m-d H:i:s', strtotime('-3 months')));
            } elseif ($date === 'earlier') {
                $query->whereTime('created_at', '<', date('Y-m-d H:i:s', strtotime('-3 months')));
            } else {
                // 兼容旧的按具体日期查询
                $query->whereTime('created_at', '>=', $date . ' 00:00:00')
                      ->whereTime('created_at', '<=', $date . ' 23:59:59');
            }
        }
        
        $list = $query->order('id', 'desc')
            ->paginate([
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
}