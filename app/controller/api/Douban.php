<?php

namespace app\controller\api;

use app\BaseController;
use app\common\Ret;
use app\service\DoubanService;
use app\service\SearchService;
use app\service\ConfigService;
use GuzzleHttp\Client;
use think\facade\Log;

/**
 * 豆瓣推荐API控制器
 */
class Douban extends BaseController
{
    /**
     * 获取豆瓣推荐数据
     */
    public function recommends()
    {
        $kind = input('get.kind', 'movie'); // movie 或 tv
        $category = input('get.category', '');
        $format = input('get.format', '');
        $region = input('get.region', '');
        $year = input('get.year', '');
        $platform = input('get.platform', '');
        $sort = input('get.sort', '');
        $label = input('get.label', '');
        $pageStart = input('get.start', 0);
        $pageLimit = input('get.limit', 20);

        $params = [
            'category' => $category,
            'format' => $format,
            'region' => $region,
            'year' => $year,
            'platform' => $platform,
            'sort' => $sort,
            'label' => $label,
            'start' => $pageStart,
            'limit' => $pageLimit,
        ];

        $list = DoubanService::getRecommends($kind, $params);

        return Ret::success($list);
    }

    /**
     * 获取热门电影
     */
    public function hotMovies()
    {
        $limit = input('get.limit', 20);
        $list = DoubanService::getHotMovies($limit);
        // 确保返回数组而不是null
        if (!is_array($list)) {
            trace("getHotMovies返回非数组: " . gettype($list), 'error');
            $list = [];
        }
        return Ret::success($list);
    }

    /**
     * 获取热门剧集
     */
    public function hotTvShows()
    {
        $limit = input('get.limit', 20);
        $list = DoubanService::getHotTvShows($limit);
        // 确保返回数组而不是null
        if (!is_array($list)) {
            trace("getHotTvShows返回非数组: " . gettype($list), 'error');
            $list = [];
        }
        return Ret::success($list);
    }

    /**
     * 获取热门综艺
     */
    public function hotVarietyShows()
    {
        $limit = input('get.limit', 20);
        $list = DoubanService::getHotVarietyShows($limit);
        return Ret::success($list);
    }

    /**
     * 获取热门动漫
     */
    public function hotAnime()
    {
        $limit = input('get.limit', 20);
        $list = DoubanService::getHotAnime($limit);
        // 确保返回数组而不是null
        if (!is_array($list)) {
            trace("getHotAnime返回非数组: " . gettype($list), 'error');
            $list = [];
        }
        return Ret::success($list);
    }

    /**
     * 从备选源获取热门内容（当豆瓣API失败时使用）
     * @param string $type 类型: movie, tv, variety, anime
     */
    public function hotFromBackup()
    {
        $type = input('get.type', 'movie'); // movie, tv, variety, anime
        $limit = input('get.limit', 12);

        // 根据类型确定搜索关键词
        $keywords = [
            'movie' => '电影',
            'tv' => '电视剧',
            'variety' => '综艺',
            'anime' => '动漫'
        ];
        $keyword = $keywords[$type] ?? '电影';

        // 备选源配置（按优先级排序）
        $backupSources = [
            [
                'key' => 'dyttzy',
                'name' => '电影天堂',
                'api' => 'http://caiji.dyttzyapi.com/api.php/provide/vod'
            ],
            [
                'key' => 'bdzy',
                'name' => '百度资源',
                'api' => 'https://api.apibdzy.com/api.php/provide/vod'
            ],
            [
                'key' => 'hongniuzy',
                'name' => '红牛资源',
                'api' => 'https://www.hongniuzy2.com/api.php/provide/vod'
            ]
        ];

        $client = new Client([
            'timeout' => 15,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'Accept' => 'application/json',
            ],
            'verify' => false,
        ]);

        // 依次尝试每个备选源
        foreach ($backupSources as $source) {
            try {
                $searchUrl = rtrim($source['api'], '/') . '/?ac=videolist&wd=' . urlencode($keyword);
                
                $response = $client->get($searchUrl);
                $data = json_decode($response->getBody()->getContents(), true);

                if (isset($data['list']) && is_array($data['list']) && !empty($data['list'])) {
                    // 转换为统一格式
                    $results = [];
                    $count = 0;
                    foreach ($data['list'] as $item) {
                        if ($count >= $limit) break;
                        
                        if (empty($item['vod_id']) || empty($item['vod_name'])) {
                            continue;
                        }

                        // 根据类型过滤（如果API支持）
                        $vodClass = $item['vod_class'] ?? '';
                        $typeName = $item['type_name'] ?? '';
                        
                        // 简单的类型匹配
                        $isMatch = false;
                        if ($type === 'movie' && (stripos($vodClass, '电影') !== false || stripos($typeName, '电影') !== false)) {
                            $isMatch = true;
                        } elseif ($type === 'tv' && (stripos($vodClass, '电视剧') !== false || stripos($typeName, '剧') !== false)) {
                            $isMatch = true;
                        } elseif ($type === 'variety' && (stripos($vodClass, '综艺') !== false || stripos($typeName, '综艺') !== false)) {
                            $isMatch = true;
                        } elseif ($type === 'anime' && (stripos($vodClass, '动漫') !== false || stripos($vodClass, '动画') !== false || stripos($typeName, '动漫') !== false)) {
                            $isMatch = true;
                        } else {
                            // 如果无法判断类型，也接受（因为搜索关键词已经限制了）
                            $isMatch = true;
                        }

                        if ($isMatch) {
                            // 备选API通常没有评分，但尝试从vod_score获取
                            $rate = '';
                            if (isset($item['vod_score']) && $item['vod_score'] > 0) {
                                $rate = number_format((float)$item['vod_score'], 1);
                            } elseif (isset($item['score']) && $item['score'] > 0) {
                                $rate = number_format((float)$item['score'], 1);
                            }
                            
                            $results[] = [
                                'id' => (string)$item['vod_id'],
                                'title' => trim($item['vod_name'] ?? ''),
                                'poster' => $item['vod_pic'] ?? '',
                                'rate' => $rate,
                                'year' => preg_match('/\d{4}/', $item['vod_year'] ?? '', $matches) ? $matches[0] : '',
                                'type' => $type,
                                'source' => $source['key'],
                                'remarks' => $item['vod_remarks'] ?? ''
                            ];
                            $count++;
                        }
                    }

                    if (!empty($results)) {
                        Log::info("备选源 {$source['name']} 成功获取 {$type} 数据: " . count($results) . " 条");
                        return Ret::success($results);
                    }
                }
            } catch (\Exception $e) {
                Log::warning("备选源 {$source['name']} 获取 {$type} 失败: " . $e->getMessage());
                continue; // 尝试下一个源
            }
        }

        // 所有备选源都失败
        Log::warning("所有备选源获取 {$type} 数据失败");
        return Ret::success([]);
    }
}

