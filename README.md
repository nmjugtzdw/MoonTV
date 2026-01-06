# PHP-MoonTV

基于 ThinkPHP 6 重构的 MoonTV 后端。

## 环境要求
- PHP >= 7.4
- MySQL >= 5.6
- Nginx / Apache
- Composer

## 安装步骤

1. **安装依赖**
   ```bash
   cd php-moontv
   composer install
   ```

2. **数据库配置**
   - 导入 `scripts/` 目录下的 SQL 文件到 MySQL 数据库（如果尚未初始化）。
   - 确保 `users`, `admin_config`, `vip_packages`, `orders`, `redemption_codes` 等表已创建。
   - 修改 `config/database.php`，或者更推荐使用环境变量配置。

3. **配置 Nginx**
   参考 `nginx.conf.example` 将网站根目录指向 `public` 目录，并配置 URL 重写规则。

4. **目录权限**
   确保 `runtime` 目录有写入权限。
   ```bash
   chmod -R 777 runtime
   ```

## 核心功能
- **聚合搜索**: 并发请求多个 CMS 源，快速响应。
- **用户系统**: 注册、登录、JWT 认证。
- **VIP 系统**: 易支付对接、卡密兑换。
- **数据管理**: 播放记录、收藏、搜索历史云同步。
- **TVBox**: 提供标准 TVBox 订阅接口。

## API 文档
- `GET /api/search?q=keyword&sources=qq,iqiyi`: 聚合搜索
- `GET /api/detail?source=qq&id=123`: 视频详情
- `POST /api/login`: 用户登录
- `POST /api/vip/order/create`: 创建支付订单
- `GET /api/tvbox/config`: TVBox 订阅地址

## 注意事项
- 本项目依赖 `guzzlehttp/guzzle` 进行并发请求，请确保服务器网络正常。
- 生产环境请修改 `app/middleware/AuthCheck.php` 中的 JWT 密钥。