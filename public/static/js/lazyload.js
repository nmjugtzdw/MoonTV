/**
 * MoonTV LazyLoad with Concurrency Control
 * 防止 net::ERR_INSUFFICIENT_RESOURCES 错误
 */
class MoonLazyLoad {
    constructor(options = {}) {
        this.options = Object.assign({
            root: null,
            rootMargin: '200px', // 提前加载距离
            threshold: 0.01,
            concurrency: 6, // 同时加载的最大数量
            // 简单的灰色占位图 (3:4 比例)
            placeholder: 'data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%20300%20450%22%3E%3Crect%20width%3D%22100%25%22%20height%3D%22100%25%22%20fill%3D%22%23f3f4f6%22%2F%3E%3C%2Fsvg%3E',
            // 加载失败图片
            errorImage: 'data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%20300%20450%22%3E%3Crect%20width%3D%22100%25%22%20height%3D%22100%25%22%20fill%3D%22%23e5e7eb%22%2F%3E%3Ctext%20x%3D%2250%25%22%20y%3D%2250%25%22%20dy%3D%22.3em%22%20fill%3D%22%239ca3af%22%20font-size%3D%2224%22%20text-anchor%3D%22middle%22%20font-family%3D%22sans-serif%22%3E%E5%9B%BE%E7%89%87%E5%8A%A0%E8%BD%BD%E5%A4%B1%E8%B4%A5%3C%2Ftext%3E%3C%2Fsvg%3E'
        }, options);

        this.queue = []; // 等待加载的队列
        this.activeCount = 0; // 当前正在加载的数量
        this.observer = new IntersectionObserver(this.onIntersect.bind(this), {
            root: this.options.root,
            rootMargin: this.options.rootMargin,
            threshold: this.options.threshold
        });
    }

    // 观察图片元素
    observe(selectorOrElement) {
        if (typeof selectorOrElement === 'string') {
            document.querySelectorAll(selectorOrElement).forEach(el => this.observeElement(el));
        } else if (selectorOrElement instanceof HTMLElement) {
            this.observeElement(selectorOrElement);
        } else if (selectorOrElement instanceof NodeList || Array.isArray(selectorOrElement)) {
            selectorOrElement.forEach(el => this.observeElement(el));
        }
    }

    observeElement(el) {
        if (!el.dataset.src || el.dataset.loaded === 'true') return;
        
        // 设置占位图
        if (!el.getAttribute('src')) {
            el.setAttribute('src', this.options.placeholder);
        }
        
        // 只有当元素可见时才加入去重队列
        this.observer.observe(el);
    }

    onIntersect(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                
                // 停止观察
                this.observer.unobserve(img);

                // 加入队列
                this.enqueue(img);
            }
        });
    }

    enqueue(img) {
        // 如果已经在加载或是已加载完成，忽略
        if (img.dataset.loading === 'true' || img.dataset.loaded === 'true') return;
        
        this.queue.push(img);
        this.processQueue();
    }

    processQueue() {
        if (this.activeCount >= this.options.concurrency || this.queue.length === 0) {
            return;
        }

        const img = this.queue.shift();
        this.activeCount++;
        this.loadImage(img);
        
        // 尝试加载下一个
        this.processQueue();
    }

    loadImage(img) {
        img.dataset.loading = 'true';
        const src = img.dataset.src;
        
        const tempImage = new Image();
        
        tempImage.onload = () => {
            if (img.tagName === 'IMG') {
                img.src = src;
            } else {
                img.style.backgroundImage = `url(${src})`;
            }
            img.dataset.loaded = 'true';
            img.removeAttribute('data-loading');
            img.classList.remove('opacity-0'); // 如果有淡入效果
            this.activeCount--;
            this.processQueue();
        };

        tempImage.onerror = () => {
            console.warn('Image load failed:', src);
            // 这里可以尝试使用代理或其他备用源
            if (img.dataset.retry && parseInt(img.dataset.retry) < 2) {
                 // 简单的重试逻辑，可以配合 image-proxy
                 let retries = parseInt(img.dataset.retry) || 0;
                 img.dataset.retry = retries + 1;
                 // 重新放回队列尾部
                 this.activeCount--;
                 this.queue.push(img); 
            } else {
                if (this.options.errorImage && img.tagName === 'IMG') {
                    img.src = this.options.errorImage;
                }
                img.dataset.error = 'true';
                img.removeAttribute('data-loading');
                this.activeCount--;
            }
            this.processQueue();
        };

        tempImage.src = src;
    }

    // 刷新 DOM 中的所有图片（用于动态插入内容后）
    refresh() {
        this.observe('img[data-src]:not([data-loaded="true"])');
    }
}

// 导出全局实例
window.MoonLazy = new MoonLazyLoad();

// 自动初始化
document.addEventListener('DOMContentLoaded', () => {
    window.MoonLazy.refresh();
});