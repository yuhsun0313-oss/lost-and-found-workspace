<script setup>
import { onMounted } from 'vue'
import { useAppState } from './composables/useAppState'

const { isDarkMode, toggleTheme, verifyAuthToken } = useAppState()

// 解決 F5 重新整理登出問題：頁面載入時向後端 /api/me 驗證 localStorage 中的 Auth Token
onMounted(() => {
  verifyAuthToken()
})
</script>

<template>
  <router-view />

  <!-- 右下角浮動圓形主題切換按鈕（全站前台與管理後台一致） -->
  <button
    type="button"
    class="fixed bottom-6 right-6 z-50 flex items-center justify-center w-12 h-12 rounded-full shadow-2xl transition-all duration-300 hover:scale-110 cursor-pointer"
    :class="isDarkMode ? 'bg-[#ff9000] text-black shadow-orange-500/20' : 'bg-[#1e3a8a] text-white shadow-blue-900/20'"
    title="切換深色/淺色模式"
    @click="toggleTheme"
  >
    <svg v-if="isDarkMode" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-6 h-6">
      <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
    </svg>
    <svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-6 h-6">
      <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
    </svg>
  </button>
</template>
