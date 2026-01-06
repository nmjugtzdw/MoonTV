<?php

namespace app\service;

use app\service\ConfigService;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Exception\RequestException;
use think\facade\Log;
use think\facade\Cache;

class SearchService
{
    // API 配置
    const API_SEARCH_PATH = '?ac=videolist&wd=';
    const API_SEARCH_PAGE_PATH = '?ac=videolist&wd={query}&pg={page}';
    const API_DETAIL_PATH = '?ac=videolist&ids=';
    const REQUEST_TIMEOUT = 8; // 优化：缩短为8秒超时
    const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
    const SEARCH_CACHE_TIME = 3600; // 搜索结果缓存1小时
    const DETAIL_CACHE_TIME = 7200; // 详情缓存2小时

    /**
     * 并发搜索多个源
     * @param string $keyword 搜索关键词
     * @param array $sources 源列表
     * @return array
     */
    public static function search($keyword, $sources = [])
    {
        try {
            if (empty($keyword) || empty($sources)) {
                return [];
            }
            
            // 优化搜索关键词：去掉年份、引号等特殊字符，提取核心关键词
            $originalKeyword = $keyword;
            // 去掉年份（如2026、2025等）
            $keyword = preg_replace('/\d{4}/', '', $keyword);
            // 去掉引号
            $keyword = preg_replace('/["""\'\']/u', '', $keyword);
            // 去掉多余空格
            $keyword = preg_replace('/\s+/', ' ', $keyword);
            $keyword = trim($keyword);
            
            // 如果优化后的关键词太短，使用原始关键词
            if (mb_strlen($keyword) < 2) {
                $keyword = $originalKeyword;
            }
            
            Log::info("搜索关键词优化: {$originalKeyword} -> {$keyword}");

            // 生成缓存键
            $sourceKeys = [];
            foreach ($sources as $s) {
                if (isset($s['key'])) {
                    $sourceKeys[] = $s['key'];
                }
            }
            sort($sourceKeys); // 排序确保缓存键一致
            $cacheKey = 'search_' . md5($keyword . json_encode($sourceKeys));
            
            // 尝试从缓存获取
            $cached = Cache::get($cacheKey);
            if ($cached !== false && is_array($cached)) {
                Log::info("从缓存获取搜索结果: {$keyword}, 源: " . implode(',', $sourceKeys) . ", 数量: " . count($cached));
                return $cached;
            }
            
            Log::info("搜索新请求: {$keyword}, 使用播放源: " . implode(',', $sourceKeys));

            $config = ConfigService::getConfig();
            if (!$config || !is_array($config)) {
                Log::error('配置获取失败，使用默认配置');
                $maxPages = 5;
            } else {
                $maxPages = $config['SiteConfig']['SearchDownstreamMaxPage'] ?? 5;
            }

        // 创建 HTTP 客户端
        $client = new Client([
            'timeout' => self::REQUEST_TIMEOUT,
            'headers' => [
                'User-Agent' => self::DEFAULT_USER_AGENT,
                'Accept' => 'application/json',
            ],
            'verify' => false, // 某些源可能使用自签名证书,
            'http_errors' => false // 即使404也不抛出异常，便于统计
        ]);

        $allResults = [];
        $promises = [];

        // 为每个源创建并发请求
        foreach ($sources as $source) {
            $sourceKey = $source['key'] ?? '';
            $apiUrl = $source['api'] ?? '';
            $sourceName = $source['name'] ?? '';

            if (empty($apiUrl)) {
                continue;
            }

            // 构建搜索 URL
            $searchUrl = rtrim($apiUrl, '/') . '/' . self::API_SEARCH_PATH . urlencode($keyword);

            // 记录请求开始时间
            $startTime = microtime(true);

            // 创建异步请求
            $promises[$sourceKey] = $client->getAsync($searchUrl)->then(
                function ($response) use ($sourceKey, $sourceName, $apiUrl, $keyword, $maxPages, $client, $startTime) {
                    // 记录响应时间并缓存（用于测速）
                    $duration = round((microtime(true) - $startTime) * 1000); // ms
                    Cache::set('source_speed_' . $sourceKey, $duration, 3600); // 缓存1小时
                    
                    $data = json_decode($response->getBody()->getContents(), true);
                    
                    if (!isset($data['list']) || !is_array($data['list'])) {
                        return [];
                    }

                    $results = [];
                    // 处理第一页结果
                    foreach ($data['list'] as $item) {
                        $mapped = self::mapItemToResult($item, $sourceKey, $sourceName);
                        if ($mapped) {
                            $results[] = $mapped;
                        }
                    }

                    // 优化：全网搜索时禁用自动翻页，只取第一页，以保证速度和减少结果总数
                    // 只有当明确在搜某一个源时才考虑翻页，但为了全局搜索体验，此处统一关闭

                    return $results;
                },
                function ($exception) use ($sourceKey, $searchUrl) {
                    // 记录错误但不中断其他请求
                    $errorMsg = self::extractErrorMessage($exception, $searchUrl);
                    Log::error("搜索源 {$sourceKey} 失败: {$errorMsg}");
                    return [];
                }
            );
        }

        // 如果没有有效的请求，直接返回空结果
        if (empty($promises)) {
            Log::warning("没有有效的播放源进行搜索");
            return [];
        }

        // 等待所有请求完成
        try {
            $settled = Utils::settle($promises)->wait();
        } catch (\Exception $e) {
            Log::error("等待搜索请求完成时发生异常: " . $e->getMessage());
            return [];
        }

        // 收集所有结果
        if (is_array($settled)) {
            foreach ($settled as $sourceKey => $result) {
                if (isset($result['state']) && $result['state'] === 'fulfilled' && isset($result['value']) && is_array($result['value'])) {
                    $allResults = array_merge($allResults, $result['value']);
                }
            }
        }

        // 去重（根据 id + source）
        $allResults = self::deduplicateResults($allResults);

        // 优化：限制最大结果数量，防止前端渲染卡死
        if (count($allResults) > 300) {
            Log::info("搜索结果过多 (" . count($allResults) . "条)，截取前300条");
            // 先简单截取，后续排序后再截取可能更好，但为了节省内存先截取
            // 考虑到排序需要，我们尽量保留多一点再排序，但不要超过内存限制
            // 还是先排序再截取吧
        }

        // 如果搜索关键词不包含"预告"，先过滤掉预告片
        $keywordLower = strtolower(trim($keyword));
        $cleanKeyword = preg_replace('/[（(].*?[）)]/', '', $keywordLower);
        $shouldFilterTrailer = !preg_match('/预告|trailer|花絮|特辑/i', $cleanKeyword);
        
        if ($shouldFilterTrailer) {
            $beforeCount = count($allResults);
            $allResults = array_filter($allResults, function($item) {
                $title = strtolower($item['title'] ?? '');
                // 过滤掉包含预告、花絮、特辑等的结果
                return !preg_match('/预告|trailer|花絮|特辑|片段|片花|MV|mv|preview/i', $title);
            });
            $afterCount = count($allResults);
            if ($beforeCount > $afterCount) {
                Log::info("过滤预告片: 从 {$beforeCount} 条减少到 {$afterCount} 条");
            }
        }

        // 排序（按标题相似度、年份等）
        usort($allResults, function ($a, $b) use ($keyword, $cleanKeyword) {
            $titleA = $a['title'] ?? '';
            $titleB = $b['title'] ?? '';
            
            // 标题完全匹配优先（去掉括号后比较）
            $cleanTitleA = preg_replace('/[（(].*?[）)]/', '', $titleA);
            $cleanTitleB = preg_replace('/[（(].*?[）)]/', '', $titleB);
            $exactMatchA = (strtolower(trim($cleanTitleA)) === $cleanKeyword);
            $exactMatchB = (strtolower(trim($cleanTitleB)) === $cleanKeyword);
            if ($exactMatchA && !$exactMatchB) return -1;
            if (!$exactMatchA && $exactMatchB) return 1;
            
            // 标题包含关键词的优先（去掉括号内容后比较）
            $keywordLower = strtolower(trim($keyword));
            $containsA = stripos($cleanTitleA, $cleanKeyword) !== false;
            $containsB = stripos($cleanTitleB, $cleanKeyword) !== false;
            if ($containsA && !$containsB) return -1;
            if (!$containsA && $containsB) return 1;
            
            // 如果都包含，按标题长度排序（短的优先，可能是更精确的匹配）
            if ($containsA && $containsB) {
                return strlen($cleanTitleA) - strlen($cleanTitleB);
            }
            
            // 优先显示有年份的
            if (!empty($a['year']) && empty($b['year'])) return -1;
            if (empty($a['year']) && !empty($b['year'])) return 1;
            
            // 最后按标题排序
            return strcmp($titleA, $titleB);
        });

        // 最终结果截取 (Top 300)
        if (count($allResults) > 300) {
            $allResults = array_slice($allResults, 0, 300);
        }

        // 缓存结果（只缓存非空结果）
        if (!empty($allResults)) {
            Cache::set($cacheKey, $allResults, self::SEARCH_CACHE_TIME);
            
            // 统计每个播放源的结果数量
            $sourceStats = [];
            foreach ($allResults as $item) {
                $sourceName = $item['source_name'] ?? $item['source'] ?? 'unknown';
                if (!isset($sourceStats[$sourceName])) {
                    $sourceStats[$sourceName] = 0;
                }
                $sourceStats[$sourceName]++;
            }
            
            $statsStr = [];
            foreach ($sourceStats as $name => $count) {
                $statsStr[] = "{$name}:{$count}";
            }
            
            Log::info("缓存搜索结果: {$keyword}, 总数: " . count($allResults) . ", 分布: " . implode(', ', $statsStr));
        }

        return $allResults;
        } catch (\Exception $e) {
            Log::error("搜索异常 [{$keyword}]: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return [];
        }
    }

    /**
     * 映射 API 数据到统一格式
     * @param array $item API 返回的单个视频项
     * @param string $sourceKey 源标识
     * @param string $sourceName 源名称
     * @return array|null
     */
    protected static function mapItemToResult($item, $sourceKey, $sourceName)
    {
        if (empty($item['vod_id']) || empty($item['vod_name'])) {
            return null;
        }

        // 解析播放链接
        $episodes = [];
        $episodesTitles = [];
        
        if (!empty($item['vod_play_url'])) {
            $parsed = self::parseEpisodes($item['vod_play_url']);
            $episodes = $parsed['episodes'];
            $episodesTitles = $parsed['titles'];
        }

        // 清理 HTML 标签
        $desc = self::cleanHtmlTags($item['vod_content'] ?? '');

        // 提取年份
        $year = 'unknown';
        if (!empty($item['vod_year'])) {
            preg_match('/\d{4}/', $item['vod_year'], $matches);
            if (!empty($matches[0])) {
                $year = $matches[0];
            }
        }

        // 提取地区信息
        $area = '';
        if (!empty($item['vod_area'])) {
            $area = trim($item['vod_area']);
        }

        return [
            'id' => (string)$item['vod_id'],
            'title' => trim(str_replace(['\n', '\r', '\t'], ' ', $item['vod_name'])),
            'poster' => $item['vod_pic'] ?? '',
            'episodes' => $episodes,
            'episodes_titles' => $episodesTitles,
            'source' => $sourceKey,
            'source_name' => $sourceName,
            'class' => $item['vod_class'] ?? '',
            'year' => $year,
            'area' => $area,
            'desc' => $desc,
            'type_name' => $item['type_name'] ?? '',
            'douban_id' => $item['vod_douban_id'] ?? null,
            // 兼容旧格式字段（前端可能还在使用）
            'vod_id' => (string)$item['vod_id'],
            'vod_name' => trim(str_replace(['\n', '\r', '\t'], ' ', $item['vod_name'])),
            'vod_pic' => $item['vod_pic'] ?? '',
            'vod_year' => $item['vod_year'] ?? '',
            'vod_area' => $area,
            'vod_class' => $item['vod_class'] ?? '',
            'vod_content' => $desc,
            'vod_remarks' => $item['vod_remarks'] ?? '',
            // 播放列表格式（兼容前端）
            'vod_play_list' => !empty($episodes) ? [[
                'from' => $sourceName,
                'urls' => array_map(function($url, $idx) use ($episodesTitles) {
                    return [
                        'name' => $episodesTitles[$idx] ?? "第" . ($idx + 1) . "集",
                        'url' => $url
                    ];
                }, $episodes, array_keys($episodes))
            ]] : [],
        ];
    }

    /**
     * 解析播放链接
     * @param string $vodPlayUrl 播放链接字符串
     * @return array ['episodes' => [], 'titles' => []]
     */
    protected static function parseEpisodes($vodPlayUrl)
    {
        $episodes = [];
        $titles = [];

        if (empty($vodPlayUrl)) {
            return ['episodes' => $episodes, 'titles' => $titles];
        }

        // 格式: 源1$链接1#源1$链接2$$$源2$链接1
        $sources = explode('$$$', $vodPlayUrl);
        
        foreach ($sources as $source) {
            $currentEpisodes = [];
            $currentTitles = [];
            
            $entries = explode('#', $source);
            foreach ($entries as $entry) {
                $parts = explode('$', $entry, 2);
                if (count($parts) === 2) {
                    $title = trim($parts[0]);
                    $url = trim($parts[1]);
                    
                    // 只保留 m3u8 链接
                    if (strpos($url, '.m3u8') !== false || strpos($url, '.mp4') !== false) {
                        $currentTitles[] = $title;
                        $currentEpisodes[] = $url;
                    }
                }
            }

            // 选择分集最多的播放源
            if (count($currentEpisodes) > count($episodes)) {
                $episodes = $currentEpisodes;
                $titles = $currentTitles;
            }
        }

        return ['episodes' => $episodes, 'titles' => $titles];
    }

    /**
     * 清理 HTML 标签
     * @param string $html
     * @return string
     */
    protected static function cleanHtmlTags($html)
    {
        if (empty($html)) {
            return '';
        }
        
        // 移除 HTML 标签
        $text = strip_tags($html);
        // 解码 HTML 实体
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // 清理多余空白
        $text = preg_replace('/\s+/', ' ', $text);
        
        return trim($text);
    }

    /**
     * 提取详细的错误信息
     * @param \Exception $exception
     * @param string $url 请求URL
     * @return string
     */
    protected static function extractErrorMessage($exception, $url = '')
    {
        // 简化URL显示（只显示域名部分）
        $urlDisplay = $url;
        if (preg_match('/https?:\/\/([^\/]+)/', $url, $matches)) {
            $urlDisplay = $matches[1];
        }

        if ($exception instanceof RequestException) {
            $response = $exception->hasResponse() ? $exception->getResponse() : null;
            
            if ($response) {
                $statusCode = $response->getStatusCode();
                $reasonPhrase = $response->getReasonPhrase();
                
                // 根据HTTP状态码提供更友好的错误信息
                $errorType = '';
                switch ($statusCode) {
                    case 403:
                        $errorType = '访问被拒绝（可能被反爬虫拦截）';
                        break;
                    case 404:
                        $errorType = '接口不存在';
                        break;
                    case 500:
                        $errorType = '服务器内部错误';
                        break;
                    case 502:
                        $errorType = '网关错误（服务器可能暂时不可用）';
                        break;
                    case 503:
                        $errorType = '服务不可用';
                        break;
                    case 521:
                        $errorType = 'Cloudflare错误（源站拒绝连接）';
                        break;
                    case 522:
                        $errorType = '连接超时（源站无响应）';
                        break;
                    case 524:
                        $errorType = '超时（源站响应超时）';
                        break;
                    default:
                        $errorType = "HTTP {$statusCode} {$reasonPhrase}";
                }
                
                return "{$errorType} | 源: {$urlDisplay}";
            } else {
                // 没有响应（可能是连接失败、超时等）
                $message = $exception->getMessage();
                if (stripos($message, 'timeout') !== false || stripos($message, 'timed out') !== false) {
                    return "请求超时（30秒） | 源: {$urlDisplay}";
                } elseif (stripos($message, 'Connection') !== false || stripos($message, 'connect') !== false) {
                    return "连接失败（无法连接到服务器） | 源: {$urlDisplay}";
                } elseif (stripos($message, 'SSL') !== false || stripos($message, 'certificate') !== false) {
                    return "SSL证书错误 | 源: {$urlDisplay}";
                } else {
                    return "网络错误: " . mb_substr($message, 0, 100) . " | 源: {$urlDisplay}";
                }
            }
        } elseif ($exception instanceof \GuzzleHttp\Exception\ConnectException) {
            return "连接异常（无法建立连接） | 源: {$urlDisplay}";
        } elseif ($exception instanceof \GuzzleHttp\Exception\TransferException) {
            return "传输异常: " . mb_substr($exception->getMessage(), 0, 100) . " | 源: {$urlDisplay}";
        } else {
            $exceptionType = get_class($exception);
            $exceptionType = substr($exceptionType, strrpos($exceptionType, '\\') + 1);
            return "异常[{$exceptionType}]: " . mb_substr($exception->getMessage(), 0, 100) . " | 源: {$urlDisplay}";
        }
    }

    /**
     * 结果去重
     * @param array $results
     * @return array
     */
    protected static function deduplicateResults($results)
    {
        $seenBySourceId = [];
        $unique = [];

        foreach ($results as $result) {
            // 只去重：同一源的同一ID只保留一次
            // 不同播放源的相同视频应该保留，以便用户选择
            $sourceIdKey = ($result['source'] ?? '') . '_' . ($result['id'] ?? '');
            if (isset($seenBySourceId[$sourceIdKey])) {
                continue;
            }
            $seenBySourceId[$sourceIdKey] = true;
            
            $unique[] = $result;
        }

        return $unique;
    }

    /**
     * 获取详情
     * @param string $sourceKey 源标识
     * @param string $id 视频ID
     * @return array|null
     */
    public static function detail($sourceKey, $id)
    {
        // 生成缓存键
        $cacheKey = 'detail_' . md5($sourceKey . '_' . $id);
        
        // 尝试从缓存获取
        $cached = Cache::get($cacheKey);
        if ($cached !== false && is_array($cached)) {
            Log::info("从缓存获取详情: {$sourceKey}:{$id}");
            return $cached;
        }

        $config = ConfigService::getConfig();
        if (!$config || !is_array($config)) {
            Log::error("配置获取失败 [{$sourceKey}:{$id}]");
            return null;
        }
        
        $sources = $config['SourceConfig'] ?? [];
        
        if (empty($sources) || !is_array($sources)) {
            Log::error("播放源配置为空 [{$sourceKey}:{$id}]");
            return null;
        }
        
        $source = null;
        foreach ($sources as $s) {
            if (($s['key'] ?? '') === $sourceKey) {
                $source = $s;
                break;
            }
        }

        if (!$source || empty($source['api'])) {
            Log::warning("未找到播放源或API地址为空 [{$sourceKey}:{$id}]");
            return null;
        }

        $client = new Client([
            'timeout' => self::REQUEST_TIMEOUT,
            'headers' => [
                'User-Agent' => self::DEFAULT_USER_AGENT,
                'Accept' => 'application/json',
            ],
            'verify' => false,
        ]);

        try {
            $detailUrl = rtrim($source['api'], '/') . '/' . self::API_DETAIL_PATH . $id;
            $response = $client->get($detailUrl);
            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['list']) || !is_array($data['list']) || empty($data['list'])) {
                return null;
            }

            $item = $data['list'][0];
            $result = self::mapItemToResult($item, $sourceKey, $source['name'] ?? '');
            
            // 缓存结果
            if ($result) {
                Cache::set($cacheKey, $result, self::DETAIL_CACHE_TIME);
                Log::info("缓存详情: {$sourceKey}:{$id}");
            }
            
            return $result;

        } catch (\Exception $e) {
            Log::error("获取详情失败 [{$sourceKey}:{$id}]: " . $e->getMessage());
            return null;
        }
    }
}
