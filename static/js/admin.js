// 页面加载完成后初始化
document.addEventListener('DOMContentLoaded', () => {
    initialize();
    setupEventHandlers();
});

// 缓存DOM元素
const DOM = {
    gallery: document.getElementById('gallery'),
    pagination: document.getElementById('pagination'),
    pageDisplay: document.getElementById('current-total-pages'),
    scrollTopBtn: document.querySelector('#scroll-to-top'),
    rightside: document.querySelector('.rightside'),
    categoryLink: document.querySelector('.category-link'),
    categoryModal: document.getElementById('category-modal'),
    categoryList: document.getElementById('category-list')
};

// 全局状态
let currentCategory = '';

// 切换分页 / 分类 / 删除后，跨页选择的缓存必须失效，否则「全选」会拿到旧数据
function invalidateAllItemsCache() {
    if (window.__multiSelectCacheReset) {
        window.__multiSelectCacheReset();
    }
}

// 注册缓存重置回调（由 setupMultiSelect 注入）
window.__multiSelectCacheReset = null;

// 初始化所有必要的组件和功能
function initialize() {
    initComponents();
    setupPageInput();
}

// 设置所有事件处理器
function setupEventHandlers() {
    setupCopyAndDeleteHandlers();
    setupScrollToTop();
    setupMultiSelect();
    setupPagination();
    setupDocumentClickHandler();
    setupCategoryManagement();
    setupBackup();
}

// API工具类
const API = {
    // 发送删除请求到服务器
    async sendDeleteRequest(id, path) {
        try {
            const response = await fetch('/config/delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `id=${encodeURIComponent(id)}&path=${encodeURIComponent(path)}`
            });
            const data = await response.json();
            return data.result === 'success';
        } catch (error) {
            console.error('Delete error:', error);
            return false;
        }
    },

    // 批量删除图片
    async deleteImages(ids, paths) {
        const errors = [];
        
        // 处理单个删除的情况
        if (!Array.isArray(ids)) {
            ids = [ids];
            paths = [paths];
        }
        
        // 执行删除操作
        for (let i = 0; i < ids.length; i++) {
            try {
                const success = await this.sendDeleteRequest(ids[i], paths[i]);
                if (!success) {
                    errors.push(ids[i]);
                }
            } catch (error) {
                errors.push(ids[i]);
                console.error(`Failed to delete image ${ids[i]}:`, error);
            }
        }

        // 刷新页面内容
        const currentPage = parseInt(document.getElementById('current-total-pages').textContent.split('/')[0]);
        const pageData = await this.loadPage(currentPage, currentCategory);
        
        if (pageData.success) {
            updatePageContent(pageData);
        }
        
        // 显示结果通知
        if (errors.length > 0) {
            UI.showNotification(`删除失败 ${errors.length} 张图片`, 'error');
            throw new Error(`Failed to delete ${errors.length} images`);
        }
        
        UI.showNotification(ids.length > 1 ? `成功删除 ${ids.length} 张图片` : '删除成功');
        return true;
    },

    // 加载指定页码的数据
    async loadPage(page, category = '') {
        try {
            let url = `/admin/index.php?page=${page}`;
            if (category) {
                url += `&category=${encodeURIComponent(category)}`;
            }
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();
            return data.success ? data : Promise.reject(data.message || '加载失败');
        } catch (error) {
            UI.showNotification('加载失败', 'error');
            throw error;
        }
    },

    // 获取所有分类
    async getCategories() {
        try {
            const response = await fetch('/admin/index.php?action=get_categories', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();
            return data.success ? data.categories : [];
        } catch (error) {
            console.error('获取分类失败:', error);
            return [];
        }
    }
};

// UI工具类 - 处理界面交互和显示
const UI = {
    // 显示通知消息
    showNotification(message, type = 'success') {
        const notification = document.createElement('div');
        notification.className = `msg ${type === 'error' ? 'msg-red' : 'msg-green'}`;
        notification.textContent = message;
        document.body.appendChild(notification);
        setTimeout(() => {
            notification.classList.add('msg-right');
            setTimeout(() => notification.remove(), 800);
        }, 1500);
    },

    // 复制内容到剪贴板
    async copyToClipboard(text) {
        try {
            // 优先使用现代API
            await navigator.clipboard.writeText(text);
            this.showNotification('已复制到剪贴板');
        } catch (err) {
            // 降级使用传统方法
            this.fallbackCopy(text);
        }
    },

    // 降级的复制方法
    fallbackCopy(text) {
        try {
            const tempInput = document.createElement('input');
            tempInput.value = text;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand('copy');
            document.body.removeChild(tempInput);
            this.showNotification('已复制到剪贴板');
        } catch (err) {
            console.error('复制到剪贴板失败: ', err);
            this.showNotification('复制到剪贴板失败', 'error');
        }
    },

    // 创建确认对话框
    createConfirmDialog(message, onConfirm) {
        const existingDialog = document.querySelector('.custom-confirm');
        if (existingDialog) {
            existingDialog.remove();
        }

        const confirmBox = document.createElement('div');
        confirmBox.className = 'custom-confirm';
        confirmBox.innerHTML = `
            <div class="confirm-message">${message}</div>
            <div class="confirm-buttons">
                <button id="confirm-delete">确认</button>
                <button id="cancel-delete">取消</button>
            </div>
        `;
        document.body.appendChild(confirmBox);
        setTimeout(() => confirmBox.classList.add('visible'), 0);

        const handleClose = (callback) => {
            confirmBox.classList.remove('visible');
            setTimeout(() => {
                confirmBox.remove();
                callback?.();
            }, 300);
        };

        confirmBox.querySelector('#confirm-delete').onclick = () => handleClose(onConfirm);
        confirmBox.querySelector('#cancel-delete').onclick = () => handleClose();
    }
};

// 页面输入控制
function setupPageInput() {
    const currentTotalPages = document.getElementById('current-total-pages');
    const input = document.createElement('input');
    input.type = 'number';
    input.min = '1';
    input.classList.add('page-input');
    input.style.display = 'none';
    
    currentTotalPages.parentNode.appendChild(input);

    // 切换输入框显示状态
    const toggleInput = (show) => {
        currentTotalPages.style.display = show ? 'none' : 'inline-block';
        input.style.display = show ? 'inline-block' : 'none';
        if (show) {
            input.focus();
        } else {
            input.value = '';
        }
    };

    // 处理页码输入
    const handlePageInput = (e) => {
        if (e.key === 'Enter') {
            const page = parseInt(input.value, 10);
            const totalPages = parseInt(currentTotalPages.textContent.split('/')[1], 10);
            
            if (page && page >= 1 && page <= totalPages) {
                window.location.href = `?page=${page}`;
                toggleInput(false);
            } else {
                UI.showNotification('请输入有效的页码', 'error');
                input.value = '';
            }
        }
    };

    currentTotalPages.addEventListener('click', () => toggleInput(true));
    input.addEventListener('keypress', handlePageInput);
    document.addEventListener('click', e => {
        if (!input.contains(e.target) && e.target !== currentTotalPages) {
            toggleInput(false);
        }
    });
}

// 事件处理器
function setupCopyAndDeleteHandlers() {
    document.addEventListener('click', async event => {
        const btn = event.target.closest('.copy-btn, .delete-btn, .download-btn');
        if (!btn) return;
        
        if (btn.classList.contains('copy-btn')) {
            await UI.copyToClipboard(btn.dataset.url);
        } else if (btn.classList.contains('download-btn')) {
            downloadImage(btn.dataset.url);
        } else if (!document.querySelector('.multi-select-mode')) {
            UI.createConfirmDialog('确定删除这张图片吗？', 
                async () => {
                    try {
                        await API.deleteImages(btn.dataset.id, btn.dataset.path);
                    } catch (error) {
                        console.error('Delete error:', error);
                    }
                }
            );
        }
    });
}

// 下载图片：统一走服务端中转，跨域图片也能下载
// 必须等响应真的拿到文件再提示成功，否则出错时用户只看到"已开始"却没文件
async function downloadImage(url, filename) {
    const name = filename || getDownloadFilename(url);
    try {
        await downloadViaServer(url, name);
        UI.showNotification('下载已开始', 'success');
    } catch (error) {
        console.error('服务端下载失败:', error);
        UI.showNotification('下载失败：' + (error.message || '未知错误'), 'error');
    }
}

// 通过服务端中转下载（相对路径，装在子目录也能用）
async function downloadViaServer(url, filename) {
    const proxyUrl = 'download.php?url=' + encodeURIComponent(url);
    const response = await fetch(proxyUrl, { credentials: 'same-origin' });
    if (!response.ok) {
        const text = (await response.text()).trim();
        throw new Error(text || ('HTTP ' + response.status));
    }
    const blob = await response.blob();
    if (!blob.size) {
        throw new Error('服务端返回空文件');
    }
    const objectUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = getHeaderFilename(response) || filename;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(objectUrl), 30000);
}

// 从响应头 Content-Disposition 取服务端给的文件名，优先 UTF-8 版本
function getHeaderFilename(response) {
    const cd = response.headers.get('Content-Disposition') || '';
    const utf8 = /filename\*=UTF-8''([^;]+)/i.exec(cd);
    if (utf8) {
        try {
            return decodeURIComponent(utf8[1]);
        } catch (e) {
            return utf8[1];
        }
    }
    const plain = /filename="?([^";]+)"?/i.exec(cd);
    return plain ? plain[1] : '';
}

// 从 URL 推断下载文件名
function getDownloadFilename(url) {
    const rawName = (url.split('/').pop() || '').split('?')[0];
    let name = 'image.jpg';
    try {
        name = decodeURIComponent(rawName) || name;
    } catch (e) {
        name = rawName || name;
    }
    return name;
}

// 回退下载方式（同源或允许直接访问时有效）
function fallbackDownload(url, filename) {
    const link = document.createElement('a');
    link.href = url;
    link.download = filename || getDownloadFilename(url);
    link.rel = 'noopener';
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    setTimeout(() => link.remove(), 1000);
}

function setupScrollToTop() {
    let ticking = false;

    window.addEventListener('scroll', () => {
        if (!ticking) {
            window.requestAnimationFrame(() => {
                const shouldShow = window.scrollY > 100;
                DOM.scrollTopBtn.classList.toggle('visible', shouldShow);
                DOM.rightside.classList.toggle('shifted', shouldShow);
                ticking = false;
            });
            ticking = true;
        }
    });

    DOM.scrollTopBtn.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}

function setupDocumentClickHandler() {
    document.addEventListener('click', e => {
        const input = document.querySelector('.page-input');
        const currentTotalPages = document.getElementById('current-total-pages');
        if (input && currentTotalPages && !input.contains(e.target) && e.target !== currentTotalPages) {
            hidePageInput(input, currentTotalPages);
        }
    });
}

// 多选功能
function setupMultiSelect() {
    // 多选状态管理
    // selectedItems 存的是「当前已选中的图片 id 字符串」
    // allItemsCache 用来缓存「全部图片」的 id -> {url, path}，实现跨页选择
    const state = {
        isMultiSelectMode: false,
        selectedItems: new Set(),
        allItemsCache: null // null 表示尚未拉取
    };

    const toolbar = createMultiSelectToolbar();
        const gallery = document.getElementById('gallery');
        const multiSelectBtn = document.querySelector('.select-link');

        // 挂上缓存重置回调：分页/切换分类/删除后由外部调用，
        // 保证下次「全选」重新拉取最新的全部图片
        window.__multiSelectCacheReset = () => {
            state.allItemsCache = null;
        };
        
        // 创建工具栏
        function createMultiSelectToolbar() {
            const toolbar = document.createElement('div');
            toolbar.className = 'multi-select-toolbar';
            toolbar.innerHTML = `
                <span class="selected-count">已选择 0</span>
                <button class="toggle-select-all" data-state="select">全选</button>
                <button class="download-selected">下载所选</button>
                <button class="delete-selected">删除所选</button>
                <button class="cancel-select">取消选择</button>
            `;
            
            // 绑定工具栏按钮事件
            toolbar.querySelector('.toggle-select-all').onclick = handleToggleSelectAll;
            toolbar.querySelector('.download-selected').onclick = handleDownloadSelected;
            toolbar.querySelector('.delete-selected').onclick = handleDeleteSelected;
            toolbar.querySelector('.cancel-select').onclick = toggleMultiSelectMode;
            
            document.body.appendChild(toolbar);
            return toolbar;
        }
    
    // 切换多选模式
    function toggleMultiSelectMode() {
        state.isMultiSelectMode = !state.isMultiSelectMode;
        [gallery, toolbar, multiSelectBtn].forEach(el => 
            el.classList.toggle(el === gallery ? 'multi-select-mode' : 'show'));
        if (!state.isMultiSelectMode) {
            clearSelection();
        }
    }
    
    function clearSelection() {
        state.selectedItems.clear();
        document.querySelectorAll('.gallery-item.selected')
            .forEach(item => item.classList.remove('selected'));
        updateSelectedCount();
        
        // 更新全选/取消全选按钮状态
        const toggleButton = document.querySelector('.toggle-select-all');
        if (toggleButton) {
            toggleButton.dataset.state = 'select';
            toggleButton.textContent = '全选';
        }
    }

    // 拉取「全部图片」（跨页，跟随当前分类筛选），并缓存
    async function fetchAllItems() {
        if (state.allItemsCache) return state.allItemsCache;
        try {
            let url = '/admin/index.php?action=get_all_images';
            if (currentCategory) {
                url += `&category=${encodeURIComponent(currentCategory)}`;
            }
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();
            if (!data.success) throw new Error(data.message || '获取全部图片失败');
            state.allItemsCache = data.images || [];
            return state.allItemsCache;
        } catch (error) {
            console.error('获取全部图片失败:', error);
            UI.showNotification('获取全部图片失败', 'error');
            state.allItemsCache = null;
            throw error;
        }
    }
    
    function updateSelectedCount() {
        toolbar.querySelector('.selected-count').textContent = 
            `已选择 ${state.selectedItems.size}`;
    }

    function handleItemSelection(e) {
        if (!state.isMultiSelectMode) return;
        
        const galleryItem = e.target.closest('.gallery-item');
        if (!galleryItem) return;
        
        e.preventDefault();
        e.stopPropagation();
        
        const itemId = galleryItem.id.replace('image-', '');
        
        if (state.selectedItems.has(itemId)) {
            state.selectedItems.delete(itemId);
            galleryItem.classList.remove('selected');
        } else {
            state.selectedItems.add(itemId);
            galleryItem.classList.add('selected');
        }
        
        updateSelectedCount();
    }

    async function handleToggleSelectAll() {
        const button = this;
        const currentState = button.dataset.state;
        
        if (currentState === 'select') {
            // 全选「全部图片」（跨页），而不是只选当前页
            let allItems;
            try {
                allItems = await fetchAllItems();
            } catch (error) {
                return;
            }

            allItems.forEach(item => {
                const id = String(item.id);
                state.selectedItems.add(id);
                // 当前页上可见的，同步加上高亮 class
                const el = document.getElementById('image-' + id);
                if (el) el.classList.add('selected');
            });

            updateSelectedCount();
            const extra = allItems.length - document.querySelectorAll('.gallery-item').length;
            UI.showNotification(
                extra > 0
                    ? `已全选全部 ${allItems.length} 张图片（含其他页 ${extra} 张）`
                    : `已全选 ${allItems.length} 张图片`,
                'success'
            );
            
            // 切换按钮状态
            button.dataset.state = 'deselect';
            button.textContent = '取消全选';
        } else {
            // 执行取消全选操作
            clearSelection();
            UI.showNotification('已取消全选', 'success');
            
            // 切换按钮状态
            button.dataset.state = 'select';
            button.textContent = '全选';
        }
    }

    // 逐个下载，带间隔，避免浏览器把连续下载当成弹窗批量拦截而丢文件
    async function downloadMultiple(urls) {
        const total = urls.length;
        let ok = 0;
        for (let i = 0; i < total; i++) {
            const done = await downloadSingle(urls[i], i);
            if (done) ok++;
            // 每张之间留出间隔：浏览器对连续下载有节流，间隔太短会漏
            const gap = Math.min(600 + Math.floor(total / 20) * 100, 1500);
            await sleep(gap);
        }
        return { total, ok, failed: total - ok };
    }

    function sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    // 单张下载：走服务端中转，跨域也能下
    async function downloadSingle(url, index = 0) {
        const filename = getDownloadFilename(url);
        try {
            await downloadViaServer(url, filename);
            return true;
        } catch (error) {
            console.warn('第 ' + (index + 1) + ' 张下载失败，回退直链:', url, error);
            fallbackDownload(url, filename);
            return false;
        }
    }

    async function handleDownloadSelected() {
        if (state.selectedItems.size === 0) {
            UI.showNotification('请先选择要下载的图片', 'error');
            return;
        }
        
        const ids = Array.from(state.selectedItems);

        // 优先从「全部图片」缓存里取 URL（跨页选择时必须），
        // 缓存拿不到再退回当前页 DOM。
        let urlMap = {};
        if (state.allItemsCache) {
            state.allItemsCache.forEach(item => { urlMap[String(item.id)] = item.url; });
        }

        const urls = ids.map(id => {
            if (urlMap[id]) return urlMap[id];
            const element = document.getElementById('image-' + id);
            return element ? element.querySelector('.download-btn').dataset.url : null;
        }).filter(url => url !== null);

        if (urls.length === 0) {
            UI.showNotification('未找到可下载的图片', 'error');
            return;
        }

        UI.showNotification(`开始下载 ${urls.length} 张图片，请勿关闭页面`, 'success');
        const result = await downloadMultiple(urls);
        // 一次性下载大量文件时，浏览器可能弹出「是否允许多文件下载」的提示，
        // 需要点允许，否则后面的会被拦截（这是浏览器的安全限制，不是程序问题）
        if (result.failed > 0) {
            UI.showNotification(
                `下载完成：成功 ${result.ok} 张，失败 ${result.failed} 张`,
                'error'
            );
        } else {
            UI.showNotification(
                urls.length > 10
                    ? `已发起 ${urls.length} 张下载，若浏览器提示多文件下载请点「允许」`
                    : `已发起 ${urls.length} 张图片的下载`,
                'success'
            );
        }
    }

    async function handleDeleteSelected() {
        if (state.selectedItems.size === 0) {
            UI.showNotification('请先选择要删除的图片', 'error');
            return;
        }
        
        UI.createConfirmDialog(
            `确定删除选中的 ${state.selectedItems.size} 张图片吗？`,
            async () => {
                try {
                    const ids = Array.from(state.selectedItems);

                    // path 同样优先从全部图片缓存里取，保证跨页删除正确
                    let pathMap = {};
                    if (state.allItemsCache) {
                        state.allItemsCache.forEach(item => { pathMap[String(item.id)] = item.path; });
                    }

                    const paths = ids.map(id => {
                        if (pathMap[id]) return pathMap[id];
                        const element = document.getElementById('image-' + id);
                        return element ? element.querySelector('.delete-btn').dataset.path : null;
                    }).filter(path => path !== null);

                    await API.deleteImages(ids, paths);
                    clearSelection();
                    toggleMultiSelectMode();
                } catch (error) {
                    console.error('Delete error:', error);
                }
            }
        );
    }
    
    // 事件绑定
    gallery.addEventListener('click', handleItemSelection);
    
    // 多选按钮事件
    multiSelectBtn.addEventListener('click', toggleMultiSelectMode);
}

// 添加分页处理函数
function setupPagination() {
    document.getElementById('pagination').addEventListener('click', async (e) => {
        e.preventDefault();
        const pageLink = e.target.closest('.page-link');
        
        if (pageLink && !pageLink.classList.contains('ellipsis') && !pageLink.classList.contains('active')) {
            const page = pageLink.dataset.page;
            if (page) {
                try {
                    const data = await API.loadPage(page, currentCategory);
                    updatePageContent(data);
                    // 更新URL
                    let url = `?page=${page}`;
                    if (currentCategory) {
                        url += `&category=${encodeURIComponent(currentCategory)}`;
                    }
                    window.history.pushState({page, category: currentCategory}, '', url);
                } catch (error) {
                    console.error('Load error:', error);
                    UI.showNotification('加载失败', 'error');
                }
            }
        }
    });
}

// 图片懒加载初始化
function initLazyLoad() {
    window.lazyLoadInstance = new LazyLoad({
        elements_selector: ".lazy",
        threshold: 50,
        callback_loaded: img => {
            img.classList.add('loaded');
            img.parentElement.querySelector('.image-placeholder')?.remove();
        },
        callback_error: img => {
            img.parentElement.classList.add('load-error');
            img.parentElement.querySelector('.image-placeholder')?.remove();
        }
    });
}

// Fancybox图片预览初始化
function initFancybox() {
    Fancybox.bind('[data-fancybox="gallery"]', {
        Toolbar: { display: { right: ["slideshow", "thumbs", "close"] }},
        Thumbs: { showOnStart: false },
        hideScrollbar: false,
        Image: { zoom: false },
        Hash: false,
        on: {
            beforeShow: () => document.body.style.overflow = 'hidden',
            destroy: () => document.body.style.overflow = ''
        }
    });
}

// 更新页面内容的函数
function updatePageContent(data) {
    DOM.gallery.innerHTML = data.html;
    DOM.pagination.innerHTML = data.pagination;
    DOM.pageDisplay.textContent = `${data.currentPage}/${data.totalPages}`;
    // 页面内容变了，跨页缓存作废
    invalidateAllItemsCache();
    initComponents();
}

// 初始化所有UI组件
function initComponents() {
    initLazyLoad();
    initFancybox();
}

// 分类管理功能
function setupCategoryManagement() {
    // 为分类管理图标添加点击事件
    if (DOM.categoryLink) {
        DOM.categoryLink.addEventListener('click', async () => {
            // 加载分类数据
            const categories = await API.getCategories();
            // 显示分类模态框
            showCategoryModal(categories);
        });
    }
}

// 显示分类模态框
function showCategoryModal(categories) {
    // 清空分类列表
    DOM.categoryList.innerHTML = '';
    
    // 添加所有分类选项
    const allCategoryItem = document.createElement('div');
    allCategoryItem.className = `category-item ${currentCategory === '' ? 'active' : ''}`;
    allCategoryItem.textContent = '全部图片';
    allCategoryItem.addEventListener('click', () => selectCategory(''));
    DOM.categoryList.appendChild(allCategoryItem);
    
    // 添加各个分类
    categories.forEach(category => {
        const categoryItem = document.createElement('div');
        categoryItem.className = `category-item ${currentCategory === category ? 'active' : ''}`;
        categoryItem.textContent = category;
        categoryItem.addEventListener('click', () => selectCategory(category));
        DOM.categoryList.appendChild(categoryItem);
    });
    
    // 显示模态框
    DOM.categoryModal.classList.add('show');
}

// 选择分类
async function selectCategory(category) {
    currentCategory = category;
    
    // 关闭模态框
    DOM.categoryModal.classList.remove('show');
    
    // 加载第一页数据
    try {
        const data = await API.loadPage(1, category);
        updatePageContent(data);
        
        // 更新URL
        let url = '?page=1';
        if (category) {
            url += `&category=${encodeURIComponent(category)}`;
        }
        window.history.pushState({page: 1, category}, '', url);
        
        // 显示通知
        UI.showNotification(category ? `已切换到"${category}"分类` : '已切换到全部图片');
    } catch (error) {
        console.error('切换分类失败:', error);
        UI.showNotification('切换分类失败', 'error');
    }
}

// 数据导入导出功能
function setupBackup() {
    const backupLink = document.querySelector('.backup-link');
    const backupModal = document.getElementById('backup-modal');
    if (!backupLink || !backupModal) return;

    backupLink.addEventListener('click', () => {
        backupModal.classList.add('show');
    });

    // 关闭按钮（modal 内已有 onclick，这里兜底点击遮罩关闭）
    backupModal.addEventListener('click', (e) => {
        if (e.target === backupModal) {
            backupModal.classList.remove('show');
        }
    });

    const exportBtn = document.getElementById('export-btn');
    exportBtn?.addEventListener('click', () => {
        UI.showNotification('正在生成备份文件…');
        // 直接走浏览器下载，交给后端设置下载头
        window.location.href = '/admin/backup.php?action=export';
    });

    const importBtn = document.getElementById('import-btn');
    importBtn?.addEventListener('click', async () => {
        const fileInput = document.getElementById('import-file');
        const file = fileInput?.files?.[0];
        if (!file) {
            UI.showNotification('请先选择备份文件', 'error');
            return;
        }

        const mode = document.querySelector('input[name="import-mode"]:checked')?.value || 'merge';
        if (mode === 'replace') {
            const ok = confirm('「覆盖」模式会先清空当前数据库里的图片记录、系统设置和管理员账号，确定继续吗？');
            if (!ok) return;
        }

        const formData = new FormData();
        formData.append('file', file);
        formData.append('mode', mode);

        importBtn.disabled = true;
        importBtn.textContent = '导入中…';
        UI.showNotification('正在导入，请稍候…');

        try {
            const response = await fetch('/admin/backup.php?action=import', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (data.success) {
                const s = data.stat || {};
                UI.showNotification(
                    `导入完成：图片 ${s.images_imported || 0} 条（跳过 ${s.images_skipped || 0}），设置 ${s.configs || 0} 项，账号 ${s.users || 0} 个`,
                    'success'
                );
                // 导入后刷新页面，让新数据生效
                setTimeout(() => window.location.reload(), 1500);
            } else {
                UI.showNotification('导入失败：' + (data.message || '未知错误'), 'error');
            }
        } catch (error) {
            console.error('导入失败:', error);
            UI.showNotification('导入失败，请检查文件或网络', 'error');
        } finally {
            importBtn.disabled = false;
            importBtn.textContent = '开始导入';
        }
    });
}

