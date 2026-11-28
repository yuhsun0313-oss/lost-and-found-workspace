<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAppState } from '../composables/useAppState'
import ClaimVerifyModal from '../components/ClaimVerifyModal.vue'
import AccountSettingsModal from '../components/AccountSettingsModal.vue'

const router = useRouter()
const {
  API_BASE_URL,
  isDarkMode,
  authToken,
  isLoggedIn,
  currentUserId,
  currentUser,
  currentUserEmail,
  userIdentity,
  isAdmin,
  setAuthSession,
  logoutSession,
  compressImageToDataUrl,
  formatRemainingTime,
} = useAppState()

const items = ref([])
const selectedItem = ref(null)
const showVerifyModal = ref(false)
const verifyingItem = ref(null)
const pendingReloadHome = ref(false)
const isRefreshingItems = ref(false)

// 頂部帳號下拉選單 & 基本資料修改彈窗
const showAccountMenu = ref(false)
const showAccountSettingsModal = ref(false)
const accountMenuRef = ref(null)

// 刊登者自行編輯 / 刪除狀態
const editingItemId = ref(null)
const deleteConfirmItem = ref(null)
const isDeletingOwnerItem = ref(false)

function handleOutsideClick(event) {
  if (showAccountMenu.value && accountMenuRef.value && !accountMenuRef.value.contains(event.target)) {
    showAccountMenu.value = false
  }
}

async function fetchItems() {
  try {
    const headers = { Accept: 'application/json' }
    if (authToken.value) {
      headers.Authorization = `Bearer ${authToken.value}`
    }
    const response = await fetch(`${API_BASE_URL}/items`, { headers })
    if (response.ok) {
      const data = await response.json()
      items.value = Array.isArray(data) ? data : []
    }
  } catch (error) {
    console.error('無法載入失物清單：', error)
  }
}

async function refreshItems() {
  if (isRefreshingItems.value) return
  try {
    isRefreshingItems.value = true
    await fetchItems()
  } finally {
    isRefreshingItems.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', handleOutsideClick)
  fetchItems()
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleOutsideClick)
})

// 登入狀態恢復或切換帳號後，重新拉取各物品的個人嘗試次數
watch(
  () => authToken.value,
  () => {
    fetchItems()
  }
)

function openDetail(item) {
  selectedItem.value = item
}

function isItemOwner(item) {
  if (!isLoggedIn.value || !item) return false
  if (item.user_id !== null && item.user_id !== undefined && currentUserId.value !== null) {
    return Number(item.user_id) === Number(currentUserId.value)
  }
  if (!item.user_id && item.publisher_email && currentUserEmail.value) {
    return item.publisher_email.toLowerCase() === currentUserEmail.value.toLowerCase()
  }
  return false
}

function openVerifyClaimModal(item) {
  verifyingItem.value = item
  showVerifyModal.value = true
}

function handleClaimVerified(data) {
  selectedItem.value = null
  pendingReloadHome.value = true
  showToast('已刪除該物品', data?.message || '已完成認領結案！點擊確定後將自動刷新頁面並返回主畫面。')
}

async function handleClaimEscalated() {
  selectedItem.value = null
  await fetchItems()
}

const category = ref('全部')
const showForm = ref(false)
const showAuthModal = ref(false)
const authMode = ref('login')

const aiQuery = ref('')
const aiMatches = ref([])
const aiReasonNotice = ref('')
const isAiReasonable = ref(true)
const isAiSearching = ref(false)
const isSubmittingItem = ref(false)

const toastModal = reactive({
  show: false,
  title: '',
  message: '',
})

function showToast(title, message) {
  toastModal.title = title
  toastModal.message = message
  toastModal.show = true
}

function closeToast() {
  toastModal.show = false
  if (pendingReloadHome.value) {
    pendingReloadHome.value = false
    window.location.assign('/')
  }
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
const isLoggingIn = ref(false)
const isRegistering = ref(false)

async function handleLoginSubmit() {
  if (isLoggingIn.value) return

  if (!loginEmail.value || !loginPassword.value) {
    showToast('欄位未填寫', '請輸入帳號與密碼！')
    return
  }
  if (!loginCaptchaInput.value || loginCaptchaInput.value.toUpperCase() !== loginCaptchaCode.value) {
    showToast('驗證錯誤', '驗證碼不正確！')
    generateCaptcha()
    return
  }

  try {
    isLoggingIn.value = true
    const response = await fetch(`${API_BASE_URL}/login`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        login: loginEmail.value,
        password: loginPassword.value,
      }),
    })

    const data = await response.json()

    if (response.ok && data.token) {
      setAuthSession(data.token, data.user)
      showAuthModal.value = false
      loginPassword.value = ''
      loginCaptchaInput.value = ''
      showToast('登入成功', `歡迎回到尋物雷達，${currentUser.value}（${userIdentity.value}）！`)
    } else {
      showToast('登入失敗', data.message || '登入失敗，請檢查帳號或密碼！')
      generateCaptcha()
    }
  } catch (error) {
    console.error('連線錯誤:', error)
    showToast('連線錯誤', '無法連線到伺服器，請確認後端是否正在運行！')
  } finally {
    isLoggingIn.value = false
  }
}

async function handleRegisterSubmit() {
  if (isRegistering.value) return

  if (!registerName.value || !registerEmail.value || !registerPassword.value) {
    showToast('欄位未填寫', '請填寫所有必填欄位！')
    return
  }
  if (registerPassword.value !== registerPasswordConfirm.value) {
    showToast('密碼不一致', '兩次輸入的密碼不一致，請重新檢查！')
    return
  }

  try {
    isRegistering.value = true
    const response = await fetch(`${API_BASE_URL}/register`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        name: registerName.value,
        email: registerEmail.value,
        password: registerPassword.value,
      }),
    })

    const data = await response.json()

    if (response.ok) {
      showToast('註冊成功', '註冊成功！請使用剛註冊的帳號密碼登入。')
      authMode.value = 'login'
      loginEmail.value = registerEmail.value
      generateCaptcha()
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
  } finally {
    isRegistering.value = false
  }
}

async function handleAiMatch() {
  if (isAiSearching.value) return

  if (!aiQuery.value.trim()) {
    showToast('請輸入描述', '請先輸入您遺失物品的特徵描述！')
    return
  }

  try {
    isAiSearching.value = true
    aiReasonNotice.value = ''
    isAiReasonable.value = true
    showToast('AI 分析中', '正在透過 Ollama AI 進行語意聯想與合理性稽核，請稍候...')

    const response = await fetch(`${API_BASE_URL}/ai-match`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({ userDescription: aiQuery.value }),
    })

    if (!response.ok) {
      throw new Error('伺服器回應逾時或異常')
    }

    const data = await response.json()

    if (data.success) {
      isAiReasonable.value = data.is_reasonable !== false
      aiReasonNotice.value = data.reason || ''
      aiMatches.value = data.matches || []

      if (!isAiReasonable.value) {
        showToast(
          '描述合理性提醒',
          `AI 合理性評分：${data.reasonableness_score ?? 0} 分\n${data.reason || '描述似乎不符合現實失物常理，請調整後再試。'}`
        )
      } else if (aiMatches.value.length === 0) {
        showToast('比對完成', '沒有找到高度相似的失物，請嘗試調整描述關鍵字！')
      } else {
        showToast('比對完成', `AI 為您推薦了 ${aiMatches.value.length} 筆可能的失物！`)
      }
    } else {
      showToast('比對失敗', '未能成功取得 AI 結果，請點擊按鈕再試一次！')
    }
  } catch (error) {
    console.error('AI 請求失敗：', error)
    showToast('連線逾時或失敗', 'AI 運算時間較長或連線中斷，請點擊「AI 開始比對」再試一次！')
  } finally {
    isAiSearching.value = false
  }
}

function getDefaultDateTimeStr() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}T${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

function toDateTimeLocal(rawDate) {
  if (!rawDate) return getDefaultDateTimeStr()
  const normalized = String(rawDate).replace(' ', 'T').slice(0, 16)
  return normalized.length === 16 ? normalized : getDefaultDateTimeStr()
}

function handlePublishClick() {
  if (!isLoggedIn.value) {
    showToast('尚未登入', '刊登物品前請先登入系統。')
    authMode.value = 'login'
    showAuthModal.value = true
  } else {
    editingItemId.value = null
    formTitle.value = ''
    formCategory.value = ''
    formLocation.value = ''
    formPublisherEmail.value = currentUserEmail.value || ''
    formDate.value = getDefaultDateTimeStr()
    formType.value = 'found'
    formImage.value = ''
    showForm.value = true
  }
}

function openEditItemModal(item) {
  editingItemId.value = item.id
  formTitle.value = item.title || ''
  formCategory.value = item.category || ''
  formLocation.value = item.location || ''
  formPublisherEmail.value = item.publisher_email || currentUserEmail.value || ''
  formDate.value = toDateTimeLocal(item.date)
  formType.value = item.type || 'found'
  formImage.value = item.image || ''
  selectedItem.value = null
  showForm.value = true
}

function openAuth(mode) {
  authMode.value = mode
  showAuthModal.value = true
}

async function handleLogout() {
  showAccountMenu.value = false
  await logoutSession()
  showToast('已登出', '您已成功登出系統。')
}

function goToAdminDashboard() {
  showAccountMenu.value = false
  router.push('/admin')
}

function openAccountSettings() {
  showAccountMenu.value = false
  showAccountSettingsModal.value = true
}

async function handleImageUpload(event) {
  const file = event.target.files?.[0]
  if (!file) return

  if (!file.type.startsWith('image/')) {
    showToast('格式錯誤', '只能上傳圖片格式的檔案喔！（例如 JPG、PNG 等）')
    event.target.value = ''
    formImage.value = ''
    return
  }

  try {
    formImage.value = await compressImageToDataUrl(file, 800, 0.8)
  } catch {
    showToast('圖片讀取失敗', '請重新選擇圖片檔案！')
  }
}

const categories = computed(() => {
  const counts = {}
  items.value.forEach((item) => {
    if (item.category) {
      counts[item.category] = (counts[item.category] || 0) + 1
    }
  })

  const sortedCategories = Object.keys(counts).sort((a, b) => counts[b] - counts[a])
  return ['全部', ...sortedCategories.slice(0, 9)]
})

const formTitle = ref('')
const formCategory = ref('')
const formLocation = ref('')
const formPublisherEmail = ref('')
const formDate = ref(getDefaultDateTimeStr())
const formType = ref('found')
const formImage = ref('')

const filteredItems = computed(() => {
  return items.value.filter((item) => {
    return category.value === '全部' || item.category === category.value
  })
})

const currentPage = ref(1)
const itemsPerPage = ref(12)

const totalPages = computed(() => Math.ceil(filteredItems.value.length / itemsPerPage.value))

const paginatedItems = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  return filteredItems.value.slice(start, start + itemsPerPage.value)
})

async function submitItem() {
  if (isSubmittingItem.value) return

  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
  const errors = []
  if (!formTitle.value.trim()) errors.push('請填寫物品名稱！')
  if (!formCategory.value) errors.push('請選擇物品分類！')
  if (!formLocation.value.trim()) errors.push('請填寫地點！')
  if (!emailRegex.test(formPublisherEmail.value.trim())) errors.push('請填寫格式正確的聯絡人 Gmail 信箱！')
  if (!formImage.value.trim()) errors.push('無論是招領或尋找，刊登時皆必須上傳物品照片！')

  if (errors.length > 0) {
    showToast('欄位不完整', errors.join('\n'))
    return
  }

  try {
    isSubmittingItem.value = true
    const isEditing = editingItemId.value !== null
    const url = isEditing
      ? `${API_BASE_URL}/items/${editingItemId.value}`
      : `${API_BASE_URL}/items`

    const headers = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    }
    if (authToken.value) {
      headers.Authorization = `Bearer ${authToken.value}`
    }

    const response = await fetch(url, {
      method: isEditing ? 'PUT' : 'POST',
      headers,
      body: JSON.stringify({
        title: formTitle.value,
        category: formCategory.value,
        location: formLocation.value,
        publisher_email: formPublisherEmail.value.trim(),
        date: formDate.value,
        type: formType.value,
        image: formImage.value.trim(),
      }),
    })

    const data = await response.json()

    if (response.ok) {
      showToast(isEditing ? '更新成功' : '刊登成功', isEditing ? '物品資料已更新！' : '刊登成功！資料已儲存至資料庫。')
      await fetchItems()

      editingItemId.value = null
      formTitle.value = ''
      formCategory.value = ''
      formLocation.value = ''
      formPublisherEmail.value = currentUserEmail.value || ''
      formType.value = 'found'
      formImage.value = ''
      showForm.value = false
    } else {
      showToast(isEditing ? '更新失敗' : '刊登失敗', data.message || '操作失敗，請檢查輸入內容！')
    }
  } catch (error) {
    console.error('刊登／編輯錯誤：', error)
    showToast('連線錯誤', '無法連線到伺服器，請確認後端是否正在運行！')
  } finally {
    isSubmittingItem.value = false
  }
}

async function confirmOwnerDelete() {
  if (!deleteConfirmItem.value || isDeletingOwnerItem.value) return
  try {
    isDeletingOwnerItem.value = true
    const response = await fetch(`${API_BASE_URL}/items/${deleteConfirmItem.value.id}`, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${authToken.value}`,
      },
    })
    const data = await response.json()
    if (response.ok && data.success) {
      deleteConfirmItem.value = null
      selectedItem.value = null
      await fetchItems()
      showToast('已刪除', '您刊登的物品已成功刪除。')
    } else {
      showToast('刪除失敗', data.message || '無法刪除此物品。')
    }
  } catch (error) {
    console.error('刪除錯誤：', error)
    showToast('連線錯誤', '無法連線到伺服器，請稍後再試！')
  } finally {
    isDeletingOwnerItem.value = false
  }
}
</script>

<template>
  <div
    class="min-h-screen transition-colors duration-300"
    :class="isDarkMode ? 'bg-[#000000] text-[#ffffff]' : 'bg-[#f8fafc] text-[#0f172a]'"
  >
    <!-- 頂部導覽列 -->
    <header
      class="sticky top-0 z-40 transition-colors duration-300"
      :class="isDarkMode ? 'bg-[#121212]' : 'bg-white shadow-sm'"
    >
      <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3 sm:px-6 sm:py-4">
        <router-link
          to="/"
          class="text-lg font-black sm:text-xl flex items-center gap-1 tracking-wider"
          :class="isDarkMode ? 'text-[#ff9000]' : 'text-[#1e3a8a]'"
        >
          ◈ 校園失物AI尋物通
        </router-link>

        <div class="flex items-center gap-2 sm:gap-3">
          <template v-if="!isLoggedIn">
            <button
              class="rounded-xl px-3 py-1.5 text-xs font-bold transition sm:px-4 sm:py-2 sm:text-sm"
              :class="isDarkMode ? 'bg-[#222222] text-[#cccccc] hover:bg-[#333333] hover:text-[#ffffff]' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
              @click="openAuth('login')"
            >
              登入 / 註冊
            </button>
          </template>
          <template v-else>
            <!-- 收合式帳號選單：點擊 👤 使用者 ID 展開選單 -->
            <div ref="accountMenuRef" class="relative">
              <button
                type="button"
                class="flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-bold transition sm:px-3.5 sm:py-2 sm:text-sm cursor-pointer"
                :class="isDarkMode ? 'bg-[#1e1e1e] text-[#ff9000] hover:bg-[#2a2a2a]' : 'bg-blue-50 text-[#1e3a8a] hover:bg-blue-100'"
                @click.stop="showAccountMenu = !showAccountMenu"
              >
                <span>👤</span>
                <span class="max-w-[90px] sm:max-w-[140px] truncate">{{ currentUser }}</span>
                <span
                  class="hidden sm:inline-block rounded-full px-2 py-0.5 text-[10px] font-bold"
                  :class="isAdmin ? (isDarkMode ? 'bg-[#ff9000]/20 text-[#ff9000]' : 'bg-blue-200/70 text-[#1e3a8a]') : (isDarkMode ? 'bg-[#2a2a2a] text-[#aaaaaa]' : 'bg-white text-gray-600')"
                >
                  {{ userIdentity }}
                </span>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 opacity-75">
                  <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                </svg>
              </button>

              <div
                v-if="showAccountMenu"
                class="absolute right-0 mt-2 w-56 rounded-2xl shadow-2xl border overflow-hidden z-50 transition-all"
                :class="isDarkMode ? 'bg-[#181818] border-[#2c2c2c] text-[#ffffff]' : 'bg-white border-gray-200 text-[#0f172a]'"
              >
                <div class="px-4 py-3 border-b text-xs" :class="isDarkMode ? 'border-[#2c2c2c]' : 'border-gray-100'">
                  <p class="font-black truncate">{{ currentUser }}</p>
                  <p class="truncate opacity-65 mt-0.5">{{ currentUserEmail }}</p>
                </div>

                <div class="py-1.5">
                  <button
                    v-if="isAdmin"
                    type="button"
                    class="w-full flex items-center gap-3 px-4 py-2.5 text-left text-xs sm:text-sm font-semibold transition"
                    :class="isDarkMode ? 'hover:bg-[#262626] text-[#ff9000]' : 'hover:bg-blue-50 text-[#1e3a8a]'"
                    @click="goToAdminDashboard"
                  >
                    <span>🛡️</span>
                    <span>管理後台</span>
                  </button>

                  <button
                    type="button"
                    class="w-full flex items-center gap-3 px-4 py-2.5 text-left text-xs sm:text-sm font-semibold transition"
                    :class="isDarkMode ? 'hover:bg-[#262626]' : 'hover:bg-gray-100'"
                    @click="openAccountSettings"
                  >
                    <span>⚙️</span>
                    <span>更改基本資料</span>
                  </button>
                </div>

                <div class="border-t py-1.5" :class="isDarkMode ? 'border-[#2c2c2c]' : 'border-gray-100'">
                  <button
                    type="button"
                    class="w-full flex items-center gap-3 px-4 py-2.5 text-left text-xs sm:text-sm font-semibold transition text-red-500"
                    :class="isDarkMode ? 'hover:bg-[#262626]' : 'hover:bg-red-50'"
                    @click="handleLogout"
                  >
                    <span>🚪</span>
                    <span>登出</span>
                  </button>
                </div>
              </div>
            </div>
          </template>

          <button
            class="rounded-xl px-3.5 py-1.5 text-xs font-bold transition sm:px-4 sm:py-2 sm:text-sm"
            :class="isDarkMode ? 'bg-[#ff9000] text-[#000000] hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
            @click="handlePublishClick"
          >
            + 刊登物品
          </button>
        </div>
      </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-6 sm:px-6 sm:py-10">
      <!-- 主 Banner 區塊（整合單一 AI 智慧搜尋欄） -->
      <section
        class="rounded-3xl px-6 py-8 sm:px-12 sm:py-12 transition-colors duration-300"
        :class="isDarkMode ? 'bg-[#121212] text-white' : 'bg-white shadow-xl text-gray-900 border border-gray-100'"
      >
        <p class="mb-2 text-xs font-bold tracking-widest sm:mb-3 sm:text-sm" :class="isDarkMode ? 'text-[#ff9000]' : 'text-[#1e3a8a]'">
          CAMPUS LOST & FOUND
        </p>
        <h1 class="text-2xl font-black leading-tight sm:text-5xl" :class="isDarkMode ? 'text-[#ffffff]' : 'text-[#0f172a]'">
          遺失的物品，<br />
          <span :class="isDarkMode ? 'text-[#ff9000]' : 'text-[#1e3a8a]'">讓我們幫你找回。</span>
        </h1>
        <p class="mt-3 max-w-lg text-xs leading-6 sm:mt-4 sm:text-sm sm:leading-7" :class="isDarkMode ? 'text-[#888888]' : 'text-gray-600'">
          輸入您遺失物品的特徵與地點描述，透過 AI 智慧語意比對，自動推薦最有可能的失物配對。
        </p>

        <div class="mt-6 flex max-w-2xl flex-col gap-2.5 sm:mt-7 sm:flex-row sm:gap-3">
          <input
            v-model="aiQuery"
            type="text"
            placeholder="例如：在操場弄丟了鞋子、綜三502教室不見手機..."
            class="min-w-0 flex-1 rounded-xl px-4 py-2.5 text-sm outline-none sm:py-3 transition-colors duration-300"
            :class="isDarkMode ? 'bg-[#000000] text-[#ffffff] placeholder-[#555555]' : 'bg-gray-100 text-[#0f172a] placeholder-gray-400 border border-gray-200'"
            @keyup.enter="handleAiMatch"
          />
          <button
            type="button"
            :disabled="isAiSearching"
            class="rounded-xl px-6 py-2.5 text-sm font-bold transition sm:py-3 disabled:opacity-50 cursor-pointer"
            :class="isDarkMode ? 'bg-[#ff9000] text-[#000000] hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
            @click="handleAiMatch"
          >
            {{ isAiSearching ? 'AI 比對中...' : 'AI 開始比對' }}
          </button>
        </div>

        <div
          v-if="!isAiReasonable && aiReasonNotice"
          class="mt-5 rounded-2xl p-4 text-xs sm:text-sm border"
          :class="isDarkMode ? 'bg-[#2a0e12] border-[#7f1d1d] text-[#fca5a5]' : 'bg-red-50 border-red-200 text-red-700'"
        >
          ⚠️ {{ aiReasonNotice }}
        </div>

        <!-- AI 推薦比對結果區塊 -->
        <div v-if="aiMatches.length > 0" class="mt-6 space-y-3">
          <div class="flex items-center justify-between">
            <p class="text-xs font-bold sm:text-sm" :class="isDarkMode ? 'text-[#ff9000]' : 'text-[#1e3a8a]'">
              ✨ AI 推薦的可能匹配項目（共 {{ aiMatches.length }} 筆）：
            </p>
            <button
              type="button"
              class="text-xs font-bold opacity-70 hover:opacity-100 transition cursor-pointer"
              @click="aiMatches = []"
            >
              ✕ 清除結果
            </button>
          </div>
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div
              v-for="item in aiMatches"
              :key="item.id"
              class="overflow-hidden rounded-2xl transition cursor-pointer flex flex-col justify-between"
              :class="isDarkMode ? 'bg-[#000000] hover:bg-[#1a1a1a]' : 'bg-gray-50 border border-gray-200 hover:bg-gray-100'"
              @click="openDetail(item)"
            >
              <div>
                <div class="relative h-44 sm:h-48" :class="isDarkMode ? 'bg-[#1a1a1a]' : 'bg-gray-100'">
                  <img v-if="item.image" :src="item.image" :alt="item.title" class="h-full w-full object-cover" />
                  <div v-else class="flex h-full items-center justify-center text-xs sm:text-sm" :class="isDarkMode ? 'text-[#555555]' : 'text-gray-400'">
                    尚無物品照片
                  </div>
                </div>

                <div class="p-4 sm:p-5 space-y-1.5">
                  <div class="text-xs font-bold" :class="isDarkMode ? 'text-[#ff9000]' : 'text-[#1e3a8a]'">[{{ item.category }}]</div>
                  <h3 class="text-base font-black truncate" :class="isDarkMode ? 'text-white' : 'text-gray-900'">{{ item.title }}</h3>
                  <div class="flex items-center gap-1.5 text-xs pt-1" :class="isDarkMode ? 'text-gray-300' : 'text-gray-600'">
                    <span>📍 {{ item.location }}</span>
                  </div>
                  <div class="flex items-center gap-1.5 text-xs" :class="isDarkMode ? 'text-gray-400' : 'text-gray-500'">
                    <span>📅 {{ item.date }}</span>
                  </div>
                </div>
              </div>

              <div class="p-4 pt-0 sm:p-5 sm:pt-0">
                <button
                  type="button"
                  class="w-full rounded-xl py-2 text-center text-xs font-bold transition sm:py-2.5 sm:text-sm"
                  :class="isDarkMode ? 'bg-[#1f1f1f] text-white hover:bg-[#ff9000] hover:text-[#000000]' : 'bg-gray-200 text-gray-800 hover:bg-[#1e3a8a] hover:text-white'"
                >
                  查看詳細內容
                </button>
              </div>
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
            :class="category === item
              ? (isDarkMode ? 'bg-[#ff9000] text-[#000000] font-bold' : 'bg-[#1e3a8a] text-white font-bold')
              : (isDarkMode ? 'bg-[#121212] text-[#888888] hover:text-[#ffffff]' : 'bg-white text-gray-600 border border-gray-200 hover:text-gray-900 shadow-sm')"
            @click="category = item"
          >
            {{ item }}
          </button>
        </div>
      </section>

      <!-- 失物網格清單 -->
      <section class="mt-8">
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
          <div>
            <h2 class="text-xl font-black sm:text-2xl" :class="isDarkMode ? 'text-[#ffffff]' : 'text-gray-900'">校園失物列表</h2>
            <p class="mt-1 text-xs sm:text-sm" :class="isDarkMode ? 'text-[#666666]' : 'text-gray-500'">找找看，有沒有你正在尋找的物品。</p>
          </div>
          <div class="flex items-center gap-3">
            <span class="text-xs sm:text-sm" :class="isDarkMode ? 'text-[#666666]' : 'text-gray-500'">{{ filteredItems.length }} 筆紀錄</span>
            <button
              type="button"
              :disabled="isRefreshingItems"
              class="rounded-xl px-3.5 py-1.5 text-xs font-bold transition sm:px-4 sm:py-2 sm:text-sm disabled:opacity-50 cursor-pointer"
              :class="isDarkMode ? 'bg-[#ff9000] text-[#000000] hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
              @click="refreshItems"
            >
              {{ isRefreshingItems ? '更新中...' : '🔄 重新整理' }}
            </button>
          </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 sm:gap-5 lg:grid-cols-3">
          <article
            v-for="item in paginatedItems"
            :key="item.id"
            class="overflow-hidden rounded-2xl transition hover:-translate-y-1 cursor-pointer flex flex-col justify-between"
            :class="isDarkMode ? 'bg-[#121212]' : 'bg-white shadow-xl border border-gray-100'"
            @click="openDetail(item)"
          >
            <div>
              <div class="relative h-48 sm:h-52" :class="isDarkMode ? 'bg-[#000000]' : 'bg-gray-100'">
                <img v-if="item.image" :src="item.image" :alt="item.title" class="h-full w-full object-cover" />
                <div v-else class="flex h-full items-center justify-center text-xs sm:text-sm" :class="isDarkMode ? 'text-[#444444]' : 'text-gray-400'">
                  尚無物品照片
                </div>
                <span
                  class="absolute left-3 top-3 rounded-full px-3 py-1 text-xs font-bold"
                  :class="item.type === 'found' ? 'bg-[#052e16] text-[#4ade80]' : 'bg-[#451a03] text-[#ff9000]'"
                >
                  {{ item.type === 'found' ? '已拾獲' : '正在尋找' }}
                </span>
                <span
                  v-if="item.review_status === 'needs_manual_review'"
                  class="absolute right-3 top-3 rounded-full bg-amber-500 px-2.5 py-1 text-[11px] font-bold text-black"
                >
                  人工辨識中
                </span>
              </div>

              <div class="p-4 sm:p-5 space-y-2">
                <div class="flex items-center justify-between gap-2">
                  <span class="text-xs font-bold" :class="isDarkMode ? 'text-[#ff9000]' : 'text-[#1e3a8a]'">[{{ item.category }}]</span>
                  <span
                    v-if="isItemOwner(item)"
                    class="rounded-full px-2 py-0.5 text-[10px] font-bold"
                    :class="isDarkMode ? 'bg-[#222222] text-[#ff9000]' : 'bg-blue-50 text-[#1e3a8a]'"
                  >
                    我的刊登
                  </span>
                </div>
                <h3 class="text-base font-bold truncate sm:text-lg" :class="isDarkMode ? 'text-white' : 'text-gray-900'">{{ item.title }}</h3>

                <div class="space-y-1.5 text-sm pt-1" :class="isDarkMode ? 'text-gray-300' : 'text-gray-600'">
                  <div class="flex items-center gap-1.5">
                    <span>📍 {{ item.location }}</span>
                  </div>
                  <div class="flex items-center gap-1.5 text-xs">
                    <span>📅 {{ item.created_at ? item.created_at.replace('T', ' ').substring(0, 19) : item.date }}</span>
                  </div>
                </div>
              </div>
            </div>

            <div class="p-4 pt-0 sm:p-5 sm:pt-0">
              <div
                class="w-full text-center rounded-xl py-2 text-xs font-bold transition sm:py-2.5 sm:text-sm"
                :class="isDarkMode ? 'bg-[#1e1e1e] text-white hover:bg-[#ff9000] hover:text-[#000000]' : 'bg-gray-100 text-gray-800 hover:bg-[#1e3a8a] hover:text-white'"
              >
                查看詳細內容
              </div>
            </div>
          </article>
        </div>

        <!-- 分頁控制 -->
        <div v-if="totalPages > 1" class="mt-8 flex justify-center items-center gap-2 sm:mt-10">
          <button
            :disabled="currentPage === 1"
            class="px-3 py-1.5 rounded-xl text-xs font-bold disabled:opacity-30 sm:px-4 sm:py-2 sm:text-sm transition"
            :class="isDarkMode ? 'bg-[#121212] text-[#888888]' : 'bg-white text-gray-700 border border-gray-200 shadow-sm'"
            @click="currentPage--"
          >
            &lt;
          </button>

          <button
            v-for="page in totalPages"
            :key="page"
            class="px-3 py-1.5 rounded-xl text-xs font-bold sm:px-4 sm:py-2 sm:text-sm transition"
            :class="currentPage === page
              ? (isDarkMode ? 'bg-[#ff9000] text-[#000000] font-bold' : 'bg-[#1e3a8a] text-white font-bold')
              : (isDarkMode ? 'bg-[#121212] text-[#888888]' : 'bg-white text-gray-700 border border-gray-200 shadow-sm')"
            @click="currentPage = page"
          >
            {{ page }}
          </button>

          <button
            :disabled="currentPage === totalPages"
            class="px-3 py-1.5 rounded-xl text-xs font-bold disabled:opacity-30 sm:px-4 sm:py-2 sm:text-sm transition"
            :class="isDarkMode ? 'bg-[#121212] text-[#888888]' : 'bg-white text-gray-700 border border-gray-200 shadow-sm'"
            @click="currentPage++"
          >
            &gt;
          </button>
        </div>

        <p v-if="filteredItems.length === 0" class="py-16 text-center text-sm" :class="isDarkMode ? 'text-[#555555]' : 'text-gray-400'">
          找不到符合條件的物品，試試其他關鍵字。
        </p>
      </section>
    </main>

    <!-- 詳細資料視窗 -->
    <div
      v-if="selectedItem"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
      @click.self="selectedItem = null"
    >
      <div
        class="w-full max-w-md rounded-2xl p-5 max-h-[90vh] overflow-y-auto sm:p-6 transition-colors duration-300"
        :class="isDarkMode ? 'bg-[#121212] text-white' : 'bg-white text-gray-900 shadow-2xl'"
      >
        <div class="flex items-center justify-between mb-4">
          <span class="text-xs font-bold" :class="isDarkMode ? 'text-[#ff9000]' : 'text-[#1e3a8a]'">{{ selectedItem.category }}</span>
          <button
            class="font-bold text-sm transition"
            :class="isDarkMode ? 'text-[#666666] hover:text-[#ffffff]' : 'text-gray-400 hover:text-gray-700'"
            @click="selectedItem = null"
          >
            ✕ 關閉
          </button>
        </div>

        <div class="relative h-48 overflow-hidden rounded-xl mb-4 sm:h-52" :class="isDarkMode ? 'bg-[#000000]' : 'bg-gray-100'">
          <img v-if="selectedItem.image" :src="selectedItem.image" :alt="selectedItem.title" class="h-full w-full object-cover" />
          <div v-else class="flex h-full items-center justify-center text-sm" :class="isDarkMode ? 'text-[#444444]' : 'text-gray-400'">
            尚無物品照片
          </div>
        </div>

        <h2 class="text-lg font-black mb-2 sm:text-xl" :class="isDarkMode ? 'text-[#ffffff]' : 'text-gray-900'">
          {{ selectedItem.title }}
        </h2>

        <p class="text-xs mb-1 sm:text-sm" :class="isDarkMode ? 'text-[#ffffff]' : 'text-gray-700'">
          📍 地點：{{ selectedItem.location }}
        </p>

        <p class="text-xs mb-4 sm:text-sm" :class="isDarkMode ? 'text-[#ffffff]' : 'text-gray-700'">
          📅 時間：{{ selectedItem.date }}
        </p>

        <div
          v-if="selectedItem.review_status === 'needs_manual_review'"
          class="mb-4 rounded-xl p-3 text-xs bg-amber-500/15 border border-amber-500/40"
          :class="isDarkMode ? 'text-amber-300' : 'text-amber-800'"
        >
          ⏳ 目前正由管理員「人工辨識」審核中（{{ formatRemainingTime(selectedItem.manual_review_deadline) }}）。
        </div>

        <!-- 刊登者自行編輯／刪除控制列（刊登中物品） -->
        <div
          v-if="isItemOwner(selectedItem) && selectedItem.review_status !== 'completed'"
          class="mb-3 grid grid-cols-2 gap-2.5"
        >
          <button
            type="button"
            class="rounded-xl py-2 text-xs font-bold transition sm:py-2.5 sm:text-sm"
            :class="isDarkMode ? 'bg-[#222222] text-[#ff9000] hover:bg-[#333333]' : 'bg-blue-50 text-[#1e3a8a] hover:bg-blue-100'"
            @click="openEditItemModal(selectedItem)"
          >
            ✏️ 編輯物品
          </button>
          <button
            type="button"
            class="rounded-xl py-2 text-xs font-bold transition sm:py-2.5 sm:text-sm bg-red-600/15 text-red-500 hover:bg-red-600 hover:text-white"
            @click="deleteConfirmItem = selectedItem"
          >
            🗑️ 刪除物品
          </button>
        </div>

        <button
          type="button"
          :disabled="selectedItem.review_status === 'needs_manual_review'"
          class="w-full rounded-xl py-2.5 text-xs font-bold transition sm:py-3 sm:text-sm disabled:opacity-50"
          :class="isDarkMode ? 'bg-[#ff9000] text-[#000000] hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
          @click="openVerifyClaimModal(selectedItem)"
        >
          {{ selectedItem.review_status === 'needs_manual_review' ? '人工辨識審核中' : '已找到，驗證並刪除' }}
        </button>
      </div>
    </div>

    <!-- 刊登者刪除確認對話框 -->
    <div
      v-if="deleteConfirmItem"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4"
      @click.self="deleteConfirmItem = null"
    >
      <div
        class="w-full max-w-sm rounded-2xl p-6 text-center transition-colors duration-300"
        :class="isDarkMode ? 'bg-[#121212] text-white' : 'bg-white text-gray-900 shadow-2xl'"
      >
        <h3 class="text-lg font-black mb-2 text-red-500">確認刪除刊登物品？</h3>
        <p class="text-xs sm:text-sm mb-5 opacity-80">
          確定要自行刪除「{{ deleteConfirmItem.title }}」嗎？刪除後將無法復原。
        </p>
        <div class="flex gap-3">
          <button
            type="button"
            :disabled="isDeletingOwnerItem"
            class="flex-1 rounded-xl py-2.5 text-xs sm:text-sm font-bold bg-red-600 text-white hover:bg-red-700 disabled:opacity-50"
            @click="confirmOwnerDelete"
          >
            {{ isDeletingOwnerItem ? '刪除中...' : '確認刪除' }}
          </button>
          <button
            type="button"
            class="flex-1 rounded-xl py-2.5 text-xs sm:text-sm font-bold"
            :class="isDarkMode ? 'bg-[#222222] text-[#aaaaaa]' : 'bg-gray-200 text-gray-700'"
            @click="deleteConfirmItem = null"
          >
            取消
          </button>
        </div>
      </div>
    </div>

    <!-- 失物認領防誤刪 VLM 驗證對話框 -->
    <ClaimVerifyModal
      :show="showVerifyModal"
      :item="verifyingItem"
      @close="showVerifyModal = false"
      @verified="handleClaimVerified"
      @escalated="handleClaimEscalated"
      @toast="showToast"
    />

    <!-- 帳號基本資料修改彈窗 -->
    <AccountSettingsModal
      :show="showAccountSettingsModal"
      @close="showAccountSettingsModal = false"
      @toast="showToast"
    />

    <!-- 刊登 / 編輯表單視窗 -->
    <div
      v-if="showForm"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
      @click.self="showForm = false"
    >
      <div
        class="w-full max-w-lg rounded-2xl p-5 max-h-[90vh] overflow-y-auto sm:p-6 transition-colors duration-300"
        :class="isDarkMode ? 'bg-[#121212] text-white' : 'bg-white text-gray-900 shadow-2xl'"
      >
        <h2 class="text-lg font-black sm:text-xl" :class="isDarkMode ? 'text-[#ffffff]' : 'text-gray-900'">
          {{ editingItemId ? '編輯刊登物品' : '刊登失物 / 拾獲物品' }}
        </h2>
        <p class="mt-1 text-xs sm:text-sm" :class="isDarkMode ? 'text-[#888888]' : 'text-gray-500'">
          填寫詳細資訊並附上物品圖片，讓失主或拾獲人更容易比對與聯繫。
        </p>

        <div class="mt-5 space-y-4">
          <div>
            <label class="block text-xs font-bold mb-1" :class="isDarkMode ? 'text-[#888888]' : 'text-gray-600'">類型</label>
            <div class="grid grid-cols-2 gap-3">
              <button
                type="button"
                class="py-2.5 rounded-xl text-xs font-bold transition sm:text-sm"
                :class="formType === 'lost'
                  ? (isDarkMode ? 'bg-[#ff9000] text-[#000000]' : 'bg-[#1e3a8a] text-white')
                  : (isDarkMode ? 'bg-[#222222] text-[#ffffff] hover:bg-[#333333]' : 'bg-gray-100 text-gray-700 hover:bg-gray-200')"
                @click="formType = 'lost'"
              >
                我弄丟了 (正在尋找)
              </button>
              <button
                type="button"
                class="py-2.5 rounded-xl text-xs font-bold transition sm:text-sm"
                :class="formType === 'found'
                  ? (isDarkMode ? 'bg-[#ff9000] text-[#000000]' : 'bg-[#1e3a8a] text-white')
                  : (isDarkMode ? 'bg-[#222222] text-[#ffffff] hover:bg-[#333333]' : 'bg-gray-100 text-gray-700 hover:bg-gray-200')"
                @click="formType = 'found'"
              >
                我撿到了 (已拾獲)
              </button>
            </div>
          </div>

          <div>
            <label class="block text-xs font-bold mb-1" :class="isDarkMode ? 'text-[#888888]' : 'text-gray-600'">物品名稱 *</label>
            <input
              v-model="formTitle"
              type="text"
              placeholder="例如：黑色筆電包、學生證"
              class="w-full rounded-xl px-4 py-2 text-sm outline-none sm:py-2.5 transition-colors duration-300"
              :class="isDarkMode ? 'bg-[#000000] text-[#ffffff] placeholder-[#444444]' : 'bg-gray-100 text-gray-900 placeholder-gray-400 border border-gray-200'"
            />
          </div>

          <div>
            <label class="block text-xs font-bold mb-1" :class="isDarkMode ? 'text-[#888888]' : 'text-gray-600'">分類 *</label>
            <select
              v-model="formCategory"
              required
              class="w-full rounded-xl px-4 py-2 text-sm outline-none sm:py-2.5 transition-colors duration-300"
              :class="isDarkMode ? 'bg-[#000000] text-[#ffffff]' : 'bg-gray-100 text-gray-900 border border-gray-200'"
            >
              <option value="" disabled>請選擇物品分類</option>
              <option value="電子產品">電子產品</option>
              <option value="文具">文具</option>
              <option value="生活用品">生活用品</option>
              <option value="其他">其他</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-bold mb-1" :class="isDarkMode ? 'text-[#888888]' : 'text-gray-600'">地點 *</label>
            <input
              v-model="formLocation"
              type="text"
              placeholder="例如：圖書館 3 樓、體育館外"
              class="w-full rounded-xl px-4 py-2 text-sm outline-none sm:py-2.5 transition-colors duration-300"
              :class="isDarkMode ? 'bg-[#000000] text-[#ffffff] placeholder-[#444444]' : 'bg-gray-100 text-gray-900 placeholder-gray-400 border border-gray-200'"
            />
          </div>

          <div>
            <label class="block text-xs font-bold mb-1" :class="isDarkMode ? 'text-[#888888]' : 'text-gray-600'">聯絡人 Gmail *</label>
            <input
              v-model="formPublisherEmail"
              type="email"
              placeholder="例如 example@gmail.com（驗證成功時接收面交通知）"
              class="w-full rounded-xl px-4 py-2 text-sm outline-none sm:py-2.5 transition-colors duration-300"
              :class="isDarkMode ? 'bg-[#000000] text-[#ffffff] placeholder-[#444444]' : 'bg-gray-100 text-gray-900 placeholder-gray-400 border border-gray-200'"
            />
          </div>

          <div>
            <label class="block text-xs font-bold mb-1" :class="isDarkMode ? 'text-[#888888]' : 'text-gray-600'">日期</label>
            <input
              v-model="formDate"
              type="datetime-local"
              class="w-full rounded-xl px-4 py-2 text-sm outline-none sm:py-2.5 transition-colors duration-300"
              :class="isDarkMode ? 'bg-[#000000] text-[#ffffff]' : 'bg-gray-100 text-gray-900 border border-gray-200'"
            />
          </div>

          <div>
            <label class="block text-xs font-bold mb-1" :class="isDarkMode ? 'text-[#888888]' : 'text-gray-600'">上傳物品照片 * (必填)</label>
            <div
              class="flex items-center justify-between rounded-xl px-4 py-2 transition-colors duration-300"
              :class="isDarkMode ? 'bg-[#000000]' : 'bg-gray-100 border border-gray-200'"
            >
              <span class="text-xs truncate pr-2" :class="isDarkMode ? 'text-[#888888]' : 'text-gray-500'">
                {{ formImage ? '已選擇照片' : '未選擇任何檔案' }}
              </span>
              <label
                class="cursor-pointer rounded-lg px-4 py-1.5 text-xs font-bold transition shrink-0"
                :class="isDarkMode ? 'bg-[#ff9000] text-[#000000] hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
              >
                選擇檔案
                <input type="file" accept="image/*" class="hidden" @change="handleImageUpload" />
              </label>
            </div>

            <div v-if="formImage" class="mt-3 relative h-36 w-full overflow-hidden rounded-xl bg-black">
              <img :src="formImage" class="h-full w-full object-cover" />
              <button
                type="button"
                class="absolute top-2 right-2 rounded-full bg-black/80 px-2 py-1 text-xs text-white hover:bg-black"
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
            :disabled="isSubmittingItem"
            class="flex-1 rounded-xl py-2.5 font-bold text-xs sm:text-sm sm:py-3 transition disabled:opacity-50"
            :class="isDarkMode ? 'bg-[#ff9000] text-[#000000] hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
            @click="submitItem"
          >
            {{ isSubmittingItem ? '送出中...' : editingItemId ? '儲存變更' : '確認送出' }}
          </button>

          <button
            type="button"
            class="flex-1 rounded-xl py-2.5 font-bold text-xs sm:text-sm sm:py-3 transition"
            :class="isDarkMode ? 'bg-[#222222] text-[#888888] hover:bg-[#333333] hover:text-[#ffffff]' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
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
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
      @click.self="showAuthModal = false"
    >
      <div
        class="w-full max-w-md rounded-2xl p-5 relative max-h-[90vh] overflow-y-auto sm:p-6 transition-colors duration-300"
        :class="isDarkMode ? 'bg-[#121212] text-white' : 'bg-white text-gray-900 shadow-2xl'"
      >
        <button
          class="absolute right-4 top-4 font-bold text-sm transition"
          :class="isDarkMode ? 'text-[#666666] hover:text-[#ffffff]' : 'text-gray-400 hover:text-gray-700'"
          @click="showAuthModal = false"
        >
          ✕
        </button>

        <div class="flex mb-5 border-b pb-2" :class="isDarkMode ? 'border-gray-800' : 'border-gray-200'">
          <button
            type="button"
            class="flex-1 pb-3 text-center font-bold text-base sm:text-lg transition"
            :class="authMode === 'login' ? (isDarkMode ? 'text-[#ff9000]' : 'text-[#1e3a8a]') : (isDarkMode ? 'text-[#555555] hover:text-[#888888]' : 'text-gray-400 hover:text-gray-600')"
            @click="authMode = 'login'"
          >
            系統登入 Login
          </button>
          <button
            type="button"
            class="flex-1 pb-3 text-center font-bold text-base sm:text-lg transition"
            :class="authMode === 'register' ? (isDarkMode ? 'text-[#ff9000]' : 'text-[#1e3a8a]') : (isDarkMode ? 'text-[#555555] hover:text-[#888888]' : 'text-gray-400 hover:text-gray-600')"
            @click="authMode = 'register'"
          >
            帳號註冊 Register
          </button>
        </div>

        <div v-if="authMode === 'login'" class="space-y-4">
          <div>
            <label class="block text-xs font-bold mb-1" :class="isDarkMode ? 'text-[#ffffff]' : 'text-gray-700'">帳號 (Name or Email)</label>
            <input
              v-model="loginEmail"
              type="text"
              placeholder="請輸入使用者名稱 或 Email"
              class="w-full rounded-xl px-4 py-2 text-sm outline-none sm:py-2.5 transition-colors duration-300"
              :class="isDarkMode ? 'bg-[#000000] text-[#ffffff] placeholder-[#444444]' : 'bg-gray-100 text-gray-900 placeholder-gray-400 border border-gray-200'"
            />
          </div>

          <div>
            <label class="block text-xs font-bold mb-1" :class="isDarkMode ? 'text-[#ffffff]' : 'text-gray-700'">密碼 (Password)</label>
            <input
              v-model="loginPassword"
              type="password"
              placeholder="輸入密碼"
              class="w-full rounded-xl px-4 py-2 text-sm outline-none sm:py-2.5 transition-colors duration-300"
              :class="isDarkMode ? 'bg-[#000000] text-[#ffffff] placeholder-[#444444]' : 'bg-gray-100 text-gray-900 placeholder-gray-400 border border-gray-200'"
            />
          </div>

          <div>
            <label class="block text-xs font-bold mb-1" :class="isDarkMode ? 'text-[#ffffff]' : 'text-gray-700'">驗證碼 (Captcha)</label>
            <div class="flex gap-2 items-center">
              <input
                v-model="loginCaptchaInput"
                type="text"
                placeholder="4碼英數字"
                class="flex-1 rounded-xl px-4 py-2 text-sm outline-none sm:py-2.5 transition-colors duration-300"
                :class="isDarkMode ? 'bg-[#000000] text-[#ffffff] placeholder-[#444444]' : 'bg-gray-100 text-gray-900 placeholder-gray-400 border border-gray-200'"
                @keyup.enter="handleLoginSubmit"
              />
              <div
                class="font-mono font-black text-base px-3 py-1.5 rounded-xl tracking-widest select-none cursor-pointer transition sm:text-lg sm:px-4 sm:py-2"
                :class="isDarkMode ? 'bg-[#222222] text-[#ff9000] hover:bg-[#333333]' : 'bg-gray-200 text-[#1e3a8a] hover:bg-gray-300'"
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
            :disabled="isLoggingIn"
            class="w-full rounded-xl py-2.5 text-xs font-bold transition mt-2 sm:py-3 sm:text-sm disabled:opacity-50"
            :class="isDarkMode ? 'bg-[#ff9000] text-[#000000] hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
            @click="handleLoginSubmit"
          >
            {{ isLoggingIn ? '登入中...' : '登入 (Login)' }}
          </button>
        </div>

        <div v-else class="space-y-4">
          <div>
            <label class="block text-xs font-bold mb-1" :class="isDarkMode ? 'text-[#ffffff]' : 'text-gray-700'">暱稱 (Name) *</label>
            <input
              v-model="registerName"
              type="text"
              placeholder="請輸入姓名"
              class="w-full rounded-xl px-4 py-2 text-sm outline-none sm:py-2.5 transition-colors duration-300"
              :class="isDarkMode ? 'bg-[#000000] text-[#ffffff] placeholder-[#444444]' : 'bg-gray-100 text-gray-900 placeholder-gray-400 border border-gray-200'"
            />
          </div>

          <div>
            <label class="block text-xs font-bold mb-1" :class="isDarkMode ? 'text-[#ffffff]' : 'text-gray-700'">帳號 / 電子郵件 (Email) *</label>
            <input
              v-model="registerEmail"
              type="email"
              placeholder="例如 s10912345@school.edu.tw"
              class="w-full rounded-xl px-4 py-2 text-sm outline-none sm:py-2.5 transition-colors duration-300"
              :class="isDarkMode ? 'bg-[#000000] text-[#ffffff] placeholder-[#444444]' : 'bg-gray-100 text-gray-900 placeholder-gray-400 border border-gray-200'"
            />
          </div>

          <div>
            <label class="block text-xs font-bold mb-1" :class="isDarkMode ? 'text-[#ffffff]' : 'text-gray-700'">設定密碼 (Password) *</label>
            <input
              v-model="registerPassword"
              type="password"
              placeholder="請輸入密碼"
              class="w-full rounded-xl px-4 py-2 text-sm outline-none sm:py-2.5 transition-colors duration-300"
              :class="isDarkMode ? 'bg-[#000000] text-[#ffffff] placeholder-[#444444]' : 'bg-gray-100 text-gray-900 placeholder-gray-400 border border-gray-200'"
            />
          </div>

          <div>
            <label class="block text-xs font-bold mb-1" :class="isDarkMode ? 'text-[#ffffff]' : 'text-gray-700'">確認密碼 (Confirm Password) *</label>
            <input
              v-model="registerPasswordConfirm"
              type="password"
              placeholder="再次輸入密碼"
              class="w-full rounded-xl px-4 py-2 text-sm outline-none sm:py-2.5 transition-colors duration-300"
              :class="isDarkMode ? 'bg-[#000000] text-[#ffffff] placeholder-[#444444]' : 'bg-gray-100 text-gray-900 placeholder-gray-400 border border-gray-200'"
            />
          </div>

          <button
            type="button"
            :disabled="isRegistering"
            class="w-full rounded-xl py-2.5 text-xs font-bold transition mt-2 sm:py-3 sm:text-sm disabled:opacity-50"
            :class="isDarkMode ? 'bg-[#ff9000] text-[#000000] hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
            @click="handleRegisterSubmit"
          >
            {{ isRegistering ? '註冊中...' : '註冊帳號 (Register)' }}
          </button>
        </div>
      </div>
    </div>

    <!-- 統一的浮動提示視窗 (Toast Modal) -->
    <div
      v-if="toastModal.show"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
      @click.self="closeToast"
    >
      <div
        class="w-full max-w-sm rounded-2xl p-5 text-center sm:p-6 transition-colors duration-300"
        :class="isDarkMode ? 'bg-[#121212] text-white' : 'bg-white text-gray-900 shadow-2xl'"
      >
        <h3 class="text-base font-black mb-2 sm:text-lg" :class="isDarkMode ? 'text-[#ff9000]' : 'text-[#1e3a8a]'">
          {{ toastModal.title }}
        </h3>
        <p class="text-xs mb-5 whitespace-pre-line leading-relaxed sm:text-sm sm:mb-6" :class="isDarkMode ? 'text-[#cccccc]' : 'text-gray-600'">
          {{ toastModal.message }}
        </p>
        <button
          type="button"
          class="w-full rounded-xl py-2 text-xs font-bold transition sm:py-2.5 sm:text-sm"
          :class="isDarkMode ? 'bg-[#ff9000] text-[#000000] hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
          @click="closeToast"
        >
          確定
        </button>
      </div>
    </div>
  </div>
</template>
