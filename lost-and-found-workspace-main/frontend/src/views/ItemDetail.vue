<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

// 取得網址上的 id（例如 /item/1 的 1）
const itemId = route.params.id

// 這裡我們暫時用模擬的資料（之後會教你怎麼跟首頁共享狀態或從後端撈）
const item = ref({
  id: Number(itemId),
  title: '白色無線耳機盒',
  category: '電子產品',
  location: '電腦教室',
  date: '2026-10-10',
  type: 'found',
  image: 'https://images.unsplash.com/photo-1606220945770-b5b6c2c55bf1?w=600',
})

// 確認刪除的函式
function deleteItem() {
  const confirmed = confirm('確定要刪除這筆失物紀錄嗎？')
  if (confirmed) {
    alert('已成功刪除！返回首頁')
    // 刪除後導回首頁
    router.push('/')
  }
}
</script>

<template>
  <div class="min-h-screen bg-slate-50 py-10 px-5 text-slate-800">
    <div class="mx-auto max-w-xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
      <!-- 返回按鈕 -->
      <button
        class="mb-4 text-sm font-bold text-indigo-600 hover:underline"
        @click="router.push('/')"
      >
        ← 返回首頁
      </button>

      <!-- 物品圖片 -->
      <div class="relative h-64 overflow-hidden rounded-xl bg-slate-100">
        <img
          v-if="item.image"
          :src="item.image"
          :alt="item.title"
          class="h-full w-full object-cover"
        />
        <div v-else class="flex h-full items-center justify-center text-slate-400">
          尚無物品照片
        </div>
        <span
          class="absolute left-3 top-3 rounded-full px-3 py-1 text-xs font-bold"
          :class="item.type === 'found' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'"
        >
          {{ item.type === 'found' ? '已拾獲' : '正在尋找' }}
        </span>
      </div>

      <!-- 物品詳細資訊 -->
      <div class="mt-6">
        <span class="text-xs font-semibold text-indigo-600">{{ item.category }}</span>
        <h1 class="mt-1 text-2xl font-black">{{ item.title }}</h1>

        <div class="mt-4 space-y-2 text-sm text-slate-600 border-t border-slate-100 pt-4">
          <p>📍 發現地點：{{ item.location }}</p>
          <p>📅 刊登日期：{{ item.date }}</p>
          <p>🆔 物品代號：#{{ item.id }}</p>
        </div>

        <!-- 刪除按鈕 -->
        <button
          class="mt-8 w-full rounded-xl border border-rose-200 bg-rose-50
          py-3 text-sm font-bold text-rose-600 hover:bg-rose-100 transition"
          @click="deleteItem"
        >
          已找到，確定刪除？
        </button>
      </div>
    </div>
  </div>
</template>