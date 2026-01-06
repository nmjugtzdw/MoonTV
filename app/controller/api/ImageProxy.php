<?php

namespace app\controller\api;

use app\BaseController;
use GuzzleHttp\Client;
use think\facade\Log;

/**
 * 图片代理和缓存控制器
 * 用于缓存首页图片，避免重复加载失败
 */
class ImageProxy extends BaseController
{
    // 缓存目录
    const CACHE_DIR = 'cache/images/';
    // 缓存有效期（7天）
    const CACHE_EXPIRE = 604800;
    // 请求超时时间（降低到3秒，避免长时间阻塞）
    const REQUEST_TIMEOUT = 3;

    /**
     * 获取并缓存图片
     */
    public function index()
    {
        $url = input('get.url');
        $id = input('get.id', ''); // 内容ID，用于缓存键
        
        if (empty($url)) {
            return $this->error('缺少图片URL参数');
        }

        // 解码URL
        $url = urldecode($url);
        
        // 跳过非图片URL或data URI
        if (strpos($url, 'data:') === 0) {
            return $this->error('不支持data URI');
        }

        // 生成缓存文件名
        $cacheKey = $this->generateCacheKey($url, $id);
        $cachePath = $this->getCachePath($cacheKey);
        $cacheDir = dirname($cachePath);

        // 检查缓存是否存在且未过期
        if (file_exists($cachePath) && (time() - filemtime($cachePath)) < self::CACHE_EXPIRE) {
            // 返回缓存的图片
            return $this->outputImage($cachePath);
        }

        // 如果缓存目录不存在，创建它
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        // 尝试从原始URL下载图片
        try {
            $headers = [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'Accept' => 'image/webp,image/apng,image/*,*/*;q=0.8',
            ];

            // 针对豆瓣图片设置Referer
            if (strpos($url, 'douban') !== false) {
                $headers['Referer'] = 'https://movie.douban.com/';
            }

            $client = new Client([
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => $headers,
                'verify' => false,
                'http_errors' => false,
            ]);

            $response = $client->get($url);
            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                // 如果原始URL失败，尝试替换为img3
                if (strpos($url, 'doubanio.com') !== false) {
                    $alternativeUrl = preg_replace('/img\d+\.doubanio\.com/', 'img3.doubanio.com', $url);
                    if ($alternativeUrl !== $url) {
                        try {
                            $response = $client->get($alternativeUrl);
                            if ($response->getStatusCode() === 200) {
                                $url = $alternativeUrl; // 更新URL
                            }
                        } catch (\Exception $e) {
                            Log::warning("图片代理：备用URL也失败: {$alternativeUrl}, 错误: " . $e->getMessage());
                        }
                    }
                }

                // 如果还是失败，返回错误
                if ($response->getStatusCode() !== 200) {
                    return $this->error('图片加载失败: HTTP ' . $response->getStatusCode());
                }
            }

            $imageData = $response->getBody()->getContents();
            $contentType = $response->getHeaderLine('Content-Type');

            // 验证是否为图片
            if (empty($contentType) || strpos($contentType, 'image/') === false) {
                // 尝试从URL推断类型
                $ext = $this->getImageExtension($url);
                if ($ext) {
                    $contentType = 'image/' . $ext;
                } else {
                    return $this->error('不是有效的图片');
                }
            }

            // 保存到缓存
            @file_put_contents($cachePath, $imageData);
            
            // 设置文件权限
            @chmod($cachePath, 0644);

            // 返回图片
            return $this->outputImageData($imageData, $contentType);

        } catch (\Exception $e) {
            Log::error("图片代理失败: URL={$url}, 错误: " . $e->getMessage());
            
            // 如果缓存存在（即使过期），返回缓存
            if (file_exists($cachePath)) {
                return $this->outputImage($cachePath);
            }

            return $this->error('图片加载失败: ' . $e->getMessage());
        }
    }

    /**
     * 生成缓存键
     */
    private function generateCacheKey($url, $id = '')
    {
        // 使用URL的MD5作为基础，如果提供了ID，也包含在键中
        $key = md5($url . ($id ? '_' . $id : ''));
        
        // 从URL提取扩展名
        $ext = $this->getImageExtension($url);
        if (!$ext) {
            $ext = 'jpg'; // 默认扩展名
        }
        
        return $key . '.' . $ext;
    }

    /**
     * 获取缓存文件路径
     */
    private function getCachePath($cacheKey)
    {
        $basePath = app()->getRootPath() . 'public/' . self::CACHE_DIR;
        return $basePath . $cacheKey;
    }

    /**
     * 从URL提取图片扩展名
     */
    private function getImageExtension($url)
    {
        // 移除查询参数
        $url = parse_url($url, PHP_URL_PATH);
        
        // 提取扩展名
        $ext = pathinfo($url, PATHINFO_EXTENSION);
        
        // 标准化扩展名
        $ext = strtolower($ext);
        
        // 只允许常见的图片格式
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        if (in_array($ext, $allowed)) {
            return $ext === 'jpeg' ? 'jpg' : $ext;
        }
        
        return 'jpg'; // 默认
    }

    /**
     * 输出缓存的图片文件
     */
    private function outputImage($filePath)
    {
        if (!file_exists($filePath)) {
            return $this->error('缓存文件不存在');
        }

        $contentType = $this->getContentTypeFromFile($filePath);
        $imageData = file_get_contents($filePath);

        return $this->outputImageData($imageData, $contentType);
    }

    /**
     * 输出图片数据
     */
    private function outputImageData($imageData, $contentType)
    {
        // 设置响应头
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . strlen($imageData));
        header('Cache-Control: public, max-age=' . self::CACHE_EXPIRE);
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + self::CACHE_EXPIRE) . ' GMT');
        
        // 输出图片数据
        echo $imageData;
        exit;
    }

    /**
     * 从文件路径获取Content-Type
     */
    private function getContentTypeFromFile($filePath)
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $types = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
        ];
        
        return $types[$ext] ?? 'image/jpeg';
    }

    /**
     * 返回错误响应
     */
    private function error($message)
    {
        // 返回一个1x1的透明PNG图片
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        
        header('Content-Type: image/png');
        header('Content-Length: ' . strlen($png));
        echo $png;
        exit;
    }

    /**
     * 清除首页图片缓存
     * 当首页数据更新时调用
     */
    public function clearHomepageCache()
    {
        $cacheDir = app()->getRootPath() . 'public/' . self::CACHE_DIR;
        
        if (!is_dir($cacheDir)) {
            return json(['code' => 200, 'msg' => '缓存目录不存在']);
        }

        $files = glob($cacheDir . '*');
        $count = 0;
        
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
                $count++;
            }
        }

        return json(['code' => 200, 'msg' => "已清除 {$count} 个缓存文件"]);
    }
}

