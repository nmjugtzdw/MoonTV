<?php

namespace app\controller\api;

use app\BaseController;
use app\common\Ret;
use app\service\SearchService;
use app\service\ConfigService;

class Search extends BaseController
{
    /**
     * 聚合搜索接口
     */
    public function index()
    {
        try {
            $keyword = input('get.q');
            if (empty($keyword)) {
                return Ret::success([]);
            }

            // 获取用户指定的源（如果有）
            $sourcesParam = input('get.sources');
            $sources = [];
            
            $config = ConfigService::getConfig();
            if (!$config || !is_array($config)) {
                \think\facade\Log::error('配置获取失败或格式错误');
                return Ret::error('系统配置错误，请检查配置', 500);
            }
            
            $allSources = $config['SourceConfig'] ?? [];

            if (empty($allSources) || !is_array($allSources)) {
                \think\facade\Log::error('播放源配置为空');
                return Ret::error('未配置播放源，请先配置播放源', 500);
            }

            if ($sourcesParam) {
                $selectedKeys = explode(',', $sourcesParam);
                // 将 $allSources 转为以 key 为键的关联数组，方便查找
                $sourceMap = [];
                foreach ($allSources as $s) {
                    $key = $s['key'] ?? '';
                    if ($key) {
                        $sourceMap[$key] = $s;
                    }
                }
                
                // 按照 selectedKeys 的顺序构建 sources
                foreach ($selectedKeys as $key) {
                    if (isset($sourceMap[$key])) {
                        $sources[] = $sourceMap[$key];
                    }
                }
            } else {
                // 默认使用所有未禁用的源
                $sources = array_filter($allSources, function ($s) {
                    return empty($s['disabled']);
                });
            }

            if (empty($sources)) {
                \think\facade\Log::warning('没有可用的播放源');
                return Ret::success([]);
            }

            // TODO: 搜索权限控制（如VIP分组可见源）
            // $this->request->user 已经在 AuthCheck 中注入，可在此判断
            // 目前简化处理，假设所有源都开放或前端已过滤

            $results = SearchService::search($keyword, $sources);

            return Ret::success($results);
        } catch (\Exception $e) {
            \think\facade\Log::error('搜索接口异常: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return Ret::error('搜索失败: ' . $e->getMessage(), 500);
        }
    }
}