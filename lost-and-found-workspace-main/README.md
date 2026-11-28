# 校園 AI 尋物通（Campus AI Lost & Found）

「校園 AI 尋物通」是一套結合 **大型語言模型（LLM）語意檢索** 與 **視覺語言模型（VLM）防誤刪驗證** 的智慧校園失物招領系統。系統支援「遺失協尋」與「拾獲招領」雙向流程，從刊登、AI 語意搜尋、現場照片比對認領、人工審核管理到雙向 Email 面交通知與自動歸檔清除，提供完整的校園失物管理體驗。

---

## 一、 系統定位與完整功能導覽

### 1. 雙向失物處理流程
- **已拾獲（招領，`found`）**：拾獲人刊登物品照片、地點與聯絡 Gmail；失主前來認領並上傳現場／持有證明照片進行 VLM 比對，通過後自動通知原拾獲人聯繫面交。
- **正在尋找（遺失，`lost`）**：失主刊登遺失物照片、地點與聯絡 Gmail；熱心拾獲人回報找到並上傳現場照片通過 VLM 或管理員人工驗證後，自動通知失主聯繫面交。
- **強制附圖與聯絡信箱規則**：無論刊登招領或協尋，表單皆強制填寫「聯絡人 Gmail」（預設帶入目前登入帳號信箱，可手動修改）並強制上傳物品圖片，確保後續 VLM 視覺比對有完整依據。

### 2. 核心系統功能
- **單一 AI 智慧語意搜尋（首頁 Hero 整合）**
  - 將語意搜尋欄整合至首頁主視覺區塊，支援自然語言描述查詢（例如：「我在綜三不見了筆電」）。
  - 自動去除語氣助詞干擾以維持檢索一致性，並進行**全品項／品牌型號聯想**與**校園大樓／教室同義詞擴展**。
  - 內建**常識與合理性防呆稽核**，攔截惡作劇或不合常理描述並給予提示，再依物品與地點權重分數排序回傳。
- **VLM 視覺比對防誤刪驗證**
  - 物品詳細頁將刪除機制改為「已找到，驗證並刪除」，點擊後須填寫聯絡 Gmail 並上傳現場物品照片。
  - 後端先檢查是否為同一張／高相似度重壓縮影像，再將「原失物圖片」與「現場照片」正規化為 Base64 傳入 VLM 模型進行比對。
  - 驗證通過後自動結案並跳出「已刪除該物品」確認視窗，確認後自動刷新並導回主畫面。
- **單一帳號驗證次數限制與人工審核機制**
  - 每個帳號對同一件物品預設最多可上傳驗證 **3 次**（透過 `item_claim_attempts` 依帳號與物品追蹤）。
  - 累計失敗達 3 次時，系統自動將物品轉為 `needs_manual_review`（人工辨識）並設定 **3 天審核期限**，期間一律**不寄送任何 Email**；若逾期超過 3 天未處理，系統自動寄信通知申請者並歸零失敗次數。
  - 若管理員執行「人工審核駁回（退回）」，系統將寄出駁回通知信提醒重新上傳圖片驗證，並將該帳號對此物品的驗證次數重置為 0（可再次獲得 3 次機會）。
- **雙向情境自動寄信通知（Email）**
  - 依「已拾獲／正在尋找」情境區分收件人，於 VLM 驗證成功、人工審核通過、人工審核駁回及帳號安全驗證時自動寄送 HTML 通知信，內含雙方聯絡資訊以便校內面交溝通。
- **帳號安全、持久化登入與下拉選單管理**
  - **F5 重新整理不登出**：以 `localStorage` 保存 Sanctum Bearer Token，頁面載入時自動呼叫 `/api/me` 驗證憑證並恢復身分狀態。
  - **註冊防連點鎖定**：登入與註冊按鈕於送出請求期間立即禁用（`disabled`）並顯示載入狀態，防止重複發送註冊封包。
  - **頂部收合式帳號選單**：點擊 `👤 使用者 ID` 展開選單，依身分顯示「管理後台」（僅限 `管理員`）、「更改基本資料」與「登出」。
  - **更改基本資料 Modal**：
    - **更改 Email**：驗證目前密碼 → 寄送 6 位數驗證碼至原 Email → 驗證通過後輸入新 Email → 寄送驗證碼至新 Email → 驗證完成更新。
    - **更改密碼**：寄送驗證碼至目前綁定 Email → 驗證碼通過後設定新密碼。
    - **更改使用者 ID**：輸入新 ID 與目前密碼驗證通過後立即變更。
- **刊登者權限與管理後台**
  - **刊登者自主管理**：刊登者可對自己「刊登中」的物品進行編輯（名稱、分類、地點、聯絡 Gmail、時間、類型、照片）或自行刪除；首頁亦提供「🔄 重新整理」按鈕即時同步清單。
  - **管理後台（`/admin`）**：僅限資料庫 `identity = '管理員'` 帳號進入；強制將 `needs_manual_review` 案件置頂，顯示「人工辨識」標籤、剩餘審核倒數時間、雙圖並排比對與「審核通過／審核失敗」按鈕；與前台共享「淺色白藍／深色黑橘」雙主題切換按鈕。
  - **已結案物品 3 個月自動清除**：結案（`completed`）物品自結案日起算保留 **3 個月**（`completed_delete_deadline`），到期後由後端自動讀取清理或 Artisan 排程自動刪除。

---

## 二、 系統架構、開發技術與 AI 模型說明

### 1. 系統架構概觀
```text
[ 使用者瀏覽器 (Vue 3 SPA @ Vercel) ]
          │ HTTPS REST API (Sanctum Bearer Token)
          ▼
[ Laravel 12 後端服務 (@ Render Docker) ]
   ├── 關聯式資料庫：PostgreSQL (Render)
   ├── 郵件寄送服務：Resend API / Brevo API / SMTP / Gmail Webhook
   └── AI 推論轉發：ngrok HTTPS Tunnel ──▶ [ 本機／校內伺服器 Ollama ]
                                               ├── 語意檢索模型：llama3
                                               └── 視覺比對模型：qwen2.5vl
```

### 2. 技術棧明細
| 層級 | 採用技術與說明 |
| :--- | :--- |
| **前端技術** | **Vue 3**（Composition API `<script setup>`）、**JavaScript**、**Vite**、**Vue Router**、**Tailwind CSS v4**；支援深／淺雙主題切換，部署於 **Vercel**（透過 `frontend/vercel.json` 處理 SPA Rewrite）。 |
| **後端與資料庫** | **Laravel 12**（**PHP 8.2+**，含 `gd`、`pdo_pgsql` 擴充）、**Laravel Sanctum**（API Token 驗證與 Session 狀態管理）、**PostgreSQL**（`lost_and_found_515a`，本地開發亦相容 SQLite）；另附有 Node.js Express 版本語意路由範例（`backend/ai.js`）。 |
| **郵件發送服務** | **Resend**（`resend/resend-laravel`）為主，並於 `App\Services\MailDispatcher` 內建支援 **Brevo HTTP API**、**Google Apps Script Gmail Webhook** 與標準 **SMTP**（需注意 Resend 測試寄件者 `onboarding@resend.dev` 僅能寄給帳號本人，正式寄給全體使用者須綁定已驗證網域或改用 Brevo／SMTP／Webhook）。 |
| **AI 與視覺模型** | 透過 **Ollama** + **ngrok** 遠端轉發提供服務：<br>• **語意搜尋（LLM）**：`llama3`（`OLLAMA_TEXT_MODEL`），負責關鍵字與品牌型號擴展、校園地點同義詞擴展與邏輯合理性評分。<br>• **現場照片比對（VLM）**：`qwen2.5vl`（`OLLAMA_VLM_MODEL`，亦相容 `llava` / `llama3.2-vision`），透過 `/api/chat` 多模態介面比對原失物照片與現場驗證照片。 |

---

## 三、 本機環境建置與安裝引導（Step-by-Step）

### 前置需求
- **PHP** `>= 8.2`（需啟用 `pdo_pgsql` / `pdo_sqlite`、`gd`、`mbstring`、`openssl`、`fileinfo` 擴充）
- **Composer** `>= 2.x`
- **Node.js** `>= 22.18`（或 `>= 24.12`）與 **npm**
- **資料庫**：PostgreSQL（推薦）或 SQLite
- **Ollama**（安裝於本機或校內 GPU 伺服器）及 **ngrok**（供遠端伺服器連線本機 Ollama）

---

### 步驟 1：下載並啟動 Ollama AI 模型
於執行 AI 的主機上拉取語意與視覺模型並啟動服務：
```bash
ollama pull llama3
ollama pull qwen2.5vl
ollama serve
```
若後端部署於雲端（如 Render）或需對外提供連線，另開終端機執行 ngrok 轉發 Ollama 預設埠位 `11434`：
```bash
ngrok http 11434
```
記下產生的 `https://xxxx.ngrok-free.dev` 網址作為後續的 `OLLAMA_URL`。

---

### 步驟 2：設定後端（Laravel）與環境變數
進入 `backend` 目錄安裝相依套件並建立 `.env`：
```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

編輯 `backend/.env`，設定資料庫、時區 `Asia/Taipei`、Resend／郵件與 Ollama 連線參數：
```env
APP_NAME="校園AI尋物通"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
APP_TIMEZONE=Asia/Taipei
APP_LOCALE=zh_TW

# 資料庫設定（以 PostgreSQL 為例；本機快速測試亦可改用 sqlite）
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=lost_and_found_515a
DB_USERNAME=postgres
DB_PASSWORD=your_db_password

# 郵件發送設定（方案一：Resend，須綁定已驗證網域才能寄給非本人信箱）
MAIL_MAILER=resend
RESEND_API_KEY=re_your_resend_api_key
MAIL_FROM_ADDRESS="noreply@yourdomain.com"
MAIL_FROM_NAME="校園AI尋物通"

# 郵件發送設定（方案二：無自購網域時，建議改設 BREVO_API_KEY 透過 HTTPS 寄給任意使用者）
# BREVO_API_KEY=xkeysib-your_brevo_key
# BREVO_SENDER_EMAIL=your_gmail@gmail.com

# Ollama AI 與 VLM 模型設定（本機可填 http://127.0.0.1:11434，遠端填 ngrok 網址）
OLLAMA_URL=https://your-ngrok-domain.ngrok-free.dev
OLLAMA_TEXT_MODEL=llama3:latest
OLLAMA_VLM_MODEL=qwen2.5vl
```

執行資料庫遷移（建立資料表與欄位）：
```bash
php artisan migrate
```

啟動後端 API 伺服器（預設運行於 `http://127.0.0.1:8000`）：
```bash
php artisan serve
```
*(若需在本機測試自動清理逾期人工審核與 3 個月到期已結案物品，可另開終端機執行 `php artisan claims:expire-overdue` 或 `php artisan schedule:work`。)*

---

### 步驟 3：設定前端（Vue 3 + Vite）並啟動
另開終端機進入 `frontend` 目錄安裝套件：
```bash
cd frontend
npm install
```

若要連接本機後端，可於 `frontend/` 新增 `.env.local` 覆寫 API 基底網址（未設定時預設連線 `https://lost-and-found-workspace.onrender.com/api`）：
```env
VITE_API_BASE_URL=http://127.0.0.1:8000/api
```

啟動前端開發伺服器：
```bash
npm run dev
```
啟動後依終端機顯示網址（通常為 `http://localhost:5173`）開啟瀏覽器即可開始使用！

其他常用前端指令：
```bash
npm run build   # 建置正式環境靜態檔案（輸出至 frontend/dist）
npm run lint    # 執行 oxlint 與 eslint 程式碼檢查
```

---

### 步驟 4：基本操作與管理員設定引導
1. **註冊與登入**：於首頁右上角點擊「登入 / 註冊」建立帳號，系統會自動核發 Token 並保留登入狀態。
2. **設定管理員權限**：新註冊帳號預設 `identity` 為 `'一般成員'`。若需啟用管理後台權限，請於資料庫（如 DBeaver）執行：
   ```sql
   UPDATE users SET identity = '管理員' WHERE email = 'your_admin@gmail.com';
   ```
   更新後重新整理頁面，點擊右上角 `👤 使用者 ID` 下拉選單即可看見「🛡️ 管理後台」入口（`/admin`）。
3. **刊登與驗證流程**：
   - 點擊「+ 刊登物品」填寫類型、品名、分類、地點、聯絡人 Gmail 並上傳照片。
   - 開啟任一失物卡片點擊「已找到，驗證並刪除」，輸入聯絡 Gmail 並上傳現場照片，即可體驗 VLM 視覺比對、3 次次數限制與人工審核流程。

---

## 四、 專案目錄結構

```text
lost-and-found-workspace-main/
├── README.md                        # 專案總覽與建置說明文件
├── backend/                         # Laravel 12 後端 API 與資料庫遷移
│   ├── ai.js                        # Node.js Express AI 語意比對範例模組
│   ├── Dockerfile                   # Render 部署用容器設定（含 gd、pdo_pgsql）
│   ├── app/
│   │   ├── Http/Controllers/        # AiController、AuthController、ItemController、AccountController
│   │   ├── Http/Middleware/         # EnsureAdmin 管理員權限中介層
│   │   ├── Mail/                    # ClaimSuccessMail、ClaimFailedMail、VerificationCodeMail
│   │   ├── Models/                  # User、Item
│   │   └── Services/                # MailDispatcher 統一寄信服務
│   ├── database/migrations/         # users、items、item_claim_attempts、verification_codes 遷移檔
│   ├── resources/views/emails/      # HTML Email Blade 樣板
│   └── routes/                      # api.php、console.php
└── frontend/                        # Vue 3 + Vite + Tailwind CSS 前端專案
    ├── index.html                   # 網站標題與 Favicon 設定
    ├── vercel.json                  # Vercel SPA 路由 Rewrite 設定
    ├── public/                      # 網站圖標（favicon.png / favicon.ico / apple-touch-icon.png）
    └── src/
        ├── App.vue                  # 根元件（Token 持久化驗證與右下角主題切換按鈕）
        ├── composables/             # useAppState.js（全域登入狀態、主題與工具函式）
        ├── components/              # ClaimVerifyModal.vue、AccountSettingsModal.vue
        ├── router/                  # index.js（/、/admin、/item/:id）
        └── views/                   # HomeView.vue、AdminDashboard.vue、ItemDetail.vue
```
