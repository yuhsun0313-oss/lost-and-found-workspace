<script setup>
import { ref, watch } from 'vue'
import { useAppState } from '../composables/useAppState'

const props = defineProps({
  show: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'toast'])

const { API_BASE_URL, isDarkMode, authToken, currentUser, currentUserEmail, setAuthSession } =
  useAppState()

const activeTab = ref('email') // 'email' | 'password' | 'username'
const isSubmitting = ref(false)
const statusMessage = ref('')
const statusError = ref('')

// 1. 更改 Email 流程狀態 (1:輸入目前密碼 -> 2:輸入原Email驗證碼 -> 3:輸入新Email並寄信 -> 4:輸入新Email驗證碼)
const emailStep = ref(1)
const emailCurrentPassword = ref('')
const oldEmailCode = ref('')
const newEmailInput = ref('')
const newEmailCode = ref('')

// 2. 更改密碼流程狀態 (1:發送驗證碼 -> 2:輸入驗證碼與新密碼)
const passwordStep = ref(1)
const passwordCode = ref('')
const newPassword = ref('')
const newPasswordConfirm = ref('')

// 3. 更改使用者 ID 狀態
const newUsername = ref('')
const usernameCurrentPassword = ref('')

function resetForms() {
  statusMessage.value = ''
  statusError.value = ''
  emailStep.value = 1
  emailCurrentPassword.value = ''
  oldEmailCode.value = ''
  newEmailInput.value = ''
  newEmailCode.value = ''
  passwordStep.value = 1
  passwordCode.value = ''
  newPassword.value = ''
  newPasswordConfirm.value = ''
  newUsername.value = currentUser.value || ''
  usernameCurrentPassword.value = ''
}

watch(
  () => props.show,
  (visible) => {
    if (visible) {
      resetForms()
    }
  }
)

watch(activeTab, () => {
  statusMessage.value = ''
  statusError.value = ''
})

async function postAccount(endpoint, body) {
  statusError.value = ''
  statusMessage.value = ''
  isSubmitting.value = true
  try {
    const response = await fetch(`${API_BASE_URL}/account${endpoint}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${authToken.value}`,
      },
      body: JSON.stringify(body),
    })
    const data = await response.json()
    if (!response.ok || !data.success) {
      const firstErr = data.errors ? Object.values(data.errors).flat()[0] : null
      statusError.value = firstErr || data.message || '操作失敗，請稍後再試'
      return null
    }
    statusMessage.value = data.message || '操作成功'
    return data
  } catch (error) {
    console.error('帳號設定請求失敗：', error)
    statusError.value = '無法連線至伺服器，請稍後再試。'
    return null
  } finally {
    isSubmitting.value = false
  }
}

// --- 更改 Email ---
async function handleSendOldEmailCode() {
  if (!emailCurrentPassword.value) {
    statusError.value = '請先輸入目前密碼！'
    return
  }
  const data = await postAccount('/email/send-old-code', {
    current_password: emailCurrentPassword.value,
  })
  if (data) {
    emailStep.value = 2
  }
}

async function handleVerifyOldEmailCode() {
  if (oldEmailCode.value.trim().length !== 6) {
    statusError.value = '請輸入 6 位數原信箱驗證碼！'
    return
  }
  const data = await postAccount('/email/verify-old-code', {
    code: oldEmailCode.value.trim(),
  })
  if (data) {
    emailStep.value = 3
  }
}

async function handleSendNewEmailCode() {
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
  if (!emailRegex.test(newEmailInput.value.trim())) {
    statusError.value = '請輸入格式正確的新 Email！'
    return
  }
  const data = await postAccount('/email/send-new-code', {
    new_email: newEmailInput.value.trim(),
  })
  if (data) {
    emailStep.value = 4
  }
}

async function handleConfirmNewEmail() {
  if (newEmailCode.value.trim().length !== 6) {
    statusError.value = '請輸入 6 位數新信箱驗證碼！'
    return
  }
  const data = await postAccount('/email/confirm', {
    new_email: newEmailInput.value.trim(),
    code: newEmailCode.value.trim(),
  })
  if (data?.user) {
    setAuthSession(authToken.value, data.user)
    emit('toast', '變更成功', `您的登入 Email 已變更為 ${data.user.email}！`)
    emit('close')
  }
}

// --- 更改密碼 ---
async function handleSendPasswordCode() {
  const data = await postAccount('/password/send-code', {})
  if (data) {
    passwordStep.value = 2
  }
}

async function handleConfirmPasswordChange() {
  if (passwordCode.value.trim().length !== 6) {
    statusError.value = '請輸入 6 位數驗證碼！'
    return
  }
  if (newPassword.value.length < 6) {
    statusError.value = '新密碼至少需 6 個字元！'
    return
  }
  if (newPassword.value !== newPasswordConfirm.value) {
    statusError.value = '兩次輸入的新密碼不一致！'
    return
  }
  const data = await postAccount('/password/confirm', {
    code: passwordCode.value.trim(),
    new_password: newPassword.value,
    new_password_confirmation: newPasswordConfirm.value,
  })
  if (data) {
    emit('toast', '密碼已變更', '您的登入密碼已成功更新！')
    emit('close')
  }
}

// --- 更改使用者 ID ---
async function handleUpdateUsername() {
  if (!newUsername.value.trim()) {
    statusError.value = '請輸入想要變更的新使用者 ID！'
    return
  }
  if (!usernameCurrentPassword.value) {
    statusError.value = '請輸入目前密碼以進行驗證！'
    return
  }
  const data = await postAccount('/username', {
    new_name: newUsername.value.trim(),
    current_password: usernameCurrentPassword.value,
  })
  if (data?.user) {
    setAuthSession(authToken.value, data.user)
    emit('toast', '使用者 ID 已變更', `您的使用者 ID 已成功變更為 ${data.user.name}！`)
    emit('close')
  }
}
</script>

<template>
  <div
    v-if="show"
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4"
    @click.self="emit('close')"
  >
    <div
      class="w-full max-w-md rounded-2xl p-5 max-h-[90vh] overflow-y-auto sm:p-6 transition-colors duration-300"
      :class="isDarkMode ? 'bg-[#121212] text-[#ffffff]' : 'bg-white text-[#0f172a] shadow-2xl'"
    >
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-base font-black sm:text-lg" :class="isDarkMode ? 'text-[#ff9000]' : 'text-[#1e3a8a]'">
          ⚙️ 更改基本資料
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

      <!-- 頁籤切換 -->
      <div class="grid grid-cols-3 gap-2 mb-5">
        <button
          v-for="tab in [
            { key: 'email', label: '更改 Email' },
            { key: 'password', label: '更改密碼' },
            { key: 'username', label: '更改 ID' },
          ]"
          :key="tab.key"
          type="button"
          class="rounded-xl py-2 text-xs font-bold transition"
          :class="activeTab === tab.key
            ? (isDarkMode ? 'bg-[#ff9000] text-[#000000]' : 'bg-[#1e3a8a] text-white')
            : (isDarkMode ? 'bg-[#1f1f1f] text-[#aaaaaa] hover:text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200')"
          @click="activeTab = tab.key"
        >
          {{ tab.label }}
        </button>
      </div>

      <!-- 狀態提示 -->
      <div
        v-if="statusMessage"
        class="mb-4 rounded-xl p-3 text-xs border"
        :class="isDarkMode ? 'bg-emerald-950/50 border-emerald-800 text-emerald-300' : 'bg-emerald-50 border-emerald-200 text-emerald-700'"
      >
        ✅ {{ statusMessage }}
      </div>
      <div
        v-if="statusError"
        class="mb-4 rounded-xl p-3 text-xs border"
        :class="isDarkMode ? 'bg-[#2a0e12] border-[#7f1d1d] text-[#fca5a5]' : 'bg-red-50 border-red-200 text-red-700'"
      >
        ⚠️ {{ statusError }}
      </div>

      <!-- 分頁 1：更改 Email -->
      <div v-if="activeTab === 'email'" class="space-y-4">
        <p class="text-xs" :class="isDarkMode ? 'text-[#888888]' : 'text-gray-500'">
          目前綁定 Email：<strong :class="isDarkMode ? 'text-white' : 'text-gray-900'">{{ currentUserEmail }}</strong>
        </p>

        <div v-if="emailStep === 1" class="space-y-3">
          <label class="block text-xs font-bold">步驟 1：請先驗證目前密碼</label>
          <input
            v-model="emailCurrentPassword"
            type="password"
            placeholder="輸入目前密碼"
            class="w-full rounded-xl px-4 py-2.5 text-sm outline-none"
            :class="isDarkMode ? 'bg-[#000000] text-white border border-[#2a2a2a]' : 'bg-gray-100 text-gray-900 border border-gray-200'"
          />
          <button
            type="button"
            :disabled="isSubmitting"
            class="w-full rounded-xl py-2.5 text-xs font-bold transition disabled:opacity-50"
            :class="isDarkMode ? 'bg-[#ff9000] text-black hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
            @click="handleSendOldEmailCode"
          >
            {{ isSubmitting ? '寄送中...' : '驗證密碼並寄送驗證碼至原 Email' }}
          </button>
        </div>

        <div v-else-if="emailStep === 2" class="space-y-3">
          <label class="block text-xs font-bold">步驟 2：輸入原 Email 收到的 6 位數驗證碼</label>
          <input
            v-model="oldEmailCode"
            type="text"
            maxlength="6"
            placeholder="輸入 6 位數驗證碼"
            class="w-full rounded-xl px-4 py-2.5 text-sm outline-none tracking-widest"
            :class="isDarkMode ? 'bg-[#000000] text-white border border-[#2a2a2a]' : 'bg-gray-100 text-gray-900 border border-gray-200'"
          />
          <button
            type="button"
            :disabled="isSubmitting"
            class="w-full rounded-xl py-2.5 text-xs font-bold transition disabled:opacity-50"
            :class="isDarkMode ? 'bg-[#ff9000] text-black hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
            @click="handleVerifyOldEmailCode"
          >
            {{ isSubmitting ? '驗證中...' : '驗證原 Email 驗證碼' }}
          </button>
        </div>

        <div v-else-if="emailStep === 3" class="space-y-3">
          <label class="block text-xs font-bold">步驟 3：輸入要綁定的新 Email</label>
          <input
            v-model="newEmailInput"
            type="email"
            placeholder="輸入新 Email（例如 new@gmail.com）"
            class="w-full rounded-xl px-4 py-2.5 text-sm outline-none"
            :class="isDarkMode ? 'bg-[#000000] text-white border border-[#2a2a2a]' : 'bg-gray-100 text-gray-900 border border-gray-200'"
          />
          <button
            type="button"
            :disabled="isSubmitting"
            class="w-full rounded-xl py-2.5 text-xs font-bold transition disabled:opacity-50"
            :class="isDarkMode ? 'bg-[#ff9000] text-black hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
            @click="handleSendNewEmailCode"
          >
            {{ isSubmitting ? '寄送中...' : '寄送驗證碼至新 Email' }}
          </button>
        </div>

        <div v-else class="space-y-3">
          <label class="block text-xs font-bold">步驟 4：輸入新 Email 收到的 6 位數驗證碼</label>
          <input
            v-model="newEmailCode"
            type="text"
            maxlength="6"
            placeholder="輸入 6 位數驗證碼"
            class="w-full rounded-xl px-4 py-2.5 text-sm outline-none tracking-widest"
            :class="isDarkMode ? 'bg-[#000000] text-white border border-[#2a2a2a]' : 'bg-gray-100 text-gray-900 border border-gray-200'"
          />
          <button
            type="button"
            :disabled="isSubmitting"
            class="w-full rounded-xl py-2.5 text-xs font-bold transition disabled:opacity-50"
            :class="isDarkMode ? 'bg-[#ff9000] text-black hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
            @click="handleConfirmNewEmail"
          >
            {{ isSubmitting ? '更新中...' : '確認更改 Email' }}
          </button>
        </div>
      </div>

      <!-- 分頁 2：更改密碼 -->
      <div v-else-if="activeTab === 'password'" class="space-y-4">
        <p class="text-xs" :class="isDarkMode ? 'text-[#888888]' : 'text-gray-500'">
          將寄送驗證碼至目前綁定的 Email：<strong :class="isDarkMode ? 'text-white' : 'text-gray-900'">{{ currentUserEmail }}</strong>
        </p>

        <div v-if="passwordStep === 1">
          <button
            type="button"
            :disabled="isSubmitting"
            class="w-full rounded-xl py-2.5 text-xs font-bold transition disabled:opacity-50"
            :class="isDarkMode ? 'bg-[#ff9000] text-black hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
            @click="handleSendPasswordCode"
          >
            {{ isSubmitting ? '寄送中...' : '寄送驗證碼至綁定 Email' }}
          </button>
        </div>

        <div v-else class="space-y-3">
          <div>
            <label class="block text-xs font-bold mb-1">6 位數驗證碼</label>
            <input
              v-model="passwordCode"
              type="text"
              maxlength="6"
              placeholder="輸入信箱收到的 6 位數驗證碼"
              class="w-full rounded-xl px-4 py-2.5 text-sm outline-none tracking-widest"
              :class="isDarkMode ? 'bg-[#000000] text-white border border-[#2a2a2a]' : 'bg-gray-100 text-gray-900 border border-gray-200'"
            />
          </div>
          <div>
            <label class="block text-xs font-bold mb-1">新密碼（至少 6 碼）</label>
            <input
              v-model="newPassword"
              type="password"
              placeholder="輸入新密碼"
              class="w-full rounded-xl px-4 py-2.5 text-sm outline-none"
              :class="isDarkMode ? 'bg-[#000000] text-white border border-[#2a2a2a]' : 'bg-gray-100 text-gray-900 border border-gray-200'"
            />
          </div>
          <div>
            <label class="block text-xs font-bold mb-1">確認新密碼</label>
            <input
              v-model="newPasswordConfirm"
              type="password"
              placeholder="再次輸入新密碼"
              class="w-full rounded-xl px-4 py-2.5 text-sm outline-none"
              :class="isDarkMode ? 'bg-[#000000] text-white border border-[#2a2a2a]' : 'bg-gray-100 text-gray-900 border border-gray-200'"
            />
          </div>
          <div class="flex gap-2">
            <button
              type="button"
              :disabled="isSubmitting"
              class="flex-1 rounded-xl py-2.5 text-xs font-bold transition disabled:opacity-50"
              :class="isDarkMode ? 'bg-[#ff9000] text-black hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
              @click="handleConfirmPasswordChange"
            >
              {{ isSubmitting ? '更新中...' : '確認變更密碼' }}
            </button>
            <button
              type="button"
              :disabled="isSubmitting"
              class="rounded-xl px-3 py-2.5 text-xs font-bold transition"
              :class="isDarkMode ? 'bg-[#222222] text-[#aaaaaa]' : 'bg-gray-200 text-gray-700'"
              @click="handleSendPasswordCode"
            >
              重寄
            </button>
          </div>
        </div>
      </div>

      <!-- 分頁 3：更改使用者 ID -->
      <div v-else class="space-y-3">
        <div>
          <label class="block text-xs font-bold mb-1">新的使用者 ID</label>
          <input
            v-model="newUsername"
            type="text"
            placeholder="輸入想變更的新使用者 ID"
            class="w-full rounded-xl px-4 py-2.5 text-sm outline-none"
            :class="isDarkMode ? 'bg-[#000000] text-white border border-[#2a2a2a]' : 'bg-gray-100 text-gray-900 border border-gray-200'"
          />
        </div>
        <div>
          <label class="block text-xs font-bold mb-1">目前密碼驗證</label>
          <input
            v-model="usernameCurrentPassword"
            type="password"
            placeholder="輸入目前密碼"
            class="w-full rounded-xl px-4 py-2.5 text-sm outline-none"
            :class="isDarkMode ? 'bg-[#000000] text-white border border-[#2a2a2a]' : 'bg-gray-100 text-gray-900 border border-gray-200'"
          />
        </div>
        <button
          type="button"
          :disabled="isSubmitting"
          class="w-full rounded-xl py-2.5 text-xs font-bold transition mt-2 disabled:opacity-50"
          :class="isDarkMode ? 'bg-[#ff9000] text-black hover:bg-[#e07f00]' : 'bg-[#1e3a8a] text-white hover:bg-[#1e40af]'"
          @click="handleUpdateUsername"
        >
          {{ isSubmitting ? '儲存中...' : '確認更改使用者 ID' }}
        </button>
      </div>
    </div>
  </div>
</template>
