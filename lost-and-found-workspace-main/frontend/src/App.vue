<script setup>
import { computed, ref, onMounted, reactive } from 'vue'

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

// 刪除物品（點擊直接刪除）
async function deleteItem(id) {
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
const currentUser = ref('') // 儲存目前登入的使用者名稱
const showAuthModal = ref(false)
const authMode = ref('login')

// AI 智慧搜尋相關變數
const aiQuery = ref('')
const aiMatches = ref([])
const isAiSearching = ref(false)

// 統一的浮動提示視窗 (Toast Modal) 狀態
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
      currentUser.value = (data.user && data.user.name) ? data.user.name : loginEmail.value
      showAuthModal.value = false
      showToast('登入成功', `歡迎回到尋物雷達，${currentUser.value}！`)
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

// 呼叫後端 AI 比對 API
async function handleAiMatch() {
  if (!aiQuery.value.trim()) {
    showToast('請輸入描述', '請先輸入您遺失物品的特徵描述！')
    return
  }

  try {
    isAiSearching.value = true
    showToast('AI 分析中', '正在透過 Ollama AI 進行智慧比對，請稍候...')
    
    const response = await fetch(`${API_BASE_URL}/api/ai-match`, {
      method: 'POST',
      headers: { 
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({ userDescription: aiQuery.value })
    })

    const data = await response.json()

    if (data.success) {
      aiMatches.value = data.matches
      if (data.matches.length === 0) {
        showToast('比對完成', '沒有找到高度相似的失物，請嘗試調整描述關鍵字！')
      } else {
        showToast('比對完成', `AI 為您推薦了 ${data.matches.length} 筆可能的失物！`)
      }
    } else {
      showToast('比對失敗', data.message || 'AI 分析過程發生錯誤。')
    }
  } catch (error) {
    console.error('AI 請求失敗：', error)
    showToast('連線錯誤', '無法連線至 AI 比對服務，請確認後端與 Ollama 是否正在運行！')
  } finally {
    isAiSearching.value = false
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
  currentUser.value = ''
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
      await fetchItems()

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
  <div class="min-h-screen bg-[#000000] text-[#ffffff]">
    <!-- 頂部導覽列 (無邊框無陰影) -->
    <header class="sticky top-0 z-40 bg-[#121212]">
      <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3 sm:px-6 sm:py-4">
        <a href="/" class="text-lg font-black text-[#ff9000] sm:text-xl flex items-center gap-1 tracking-wider">
          ◈ 尋物雷達
        </a>
        
        <!-- 獨立按鈕區塊 -->
        <div class="flex items-center gap-2 sm:gap-3">
          <!-- 登入 / 註冊合併按鈕：灰底、暗白字，滑鼠移上去變亮白字 -->
          <template v-if="!isLoggedIn">
            <button
              class="rounded-xl bg-[#222222] px-3 py-1.5 text-xs font-bold text-[#cccccc] hover:bg-[#222222] hover:text-[#ffffff] transition sm:px-4 sm:py-2 sm:text-sm"
              @click="openAuth('login')"
            >
              登入 / 註冊
            </button>
          </template>
          <template v-else>
            <span class="text-xs font-bold text-[#ff9000] sm:text-sm max-w-[100px] sm:max-w-[150px] truncate">
              👤 {{ currentUser }}
            </span>
            <button
              class="rounded-xl bg-[#222222] px-3 py-1.5 text-xs font-bold text-[#aaaaaa] hover:bg-[#333333] transition sm:px-4 sm:py-2 sm:text-sm"
              @click="handleLogout"
            >
              登出
            </button>
          </template>

          <button
            class="rounded-xl bg-[#ff9000] px-3.5 py-1.5 text-xs font-bold text-[#000000] hover:bg-[#e07f00] transition sm:px-4 sm:py-2 sm:text-sm"
            @click="handlePublishClick"
          >
            + 刊登物品
          </button>
        </div>
      </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-6 sm:px-6 sm:py-10">
      <!-- 主 Banner 區塊 -->
      <section class="rounded-3xl bg-[#121212] px-6 py-8 text-white sm:px-12 sm:py-12">
        <p class="mb-2 text-xs font-bold tracking-widest text-[#ff9000] sm:mb-3 sm:text-sm">CAMPUS LOST & FOUND</p>
        <h1 class="text-2xl font-black leading-tight sm:text-5xl text-[#ffffff]">遺失的物品，<br /><span class="text-[#ff9000]">讓我們幫你找回。</span></h1>
        <p class="mt-3 max-w-lg text-xs leading-6 text-[#888888] sm:mt-4 sm:text-sm sm:leading-7">
          搜尋校園失物，或刊登拾獲物品。透過 AI 智慧比對，推薦可能的失物配對。
        </p>

        <div class="mt-6 flex max-w-xl flex-col gap-2.5 sm:mt-7 sm:flex-row sm:gap-3">
          <input
            v-model="keyword"
            type="text"
            placeholder="搜尋耳機、水壺、圖書館..."
            class="min-w-0 flex-1 rounded-xl bg-[#000000] px-4 py-2.5 text-sm text-[#ffffff] outline-none sm:py-3 placeholder-[#555555]"
          />
          <button
            class="rounded-xl bg-[#ff9000] px-6 py-2.5 text-sm font-bold text-[#000000] hover:bg-[#e07f00] transition sm:py-3"
            @click="category = '全部'"
          >
            搜尋物品
          </button>
        </div>
      </section>

      <!-- AI 智慧尋物助手區塊 -->
      <section class="mt-6 rounded-3xl bg-[#121212] px-6 py-6 sm:px-8 sm:py-8">
        <h2 class="text-base font-black text-[#ff9000] sm:text-lg mb-1">🤖 AI 智慧尋物助手</h2>
        <p class="text-xs text-[#888888] sm:text-sm mb-4">輸入您遺失物品的特徵描述，讓 AI 自動比對最有可能的物品！</p>
        
        <div class="flex flex-col gap-2.5 sm:flex-row sm:gap-3">
          <input
            v-model="aiQuery"
            type="text"
            placeholder="例如：黑色皮革短夾，裡面有學生證..."
            class="min-w-0 flex-1 rounded-xl bg-[#000000] px-4 py-2.5 text-sm text-[#ffffff] outline-none sm:py-3 placeholder-[#555555]"
            @keyup.enter="handleAiMatch"
          />
          <button
            type="button"
            :disabled="isAiSearching"
            class="rounded-xl bg-[#ff9000] px-6 py-2.5 text-sm font-bold text-[#000000] hover:bg-[#e07f00] transition sm:py-3 disabled:opacity-50"
            @click="handleAiMatch"
          >
            {{ isAiSearching ? 'AI 比對中...' : 'AI 開始比對' }}
          </button>
        </div>

        <!-- AI 推薦比對結果區塊 -->
        <div v-if="aiMatches.length > 0" class="mt-5 space-y-3">
          <p class="text-xs font-bold text-[#ff9000]">✨ AI 推薦的可能匹配項目：</p>
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div 
              v-for="item in aiMatches" 
              :key="item.id" 
              class="p-4 rounded-xl bg-[#000000] flex justify-between items-center cursor-pointer hover:bg-[#1a1a1a] transition"
              @click="openDetail(item)"
            >
              <div>
                <span class="text-xs font-bold text-[#ff9000] block">[{{ item.category }}]</span>
                <span class="text-sm font-bold text-[#ffffff] block mt-0.5">{{ item.title }}</span>
                <span class="text-xs text-[#888888] block mt-1">📍 {{ item.location }}</span>
              </div>
              <span class="text-xs font-bold text-[#ff9000] shrink-0">查看詳情 →</span>
            </div>
          </div>
        </div>
      </section>

      <!-- 分類標籤區塊 -->
      <section class="mt-8 sm:mt-10">
        <div class="flex flex-wrap gap-2">
          <button
            v-for="item in categories"
            :key="item"
            class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition sm:px-4 sm:py-2 sm:text-sm"
            :class="category === item ? 'bg-[#ff9000] text-[#000000] font-bold' : 'bg-[#121212] text-[#888888] hover:text-[#ffffff]'"
            @click="category = item"
          >
            {{ item }}
          </button>
        </div>
      </section>

      <!-- 失物網格清單 -->
      <section class="mt-8">
        <div class="mb-5 flex items-end justify-between">
          <div>
            <h2 class="text-xl font-black text-[#ffffff] sm:text-2xl">校園失物列表</h2>
            <p class="mt-1 text-xs text-[#666666] sm:text-sm">找找看，有沒有你正在尋找的物品。</p>
          </div>
          <span class="text-xs text-[#666666] sm:text-sm">{{ filteredItems.length }} 筆紀錄</span>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 sm:gap-5 lg:grid-cols-3">
          <article
            v-for="item in paginatedItems"
            :key="item.id"
            class="overflow-hidden rounded-2xl bg-[#121212] transition hover:-translate-y-1 cursor-pointer flex flex-col justify-between"
            @click="openDetail(item)"
          >
            <div>
              <div class="relative h-48 sm:h-52 bg-[#000000]">
                <img v-if="item.image" :src="item.image" :alt="item.title" class="h-full w-full object-cover" />
                <div v-else class="flex h-full items-center justify-center text-xs text-[#444444] sm:text-sm">尚無物品照片</div>
                <span
                  class="absolute left-3 top-3 rounded-full px-3 py-1 text-xs font-bold"
                  :class="item.type === 'found' ? 'bg-[#052e16] text-[#4ade80]' : 'bg-[#451a03] text-[#ff9000]'"
                >
                  {{ item.type === 'found' ? '已拾獲' : '正在尋找' }}
                </span>
              </div>

              <div class="p-4 sm:p-5">
                <p class="text-xs font-bold text-[#ff9000]">{{ item.category }}</p>
                <h3 class="mt-1.5 text-base font-bold text-[#ffffff] sm:text-lg sm:mt-2">{{ item.title }}</h3>

                <div class="mt-3 space-y-1.5 text-xs text-[#ffffff] sm:mt-4 sm:space-y-2 sm:text-sm">
                  <!-- 地點：橘色地圖釘 SVG 圖標 -->
                  <p class="flex items-center gap-2">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="#ff9000" stroke-width="2" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    {{ item.location }}
                  </p>

                  <!-- 時間：橘色行事曆 SVG 圖標 -->
                  <p class="flex items-center gap-2">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="#ff9000" stroke-width="2" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    {{ item.date }}
                  </p>
                </div>
              </div>
            </div>

            <div class="p-4 pt-0 sm:p-5 sm:pt-0">
              <div class="w-full text-center rounded-xl bg-[#1e1e1e] py-2 text-xs font-bold text-[#ff9000] hover:bg-[#ff9000] hover:text-[#000000] transition sm:py-2.5 sm:text-sm">
                查看詳細內容
              </div>
            </div>
          </article>
        </div>

        <!-- 分頁控制 -->
        <div v-if="totalPages > 1" class="mt-8 flex justify-center items-center gap-2 sm:mt-10">
          <button :disabled="currentPage === 1" @click="currentPage--" class="px-3 py-1.5 rounded-xl bg-[#121212] text-xs font-bold text-[#888888] disabled:opacity-30 sm:px-4 sm:py-2 sm:text-sm">&lt;</button>
          <button v-for="page in totalPages" :key="page" @click="currentPage = page" :class="currentPage === page ? 'bg-[#ff9000] text-[#000000] font-bold' : 'bg-[#121212] text-[#888888]'" class="px-3 py-1.5 rounded-xl text-xs font-bold sm:px-4 sm:py-2 sm:text-sm">
            {{ page }}
          </button>
          <button :disabled="currentPage === totalPages" @click="currentPage++" class="px-3 py-1.5 rounded-xl bg-[#121212] text-xs font-bold text-[#888888] disabled:opacity-30 sm:px-4 sm:py-2 sm:text-sm">&gt;</button>
        </div>

        <p v-if="filteredItems.length === 0" class="py-16 text-center text-sm text-[#555555]">
          找不到符合條件的物品，試試其他關鍵字。
        </p>
      </section>
    </main>

    <!-- 詳細資料視窗 -->
    <div
      v-if="selectedItem"
      class="fixed inset-0 z-50 flex items-center justify-center bg-[#000000]/85 p-4"
      @click.self="selectedItem = null"
    >
      <div class="w-full max-w-md rounded-2xl bg-[#121212] p-5 max-h-[90vh] overflow-y-auto sm:p-6">
        <div class="flex items-center justify-between mb-4">
          <span class="text-xs font-bold text-[#ff9000]">{{ selectedItem.category }}</span>
          <button class="text-[#666666] hover:text-[#ffffff] font-bold text-sm" @click="selectedItem = null">✕ 關閉</button>
        </div>

        <div class="relative h-48 overflow-hidden rounded-xl bg-[#000000] mb-4 sm:h-52">
          <img v-if="selectedItem.image" :src="selectedItem.image" :alt="selectedItem.title" class="h-full w-full object-cover" />
          <div v-else class="flex h-full items-center justify-center text-sm text-[#444444]">尚無物品照片</div>
        </div>

        <h2 class="text-lg font-black text-[#ffffff] mb-2 sm:text-xl">{{ selectedItem.title }}</h2>
        
        <!-- 地點行 -->
        <p class="text-xs text-[#ffffff] mb-1 sm:text-sm flex items-center gap-2">
          <svg class="w-4 h-4 shrink-0" fill="none" stroke="#ff9000" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
          </svg>
          地點：{{ selectedItem.location }}
        </p>

        <!-- 時間行 --> 
        <p class="text-xs text-[#ffffff] mb-6 sm:text-sm flex items-center gap-2">
          <svg class="w-4 h-4 shrink-0" fill="none" stroke="#ff9000" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
          </svg>
          時間：{{ selectedItem.date }}
        </p>

        <!-- 點擊直接刪除（不需二次確認） -->
        <button
          type="button"
          class="w-full rounded-xl bg-[#3f0f15] py-2.5 text-xs font-bold text-[#f87171] hover:bg-[#5f131d] transition sm:py-3 sm:text-sm"
          @click="deleteItem(selectedItem.id)"
        >
          已找到，確定刪除？
        </button>
      </div>
    </div>

    <!-- 刊登表單視窗 -->
    <div
      v-if="showForm"
      class="fixed inset-0 z-50 flex items-center justify-center bg-[#000000]/85 p-4"
      @click.self="showForm = false"
    >
      <div class="w-full max-w-lg rounded-2xl bg-[#121212] p-5 max-h-[90vh] overflow-y-auto sm:p-6">
        <h2 class="text-lg font-black text-[#ffffff] sm:text-xl">刊登失物 / 拾獲物品</h2>
        <p class="mt-1 text-xs text-[#888888] sm:text-sm">填寫詳細資訊，讓失主或拾獲人更容易找到。</p>

        <div class="mt-5 space-y-4">
          <div>
            <label class="block text-xs font-bold text-[#888888] mb-1">類型</label>
            <div class="grid grid-cols-2 gap-3">
              <button
                type="button"
                class="py-2.5 rounded-xl text-xs font-bold transition sm:text-sm"
                :class="formType === 'lost' ? 'bg-[#ff9000] text-[#000000]' : 'bg-[#222222] text-[#ffffff] hover:bg-[#222222] hover:text-[#ffffff]'"
                @click="formType = 'lost'"
              >
                我弄丟了 (正在尋找)
              </button>
              <button
                type="button"
                class="py-2.5 rounded-xl text-xs font-bold transition sm:text-sm"
                :class="formType === 'found' ? 'bg-[#ff9000] text-[#000000]' : 'bg-[#222222] text-[#ffffff] hover:bg-[#222222] hover:text-[#ffffff]'"
                @click="formType = 'found'"
              >
                我撿到了 (已拾獲)
              </button>
            </div>
          </div>

          <div>
            <label class="block text-xs font-bold text-[#888888] mb-1">物品名稱 *</label>
            <input
              v-model="formTitle"
              type="text"
              placeholder="例如：黑色筆電包、學生證"
              class="w-full rounded-xl bg-[#000000] px-4 py-2 text-sm text-[#ffffff] outline-none sm:py-2.5 placeholder-[#444444]"
            />
          </div>

          <div>
            <label class="block text-xs font-bold text-[#888888] mb-1">分類</label>
            <select
              v-model="formCategory"
              required
              class="w-full rounded-xl bg-[#000000] text-[#ffffff] px-4 py-2 text-sm outline-none sm:py-2.5"
            >
              <option value="" disabled selected>請選擇物品分類</option>
              <option value="電子產品">電子產品</option>
              <option value="文具">文具</option>
              <option value="生活用品">生活用品</option>
              <option value="其他">其他</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-bold text-[#888888] mb-1">地點 *</label>
            <input
              v-model="formLocation"
              type="text"
              placeholder="例如：圖書館 3 樓、體育館外"
              class="w-full rounded-xl bg-[#000000] px-4 py-2 text-sm text-[#ffffff] outline-none sm:py-2.5 placeholder-[#444444]"
            />
          </div>

          <div>
            <label class="block text-xs font-bold text-[#888888] mb-1">日期</label>
            <input
              v-model="formDate"
              type="datetime-local"
              class="w-full rounded-xl bg-[#000000] px-4 py-2 text-sm text-[#ffffff] outline-none sm:py-2.5"
            />
          </div>

          <div>
            <label class="block text-xs font-bold text-[#888888] mb-1">上傳物品照片 (選填)</label>
            <input
              type="file"
              accept="image/*"
              class="w-full text-xs text-[#888888] file:mr-3 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-[#ff9000] file:text-[#000000] hover:file:bg-[#e07f00] cursor-pointer bg-[#000000] rounded-xl p-1 sm:text-sm"
              @change="handleImageUpload"
            />
            <div v-if="formImage" class="mt-3 relative h-36 w-full overflow-hidden rounded-xl bg-[#000000]">
              <img :src="formImage" class="h-full w-full object-cover" />
              <button
                type="button"
                class="absolute top-2 right-2 rounded-full bg-[#000000]/80 px-2 py-1 text-xs text-white hover:bg-[#000000]"
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
            class="flex-1 rounded-xl bg-[#ff9000] py-2.5 font-bold text-[#000000] hover:bg-[#e07f00] text-xs sm:text-sm sm:py-3" 
            @click="submitItem"
          >
            確認送出
          </button>   

          <button
            type="button"
            class="flex-1 rounded-xl bg-[#222222] py-2.5 font-bold text-[#888888] hover:bg-[#333333] hover:text-[#ffffff] text-xs sm:text-sm sm:py-3"
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
      class="fixed inset-0 z-50 flex items-center justify-center bg-[#000000]/85 p-4"
      @click.self="showAuthModal = false"
    >
      <div class="w-full max-w-md rounded-2xl bg-[#121212] p-5 relative max-h-[90vh] overflow-y-auto sm:p-6">
        <button class="absolute right-4 top-4 text-[#666666] hover:text-[#ffffff] font-bold text-sm" @click="showAuthModal = false">✕</button>

        <div class="flex mb-5">
          <button
            type="button"
            class="flex-1 pb-3 text-center font-bold text-base sm:text-lg transition"
            :class="authMode === 'login' ? 'text-[#ff9000]' : 'text-[#555555] hover:text-[#888888]'"
            @click="authMode = 'login'"
          >
            系統登入 Login
          </button>
          <button
            type="button"
            class="flex-1 pb-3 text-center font-bold text-base sm:text-lg transition"
            :class="authMode === 'register' ? 'text-[#ff9000]' : 'text-[#555555] hover:text-[#888888]'"
            @click="authMode = 'register'"
          >
            帳號註冊 Register
          </button>
        </div>

        <div v-if="authMode === 'login'" class="space-y-4">
          <div>
            <label class="block text-xs font-bold text-[#ffffff] mb-1">帳號 (Name or Email)</label>
            <input
              v-model="loginEmail"
              type="text"
              placeholder="請輸入使用者名稱 或 Email"
              class="w-full rounded-xl bg-[#000000] px-4 py-2 text-sm text-[#ffffff] outline-none sm:py-2.5 placeholder-[#444444]"
            />
          </div>

          <div>
            <label class="block text-xs font-bold text-[#ffffff] mb-1">密碼 (Password)</label>
            <input
              v-model="loginPassword"
              type="password"
              placeholder="輸入密碼"
              class="w-full rounded-xl bg-[#000000] px-4 py-2 text-sm text-[#ffffff] outline-none sm:py-2.5 placeholder-[#444444]"
            />
          </div>

          <div>
            <label class="block text-xs font-bold text-[#ffffff] mb-1">驗證碼 (Captcha)</label>
            <div class="flex gap-2 items-center">
              <input
                v-model="loginCaptchaInput"
                type="text"
                placeholder="4碼英數字"
                class="flex-1 rounded-xl bg-[#000000] px-4 py-2 text-sm text-[#ffffff] outline-none sm:py-2.5 placeholder-[#444444]"
              />
              <div 
                class="bg-[#222222] text-[#ff9000] font-mono font-black text-base px-3 py-1.5 rounded-xl tracking-widest select-none cursor-pointer hover:bg-[#333333] transition sm:text-lg sm:px-4 sm:py-2"
                title="點擊更換驗證碼"
                @click="generateCaptcha"
              >
                {{ loginCaptchaCode }}
              </div>
            </div>
            <p class="text-xs text-[#f87171] mt-1">(驗證碼不分大小寫)</p>
          </div>

          <button
            type="button"
            class="w-full rounded-xl bg-[#ff9000] py-2.5 text-xs font-bold text-[#000000] hover:bg-[#e07f00] transition mt-2 sm:py-3 sm:text-sm"
            @click="handleLoginSubmit"
          >
            登入 (Login)
          </button>
        </div>

        <div v-else class="space-y-4">
          <div>
            <label class="block text-xs font-bold text-[#ffffff] mb-1">暱稱 (Name) *</label>
            <input
              v-model="registerName"
              type="text"
              placeholder="請輸入姓名"
              class="w-full rounded-xl bg-[#000000] px-4 py-2 text-sm text-[#ffffff] outline-none sm:py-2.5 placeholder-[#444444]"
            />
          </div>

          <div>
            <label class="block text-xs font-bold text-[#ffffff] mb-1">帳號 / 電子郵件 (Email) *</label>
            <input
              v-model="registerEmail"
              type="email"
              placeholder="例如 s10912345@school.edu.tw"
              class="w-full rounded-xl bg-[#000000] px-4 py-2 text-sm text-[#ffffff] outline-none sm:py-2.5 placeholder-[#444444]"
            />
          </div>

          <div>
            <label class="block text-xs font-bold text-[#ffffff] mb-1">設定密碼 (Password) *</label>
            <input
              v-model="registerPassword"
              type="password"
              placeholder="請輸入密碼"
              class="w-full rounded-xl bg-[#000000] px-4 py-2 text-sm text-[#ffffff] outline-none sm:py-2.5 placeholder-[#444444]"
            />
          </div>

          <div>
            <label class="block text-xs font-bold text-[#ffffff] mb-1">確認密碼 (Confirm Password) *</label>
            <input
              v-model="registerPasswordConfirm"
              type="password"
              placeholder="再次輸入密碼"
              class="w-full rounded-xl bg-[#000000] px-4 py-2 text-sm text-[#ffffff] outline-none sm:py-2.5 placeholder-[#444444]"
            />
          </div>

          <button
            type="button"
            class="w-full rounded-xl bg-[#ff9000] py-2.5 text-xs font-bold text-[#000000] hover:bg-[#e07f00] transition mt-2 sm:py-3 sm:text-sm"
            @click="handleRegisterSubmit"
          >
            註冊帳號 (Register)
          </button>
        </div>
      </div>
    </div>

    <!-- 統一的浮動提示視窗 (Toast Modal) -->
    <div
      v-if="toastModal.show"
      class="fixed inset-0 z-50 flex items-center justify-center bg-[#000000]/85 p-4"
      @click.self="toastModal.show = false"
    >
      <div class="w-full max-w-sm rounded-2xl bg-[#121212] p-5 text-center sm:p-6">
        <h3 class="text-base font-black text-[#ff9000] mb-2 sm:text-lg">{{ toastModal.title }}</h3>
        <p class="text-xs text-[#cccccc] mb-5 whitespace-pre-line leading-relaxed sm:text-sm sm:mb-6">{{ toastModal.message }}</p>
        <button
          type="button"
          class="w-full rounded-xl bg-[#ff9000] py-2 text-xs font-bold text-[#000000] hover:bg-[#e07f00] transition sm:py-2.5 sm:text-sm"
          @click="toastModal.show = false"
        >
          確定
        </button>
      </div>
    </div>
  </div>
</template>
