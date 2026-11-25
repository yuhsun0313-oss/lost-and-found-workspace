<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAppState } from '../composables/useAppState'

const router = useRouter()
const {
  API_BASE_URL,
  isDarkMode,
  authToken,
  isLoggedIn,
  currentUser,
  userIdentity,
  isAdmin,
  isAuthChecking,
  formatRemainingTime,
} = useAppState()

const adminItems = ref([])
const isLoading = ref(false)
const statusFilter = ref('active') // 'active' | 'manual' | 'completed' | 'all'
const processingId = ref(null)

const toast = reactive({
  show: false,
  title: '',
  message: '',
})

function showToast(title, message) {
  toast.title = title
  toast.message = message
  toast.show = true
}

async function fetchAdminItems() {
  if (!isAdmin.value || !authToken.value) return

  try {
    isLoading.value = true
    const response = await fetch(`${API_BASE_URL}/admin/items`, {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${authToken.value}`,
      },
    })
    const data = await response.json()
    if (response.ok && data.success) {
      adminItems.value = data.items || []
    } else {
      showToast('載入失敗', data.message || '無法取得後台管理清單')
    }
  } catch (error) {
    console.error('載入管理後台失物失敗：', error)
    showToast('連線錯誤', '無法連線至後端伺服器')
  } finally {
    isLoading.value = false
  }
}

onMounted(() => {
  if (!isAuthChecking.value && isAdmin.value) {
    fetchAdminItems()
  }
})

watch(
  () => [isAuthChecking.value, isAdmin.value],
  ([checking, admin]) => {
    if (!checking && admin) {
      fetchAdminItems()
    }
  }
)

const sortedAndFilteredItems = computed(() => {
  const rank = (status) => {
    if (status === 'needs_manual_review') return 0
    if (!status || status === 'pending') return 1
    return 2
  }

  return [...adminItems.value]
    .filter((item) => {
      if (statusFilter.value === 'manual') return item.review_status === 'needs_manual_review'
      if (statusFilter.value === 'completed') return item.review_status === 'completed'
      if (statusFilter.value === 'active') return item.review_status !== 'completed'
      return true
    })
    .sort((a, b) => rank(a.review_status) - rank(b.review_status) || b.id - a.id)
})

const manualReviewCount = computed(
  () => adminItems.value.filter((i) => i.review_status === 'needs_manual_review').length
)
const pendingCount = computed(
  () => adminItems.value.filter((i) => !i.review_status || i.review_status === 'pending').length
)
const completedCount = computed(
  () => adminItems.value.filter((i) => i.review_status === 'completed').length
)

async function approveItem(item) {
  if (processingId.value) return
  try {
    processingId.value = item.id
    const response = await fetch(`${API_BASE_URL}/admin/items/${item.id}/approve`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${authToken.value}`,
      },
    })
    const data = await response.json()
    if (response.ok && data.success) {
      showToast('審核通過', data.message || '已核准認領並結案！')
      await fetchAdminItems()
    } else {
      showToast('操作失敗', data.message || '無法完成審核通過操作')
    }
  } catch {
    showToast('連線錯誤', '無法連線至後端伺服器')
  } finally {
    processingId.value = null
  }
}

async function rejectItem(item) {
  if (processingId.value) return
  try {
    processingId.value = item.id
    const response = await fetch(`${API_BASE_URL}/admin/items/${item.id}/reject`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${authToken.value}`,
      },
      body: JSON.stringify({
        reason: '管理員比對現場照片與原失物特徵不符，已重置 VLM 驗證次數，請重新拍攝清晰照片驗證。',
      }),
    })
    const data = await response.json()
    if (response.ok && data.success) {
      showToast('已退回人工審核', data.message || '已寄出通知信並歸零失敗次數！')
      await fetchAdminItems()
    } else {
      showToast('操作失敗', data.message || '無法完成審核失敗操作')
    }
  } catch {
    showToast('連線錯誤', '無法連線至後端伺服器')
  } finally {
    processingId.value = null
  }
}

async function hardDeleteItem(item) {
  if (processingId.value) return
  try {
    processingId.value = item.id
    const response = await fetch(`${API_BASE_URL}/admin/items/${item.id}`, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${authToken.value}`,
      },
    })
    const data = await response.json()
    if (response.ok && data.success) {
      showToast('已刪除', '該筆資料已永久刪除。')
      await fetchAdminItems()
    } else {
      showToast('刪除失敗', data.message || '刪除失敗')
    }
  } catch {
    showToast('連線錯誤', '無法連線至後端伺服器')
  } finally {
    processingId.value = null
  }
}
</script>

<template>
  <div
    class="min-h-screen transition-colors duration-300"
    :class="isDarkMode ? 'bg-[#000000] text-[#ffffff]' : 'bg-[#f8fafc] text-[#0f172a]'"
  >
    <!-- 導覽列 -->
    <header
      class="sticky top-0 z-40 transition-colors duration-300"
      :class="isDarkMode ? 'bg-[#121212]' : 'bg-white shadow-sm'"
    >
      <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3 sm:px-6 sm:py-4">
        <div class="flex items-center gap-3">
          <button
            type="button"
            class="rounded-xl px-3 py-1.5 text-xs font-bold transition sm:text-sm"
            :class="isDarkMode ? 'bg-[#222222] text-[#ffffff] hover:bg-[#333333]' : 'bg-gray-100 text-[#0f172a] hover:bg-gray-200'"
            @click="router.push('/')"
          >
            ← 返回前台
          </button>
          <span class="text-base font-black sm:text-xl" :class="isDarkMode ? 'text-[#ff9000]' : 'text-[#1e3a8a]'">
            🛡️ 校園AI尋物通・管理後台
          </span>
        </div>

        <div v-if="isLoggedIn" class="flex items-center gap-2">
          <span class="text-xs font-bold sm:text-sm" :class="isDarkMode ? 'text-[#ff9000]' : 'text-[#1e3a8a]'">
            {{ currentUser }} ({{ userIdentity }})
          </span>
        </div>
      </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-6 sm:px-6 sm:py-10">
      <!-- 權限檢查中 -->
      <div
        v-if="isAuthChecking"
        class="rounded-3xl p-10 text-center"
        :class="isDarkMode ? 'bg-[#121212]' : 'bg-white shadow-xl border border-gray-100'"
      >
        <p class="text-sm font-bold">正在驗證管理員身分與登入憑證...</p>
      </div>

      <!-- 非管理員擋牆 -->
      <div
        v-else-if="!isAdmin"
        class="rounded-3xl p-8 text-center sm:p-12"
        :class="isDarkMode ? 'bg-[#121212]' : 'bg-white shadow-xl border border-gray-100'"
      >
        <h1 class="text-xl font-black mb-2" :class="isDarkMode ? 'text-[#ff9000]' : 'text-[#1e3a8a]'">
          🔒 無權限存取管理後台
        </h1>
        <p class="text-sm mb-6" :class="isDarkMode ? 'text-[#aaaaaa]' : 'text-gray-600'">
          此頁面僅限資料庫身分（identity）為「管理員」的帳號進入。請先回首頁使用管理員帳號登入。
        </p>
        <button
          type="button"
          class="rounded-xl px-6 py-2.5 text-sm font-bold transition"
          :class="isDarkMode ? 'bg-[#ff9000] text-black hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
          @click="router.push('/')"
        >
          返回首頁登入
        </button>
      </div>

      <!-- 管理後台主內容 -->
      <template v-else>
        <!-- 統計卡片 -->
        <section class="grid grid-cols-1 gap-4 sm:grid-cols-3 mb-6">
          <div
            class="rounded-2xl p-5 transition-colors duration-300"
            :class="isDarkMode ? 'bg-[#121212]' : 'bg-white shadow-lg border border-gray-100'"
          >
            <p class="text-xs font-bold" :class="isDarkMode ? 'text-[#aaaaaa]' : 'text-gray-500'">待人工辨識（置頂優先）</p>
            <p class="mt-2 text-3xl font-black" :class="isDarkMode ? 'text-[#ff9000]' : 'text-[#1e3a8a]'">
              {{ manualReviewCount }}
            </p>
          </div>
          <div
            class="rounded-2xl p-5 transition-colors duration-300"
            :class="isDarkMode ? 'bg-[#121212]' : 'bg-white shadow-lg border border-gray-100'"
          >
            <p class="text-xs font-bold" :class="isDarkMode ? 'text-[#aaaaaa]' : 'text-gray-500'">一般進行中失物</p>
            <p class="mt-2 text-3xl font-black">{{ pendingCount }}</p>
          </div>
          <div
            class="rounded-2xl p-5 transition-colors duration-300"
            :class="isDarkMode ? 'bg-[#121212]' : 'bg-white shadow-lg border border-gray-100'"
          >
            <p class="text-xs font-bold" :class="isDarkMode ? 'text-[#aaaaaa]' : 'text-gray-500'">已結案紀錄</p>
            <p class="mt-2 text-3xl font-black text-emerald-500">{{ completedCount }}</p>
          </div>
        </section>

        <!-- 篩選按鈕列 -->
        <section class="flex flex-wrap items-center justify-between gap-3 mb-6">
          <div class="flex flex-wrap gap-2">
            <button
              v-for="tab in [
                { key: 'active', label: '未結案（人工辨識置頂）' },
                { key: 'manual', label: `僅人工辨識 (${manualReviewCount})` },
                { key: 'completed', label: `已結案 (${completedCount})` },
                { key: 'all', label: '全部資料' },
              ]"
              :key="tab.key"
              type="button"
              class="rounded-full px-4 py-2 text-xs font-bold transition sm:text-sm"
              :class="statusFilter === tab.key
                ? (isDarkMode ? 'bg-[#ff9000] text-[#000000]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]')
                : (isDarkMode ? 'bg-[#121212] text-[#aaaaaa] hover:text-white' : 'bg-white text-gray-600 border border-gray-200 hover:text-gray-900')"
              @click="statusFilter = tab.key"
            >
              {{ tab.label }}
            </button>
          </div>

          <button
            type="button"
            class="rounded-xl px-4 py-2 text-xs font-bold transition sm:text-sm"
            :class="isDarkMode ? 'bg-[#ff9000] text-[#000000] hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
            @click="fetchAdminItems"
          >
            🔄 重新整理清單
          </button>
        </section>

        <!-- 失物審核列表 -->
        <div v-if="isLoading" class="py-16 text-center text-sm opacity-70">正在載入失物與人工審核資料...</div>

        <div v-else-if="sortedAndFilteredItems.length === 0" class="py-16 text-center text-sm opacity-60">
          目前此分類沒有符合的失物資料。
        </div>

        <div v-else class="space-y-5">
          <article
            v-for="item in sortedAndFilteredItems"
            :key="item.id"
            class="rounded-2xl p-5 sm:p-6 transition-colors duration-300"
            :class="[
              isDarkMode ? 'bg-[#121212]' : 'bg-white shadow-xl border border-gray-100',
              item.review_status === 'needs_manual_review'
                ? (isDarkMode ? 'ring-2 ring-[#ff9000]' : 'ring-2 ring-[#1e3a8a]')
                : '',
            ]"
          >
            <!-- 頂部標籤與狀態 -->
            <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
              <div class="flex flex-wrap items-center gap-2">
                <span
                  v-if="item.review_status === 'needs_manual_review'"
                  class="rounded-full px-3 py-1 text-xs font-black"
                  :class="isDarkMode ? 'bg-[#ff9000] text-black' : 'bg-[#1e3a8a] text-white'"
                >
                  🔍 人工辨識（置頂待審核）
                </span>
                <span
                  v-else-if="item.review_status === 'completed'"
                  class="rounded-full bg-emerald-500/20 px-3 py-1 text-xs font-bold text-emerald-500"
                >
                  ✅ 已結案
                </span>
                <span
                  v-else
                  class="rounded-full px-3 py-1 text-xs font-bold"
                  :class="isDarkMode ? 'bg-[#222222] text-[#aaaaaa]' : 'bg-gray-100 text-gray-600'"
                >
                  ⏳ 待認領 (pending)
                </span>

                <span class="text-xs font-bold opacity-70">#{{ item.id }} · {{ item.category }}</span>
              </div>

              <span
                v-if="item.review_status === 'needs_manual_review'"
                class="rounded-lg px-3 py-1 text-xs font-bold"
                :class="isDarkMode ? 'bg-[#2a1800] text-[#ff9000]' : 'bg-amber-50 text-amber-800 border border-amber-200'"
              >
                ⏰ {{ formatRemainingTime(item.manual_review_deadline) }}
              </span>
            </div>

            <!-- 圖片比對與資訊主體 -->
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-12 items-start">
              <!-- 原失物圖片 -->
              <div class="lg:col-span-3">
                <p class="text-xs font-bold mb-1.5 opacity-75">原失物登錄照片</p>
                <div class="h-40 overflow-hidden rounded-xl" :class="isDarkMode ? 'bg-[#000000]' : 'bg-gray-100'">
                  <img v-if="item.image" :src="item.image" :alt="item.title" class="h-full w-full object-cover" />
                  <div v-else class="flex h-full items-center justify-center text-xs opacity-50">無原失物照片</div>
                </div>
              </div>

              <!-- 認領人現場照片 -->
              <div class="lg:col-span-3">
                <p class="text-xs font-bold mb-1.5 opacity-75">認領人現場驗證照片</p>
                <div class="h-40 overflow-hidden rounded-xl" :class="isDarkMode ? 'bg-[#000000]' : 'bg-gray-100'">
                  <img
                    v-if="item.claim_proof_image"
                    :src="item.claim_proof_image"
                    alt="現場驗證照片"
                    class="h-full w-full object-cover"
                  />
                  <div v-else class="flex h-full items-center justify-center text-xs opacity-50">尚未提交現場照片</div>
                </div>
              </div>

              <!-- 待核實資料明細 -->
              <div class="lg:col-span-6 space-y-2 text-xs sm:text-sm">
                <h2 class="text-lg font-black">{{ item.title }}</h2>
                <p class="opacity-80">📍 地點：{{ item.location || '未填寫' }} ｜ 📅 時間：{{ item.date }}</p>
                <p class="opacity-80">📧 認領聯絡 Email：{{ item.claimant_email || '尚無認領申請' }}</p>
                <p class="opacity-80">🤖 VLM 累計失敗次數：{{ item.vlm_fail_count ?? 0 }} / 3</p>
                <div
                  v-if="item.vlm_last_reason"
                  class="rounded-xl p-3 text-xs leading-relaxed"
                  :class="isDarkMode ? 'bg-[#000000] text-[#cccccc]' : 'bg-gray-50 text-gray-700 border border-gray-200'"
                >
                  <strong>AI / 系統備註：</strong>{{ item.vlm_last_reason }}
                </div>

                <!-- 審核操作按鈕 -->
                <div class="pt-3 flex flex-wrap gap-2.5">
                  <button
                    v-if="item.review_status === 'needs_manual_review'"
                    type="button"
                    :disabled="processingId === item.id"
                    class="rounded-xl px-4 py-2 text-xs font-bold transition sm:text-sm disabled:opacity-50"
                    :class="isDarkMode ? 'bg-[#ff9000] text-[#000000] hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
                    @click="approveItem(item)"
                  >
                    ✅ 審核通過（寄信並結案）
                  </button>

                  <button
                    v-if="item.review_status === 'needs_manual_review'"
                    type="button"
                    :disabled="processingId === item.id"
                    class="rounded-xl px-4 py-2 text-xs font-bold transition sm:text-sm bg-red-600 text-white hover:bg-red-700 disabled:opacity-50"
                    @click="rejectItem(item)"
                  >
                    ❌ 審核失敗（寄信並重置次數）
                  </button>

                  <button
                    type="button"
                    :disabled="processingId === item.id"
                    class="rounded-xl px-4 py-2 text-xs font-bold transition sm:text-sm disabled:opacity-50"
                    :class="isDarkMode ? 'bg-[#222222] text-[#aaaaaa] hover:bg-[#333333]' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
                    @click="hardDeleteItem(item)"
                  >
                    🗑️ 永久刪除
                  </button>
                </div>
              </div>
            </div>
          </article>
        </div>
      </template>
    </main>

    <!-- 提示視窗 -->
    <div
      v-if="toast.show"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
      @click.self="toast.show = false"
    >
      <div
        class="w-full max-w-sm rounded-2xl p-6 text-center transition-colors duration-300"
        :class="isDarkMode ? 'bg-[#121212] text-[#ffffff]' : 'bg-white text-[#0f172a] shadow-2xl'"
      >
        <h3 class="text-lg font-black mb-2" :class="isDarkMode ? 'text-[#ff9000]' : 'text-[#1e3a8a]'">
          {{ toast.title }}
        </h3>
        <p class="text-sm mb-5 whitespace-pre-line" :class="isDarkMode ? 'text-[#cccccc]' : 'text-gray-600'">
          {{ toast.message }}
        </p>
        <button
          type="button"
          class="w-full rounded-xl py-2.5 text-sm font-bold transition"
          :class="isDarkMode ? 'bg-[#ff9000] text-[#000000] hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
          @click="toast.show = false"
        >
          確定
        </button>
      </div>
    </div>
  </div>
</template>
