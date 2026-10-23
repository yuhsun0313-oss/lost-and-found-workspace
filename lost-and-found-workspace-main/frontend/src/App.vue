<script setup>
import { computed, ref, onMounted } from 'vue'

// 雲端後端 API 基底網址
const API_BASE_URL = 'https://lost-and-found-workspace.onrender.com/api'

// 載入後端資料庫的物品清單
async function fetchItems() {
  try {
    const response = await fetch(`${API_BASE_URL}/items`)
    if (response.ok) {
      const data = await response.json()
      items.value = data
    }
  } catch (error) {
    console.error('無法載入失物清單：', error)
  }
}

// 網頁一載入就從資料庫抓取資料
onMounted(() => {
  fetchItems()
})

// 控制詳細資料浮動視窗的變數
const selectedItem = ref(null)

function openDetail(item) {
  selectedItem.value = item
}

// 刪除物品（串接後端 API）
async function deleteItem(id) {
  if (!confirm('確定要刪除這筆失物招領資料嗎？')) {
    return
  }

  try {
    const response = await fetch(`${API_BASE_URL}/items/${id}`, {
      method: 'DELETE',
      headers: {
        'Accept': 'application/json'
      }
    })

    if (response.ok) {
      showToast('刪除成功', '該筆失物招領資料已成功刪除！')
      await fetchItems()
      selectedItem.value = null // 關閉詳細視窗
    } else {
      const data = await response.json()
      showToast('刪除失敗', data.message || '刪除失敗！')
    }
  } catch (error) {
    console.error('刪除錯誤：', error)
    showToast('連線錯誤', '無法連線到伺服器，請確認後端是否正在運行！')
  }
}

const keyword = ref('')
const category = ref('全部')
const showForm = ref(false)
const isLoggedIn = ref(false)
const showAuthModal = ref(false)
const authMode = ref('login')

// 統一的浮動提示視窗 (Toast/Alert Modal) 狀態
const toastModal = reactive({
  show: false,
  title: '',
  message: ''
})

function showToast(title, message) {
  toastModal.title = title
  toastModal.message = message
  toastModal.show = true
}

const loginEmail = ref('')
const loginPassword = ref('')
const loginCaptchaInput = ref('')
const loginCaptchaCode = ref('')

function generateCaptcha() {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'
  let result = ''
  for (let i = 0; i < 4; i++) {
    result += chars.charAt(Math.floor(Math.random() * chars.length))
  }
  loginCaptchaCode.value = result
}

generateCaptcha()

const registerName = ref('')
const registerEmail = ref('')
const registerPassword = ref('')
const registerPasswordConfirm = ref('')

async function handleLoginSubmit() {
  if (!loginEmail.value || !loginPassword.value) {
    showToast('欄位未填寫', '請輸入帳號與密碼！')
    return
  }
  if (loginCaptchaInput.value.toUpperCase() !== loginCaptchaCode.value) {
    showToast('驗證錯誤', '驗證碼不正確！')
    return
  }

  try {
    const response = await fetch(`${API_BASE_URL}/login`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({
        login: loginEmail.value,
        password: loginPassword.value
      })
    })

    const data = await response.json()

    if (response.ok) {
      isLoggedIn.value = true
      showAuthModal.value = false
      showToast('登入成功', '歡迎回到尋物雷達！')
    } else {
      showToast('登入失敗', data.message || '登入失敗，請檢查帳號或密碼！')
    }
  } catch (error) {
    console.error('連線錯誤:', error)
    showToast('連線錯誤', '無法連線到伺服器，請確認後端是否正在運行！')
  }
}

async function handleRegisterSubmit() {
  if (!registerName.value || !registerEmail.value || !registerPassword.value) {
    showToast('欄位未填寫', '請填寫所有必填欄位！')
    return
  }
  if (registerPassword.value !== registerPasswordConfirm.value) {
    showToast('密碼不一致', '兩次輸入的密碼不一致，請重新檢查！')
    return
  }

  try {
    const response = await fetch(`${API_BASE_URL}/register`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({
        name: registerName.value,
        email: registerEmail.value,
        password: registerPassword.value
      })
    })

    const data = await response.json()

    if (response.ok) {
      showToast('註冊成功', '註冊成功！請使用剛註冊的帳號密碼登入。')
      authMode.value = 'login'
      loginEmail.value = registerEmail.value
    } else {
      let errorMsg = data.message || '註冊失敗！'
      if (data.errors) {
        errorMsg = Object.values(data.errors).flat().join('\n')
      }
      showToast('註冊失敗', errorMsg)
    }
  } catch (error) {
    console.error('連線錯誤:', error)
    showToast('連線錯誤', '無法連線到伺服器，請確認後端是否正在運行！')
  }
}

function handlePublishClick() {
  if (!isLoggedIn.value) {
    showToast('尚未登入', '刊登物品前請先登入系統。')
    authMode.value = 'login'
    showAuthModal.value = true
  } else {
    showForm.value = true
  }
}

function openAuth(mode) {
  authMode.value = mode
  showAuthModal.value = true
}

function handleLogout() {
  isLoggedIn.value = false
  showToast('已登出', '您已成功登出系統。')
}

function handleImageUpload(event) {
  const file = event.target.files[0]
  if (file) {
    if (!file.type.startsWith('image/')) {
      showToast('格式錯誤', '只能上傳圖片格式的檔案喔！（例如 JPG、PNG 等）')
      event.target.value = ''
      formImage.value = ''
      return
    }

    const reader = new FileReader()
    reader.onload = (e) => {
      formImage.value = e.target.result
    }
    reader.readAsDataURL(file)
  }
}

const categories = computed(() => {
  const counts = {}
  items.value.forEach(item => {
    if (item.category) {
      counts[item.category] = (counts[item.category] || 0) + 1
    }
  })

  const sortedCategories = Object.keys(counts).sort((a, b) => {
    return counts[b] - counts[a]
  })

  const topCategories = sortedCategories.slice(0, 9)
  return ['全部', ...topCategories]
})

// 由資料庫提供資料，初始為空陣列
const items = ref([])

const now = new Date()
const formattedDate = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')} ${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`

const formTitle = ref('')
const formCategory = ref('')
const formLocation = ref('')
const formDate = ref(formattedDate)
const formType = ref('found')
const formImage = ref('')

const filteredItems = computed(() => {
  return items.value.filter((item) => {
    const matchesKeyword =
      `${item.title} ${item.location}`
        .toLowerCase()
        .includes(keyword.value.toLowerCase())

    const matchesCategory =
      category.value === '全部' ||
      item.category === category.value

    return matchesKeyword && matchesCategory
  })
})

const currentPage = ref(1)
const itemsPerPage = ref(12)

const totalPages = computed(() => {
  return Math.ceil(filteredItems.value.length / itemsPerPage.value)
})

const paginatedItems = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return filteredItems.value.slice(start, end)
})

// 串接後端 API 的新增函式
async function submitItem() {
  let errors = []

  if (!formTitle.value.trim()) {
    errors.push('請填寫物品名稱！')
  }
  if (!formLocation.value.trim()) {
    errors.push('請填寫地點！')
  }
  if (!formCategory.value) {
    errors.push('請選擇物品分類！')
  }

  if (errors.length > 0) {
    showToast('欄位不完整', errors.join('\n'))
    return
  }

  try {
    const response = await fetch(`${API_BASE_URL}/items`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({
        title: formTitle.value,
        category: formCategory.value,
        location: formLocation.value,
        date: formDate.value,
        type: formType.value,
        image: formImage.value.trim()
      })
    })

    const data = await response.json()

    if (response.ok) {
      showToast('刊登成功', '刊登成功！資料已儲存至資料庫。')
      
      // 重新跟後端要最新清單，並即時顯示在畫面上
      await fetchItems()

      // 清空表單並關閉視窗
      formTitle.value = ''
      formCategory.value = ''
      formLocation.value = ''
      formType.value = 'found'
      formImage.value = ''
      showForm.value = false
    } else {
      showToast('刊登失敗', data.message || '刊登失敗，請檢查輸入內容！')
    }
  } catch (error) {
    console.error('刊登錯誤：', error)
    showToast('連線錯誤', '無法連線到伺服器，請確認後端是否正在運行！')
  }
}
</script>

<template>
  <div class="min-h-screen bg-slate-50 text-slate-800">
    <header class="border-b border-slate-200 bg-white">
      <div class="mx-auto flex max-w-6xl items-center justify-between px-5 py-4">
        <a href="/" class="text-xl font-black text-indigo-600">◈ 尋物雷達</a>
        
        <!-- 獨立的登入、註冊與刊登按鈕 -->
        <div class="flex items-center gap-2 sm:gap-3">
          <template v-if="!isLoggedIn">
            <button
              class="rounded-xl border border-indigo-600 px-3 py-2 text-xs sm:text-sm font-bold text-indigo-600 hover:bg-indigo-50 transition"
              @click="openAuth('login')"
            >
              登入
            </button>
            <button
              class="rounded-xl border border-indigo-600 px-3 py-2 text-xs sm:text-sm font-bold text-indigo-600 hover:bg-indigo-50 transition"
              @click="openAuth('register')"
            >
              註冊
            </button>
          </template>
          <template v-else>
            <span class="text-xs sm:text-sm font-semibold text-slate-600 hidden sm:inline">已登入</span>
            <button
              class="rounded-xl bg-slate-200 px-3 py-2 text-xs sm:text-sm font-bold text-slate-700 hover:bg-slate-300 transition"
              @click="handleLogout"
            >
              登出
            </button>
          </template>

          <button
            class="rounded-xl bg-indigo-600 px-4 py-2 text-xs sm:text-sm font-bold text-white hover:bg-indigo-700 transition shadow-sm"
            @click="handlePublishClick"
          >
            + 刊登物品
          </button>
        </div>
      </div>
    </header>

    <main class="mx-auto max-w-6xl px-5 py-10">
      <section class="rounded-3xl bg-indigo-600 px-6 py-12 text-white sm:px-12">
        <p class="mb-3 text-sm font-bold tracking-widest text-indigo-200">CAMPUS LOST & FOUND</p>
        <h1 class="text-3xl font-black leading-tight sm:text-5xl">遺失的物品，<br />讓我們幫你找回。</h1>
        <p class="mt-4 max-w-lg text-sm leading-7 text-indigo-100">
          搜尋校園失物，或刊登拾獲物品。將透過 AI 影像比對，推薦可能的失物配對。
        </p>

        <div class="mt-7 flex max-w-xl flex-col gap-3 sm:flex-row">
          <input
            v-model="keyword"
            type="text"
            placeholder="搜尋耳機、水壺、圖書館..."
            class="min-w-0 flex-1 rounded-xl bg-white px-4 py-3 text-slate-800 outline-none focus:ring-4 focus:ring-indigo-300"
          />
          <button
            class="rounded-xl bg-white px-6 py-3 font-bold text-indigo-700 hover:bg-indigo-50"
            @click="category = '全部'"
          >
            搜尋物品
          </button>
        </div>
      </section>

      <section class="mt-10">
        <div class="flex flex-wrap gap-2">
          <button
            v-for="item in categories"
            :key="item"
            class="rounded-full border px-4 py-2 text-sm font-semibold transition"
            :class="category === item ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-indigo-300'"
            @click="category = item"
          >
            {{ item }}
          </button>
        </div>
      </section>

      <section class="mt-8">
        <div class="mb-5 flex items-end justify-between">
          <div>
            <h2 class="text-2xl font-black">校園失物列表</h2>
            <p class="mt-1 text-sm text-slate-500">找找看，有沒有你正在尋找的物品。</p>
          </div>
          <span class="text-sm text-slate-500">{{ filteredItems.length }} 筆紀錄</span>
        </div>

        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
          <article
            v-for="item in paginatedItems"
            :key="item.id"
            class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-1 hover:shadow-lg cursor-pointer"
            @click="openDetail(item)"
          >
            <div class="relative h-52 bg-slate-100">
              <img v-if="item.image" :src="item.image" :alt="item.title" class="h-full w-full object-cover" />
              <div v-else class="flex h-full items-center justify-center text-slate-400">尚無物品照片</div>
              <span
                class="absolute left-3 top-3 rounded-full px-3 py-1 text-xs font-bold"
                :class="item.type === 'found' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'"
              >
                {{ item.type === 'found' ? '已拾獲' : '正在尋找' }}
              </span>
            </div>

            <div class="p-5">
              <p class="text-xs font-semibold text-indigo-600">{{ item.category }}</p>
              <h3 class="mt-2 text-lg font-bold">{{ item.title }}</h3>
              <div class="mt-4 space-y-2 text-sm text-slate-500">
                <p>📍 {{ item.location }}</p>
                <p>📅 {{ item.date }}</p>
              </div>
              <div class="mt-5 w-full text-center rounded-xl border border-indigo-200 py-2.5 text-sm font-bold text-indigo-700 hover:bg-indigo-50">
                查看詳細內容
              </div>
            </div>
          </article>
        </div>

        <div v-if="totalPages > 1" class="mt-10 flex justify-center items-center gap-2">
          <button :disabled="currentPage === 1" @click="currentPage--" class="px-4 py-2 border rounded-xl bg-white">&lt;</button>
          <button v-for="page in totalPages" :key="page" @click="currentPage = page" :class="currentPage === page ? 'bg-indigo-600 text-white' : 'bg-white border'" class="px-4 py-2 rounded-xl font-bold">
            {{ page }}
          </button>
          <button :disabled="currentPage === totalPages" @click="currentPage++" class="px-4 py-2 border rounded-xl bg-white">&gt;</button>
        </div>

        <p v-if="filteredItems.length === 0" class="py-16 text-center text-slate-500">
          找不到符合條件的物品，試試其他關鍵字。
        </p>
      </section>
    </main>

    <!-- 詳細資料與刪除視窗 -->
    <div
      v-if="selectedItem"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-5 backdrop-blur-xs"
      @click.self="selectedItem = null"
    >
      <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-4">
          <span class="text-xs font-semibold text-indigo-600">{{ selectedItem.category }}</span>
          <button class="text-slate-400 hover:text-slate-600 font-bold" @click="selectedItem = null">✕ 關閉</button>
        </div>

        <div class="relative h-52 overflow-hidden rounded-xl bg-slate-100 mb-4">
          <img v-if="selectedItem.image" :src="selectedItem.image" :alt="selectedItem.title" class="h-full w-full object-cover" />
          <div v-else class="flex h-full items-center justify-center text-slate-400">尚無物品照片</div>
        </div>

        <h2 class="text-xl font-black mb-2">{{ selectedItem.title }}</h2>
        <p class="text-sm text-slate-600 mb-1">📍 地點：{{ selectedItem.location }}</p>
        <p class="text-sm text-slate-600 mb-6">📅 時間：{{ selectedItem.date }}</p>

        <button
          type="button"
          class="w-full rounded-xl border border-rose-200 bg-rose-50 py-3 text-sm font-bold text-rose-600 hover:bg-rose-100 transition"
          @click="deleteItem(selectedItem.id)"
        >
          已找到，確定刪除？
        </button>
      </div>
    </div>

    <!-- 刊登表單視窗 -->
    <div
      v-if="showForm"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-5 backdrop-blur-xs"
      @click.self="showForm = false"
    >
      <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl max-h-[90vh] overflow-y-auto">
        <h2 class="text-xl font-black">刊登失物 / 拾獲物品</h2>
        <p class="mt-1 text-sm text-slate-500">填寫詳細資訊，讓失主或拾獲人更容易找到。</p>

        <div class="mt-5 space-y-4">
          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">類型</label>
            <div class="grid grid-cols-2 gap-3">
              <button
                type="button"
                class="py-2.5 rounded-xl text-sm font-bold border transition"
                :class="formType === 'found' ? 'border-emerald-600 bg-emerald-50 text-emerald-700' : 'border-slate-200 text-slate-600'"
                @click="formType = 'found'"
              >
                🟢 我撿到了 (已拾獲)
              </button>
              <button
                type="button"
                class="py-2.5 rounded-xl text-sm font-bold border transition"
                :class="formType === 'lost' ? 'border-amber-600 bg-amber-50 text-amber-700' : 'border-slate-200 text-slate-600'"
                @click="formType = 'lost'"
              >
                🟠 我弄丟了 (正在尋找)
              </button>
            </div>
          </div>

          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">物品名稱 *</label>
            <input
              v-model="formTitle"
              type="text"
              placeholder="例如：黑色筆電包、學生證"
              class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-indigo-600"
            />
          </div>

          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">分類</label>
            <select
              v-model="formCategory"
              required
              class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-indigo-600"
            >
              <option value="" disabled selected>請選擇物品分類</option>
              <option value="電子產品">電子產品</option>
              <option value="文具">文具</option>
              <option value="生活用品">生活用品</option>
              <option value="其他">其他</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">地點 *</label>
            <input
              v-model="formLocation"
              type="text"
              placeholder="例如：圖書館 3 樓、體育館外"
              class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-indigo-600"
            />
          </div>

          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">日期</label>
            <input
              v-model="formDate"
              type="datetime-local"
              class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-indigo-600"
            />
          </div>

          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">上傳物品照片 (選填)</label>
            <input
              type="file"
              accept="image/*"
              class="w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-bold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 cursor-pointer border border-slate-200 rounded-xl p-1"
              @change="handleImageUpload"
            />
            <div v-if="formImage" class="mt-3 relative h-36 w-full overflow-hidden rounded-xl bg-slate-100 border border-slate-200">
              <img :src="formImage" class="h-full w-full object-cover" />
              <button
                type="button"
                class="absolute top-2 right-2 rounded-full bg-slate-900/60 px-2 py-1 text-xs text-white hover:bg-slate-900"
                @click="formImage = ''"
              >
                ✕ 移除照片
              </button>
            </div>
          </div>
        </div>

        <div class="mt-6 flex gap-3">
          <button 
            type="button" 
            class="flex-1 rounded-xl bg-indigo-600 py-3 font-bold text-white hover:bg-indigo-700" 
            @click="submitItem"
          >
            確認送出
          </button>   

          <button
            type="button"
            class="flex-1 rounded-xl border border-slate-200 py-3 font-bold text-slate-600 hover:bg-slate-100"
            @click="showForm = false"
          >
            取消
          </button>
        </div>
      </div>
    </div>

    <!-- 獨立登入/註冊彈窗 -->
    <div
      v-if="showAuthModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-5 backdrop-blur-xs"
      @click.self="showAuthModal = false"
    >
      <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl relative max-h-[90vh] overflow-y-auto">
        <button class="absolute right-4 top-4 text-slate-400 hover:text-slate-600 font-bold" @click="showAuthModal = false">✕</button>

        <div class="flex border-b border-slate-200 mb-6">
          <button
            type="button"
            class="flex-1 pb-3 text-center font-bold text-lg border-b-2 transition"
            :class="authMode === 'login' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-400 hover:text-slate-600'"
            @click="authMode = 'login'"
          >
            系統登入 Login
          </button>
          <button
            type="button"
            class="flex-1 pb-3 text-center font-bold text-lg border-b-2 transition"
            :class="authMode === 'register' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-400 hover:text-slate-600'"
            @click="authMode = 'register'"
          >
            帳號註冊 Register
          </button>
        </div>

        <div v-if="authMode === 'login'" class="space-y-4">
          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">帳號 (Name or Email)</label>
            <input
              v-model="loginEmail"
              type="text"
              placeholder="請輸入使用者名稱 或 Email"
              class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-indigo-600"
            />
          </div>

          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">密碼 (Password)</label>
            <input
              v-model="loginPassword"
              type="password"
              placeholder="輸入密碼"
              class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-indigo-600"
            />
          </div>

          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">驗證碼 (Captcha)</label>
            <div class="flex gap-2 items-center">
              <input
                v-model="loginCaptchaInput"
                type="text"
                placeholder="4碼英數字"
                class="flex-1 rounded-xl border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-indigo-600"
              />
              <div 
                class="bg-indigo-100 text-indigo-700 font-mono font-black text-lg px-4 py-2 rounded-xl tracking-widest select-none cursor-pointer hover:bg-indigo-200 transition"
                title="點擊更換驗證碼"
                @click="generateCaptcha"
              >
                {{ loginCaptchaCode }}
              </div>
            </div>
            <p class="text-xs text-rose-500 mt-1">(驗證碼不分大小寫)</p>
          </div>

          <button
            type="button"
            class="w-full rounded-xl bg-indigo-600 py-3 font-bold text-white hover:bg-indigo-700 transition mt-2"
            @click="handleLoginSubmit"
          >
            登入 (Login)
          </button>
        </div>

        <div v-else class="space-y-4">
          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">暱稱 (Name) *</label>
            <input
              v-model="registerName"
              type="text"
              placeholder="請輸入姓名"
              class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-indigo-600"
            />
          </div>

          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">帳號 / 電子郵件 (Email) *</label>
            <input
              v-model="registerEmail"
              type="email"
              placeholder="例如 s10912345@school.edu.tw"
              class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-indigo-600"
            />
          </div>

          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">設定密碼 (Password) *</label>
            <input
              v-model="registerPassword"
              type="password"
              placeholder="請輸入密碼"
              class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-indigo-600"
            />
          </div>

          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">確認密碼 (Confirm Password) *</label>
            <input
              v-model="registerPasswordConfirm"
              type="password"
              placeholder="再次輸入密碼"
              class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-indigo-600"
            />
          </div>

          <button
            type="button"
            class="w-full rounded-xl bg-indigo-600 py-3 font-bold text-white hover:bg-indigo-700 transition mt-2"
            @click="handleRegisterSubmit"
          >
            註冊帳號 (Register)
          </button>
        </div>
      </div>
    </div>

    <!-- 統一的浮動提示視窗 (Toast / Alert Modal) -->
    <div
      v-if="toastModal.show"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-5 backdrop-blur-xs"
      @click.self="toastModal.show = false"
    >
      <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl text-center">
        <h3 class="text-lg font-black text-slate-800 mb-2">{{ toastModal.title }}</h3>
        <p class="text-sm text-slate-600 mb-6 whitespace-pre-line leading-relaxed">{{ toastModal.message }}</p>
        <button
          type="button"
          class="w-full rounded-xl bg-indigo-600 py-2.5 text-sm font-bold text-white hover:bg-indigo-700 transition"
          @click="toastModal.show = false"
        >
          確定
        </button>
      </div>
    </div>
  </div>
</template>