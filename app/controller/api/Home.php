<?php
namespace app\controller\api;

use app\BaseController;
use app\common\Ret;
use app\service\ResourceSiteService;
use app\service\ConfigService;
use think\facade\Cache;

/**
 * 首页推荐API控制器 (替代 Recommend)
 */
class Home extends BaseController
{
    /**
     * 获取推荐内容
     */
    public function index()
    {
        try {
            $type = $this->request->param('type', 'all');
            $page = (int)$this->request->param('page', 1);
            $limit = (int)$this->request->param('limit', 24);
            $year = $this->request->param('year', '');
            $area = $this->request->param('area', '');
            $wd = $this->request->param('wd', '');
            
            // 验证参数
            $allowedTypes = ['all', 'movie', 'tv', 'variety', 'anime', 'shorts'];
            if (!in_array($type, $allowedTypes)) {
                return Ret::error('无效的类型参数');
            }
            
            if ($page < 1) $page = 1;
            if ($limit < 1 || $limit > 100) $limit = 24;
            
            $config = ConfigService::getConfig();
            $homepageSource = $config['SiteConfig']['HomepageSource'] ?? 'douban';
            
            $cacheKey = "home_rec_{$homepageSource}_{$type}_page{$page}_limit{$limit}_y{$year}_a{$area}_wd{$wd}";
            $data = Cache::get($cacheKey);
            
            if (!$data) {
                $resourceService = new ResourceSiteService();
                
                if ($type === 'all') {
                    // 强制每个分类获取16条数据，正好2行
                    $data = $resourceService->getMixedRecommend(1, 16);
                } else {
                    $data = $resourceService->getRecommendations($type, $limit, $page, $year, $area, $wd);
                }
                
                if (!empty($data)) {
                    Cache::set($cacheKey, $data, 1800);
                }
            }
            
            return Ret::success($data);
            
        } catch (\Throwable $e) {
            return Ret::error('获取推荐失败: ' . $e->getMessage());
        }
    }
}