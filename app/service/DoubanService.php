<?php

namespace app\service;

use GuzzleHttp\Client;
use think\facade\Cache;

/**
 * 豆瓣推荐服务
 * 用于获取首页推荐数据
 * 支持多种代理方式：直连、腾讯CDN、阿里CDN、CORS代理、自定义代理
 */
class DoubanService
{
    const DOUBAN_API_BASE = 'https://m.douban.com/rexxar/api/v2';
    const DOUBAN_TENCENT_CDN = 'https://m.douban.cmliussss.net/rexxar/api/v2';
    const DOUBAN_ALI_CDN = 'https://m.douban.cmliussss.com/rexxar/api/v2';
    const CORS_PROXY = 'https://ciao-cors.is-an.org/';
    const CACHE_TIME = 7200; // 2小时缓存
    const REQUEST_TIMEOUT = 10; // 10秒超时（CDN更快，可以缩短超时）

    /**
     * 获取代理配置
     * @return array ['type' => 'direct|cmliussss-cdn-tencent|cmliussss-cdn-ali|cors-proxy-zwei|custom', 'url' => '']
     */
    private static function getProxyConfig()
    {
        try {
            $config = ConfigService::getConfig();
            $proxyType = $config['SiteConfig']['DoubanProxyType'] ?? 'direct';
            $proxyUrl = $config['SiteConfig']['DoubanProxy'] ?? '';
            
            return [
                'type' => $proxyType,
                'url' => $proxyUrl,
            ];
        } catch (\Exception $e) {
            trace('获取豆瓣代理配置失败: ' . $e->getMessage(), 'error');
            return ['type' => 'direct', 'url' => ''];
        }
    }

    /**
     * 构建请求URL（不包含查询参数）
     * @param string $kind 类型：movie 或 tv
     * @param string $proxyType 代理类型
     * @param string $proxyUrl 自定义代理URL
     * @return string
     */
    private static function buildApiUrl($kind, $proxyType, $proxyUrl = '')
    {
        $endpoint = '/' . $kind . '/recommend';
        $baseUrl = self::DOUBAN_API_BASE . $endpoint;
        
        switch ($proxyType) {
            case 'cmliussss-cdn-tencent':
                return self::DOUBAN_TENCENT_CDN . $endpoint;
            case 'cmliussss-cdn-ali':
                return self::DOUBAN_ALI_CDN . $endpoint;
            case 'cors-proxy-zwei':
                // CORS代理需要完整URL（查询参数稍后添加）
                return self::CORS_PROXY . urlencode($baseUrl);
            case 'custom':
                if ($proxyUrl) {
                    // 自定义代理：如果以/结尾，则拼接完整URL；否则作为完整代理URL
                    if (substr($proxyUrl, -1) === '/') {
                        return $proxyUrl . urlencode($baseUrl);
                    } else {
                        // 假设是完整的代理URL，直接返回
                        return $proxyUrl;
                    }
                }
                // 如果没有配置自定义代理URL，降级到直连
                return $baseUrl;
            case 'direct':
            default:
                return $baseUrl;
        }
    }

    /**
     * 发送HTTP请求
     * @param string $url 基础URL（可能包含代理前缀）
     * @param array $queryParams 查询参数
     * @return array|null
     */
    private static function fetchData($url, $queryParams = [])
    {
        try {
            $client = new Client([
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 MicroMessenger/8.0.0',
                    'Referer' => 'https://m.douban.com/',
                    'Accept' => 'application/json',
                ],
                'verify' => false,
                'http_errors' => false, // 不抛出HTTP错误，手动处理
            ]);

            // 判断是否是代理模式（CORS代理或自定义代理）
            $isProxy = strpos($url, self::CORS_PROXY) === 0 || 
                      (strpos($url, 'http') === 0 && strpos($url, 'douban.com') === false && strpos($url, 'cmliussss') === false);
            
            if ($isProxy && !empty($queryParams)) {
                // 代理模式：需要将查询参数拼接到URL
                $separator = strpos($url, '?') !== false ? '&' : '?';
                $url .= $separator . http_build_query($queryParams);
                $response = $client->get($url);
            } else {
                // 直连或CDN模式：使用Guzzle的query参数
                $response = $client->get($url, ['query' => $queryParams]);
            }

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                throw new \Exception("HTTP {$statusCode}");
            }

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('JSON解析失败: ' . json_last_error_msg());
            }

            return $data;
        } catch (\Exception $e) {
            trace('豆瓣API请求失败: ' . $e->getMessage() . ' URL: ' . $url, 'error');
            return null;
        }
    }

    /**
     * 获取豆瓣推荐数据
     * @param string $kind 类型：movie 或 tv
     * @param array $params 参数
     * @return array
     */
    public static function getRecommends($kind = 'movie', $params = [])
    {
        $category = $params['category'] ?? '';
        $format = $params['format'] ?? '';
        $region = $params['region'] ?? '';
        $year = $params['year'] ?? '';
        $platform = $params['platform'] ?? '';
        $sort = $params['sort'] ?? '';
        $label = $params['label'] ?? '';
        $pageStart = $params['start'] ?? 0;
        $pageLimit = $params['limit'] ?? 20;

        // 构建缓存键
        $cacheKey = 'douban_recommends_' . md5(json_encode([
            'kind' => $kind,
            'category' => $category,
            'format' => $format,
            'region' => $region,
            'year' => $year,
            'platform' => $platform,
            'sort' => $sort,
            'label' => $label,
            'start' => $pageStart,
            'limit' => $pageLimit,
        ]));

        // 尝试从缓存获取
        $cached = Cache::get($cacheKey);
        // 修复：确保缓存返回的是数组，而不是 null
        if ($cached !== false && is_array($cached)) {
            trace("从缓存获取豆瓣推荐数据: {$kind}, 数量: " . count($cached), 'info');
            return $cached;
        }
        // 如果缓存是 null 或 false，清除它并重新获取
        if ($cached === null || $cached === false) {
            Cache::delete($cacheKey);
        }

        // 构建请求参数
        $selectedCategories = [];
        if ($category) {
            $selectedCategories['类型'] = $category;
        }
        if ($format) {
            $selectedCategories['形式'] = $format;
        }
        if ($region) {
            $selectedCategories['地区'] = $region;
        }

        $tags = [];
        if ($category) {
            $tags[] = $category;
        }
        if (!$category && $format) {
            $tags[] = $format;
        }
        if ($label) {
            $tags[] = $label;
        }
        if ($region) {
            $tags[] = $region;
        }
        if ($year) {
            $tags[] = $year;
        }
        if ($platform) {
            $tags[] = $platform;
        }

        // 构建查询参数
        $queryParams = [
            'refresh' => '0',
            'start' => $pageStart,
            'count' => $pageLimit,
            'uncollect' => 'false',
            'score_range' => '0,10',
        ];
        
        // 只有当 selectedCategories 不为空时才添加
        if (!empty($selectedCategories)) {
            $queryParams['selected_categories'] = json_encode($selectedCategories, JSON_UNESCAPED_UNICODE);
        }
        
        // 只有当 tags 不为空时才添加
        if (!empty($tags)) {
            $queryParams['tags'] = implode(',', $tags);
        }
        
        trace("豆瓣API请求参数: kind={$kind}, selectedCategories=" . json_encode($selectedCategories, JSON_UNESCAPED_UNICODE) . ", tags=" . implode(',', $tags), 'info');

        if ($sort) {
            $queryParams['sort'] = $sort;
        }

        // 获取代理配置
        $proxyConfig = self::getProxyConfig();
        $proxyType = $proxyConfig['type'];
        $proxyUrl = $proxyConfig['url'];

        // 定义代理优先级列表（按速度从快到慢，根据实际测试调整）
        $proxyPriority = [
            'direct',                 // 直连（最快，0.3秒）
            'cmliussss-cdn-tencent',  // 腾讯CDN（备用，3.3秒）
            'cmliussss-cdn-ali',      // 阿里CDN（备用，3.2秒）
            'cors-proxy-zwei',        // CORS代理
            'custom',                 // 自定义代理
        ];

        // 如果配置了特定代理，优先使用
        if ($proxyType !== 'direct' && in_array($proxyType, $proxyPriority)) {
            array_unshift($proxyPriority, $proxyType);
            $proxyPriority = array_unique($proxyPriority);
        }

        // 尝试不同的代理方式
        $data = null;
        $lastError = '';
        
        foreach ($proxyPriority as $tryProxyType) {
            try {
                $url = self::buildApiUrl($kind, $tryProxyType, $proxyUrl);
                trace("尝试豆瓣API请求: {$tryProxyType}, URL: {$url}", 'info');
                $data = self::fetchData($url, $queryParams);
                
                // 详细记录返回数据
                if ($data !== null) {
                    $hasItems = isset($data['items']);
                    $itemsCount = $hasItems && is_array($data['items']) ? count($data['items']) : 0;
                    trace("豆瓣API响应（{$tryProxyType}）: hasItems={$hasItems}, itemsCount={$itemsCount}, dataKeys=" . json_encode(array_keys($data ?? [])), 'info');
                    
                    // 如果 items 为空，记录更多信息用于调试
                    if ($hasItems && is_array($data['items']) && $itemsCount === 0) {
                        trace("警告: 豆瓣API返回空items数组（{$tryProxyType}），URL: {$url}", 'warning');
                        trace("完整响应数据（前1000字符）: " . substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 1000), 'warning');
                    }
                    
                    if ($hasItems && is_array($data['items']) && $itemsCount > 0) {
                        trace("豆瓣API请求成功，使用代理: {$tryProxyType}, 获取到 {$itemsCount} 条数据", 'info');
                        break; // 成功获取数据，退出循环
                    } else {
                        // 数据为空或格式不正确，尝试下一个
                        trace("豆瓣API返回空数据（{$tryProxyType}），items为空或不是数组，尝试下一个代理", 'warning');
                        continue;
                    }
                } else {
                    trace("豆瓣API返回null（{$tryProxyType}），尝试下一个代理", 'warning');
                    continue;
                }
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                trace("豆瓣API请求失败（{$tryProxyType}）: {$lastError}", 'error');
                continue; // 尝试下一个代理
            }
        }

        // 如果所有代理都失败
        if ($data === null || !isset($data['items']) || !is_array($data['items'])) {
            trace('所有豆瓣API代理方式均失败，最后错误: ' . ($lastError ?: '数据为空或格式不正确'), 'error');
            if ($data !== null) {
                trace('最后返回的数据结构: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'error');
            }
            return [];
        }

        // 处理数据
        $list = [];
        $itemsCount = count($data['items']);
        $skippedCount = 0;
        
        trace("开始处理豆瓣API数据，items总数: {$itemsCount}", 'info');
        
        foreach ($data['items'] as $index => $item) {
            // 只处理电影和电视剧
            $itemType = $item['type'] ?? 'unknown';
            if ($itemType !== 'movie' && $itemType !== 'tv') {
                $skippedCount++;
                if ($index < 3) { // 只记录前3个被跳过的项目
                    trace("跳过项目[{$index}]: type={$itemType}, title=" . ($item['title'] ?? 'N/A'), 'debug');
                }
                continue;
            }

            // 处理评分：尝试多种方式获取评分
            $rate = '';
            
            // 方式1：从 rating.value 获取
            if (isset($item['rating']['value']) && $item['rating']['value'] > 0) {
                $rate = number_format($item['rating']['value'], 1);
            }
            // 方式2：从 rating 直接获取（可能是字符串）
            elseif (isset($item['rating']) && is_numeric($item['rating']) && $item['rating'] > 0) {
                $rate = number_format((float)$item['rating'], 1);
            }
            // 方式3：从 rate 字段获取
            elseif (isset($item['rate']) && is_numeric($item['rate']) && $item['rate'] > 0) {
                $rate = number_format((float)$item['rate'], 1);
            }
            
            // 调试：记录前3个项目的评分信息
            if ($index < 3) {
                $ratingInfo = [
                    'title' => $item['title'] ?? 'N/A',
                    'has_rating' => isset($item['rating']),
                    'rating_value' => $item['rating']['value'] ?? 'N/A',
                    'rating_full' => $item['rating'] ?? 'N/A',
                    'rate_field' => $item['rate'] ?? 'N/A',
                    'final_rate' => $rate
                ];
                trace("评分调试[{$index}]: " . json_encode($ratingInfo, JSON_UNESCAPED_UNICODE), 'info');
            }

            $list[] = [
                'id' => (string)($item['id'] ?? ''),
                'title' => $item['title'] ?? '',
                'poster' => $item['pic']['normal'] ?? $item['pic']['large'] ?? '',
                'rate' => $rate,
                'year' => $item['year'] ?? '',
                'type' => $itemType,
            ];
        }
        
        trace("数据处理完成: 总数={$itemsCount}, 跳过={$skippedCount}, 处理后=" . count($list), 'info');
        
        if (empty($list)) {
            trace("警告: 处理后的列表为空，原始items数量={$itemsCount}, 跳过数量={$skippedCount}", 'warning');
            if ($itemsCount > 0 && $itemsCount <= 5) {
                // 如果items数量不多，记录所有items的类型
                $types = [];
                foreach ($data['items'] as $item) {
                    $types[] = $item['type'] ?? 'unknown';
                }
                trace("所有items的类型: " . json_encode($types), 'warning');
            }
        }

        // 缓存结果（确保只缓存非空数组）
        if (!empty($list)) {
            Cache::set($cacheKey, $list, self::CACHE_TIME);
            trace("缓存豆瓣推荐数据: {$kind}, 数量: " . count($list), 'info');
        } else {
            // 如果列表为空，不缓存，避免缓存空结果
            trace("豆瓣推荐数据为空，不缓存: {$kind}", 'warning');
        }

        // 确保返回数组而不是null
        return is_array($list) ? $list : [];
    }

    /**
     * 获取热门电影
     * @param int $limit 数量限制
     * @return array
     */
    public static function getHotMovies($limit = 20)
    {
        // 热门电影：不传 category，让后端按默认推荐返回
        // 参考：src/app/api/tvbox/categories/route.ts 中的处理逻辑
        // "热门：不传 category/label，由后端按默认推荐返回"
        // 添加按时间排序，优先显示最新内容
        // 限制年份为最近3年，确保获取最新内容
        $currentYear = (int)date('Y');
        $recentYears = [];
        for ($i = 0; $i < 3; $i++) {
            $recentYears[] = (string)($currentYear - $i);
        }
        
        $list = self::getRecommends('movie', [
            'category' => '',  // 不传 category，使用默认推荐
            'sort' => 'time',  // 按时间排序
            'year' => $currentYear,  // 优先获取当年的内容
            'limit' => $limit * 2,  // 多获取一些，因为要过滤
        ]);
        
        // 过滤：只保留最近3年的内容
        $list = self::filterRecentYears($list, $currentYear - 2);
        
        // 如果API排序不够准确，再次按年份倒序排序
        $list = self::sortByYearDesc($list);
        
        // 限制返回数量
        return array_slice($list, 0, $limit);
    }

    /**
     * 获取热门剧集
     * @param int $limit 数量限制
     * @return array
     */
    public static function getHotTvShows($limit = 20)
    {
        $currentYear = (int)date('Y');
        
        $list = self::getRecommends('tv', [
            'category' => 'tv',
            'sort' => 'time',  // 按时间排序
            'year' => $currentYear,  // 优先获取当年的内容
            'limit' => $limit * 2,  // 多获取一些，因为要过滤
        ]);
        
        // 过滤：只保留最近3年的内容
        $list = self::filterRecentYears($list, $currentYear - 2);
        
        // 如果API排序不够准确，再次按年份倒序排序
        $list = self::sortByYearDesc($list);
        
        // 限制返回数量
        return array_slice($list, 0, $limit);
    }

    /**
     * 获取热门综艺
     * @param int $limit 数量限制
     * @return array
     */
    public static function getHotVarietyShows($limit = 20)
    {
        $currentYear = (int)date('Y');
        
        $list = self::getRecommends('tv', [
            'format' => '综艺',
            'sort' => 'time',  // 按时间排序
            'year' => $currentYear,  // 优先获取当年的内容
            'limit' => $limit * 2,  // 多获取一些，因为要过滤
        ]);
        
        // 过滤：只保留最近3年的内容
        $list = self::filterRecentYears($list, $currentYear - 2);
        
        // 如果API排序不够准确，再次按年份倒序排序
        $list = self::sortByYearDesc($list);
        
        // 限制返回数量
        return array_slice($list, 0, $limit);
    }

    /**
     * 获取热门动漫
     * @param int $limit 数量限制
     * @return array
     */
    public static function getHotAnime($limit = 20)
    {
        $currentYear = (int)date('Y');
        
        // 动漫：使用 tv 类型，category 为"动画"
        $list = self::getRecommends('tv', [
            'category' => '动画',
            'format' => '电视剧',
            'sort' => 'time',  // 按时间排序
            'year' => $currentYear,  // 优先获取当年的内容
            'limit' => $limit * 2,  // 多获取一些，因为要过滤
        ]);
        
        // 过滤：只保留最近3年的内容
        $list = self::filterRecentYears($list, $currentYear - 2);
        
        // 如果API排序不够准确，再次按年份倒序排序
        $list = self::sortByYearDesc($list);
        
        // 限制返回数量
        return array_slice($list, 0, $limit);
    }

    /**
     * 过滤最近几年的内容
     * @param array $list 数据列表
     * @param int $minYear 最小年份（只保留此年份及之后的内容）
     * @return array
     */
    private static function filterRecentYears($list, $minYear)
    {
        if (empty($list) || !is_array($list)) {
            return $list;
        }

        return array_filter($list, function ($item) use ($minYear) {
            $year = intval($item['year'] ?? 0);
            // 只保留最近几年的内容（minYear及之后）
            // 如果年份为0或无效，也保留（可能是未标注年份的新内容）
            return $year >= $minYear || $year == 0;
        });
    }

    /**
     * 按年份倒序排序（最新的在前）
     * @param array $list 数据列表
     * @return array
     */
    private static function sortByYearDesc($list)
    {
        if (empty($list) || !is_array($list)) {
            return $list;
        }

        usort($list, function ($a, $b) {
            $yearA = intval($a['year'] ?? 0);
            $yearB = intval($b['year'] ?? 0);
            
            // 年份大的（新的）排在前面
            if ($yearA !== $yearB) {
                return $yearB - $yearA;
            }
            
            // 如果年份相同，按评分排序（评分高的在前）
            $rateA = floatval($a['rate'] ?? 0);
            $rateB = floatval($b['rate'] ?? 0);
            if ($rateA !== $rateB) {
                return $rateB <=> $rateA;
            }
            
            // 如果年份和评分都相同，保持原顺序
            return 0;
        });

        return $list;
    }
}

