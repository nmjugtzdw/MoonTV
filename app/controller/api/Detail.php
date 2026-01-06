<?php

namespace app\controller\api;

use app\BaseController;
use app\common\Ret;
use app\service\SearchService;

class Detail extends BaseController
{
    /**
     * 获取详情接口
     */
    public function index()
    {
        try {
            $id = input('get.id');
            $source = input('get.source');

            if (empty($id) || empty($source)) {
                return Ret::error('参数错误', 400);
            }

            // 豆瓣不是播放源，直接返回404，让前端进行聚合搜索
            if ($source === 'douban') {
                \think\facade\Log::info("豆瓣源请求详情，返回404，让前端进行聚合搜索 [{$id}]");
                return Ret::error('资源不存在或获取失败', 404);
            }

            $detail = SearchService::detail($source, $id);

            if (!$detail) {
                return Ret::error('资源不存在或获取失败', 404);
            }

            return Ret::success($detail);
        } catch (\Exception $e) {
            \think\facade\Log::error('详情接口异常: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return Ret::error('获取详情失败: ' . $e->getMessage(), 500);
        }
    }
}