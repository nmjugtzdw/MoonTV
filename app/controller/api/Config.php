<?php

namespace app\controller\api;

use app\BaseController;
use app\common\Ret;
use app\service\ConfigService;
use think\facade\Cache;

class Config extends BaseController
{
    /**
     * 获取可用的 API 源列表
     * GET /api/config/sources
     */
    public function sources()
    {
        try {
            $config = ConfigService::getConfig();
            
            // 获取所有未禁用的源
            $allSources = $config['SourceConfig'] ?? [];
            $enabledSources = array_filter($allSources, function ($s) {
                return empty($s['disabled']);
            });
            
            // 如果有用户认证，可以根据用户分组过滤（暂时简化处理）
            // TODO: 实现用户分组权限控制
            $username = $this->request->user['username'] ?? null;
            
            // 格式化返回数据
            $sites = array_map(function ($source) {
                return [
                    'key' => $source['key'] ?? '',
                    'name' => $source['name'] ?? '',
                    'api' => $source['api'] ?? '',
                    'detail' => $source['detail'] ?? '',
                ];
            }, $enabledSources);
            
            // 获取缓存时间
            $cacheTime = $config['SiteConfig']['SiteInterfaceCacheTime'] ?? 7200;
            
            // 设置响应头（缓存控制）
            header('Cache-Control: public, max-age=' . $cacheTime . ', s-maxage=0');
            
            // 直接返回 JSON（不使用 Ret::success，因为前端期望直接是数组）
            return json($sites);
            
        } catch (\Exception $e) {
            // 记录错误日志
            trace('获取视频源失败: ' . $e->getMessage(), 'error');
            
            return Ret::error('获取视频源失败', 500);
        }
    }
}

