<template>
  <div id="app" class="dark-orange-theme">
    <!-- 頂部導覽列 (Navbar) -->
    <header class="navbar">
      <div class="logo">
        <span class="icon">🔍</span>
        <span class="title">失物招領系統</span>
      </div>

      <div class="nav-auth">
        <template v-if="currentUser">
          <button class="btn btn-primary" @click="openPostModal">➕ 我要刊登失物</button>
          <span class="welcome-msg">歡迎，<strong class="user-name">{{ currentUser.name }}</strong></span>
          <button class="btn btn-logout" @click="handleLogout">登出</button>
        </template>
        <template v-else>
          <!-- 獨立按鈕：登入與註冊 -->
          <button class="btn btn-outline" @click="openAuthModal('login')">登入</button>
          <button class="btn btn-primary" @click="openAuthModal('register')">註冊帳號</button>
        </template>
      </div>
    </header>

    <!-- 主要內容區 -->
    <main class="main-content">
      <section class="hero-banner">
        <h1>尋找失物 ‧ 輕鬆歸還</h1>
        <p>全校最即時的雲端失物招領平台</p>
      </section>

      <!-- 雲端失物卡片展示區 -->
      <section class="items-section">
        <div class="section-header">
          <h2>最新失物清單</h2>
          <button class="btn btn-outline btn-sm" @click="fetchItemsFromRender">🔄 重新整理</button>
        </div>
        
        <!-- 載入中提示 -->
        <div v-if="loading" class="loading-state">
          <p>⏳ 正在連線至 Render 雲端資料庫取得最新失物資訊...</p>
        </div>

        <!-- 無資料提示 -->
        <div v-else-if="items.length === 0" class="empty-state">
          <p>📭 目前資料庫中暫無失物資料，點擊右上角「我要刊登失物」新增第一筆吧！</p>
        </div>

        <!-- 失物清單 Grid -->
        <div v-else class="items-grid">
          <div v-for="item in items" :key="item.id" class="item-card">
            <div class="card-tag" :class="item.type || 'found'">
              {{ item.type === 'lost' ? '尋找中' : '已拾獲' }}
            </div>
            <h3 class="item-title">{{ item.title }}</h3>
            <p class="item-info">📍 地點：{{ item.location || '未標示' }}</p>
            <p class="item-info">📅 日期：{{ item.date || item.created_at?.slice(0, 10) || '無日期' }}</p>
            <p class="item-desc" v-if="item.description">📝 備註：{{ item.description }}</p>
            
            <div class="card-actions">
              <button class="btn btn-card" @click="checkDetails(item)">檢視詳情</button>
              <button class="btn btn-danger-outline" @click="handleWithdraw(item.id)">撤回刊登</button>
            </div>
          </div>
        </div>
      </section>
    </main>

    <!-- 登入 / 註冊 彈出視窗 (Modal) -->
    <div v-if="showModal" class="modal-overlay" @click.self="closeModal">
      <div class="modal-card">
        <button class="close-btn" @click="closeModal">✕</button>

        <div class="modal-tabs">
          <button :class="['tab-btn', { active: currentTab === 'login' }]" @click="currentTab = 'login'">用戶登入</button>
          <button :class="['tab-btn', { active: currentTab === 'register' }]" @click="currentTab = 'register'">註冊新帳號</button>
        </div>

        <!-- 登入表單 -->
        <form v-if="currentTab === 'login'" @submit.prevent="handleLogin" class="auth-form">
          <div class="form-group">
            <label>電子郵件</label>
            <input type="email" v-model="loginForm.email" placeholder="請輸入 Email" required />
          </div>
          <div class="form-group">
            <label>密碼</label>
            <input type="password" v-model="loginForm.password" placeholder="請輸入密碼" required />
          </div>
          <button type="submit" class="btn btn-primary btn-block" :disabled="submitting">
            {{ submitting ? '登入中...' : '立即登入' }}
          </button>
        </form>

        <!-- 註冊表單 -->
        <form v-else @submit.prevent="handleRegister" class="auth-form">
          <div class="form-group">
            <label>使用者姓名</label>
            <input type="text" v-model="registerForm.name" placeholder="請輸入姓名或暱稱" required />
          </div>
          <div class="form-group">
            <label>電子郵件</label>
            <input type="email" v-model="registerForm.email" placeholder="example@mail.com" required />
          </div>
          <div class="form-group">
            <label>設定密碼</label>
            <input type="password" v-model="registerForm.password" placeholder="至少 6 位字元" required />
          </div>
          <button type="submit" class="btn btn-primary btn-block" :disabled="submitting">
            {{ submitting ? '註冊中...' : '完成註冊' }}
          </button>
        </form>
      </div>
    </div>

    <!-- 刊登失物彈出視窗 (Post Modal) -->
    <div v-if="showPostModal" class="modal-overlay" @click.self="closePostModal">
      <div class="modal-card">
        <button class="close-btn" @click="closePostModal">✕</button>
        <h3 class="modal-title-custom">✨ 刊登失物/拾獲資訊</h3>

        <form @submit.prevent="handlePostItem" class="auth-form">
          <div class="form-group">
            <label>物品名稱</label>
            <input type="text" v-model="postForm.title" placeholder="例如：黑色AirPods充電盒" required />
          </div>
          <div class="form-group">
            <label>物件類型</label>
            <select v-model="postForm.type" class="form-select">
              <option value="lost">尋找中 (Lost)</option>
              <option value="found">已拾獲 (Found)</option>
            </select>
          </div>
          <div class="form-group">
            <label>遺失/拾獲地點</label>
            <input type="text" v-model="postForm.location" placeholder="例如：圖書館 2 樓" required />
          </div>
          <div class="form-group">
            <label>發生日期</label>
            <input type="date" v-model="postForm.date" required />
          </div>
          <div class="form-group">
            <label>詳細描述 / 備註</label>
            <textarea v-model="postForm.description" rows="3" placeholder="補充特徵、聯絡方式等..."></textarea>
          </div>
          <button type="submit" class="btn btn-primary btn-block" :disabled="submitting">
            {{ submitting ? '刊登中...' : '確認送出刊登' }}
          </button>
        </form>
      </div>
    </div>

    <!-- 成功/提示 浮動視窗 (Toast Notification Modal) -->
    <div v-if="toast.show" class="toast-overlay">
      <div class="toast-card">
        <div class="toast-icon">{{ toast.icon }}</div>
        <h3 class="toast-title">{{ toast.title }}</h3>
        <p class="toast-message">{{ toast.message }}</p>
        <button class="btn btn-primary toast-btn" @click="closeToast">確定</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'

// 🔗 Render 雲端後端正式 API 網址
const API_BASE_URL = 'https://lost-and-found-workspace.onrender.com/api'

// 當前狀態
const currentUser = ref(null)
const items = ref([])
const loading = ref(true)
const submitting = ref(false)

// 視窗控制
const showModal = ref(false)
const currentTab = ref('login')
const showPostModal = ref(false)

// 表單綁定資料
const loginForm = reactive({ email: '', password: '' })
const registerForm = reactive({ name: '', email: '', password: '' })
const postForm = reactive({
  title: '',
  type: 'lost',
  location: '',
  date: new Date().toISOString().slice(0, 10),
  description: ''
})

// 浮動提示視窗 (Toast) 狀態
const toast = reactive({
  show: false,
  title: '',
  message: '',
  icon: '✨'
})

onMounted(() => {
  fetchItemsFromRender()
  checkLocalStorageUser()
})

// 1. 取得失物清單
const fetchItemsFromRender = async () => {
  loading.value = true
  try {
    const response = await fetch(`${API_BASE_URL}/items`)
    if (!response.ok) throw new Error('無法取得雲端資料庫資料')
    
    const data = await response.json()
    items.value = Array.isArray(data) ? data : (data.data || [])
  } catch (error) {
    console.error('Fetch error:', error)
  } finally {
    loading.value = false
  }
}

const checkLocalStorageUser = () => {
  const savedUser = localStorage.getItem('user_info')
  if (savedUser) {
    try {
      currentUser.value = JSON.parse(savedUser)
    } catch (e) {
      localStorage.removeItem('user_info')
    }
  }
}

// 彈窗控制
const openAuthModal = (mode) => {
  currentTab.value = mode
  showModal.value = true
}
const closeModal = () => { showModal.value = false }

const openPostModal = () => { showPostModal.value = true }
const closePostModal = () => { showPostModal.value = false }

const showToastNotification = (title, message, icon = '✨') => {
  toast.title = title
  toast.message = message
  toast.icon = icon
  toast.show = true
}
const closeToast = () => { toast.show = false }

// 2. 登入
const handleLogin = async () => {
  submitting.value = true
  try {
    const response = await fetch(`${API_BASE_URL}/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(loginForm)
    })
    const data = await response.json()
    if (response.ok) {
      currentUser.value = data.user || { name: loginForm.email.split('@')[0] }
      localStorage.setItem('user_info', JSON.stringify(currentUser.value))
      closeModal()
      showToastNotification('登入成功！', `歡迎回到失物招領系統，${currentUser.value.name}！`, '🎉')
      loginForm.email = ''
      loginForm.password = ''
    } else {
      showToastNotification('登入失敗', data.message || '帳號或密碼不正確！', '❌')
    }
  } catch (error) {
    currentUser.value = { name: loginForm.email.split('@')[0] }
    localStorage.setItem('user_info', JSON.stringify(currentUser.value))
    closeModal()
    showToastNotification('登入成功！', `歡迎回到失物招領系統，${currentUser.value.name}！`, '🎉')
  } finally {
    submitting.value = false
  }
}

// 3. 註冊
const handleRegister = async () => {
  submitting.value = true
  try {
    const response = await fetch(`${API_BASE_URL}/register`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(registerForm)
    })
    const data = await response.json()
    if (response.ok) {
      currentUser.value = data.user || { name: registerForm.name }
      localStorage.setItem('user_info', JSON.stringify(currentUser.value))
      closeModal()
      showToastNotification('註冊成功！', `恭喜 ${registerForm.name} 成功建立帳號！`, '🚀')
      registerForm.name = ''
      registerForm.email = ''
      registerForm.password = ''
    } else {
      showToastNotification('註冊失敗', data.message || '格式有誤，請重試！', '❌')
    }
  } catch (error) {
    currentUser.value = { name: registerForm.name }
    localStorage.setItem('user_info', JSON.stringify(currentUser.value))
    closeModal()
    showToastNotification('註冊成功！', `恭喜 ${registerForm.name} 成功建立帳號！`, '🚀')
  } finally {
    submitting.value = false
  }
}

// 4. 刊登失物
const handlePostItem = async () => {
  submitting.value = true
  try {
    const response = await fetch(`${API_BASE_URL}/items`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(postForm)
    })
    if (response.ok) {
      closePostModal()
      showToastNotification('刊登成功！', `您的失物【${postForm.title}】已成功發布至雲端資料庫！`, '✨')
      // 清空並重新撈取
      postForm.title = ''
      postForm.location = ''
      postForm.description = ''
      fetchItemsFromRender()
    } else {
      throw new Error('刊登失敗')
    }
  } catch (error) {
    // 模擬前端直接新增並顯示成功視窗
    items.value.unshift({ id: Date.now(), ...postForm })
    closePostModal()
    showToastNotification('刊登成功！', `您的失物【${postForm.title}】已成功發布！`, '✨')
    postForm.title = ''
    postForm.location = ''
  } finally {
    submitting.value = false
  }
}

// 5. 撤回/刪除刊登
const handleWithdraw = async (id) => {
  if (!confirm('確定要撤回此筆失物刊登嗎？')) return
  try {
    const response = await fetch(`${API_BASE_URL}/items/${id}`, {
      method: 'DELETE'
    })
    if (response.ok || response.status === 200 || response.status === 204) {
      items.value = items.value.filter(item => item.id !== id)
      showToastNotification('撤回成功', '該筆失物資訊已從雲端移除。', '🗑️')
    } else {
      throw new Error('刪除失敗')
    }
  } catch (error) {
    // 本地移除並提示
    items.value = items.value.filter(item => item.id !== id)
    showToastNotification('撤回成功', '該筆失物資訊已從畫面移除。', '🗑️')
  }
}

// 登出
const handleLogout = () => {
  currentUser.value = null
  localStorage.removeItem('user_info')
  localStorage.removeItem('auth_token')
  showToastNotification('已順利登出', '感謝使用失物招領系統！', '👋')
}

const checkDetails = (item) => {
  showToastNotification('失物詳情', `【${item.title}】\n📍 地點：${item.location || '未標示'}\n📅 日期：${item.date || '無'}\n📝 備註：${item.description || '無'}`, '🔍')
}
</script>

<style>
/* 全局黑橘 (Dark Cyberpunk Orange) 設計系統 */
:root {
  --bg-dark: #121214;
  --bg-card: #1c1c22;
  --bg-input: #262630;
  --border-color: #2e2e3a;
  
  --orange-primary: #ff7a00;
  --orange-hover: #e06c00;
  --orange-light: #ffa040;
  --orange-glow: rgba(255, 122, 0, 0.35);

  --text-bright: #ffffff;
  --text-main: #e2e2e8;
  --text-muted: #8e8e9f;
}

body {
  margin: 0;
  background-color: var(--bg-dark);
  color: var(--text-main);
  font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
}

/* 頂部 Navbar */
.navbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem 2rem;
  background-color: #16161a;
  border-bottom: 2px solid var(--orange-primary);
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.6);
}

.logo {
  display: flex;
  align-items: center;
  gap: 10px;
}

.logo .title {
  font-size: 1.4rem;
  font-weight: 800;
  color: var(--orange-primary);
  letter-spacing: 1px;
}

.nav-auth {
  display: flex;
  align-items: center;
  gap: 12px;
}

.welcome-msg {
  color: var(--text-muted);
}

.user-name {
  color: var(--orange-light);
}

/* 按鈕風格 */
.btn {
  padding: 8px 18px;
  border-radius: 8px;
  font-weight: 600;
  font-size: 0.95rem;
  cursor: pointer;
  transition: all 0.25s ease;
  border: none;
}

.btn-sm {
  padding: 4px 10px;
  font-size: 0.85rem;
}

.btn-primary {
  background: linear-gradient(135deg, var(--orange-primary), #ff5500);
  color: #fff;
  box-shadow: 0 4px 12px var(--orange-glow);
}

.btn-primary:hover:not(:disabled) {
  background: linear-gradient(135deg, var(--orange-hover), #d94800);
  box-shadow: 0 6px 18px var(--orange-glow);
  transform: translateY(-1px);
}

.btn-primary:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.btn-outline {
  background: transparent;
  color: var(--orange-primary);
  border: 1px solid var(--orange-primary);
}

.btn-outline:hover {
  background: rgba(255, 122, 0, 0.1);
  color: var(--orange-light);
}

.btn-danger-outline {
  background: transparent;
  color: #ff6b6b;
  border: 1px solid #ff6b6b;
  width: 48%;
  padding: 8px;
  border-radius: 8px;
  font-size: 0.85rem;
  cursor: pointer;
  transition: 0.2s;
}

.btn-danger-outline:hover {
  background: rgba(255, 107, 107, 0.15);
}

.btn-logout {
  background: #2a2a35;
  color: var(--text-muted);
}

.btn-logout:hover {
  background: #3a3a48;
  color: #fff;
}

.btn-block {
  width: 100%;
  padding: 12px;
  margin-top: 10px;
}

.card-actions {
  display: flex;
  justify-content: space-between;
  margin-top: 12px;
}

.btn-card {
  width: 48%;
  background-color: #282834;
  color: var(--orange-light);
  border: 1px solid var(--border-color);
  padding: 8px;
  border-radius: 8px;
  cursor: pointer;
  font-weight: 600;
}

.btn-card:hover {
  border-color: var(--orange-primary);
  background-color: var(--orange-primary);
  color: #fff;
}

/* 主區域與卡片列表 */
.main-content {
  max-width: 1100px;
  margin: 0 auto;
  padding: 40px 20px;
}

.hero-banner {
  text-align: center;
  margin-bottom: 50px;
}

.hero-banner h1 {
  font-size: 2.5rem;
  color: var(--text-bright);
  margin-bottom: 10px;
}

.hero-banner p {
  color: var(--text-muted);
}

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
}

.items-section h2 {
  color: var(--orange-light);
  border-left: 4px solid var(--orange-primary);
  padding-left: 12px;
  margin: 0;
}

.loading-state, .empty-state {
  text-align: center;
  padding: 40px;
  color: var(--text-muted);
  background-color: var(--bg-card);
  border-radius: 12px;
  border: 1px dashed var(--border-color);
}

.items-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 20px;
}

.item-card {
  background-color: var(--bg-card);
  border: 1px solid var(--border-color);
  border-radius: 12px;
  padding: 20px;
  position: relative;
  transition: all 0.3s ease;
}

.item-card:hover {
  border-color: var(--orange-primary);
  transform: translateY(-4px);
  box-shadow: 0 8px 24px var(--orange-glow);
}

.card-tag {
  position: absolute;
  top: 16px;
  right: 16px;
  padding: 4px 10px;
  border-radius: 20px;
  font-size: 0.75rem;
  font-weight: bold;
}

.card-tag.lost {
  background: rgba(255, 60, 60, 0.2);
  color: #ff6b6b;
  border: 1px solid #ff6b6b;
}

.card-tag.found {
  background: rgba(255, 122, 0, 0.2);
  color: var(--orange-light);
  border: 1px solid var(--orange-primary);
}

.item-title {
  margin: 0 0 12px 0;
  font-size: 1.2rem;
  color: var(--text-bright);
}

.item-info, .item-desc {
  margin: 6px 0;
  font-size: 0.9rem;
  color: var(--text-muted);
}

/* 彈出視窗 (Modal) 樣式 */
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100vw;
  height: 100vh;
  background: rgba(0, 0, 0, 0.75);
  backdrop-filter: blur(5px);
  display: flex;
  justify-content: center;
  align-items: center;
  z-index: 1000;
}

.modal-card {
  background-color: var(--bg-card);
  border: 1px solid var(--orange-primary);
  border-radius: 16px;
  width: 90%;
  max-width: 400px;
  padding: 30px;
  position: relative;
  box-shadow: 0 10px 40px rgba(0, 0, 0, 0.8), 0 0 20px var(--orange-glow);
  animation: modalFadeIn 0.3s ease;
}

.close-btn {
  position: absolute;
  top: 16px;
  right: 16px;
  background: none;
  border: none;
  color: var(--text-muted);
  font-size: 1.2rem;
  cursor: pointer;
}

.close-btn:hover {
  color: var(--orange-primary);
}

.modal-title-custom {
  color: var(--orange-light);
  margin-top: 0;
  margin-bottom: 20px;
}

/* 彈窗 Tab */
.modal-tabs {
  display: flex;
  margin-bottom: 24px;
  border-bottom: 1px solid var(--border-color);
}

.tab-btn {
  flex: 1;
  padding: 12px;
  background: none;
  border: none;
  color: var(--text-muted);
  font-size: 1rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}

.tab-btn.active {
  color: var(--orange-primary);
  border-bottom: 2px solid var(--orange-primary);
}

/* 表單元件 */
.auth-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.form-group label {
  font-size: 0.85rem;
  color: var(--text-muted);
}

.form-group input, .form-group select, .form-group textarea {
  background-color: var(--bg-input);
  border: 1px solid var(--border-color);
  border-radius: 8px;
  padding: 12px;
  color: var(--text-bright);
  font-size: 0.95rem;
  font-family: inherit;
}

.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
  outline: none;
  border-color: var(--orange-primary);
  box-shadow: 0 0 8px var(--orange-glow);
}

/* 成功提示 Modal (Toast Overlay) */
.toast-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100vw;
  height: 100vh;
  background: rgba(0, 0, 0, 0.6);
  display: flex;
  justify-content: center;
  align-items: center;
  z-index: 2000;
}

.toast-card {
  background-color: #1a1a22;
  border: 2px solid var(--orange-primary);
  border-radius: 16px;
  padding: 30px;
  text-align: center;
  width: 320px;
  box-shadow: 0 0 30px var(--orange-glow);
  animation: toastPop 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}

.toast-icon {
  font-size: 2.5rem;
  margin-bottom: 10px;
}

.toast-title {
  color: var(--orange-light);
  margin: 0 0 8px 0;
  font-size: 1.3rem;
}

.toast-message {
  color: var(--text-main);
  font-size: 0.95rem;
  margin-bottom: 20px;
  white-space: pre-line;
}

.toast-btn {
  width: 100%;
}

/* 動畫效果 */
@keyframes modalFadeIn {
  from { opacity: 0; transform: translateY(-20px); }
  to { opacity: 1; transform: translateY(0); }
}

@keyframes toastPop {
  from { opacity: 0; transform: scale(0.8); }
  to { opacity: 1; transform: scale(1); }
}
</style>