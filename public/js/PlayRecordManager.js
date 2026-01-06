/**
 * 播放记录管理器
 * 
 * 功能说明：
 * 1. 游客：播放记录只保存在localStorage，不调用后端API
 * 2. 会员/管理员：播放记录保存到数据库，同时缓存到localStorage
 * 3. 登录后自动从数据库同步播放记录
 * 4. 删除记录时根据用户类型决定是否调用后端API
 */
class PlayRecordManager {
    constructor() {
        this.STORAGE_KEY = 'play_records';
        this.API_BASE = '/api/playrecord';
        this.isLoggedIn = false;
        this.authToken = null;
        
        // 初始化时检查登录状态
        this.checkLoginStatus();
    }

    /**
     * 检查用户登录状态
     */
    checkLoginStatus() {
        // 从localStorage或Cookie获取token
        this.authToken = localStorage.getItem('auth_token') || this.getCookie('auth_token');
        this.isLoggedIn = !!this.authToken;
        
        // 如果已登录，从数据库同步播放记录
        if (this.isLoggedIn) {
            this.syncFromServer();
        }
    }

    /**
     * 获取Cookie值
     */
    getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return parts.pop().split(';').shift();
        return null;
    }

    /**
     * 获取所有播放记录
     * @returns {Object} 播放记录对象，格式：{ "source+id": {...}, ... }
     */
    async getRecords() {
        if (this.isLoggedIn) {
            // 已登录：优先从localStorage获取（快速），后台同步数据库
            const localRecords = this.getLocalRecords();
            
            // 异步同步服务器数据（不阻塞UI）
            this.syncFromServer().catch(err => {
                console.warn('同步播放记录失败:', err);
            });
            
            return localRecords;
        } else {
            // 游客：只从localStorage获取
            return this.getLocalRecords();
        }
    }

    /**
     * 从localStorage获取播放记录
     */
    getLocalRecords() {
        try {
            const records = localStorage.getItem(this.STORAGE_KEY);
            return records ? JSON.parse(records) : {};
        } catch (error) {
            console.error('读取本地播放记录失败:', error);
            return {};
        }
    }

    /**
     * 保存播放记录到localStorage
     */
    saveLocalRecords(records) {
        try {
            localStorage.setItem(this.STORAGE_KEY, JSON.stringify(records));
        } catch (error) {
            console.error('保存本地播放记录失败:', error);
        }
    }

    /**
     * 从服务器同步播放记录（仅限已登录用户）
     */
    async syncFromServer() {
        if (!this.isLoggedIn) {
            return;
        }

        try {
            const response = await fetch(this.API_BASE, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'Authorization': `Bearer ${this.authToken}`
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const result = await response.json();
            if (result.code === 200 && result.data) {
                // 合并服务器数据到本地（服务器数据优先）
                const localRecords = this.getLocalRecords();
                const mergedRecords = { ...localRecords, ...result.data };
                this.saveLocalRecords(mergedRecords);
                
                console.log('✓ 播放记录已同步', Object.keys(result.data).length, '条');
                return mergedRecords;
            }
        } catch (error) {
            console.error('从服务器同步播放记录失败:', error);
            // 同步失败不影响本地记录的使用
        }
    }

    /**
     * 保存播放记录
     * @param {string} source - 来源（如 'douban'）
     * @param {string} id - 影片ID
     * @param {Object} record - 播放记录数据
     */
    async saveRecord(source, id, record) {
        const key = `${source}+${id}`;
        
        // 1. 立即保存到localStorage（游客和会员都需要）
        const records = this.getLocalRecords();
        records[key] = {
            ...record,
            updatedAt: new Date().toISOString()
        };
        this.saveLocalRecords(records);
        
        // 2. 如果是会员/管理员，同时保存到数据库
        if (this.isLoggedIn) {
            try {
                const response = await fetch(this.API_BASE, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'Authorization': `Bearer ${this.authToken}`
                    },
                    body: JSON.stringify({
                        source,
                        id,
                        record: records[key]
                    })
                });

                if (!response.ok) {
                    console.warn('保存播放记录到服务器失败:', response.status);
                }
            } catch (error) {
                console.error('保存播放记录到服务器失败:', error);
                // 保存到服务器失败不影响本地记录
            }
        }
        
        return true;
    }

    /**
     * 删除播放记录
     * @param {string} source - 来源
     * @param {string} id - 影片ID
     */
    async deleteRecord(source, id) {
        const key = `${source}+${id}`;
        
        // 1. 立即从localStorage删除（游客和会员都需要）
        const records = this.getLocalRecords();
        delete records[key];
        this.saveLocalRecords(records);
        
        // 2. 如果是会员/管理员，同时从数据库删除
        if (this.isLoggedIn) {
            try {
                const response = await fetch(this.API_BASE, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'Authorization': `Bearer ${this.authToken}`
                    },
                    body: JSON.stringify({ source, id })
                });

                if (!response.ok) {
                    console.warn('从服务器删除播放记录失败:', response.status);
                }
            } catch (error) {
                console.error('从服务器删除播放记录失败:', error);
                // 删除服务器记录失败不影响本地操作
            }
        }
        
        return true;
    }

    /**
     * 清空所有播放记录
     */
    async clearAllRecords() {
        // 1. 清空localStorage
        localStorage.removeItem(this.STORAGE_KEY);
        
        // 2. 如果是会员/管理员，同时清空数据库
        if (this.isLoggedIn) {
            try {
                const response = await fetch(this.API_BASE, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'Authorization': `Bearer ${this.authToken}`
                    },
                    body: JSON.stringify({ 
                        source: 'all', 
                        id: 'all' 
                    })
                });

                if (!response.ok) {
                    console.warn('清空服务器播放记录失败:', response.status);
                }
            } catch (error) {
                console.error('清空服务器播放记录失败:', error);
            }
        }
        
        return true;
    }

    /**
     * 获取单条播放记录
     * @param {string} source - 来源
     * @param {string} id - 影片ID
     */
    getRecord(source, id) {
        const records = this.getLocalRecords();
        const key = `${source}+${id}`;
        return records[key] || null;
    }

    /**
     * 检查是否有播放记录
     * @param {string} source - 来源
     * @param {string} id - 影片ID
     */
    hasRecord(source, id) {
        return !!this.getRecord(source, id);
    }

    /**
     * 获取播放记录数量
     */
    getRecordCount() {
        const records = this.getLocalRecords();
        return Object.keys(records).length;
    }

    /**
     * 获取按时间排序的播放记录列表
     * @param {number} limit - 限制数量（可选）
     */
    getRecordList(limit = null) {
        const records = this.getLocalRecords();
        const list = Object.entries(records).map(([key, record]) => {
            const [source, id] = key.split('+');
            return {
                source,
                id,
                key,
                ...record
            };
        });

        // 按更新时间倒序排序
        list.sort((a, b) => {
            const timeA = new Date(a.updatedAt || 0).getTime();
            const timeB = new Date(b.updatedAt || 0).getTime();
            return timeB - timeA;
        });

        return limit ? list.slice(0, limit) : list;
    }

    /**
     * 用户登录后调用此方法
     * @param {string} token - 认证token
     */
    async onUserLogin(token) {
        this.authToken = token;
        this.isLoggedIn = true;
        
        // 登录后立即同步服务器数据
        await this.syncFromServer();
        
        // 将本地游客时期的播放记录上传到服务器
        await this.uploadLocalRecords();
    }

    /**
     * 将本地播放记录上传到服务器（登录后调用）
     */
    async uploadLocalRecords() {
        if (!this.isLoggedIn) {
            return;
        }

        const records = this.getLocalRecords();
        const entries = Object.entries(records);
        
        if (entries.length === 0) {
            return;
        }

        console.log('正在上传本地播放记录到服务器...', entries.length, '条');
        
        // 批量上传（逐条上传，避免并发过多）
        for (const [key, record] of entries) {
            const [source, id] = key.split('+');
            try {
                await this.saveRecord(source, id, record);
                // 添加小延迟，避免请求过快
                await new Promise(resolve => setTimeout(resolve, 100));
            } catch (error) {
                console.error(`上传记录失败 ${key}:`, error);
            }
        }
        
        console.log('✓ 本地播放记录已上传到服务器');
    }

    /**
     * 用户退出登录后调用此方法
     */
    onUserLogout() {
        this.authToken = null;
        this.isLoggedIn = false;
        
        // 可选：清空本地记录（根据需求决定）
        // localStorage.removeItem(this.STORAGE_KEY);
    }
}

// 创建全局实例
window.playRecordManager = new PlayRecordManager();

// 导出（如果使用模块化）
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PlayRecordManager;
}