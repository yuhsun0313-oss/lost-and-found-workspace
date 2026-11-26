<script setup>
import { ref, watch } from 'vue'
import { useAppState } from '../composables/useAppState'

const props = defineProps({
  show: { type: Boolean, default: false },
  item: { type: Object, default: null },
})

const emit = defineEmits(['close', 'verified', 'escalated', 'toast'])

const { API_BASE_URL, isDarkMode, currentUserEmail, compressImageToDataUrl } = useAppState()

const claimantEmail = ref('')
const proofPhotoDataUrl = ref('')
const isVerifying = ref(false)
const vlmFeedback = ref('')
const failCount = ref(0)

watch(
  () => [props.show, props.item],
  ([visible]) => {
    if (visible) {
      claimantEmail.value = currentUserEmail.value || ''
      proofPhotoDataUrl.value = ''
      vlmFeedback.value = ''
      failCount.value = props.item?.vlm_fail_count || 0
    }
  }
)

async function handleProofUpload(event) {
  const file = event.target.files?.[0]
  if (!file) return

  if (!file.type.startsWith('image/')) {
    emit('toast', '格式錯誤', '只能上傳圖片格式的檔案喔！（例如 JPG、PNG）')
    event.target.value = ''
    proofPhotoDataUrl.value = ''
    return
  }

  try {
    proofPhotoDataUrl.value = await compressImageToDataUrl(file, 1024, 0.82)
  } catch {
    emit('toast', '讀取失敗', '無法讀取您選擇的圖片，請重新選擇！')
  }
}

async function submitVerification() {
  if (isVerifying.value || !props.item) return

  const email = claimantEmail.value.trim()
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
  if (!emailRegex.test(email)) {
    emit('toast', 'Gmail 格式錯誤', '請填寫正確的聯絡 Gmail，以利後續面交與審核通知！')
    return
  }

  if (!proofPhotoDataUrl.value) {
    emit('toast', '尚未上傳照片', '為防止誤刪，請務必上傳尋獲／認領物品的現場照片！')
    return
  }

  try {
    isVerifying.value = true
    vlmFeedback.value = ''

    const response = await fetch(`${API_BASE_URL}/items/${props.item.id}/verify-claim`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        claimant_email: email,
        photo: proofPhotoDataUrl.value,
      }),
    })

    const data = await response.json()

    if (response.status === 409 || data.needs_manual_review) {
      emit('escalated', data)
      emit('toast', '已轉交人工審核', data.message || '此物品已轉交管理員進行人工辨識（限期 3 天）。')
      emit('close')
      return
    }

    if (!response.ok) {
      vlmFeedback.value = data.message || '驗證服務發生異常，請稍後再試。'
      return
    }

    if (data.verified) {
      emit('verified', data)
      emit('close')
    } else {
      failCount.value = data.vlm_fail_count ?? failCount.value + 1
      vlmFeedback.value = data.message || '現場照片與原失物特徵不符，請拍攝更清晰的照片再試一次。'
    }
  } catch (error) {
    console.error('VLM 驗證請求失敗：', error)
    vlmFeedback.value = '無法連線到 VLM 驗證伺服器，請稍後再試。'
  } finally {
    isVerifying.value = false
  }
}
</script>

<template>
  <div
    v-if="show && item"
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4"
    @click.self="emit('close')"
  >
    <div
      class="w-full max-w-md rounded-2xl p-5 max-h-[90vh] overflow-y-auto sm:p-6 transition-colors duration-300"
      :class="isDarkMode ? 'bg-[#121212] text-[#ffffff]' : 'bg-white text-[#0f172a] shadow-2xl'"
    >
      <div class="flex items-center justify-between mb-3">
        <h3 class="text-base font-black sm:text-lg" :class="isDarkMode ? 'text-[#ff9000]' : 'text-[#1e3a8a]'">
          🛡️ {{ item.type === 'lost' ? '拾獲回報驗證與結案' : '失主認領驗證與結案' }}
        </h3>
        <button
          type="button"
          class="text-sm font-bold transition"
          :class="isDarkMode ? 'text-[#888888] hover:text-white' : 'text-gray-400 hover:text-gray-700'"
          @click="emit('close')"
        >
          ✕
        </button>
      </div>

      <p class="text-xs leading-relaxed mb-4" :class="isDarkMode ? 'text-[#aaaaaa]' : 'text-gray-600'">
        為避免失物遭誤刪，請填寫您的聯絡 Gmail 並上傳現場物品照片。系統將透過 VLM 視覺模型比對特徵（累計失敗達 3 次將轉交管理員人工辨識 3 天）。
      </p>

      <div
        class="rounded-xl p-3 mb-4 text-xs"
        :class="isDarkMode ? 'bg-[#000000] border border-[#222222]' : 'bg-gray-50 border border-gray-200'"
      >
        <p class="font-bold truncate">目標失物：{{ item.title }}</p>
        <p class="mt-0.5" :class="isDarkMode ? 'text-[#888888]' : 'text-gray-500'">
          目前 VLM 失敗次數：{{ failCount }} / 3
        </p>
      </div>

      <div class="space-y-4">
        <div>
          <label class="block text-xs font-bold mb-1" :class="isDarkMode ? 'text-[#cccccc]' : 'text-gray-700'">
            聯絡 Gmail *
          </label>
          <input
            v-model="claimantEmail"
            type="email"
            placeholder="請輸入您的 Gmail 信箱（用於面交與審核通知）"
            class="w-full rounded-xl px-4 py-2.5 text-sm outline-none transition-colors duration-300"
            :class="isDarkMode ? 'bg-[#000000] text-[#ffffff] placeholder-[#555555] border border-[#2a2a2a]' : 'bg-gray-100 text-[#0f172a] placeholder-gray-400 border border-gray-200'"
          />
        </div>

        <div>
          <label class="block text-xs font-bold mb-1" :class="isDarkMode ? 'text-[#cccccc]' : 'text-gray-700'">
            物品現場照片 *
          </label>
          <div
            class="flex items-center justify-between rounded-xl px-4 py-2 transition-colors duration-300"
            :class="isDarkMode ? 'bg-[#000000] border border-[#2a2a2a]' : 'bg-gray-100 border border-gray-200'"
          >
            <span class="text-xs truncate pr-2" :class="isDarkMode ? 'text-[#888888]' : 'text-gray-500'">
              {{ proofPhotoDataUrl ? '已選擇現場照片' : '尚未選擇照片' }}
            </span>
            <label
              class="cursor-pointer rounded-lg px-4 py-1.5 text-xs font-bold transition shrink-0"
              :class="isDarkMode ? 'bg-[#ff9000] text-[#000000] hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
            >
              拍攝 / 上傳照片
              <input type="file" accept="image/*" class="hidden" @change="handleProofUpload" />
            </label>
          </div>

          <div v-if="proofPhotoDataUrl" class="mt-3 relative h-40 w-full overflow-hidden rounded-xl bg-black">
            <img :src="proofPhotoDataUrl" alt="現場驗證照片預覽" class="h-full w-full object-cover" />
            <button
              type="button"
              class="absolute top-2 right-2 rounded-full bg-black/80 px-2.5 py-1 text-xs text-white hover:bg-black"
              @click="proofPhotoDataUrl = ''"
            >
              ✕ 移除
            </button>
          </div>
        </div>

        <div
          v-if="vlmFeedback"
          class="rounded-xl p-3 text-xs leading-relaxed border"
          :class="isDarkMode ? 'bg-[#2a0e12] border-[#7f1d1d] text-[#fca5a5]' : 'bg-red-50 border-red-200 text-red-700'"
        >
          {{ vlmFeedback }}
        </div>
      </div>

      <div class="mt-6 flex gap-3">
        <button
          type="button"
          :disabled="isVerifying"
          class="flex-1 rounded-xl py-2.5 text-xs font-bold transition sm:text-sm disabled:opacity-50"
          :class="isDarkMode ? 'bg-[#ff9000] text-[#000000] hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
          @click="submitVerification"
        >
          {{ isVerifying ? 'VLM 視覺比對中...' : '送出 AI 視覺驗證' }}
        </button>
        <button
          type="button"
          :disabled="isVerifying"
          class="rounded-xl px-4 py-2.5 text-xs font-bold transition sm:text-sm"
          :class="isDarkMode ? 'bg-[#222222] text-[#aaaaaa] hover:bg-[#333333]' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
          @click="emit('close')"
        >
          取消
        </button>
      </div>
    </div>
  </div>
</template>
