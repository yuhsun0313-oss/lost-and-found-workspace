<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAppState } from '../composables/useAppState'
import ClaimVerifyModal from '../components/ClaimVerifyModal.vue'

const route = useRoute()
const router = useRouter()
const { API_BASE_URL, isDarkMode, authToken, formatRemainingTime } = useAppState()

const itemId = route.params.id
const item = ref(null)
const isLoading = ref(true)
const showVerifyModal = ref(false)
const pendingReloadHome = ref(false)

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

function closeToast() {
  toast.show = false
  if (pendingReloadHome.value) {
    pendingReloadHome.value = false
    window.location.assign('/')
  }
}

async function fetchItem() {
  try {
    isLoading.value = true
    const headers = { Accept: 'application/json' }
    if (authToken.value) {
      headers.Authorization = `Bearer ${authToken.value}`
    }
    const response = await fetch(`${API_BASE_URL}/items/${itemId}`, {
      headers,
    })
    const data = await response.json()
    if (response.ok && data.success) {
      item.value = data.data
    }
  } catch (error) {
    console.error('載入物品明細失敗：', error)
  } finally {
    isLoading.value = false
  }
}

onMounted(() => {
  fetchItem()
})

function handleVerified(data) {
  pendingReloadHome.value = true
  showToast('已刪除該物品', data?.message || '已完成認領結案！點擊確定後將重新整理並返回主畫面。')
}
</script>

<template>
  <div
    class="min-h-screen py-10 px-5 transition-colors duration-300"
    :class="isDarkMode ? 'bg-[#000000] text-[#ffffff]' : 'bg-[#f8fafc] text-[#0f172a]'"
  >
    <div
      class="mx-auto max-w-xl rounded-2xl p-6 transition-colors duration-300"
      :class="isDarkMode ? 'bg-[#121212] text-[#ffffff]' : 'bg-white border border-slate-200 shadow-sm'"
    >
      <button
        type="button"
        class="mb-4 text-sm font-bold hover:underline"
        :class="isDarkMode ? 'text-[#ff9000]' : 'text-[#1e3a8a]'"
        @click="router.push('/')"
      >
        ← 返回首頁
      </button>

      <div v-if="isLoading" class="py-12 text-center text-sm opacity-70">載入失物明細中...</div>

      <div v-else-if="!item" class="py-12 text-center text-sm opacity-70">找不到該筆失物紀錄。</div>

      <template v-else>
        <div class="relative h-64 overflow-hidden rounded-xl" :class="isDarkMode ? 'bg-[#000000]' : 'bg-slate-100'">
          <img v-if="item.image" :src="item.image" :alt="item.title" class="h-full w-full object-cover" />
          <div v-else class="flex h-full items-center justify-center text-slate-400">尚無物品照片</div>
          <span
            class="absolute left-3 top-3 rounded-full px-3 py-1 text-xs font-bold"
            :class="item.type === 'found' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'"
          >
            {{ item.type === 'found' ? '已拾獲' : '正在尋找' }}
          </span>
        </div>

        <div class="mt-6">
          <span class="text-xs font-semibold" :class="isDarkMode ? 'text-[#ff9000]' : 'text-[#1e3a8a]'">{{ item.category }}</span>
          <h1 class="mt-1 text-2xl font-black">{{ item.title }}</h1>

          <div
            class="mt-4 space-y-2 text-sm border-t pt-4"
            :class="isDarkMode ? 'text-[#cccccc] border-[#222222]' : 'text-slate-600 border-slate-100'"
          >
            <p>📍 發現地點：{{ item.location }}</p>
            <p>📅 刊登日期：{{ item.date }}</p>
            <p>🆔 物品代號：#{{ item.id }}</p>
          </div>

          <div
            v-if="item.review_status === 'needs_manual_review'"
            class="mt-4 rounded-xl p-3 text-xs bg-amber-500/15 border border-amber-500/40"
            :class="isDarkMode ? 'text-amber-300' : 'text-amber-800'"
          >
            ⏳ 目前正由管理員「人工辨識」審核中（{{ formatRemainingTime(item.manual_review_deadline) }}）。
          </div>

          <button
            type="button"
            :disabled="item.review_status === 'needs_manual_review' || item.review_status === 'completed'"
            class="mt-8 w-full rounded-xl py-3 text-sm font-bold transition disabled:opacity-50"
            :class="isDarkMode ? 'bg-[#ff9000] text-[#000000] hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
            @click="showVerifyModal = true"
          >
            {{ item.review_status === 'needs_manual_review' ? '人工辨識審核中' : '已找到，驗證並刪除' }}
          </button>
        </div>
      </template>
    </div>

    <ClaimVerifyModal
      :show="showVerifyModal"
      :item="item"
      @close="showVerifyModal = false"
      @verified="handleVerified"
      @escalated="fetchItem"
      @toast="showToast"
    />

    <div
      v-if="toast.show"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
      @click.self="closeToast"
    >
      <div
        class="w-full max-w-sm rounded-2xl p-6 text-center"
        :class="isDarkMode ? 'bg-[#121212] text-[#ffffff]' : 'bg-white text-[#0f172a] shadow-2xl'"
      >
        <h3 class="text-lg font-black mb-2" :class="isDarkMode ? 'text-[#ff9000]' : 'text-[#1e3a8a]'">{{ toast.title }}</h3>
        <p class="text-sm mb-5 whitespace-pre-line">{{ toast.message }}</p>
        <button
          type="button"
          class="w-full rounded-xl py-2.5 text-sm font-bold"
          :class="isDarkMode ? 'bg-[#ff9000] text-[#000000] hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
          @click="closeToast"
        >
          確定
        </button>
      </div>
    </div>
  </div>
</template>
