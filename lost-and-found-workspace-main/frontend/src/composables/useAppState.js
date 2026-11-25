import { computed, ref } from 'vue'

export const API_BASE_URL =
  import.meta.env.VITE_API_BASE_URL || 'https://lost-and-found-workspace.onrender.com/api'

const AUTH_TOKEN_KEY = 'auth_token'
const THEME_STORAGE_KEY = 'theme_mode'

const isDarkMode = ref(localStorage.getItem(THEME_STORAGE_KEY) === 'dark')
const authToken = ref(localStorage.getItem(AUTH_TOKEN_KEY) || '')
const isLoggedIn = ref(false)
const currentUser = ref('')
const currentUserEmail = ref('')
const userIdentity = ref('一般成員')
const isAuthChecking = ref(true)

const isAdmin = computed(() => isLoggedIn.value && userIdentity.value === '管理員')

function toggleTheme() {
  isDarkMode.value = !isDarkMode.value
  localStorage.setItem(THEME_STORAGE_KEY, isDarkMode.value ? 'dark' : 'light')
}

function setAuthSession(token, user) {
  if (token) {
    authToken.value = token
    localStorage.setItem(AUTH_TOKEN_KEY, token)
  }
  isLoggedIn.value = true
  currentUser.value = user?.name || ''
  currentUserEmail.value = user?.email || ''
  userIdentity.value = user?.identity || '一般成員'
}

function clearAuthSession() {
  authToken.value = ''
  localStorage.removeItem(AUTH_TOKEN_KEY)
  isLoggedIn.value = false
  currentUser.value = ''
  currentUserEmail.value = ''
  userIdentity.value = '一般成員'
}

async function verifyAuthToken() {
  const token = localStorage.getItem(AUTH_TOKEN_KEY)
  if (!token) {
    clearAuthSession()
    isAuthChecking.value = false
    return false
  }

  isAuthChecking.value = true
  try {
    const response = await fetch(`${API_BASE_URL}/me`, {
      method: 'GET',
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
    })

    if (!response.ok) {
      clearAuthSession()
      return false
    }

    const data = await response.json()
    if (data?.valid && data?.user) {
      setAuthSession(token, data.user)
      return true
    }

    clearAuthSession()
    return false
  } catch (error) {
    console.error('驗證登入憑證失敗：', error)
    return false
  } finally {
    isAuthChecking.value = false
  }
}

async function logoutSession() {
  const token = authToken.value || localStorage.getItem(AUTH_TOKEN_KEY)
  if (token) {
    try {
      await fetch(`${API_BASE_URL}/logout`, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
      })
    } catch (error) {
      console.warn('登出撤銷 Token 失敗：', error)
    }
  }
  clearAuthSession()
}

function compressImageToDataUrl(file, maxWidth = 1024, quality = 0.82) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onerror = () => reject(new Error('讀取圖片失敗'))
    reader.onload = (event) => {
      const img = new Image()
      img.onerror = () => resolve(event.target.result)
      img.onload = () => {
        const scale = img.width > maxWidth ? maxWidth / img.width : 1
        const canvas = document.createElement('canvas')
        canvas.width = Math.round(img.width * scale)
        canvas.height = Math.round(img.height * scale)
        const ctx = canvas.getContext('2d')
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height)
        resolve(canvas.toDataURL('image/jpeg', quality))
      }
      img.src = event.target.result
    }
    reader.readAsDataURL(file)
  })
}

function formatRemainingTime(deadlineStr) {
  if (!deadlineStr) return '未設定期限'
  const diffMs = new Date(deadlineStr).getTime() - Date.now()
  if (Number.isNaN(diffMs)) return '格式異常'
  if (diffMs <= 0) return '已逾期（系統將自動重置）'

  const totalMinutes = Math.floor(diffMs / 60000)
  const days = Math.floor(totalMinutes / (60 * 24))
  const hours = Math.floor((totalMinutes % (60 * 24)) / 60)
  const minutes = totalMinutes % 60
  return `剩餘 ${days} 天 ${hours} 小時 ${minutes} 分`
}

export function useAppState() {
  return {
    API_BASE_URL,
    isDarkMode,
    authToken,
    isLoggedIn,
    currentUser,
    currentUserEmail,
    userIdentity,
    isAdmin,
    isAuthChecking,
    toggleTheme,
    setAuthSession,
    clearAuthSession,
    verifyAuthToken,
    logoutSession,
    compressImageToDataUrl,
    formatRemainingTime,
  }
}
