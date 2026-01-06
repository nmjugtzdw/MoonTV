<?php
namespace app\controller\api;

use app\BaseController;
use app\common\Ret;
use think\facade\Cache;
use think\facade\Db;

/**
 * 搜索热榜API
 */
class SearchHot extends BaseController
{
    /**
     * 获取热搜榜
     */
    public function index()
    {
        try {
            // 尝试从缓存获取
            $cacheKey = 'search_hot_list';
            $hotList = Cache::get($cacheKey);
            
            if (!$hotList) {
                // 从搜索历史统计热词
                $hotList = $this->getHotFromHistory();
                
                // 如果没有历史数据，使用默认热搜
                if (empty($hotList)) {
                    $hotList = $this->getDefaultHotSearch();
                }
                
                // 缓存1小时
                if (!empty($hotList)) {
                    Cache::set($cacheKey, $hotList, 3600);
                }
            }
            
            return Ret::success($hotList);
            
        } catch (\Exception $e) {
            return Ret::error('获取热搜失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 从搜索历史统计热词
     */
    private function getHotFromHistory()
    {
        try {
            // 查询最近7天的搜索历史，按搜索次数排序
            $result = Db::table('search_history')
                ->field('keyword, COUNT(*) as count')
                ->where('create_time', '>', date('Y-m-d H:i:s', strtotime('-7 days')))
                ->group('keyword')
                ->order('count', 'desc')
                ->limit(20)
                ->select()
                ->toArray();
            
            $hotList = [];
            foreach ($result as $item) {
                $hotList[] = [
                    'keyword' => $item['keyword'],
                    'count' => $item['count'],
                    'hot' => $item['count'] > 10 // 搜索次数超过10次标记为热门
                ];
            }
            
            return $hotList;
            
        } catch (\Exception $e) {
            trace('从搜索历史获取热词失败: ' . $e->getMessage(), 'error');
            return [];
        }
    }
    
    /**
     * 获取默认热搜（当没有历史数据时）
     */
    private function getDefaultHotSearch()
    {
        return [
            ['keyword' => '斗罗大陆', 'count' => 999, 'hot' => true],
            ['keyword' => '庆余年', 'count' => 888, 'hot' => true],
            ['keyword' => '三体', 'count' => 777, 'hot' => true],
            ['keyword' => '流浪地球', 'count' => 666, 'hot' => false],
            ['keyword' => '想见你', 'count' => 555, 'hot' => false],
            ['keyword' => '繁花', 'count' => 444, 'hot' => false],
            ['keyword' => '长安三万里', 'count' => 333, 'hot' => false],
            ['keyword' => '消失的她', 'count' => 222, 'hot' => false],
            ['keyword' => '封神', 'count' => 111, 'hot' => false],
            ['keyword' => '孤注一掷', 'count' => 100, 'hot' => false],
        ];
    }
    
    /**
     * 获取搜索建议
     */
    public function suggestions()
    {
        try {
            $query = $this->request->param('q', '');
            
            if (empty($query)) {
                return Ret::success([]);
            }
            
            // 尝试从缓存获取
            $cacheKey = 'search_suggestions_' . md5($query);
            $suggestions = Cache::get($cacheKey);
            
            if (!$suggestions) {
                // 从搜索历史中查找相似的关键词
                $suggestions = $this->getSuggestionsFromHistory($query);
                
                // 如果没有足够的建议，添加一些默认建议
                if (count($suggestions) < 5) {
                    $suggestions = array_merge($suggestions, $this->getDefaultSuggestions($query));
                    $suggestions = array_slice($suggestions, 0, 5);
                }
                
                // 缓存10分钟
                if (!empty($suggestions)) {
                    Cache::set($cacheKey, $suggestions, 600);
                }
            }
            
            return Ret::success($suggestions);
            
        } catch (\Exception $e) {
            return Ret::error('获取搜索建议失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 从搜索历史获取建议
     */
    private function getSuggestionsFromHistory($query)
    {
        try {
            $result = Db::table('search_history')
                ->field('keyword')
                ->where('keyword', 'like', '%' . $query . '%')
                ->group('keyword')
                ->order('create_time', 'desc')
                ->limit(5)
                ->select()
                ->toArray();
            
            return array_column($result, 'keyword');
            
        } catch (\Exception $e) {
            trace('从搜索历史获取建议失败: ' . $e->getMessage(), 'error');
            return [];
        }
    }
    
    /**
     * 获取默认搜索建议
     */
    private function getDefaultSuggestions($query)
    {
        // 这里可以添加一些智能建议逻辑
        // 目前返回空数组，让前端直接搜索
        return [];
    }
}