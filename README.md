# 🔍 智慧失物招領系統 (AI-Powered Lost & Found System)

本專案為結合 **Laravel 後端框架**、**PostgreSQL 關聯式資料庫** 與 **Ollama 本地端大型語言模型（LLM）** 的智慧失物招領平台。

透過語意聯想與分層權重排序演算法，系統打破傳統關鍵字死板搜尋的限制——即使失主僅輸入口語化的情境描述、代名詞或同義詞，AI 也能自動拆解出核心物品、自由聯想相關同義詞與品牌，並結合遺失地點進行精準加權比對。

---

## ✨ 核心技術與特色

1. **無限制語意自由聯想（Ollama LLM）**：不依賴寫死的硬編碼標籤，直接透過 `llama3:latest` 動態分離使用者的「核心物品原名」、「同義詞／品牌聯想」與「遺失地點」。
2. **四層優先級加權排序演算法**：
   * **第一順位（100 分）**：相同物品名稱 ＋ 相同地點（最精準命中）。
   * **第二順位（80 分）**：AI 聯想同義詞 ＋ 相同地點。
   * **第三順位（60 分）**：相同物品名稱，但地點不同（防止物品被好心人撿至他處而漏掉）。
   * **第四順位（40 分）**：AI 聯想同義詞，地點不同。
   * **保底機制（10 分）**：若上述皆無命中，自動撈出該地點的所有遺失物供失主確認。
3. **高可用性與防逾時防護**：內建成功結果智慧快取（避免重複請求觸發外部轉址速率限制）與無 AI 降級保底搜尋，確保專題 Demo 現場秒速回應、絕不開天窗。

---

## 🛠️ 系統架構與環境需求

在開始架設前，請確保你的電腦或伺服器已安裝以下基礎環境：

* **PHP** >= 8.2（需開啟 `pdo_pgsql`、`mbstring`、`curl`、`json` 擴充套件）
* **Composer** >= 2.0
* **資料庫**：PostgreSQL >= 14（支援 Render 雲端託管或本地端 PostgreSQL）
* **AI 運算引擎**：[Ollama](https://ollama.com/)（需下載 `llama3:latest` 模型）
* **內網穿透工具**（若 Laravel 部署於雲端如 Render，需連線回本地 Ollama）：[ngrok](https://ngrok.com/)

---

## 🚀 完整架設流程（Step-by-Step）

### 第一步：啟動本地 AI 模型與內網穿透 (Ollama + ngrok)

1. **安裝並啟動 Ollama**：
   前往 [Ollama 官網](https://ollama.com/) 下載安裝後，開啟終端機（Terminal / CMD）下載並測試模型：
   ```bash
   ollama run llama3:latest
   ```
   *(確認出現 `>>>` 對話提示字元代表模型已成功載入，輸入 `/bye` 即可退出對話，Ollama 背景服務會持續運行於 `11434` 埠)*

2. **設定 ngrok 外部轉址**（若前後端皆在本機執行可跳過此步）：
   啟動 ngrok 將本地的 `11434` 埠公開給雲端 Laravel 伺服器呼叫：
   ```bash
   ngrok http 11434 --host-header="localhost:11434"
   ```
   請記下終端機顯示的 Forwarding 網址（例如：`https://your-domain.ngrok-free.dev`）。

---

### 第二步：取得專案與安裝後端套件

1. **複製專案至本地**：
   ```bash
   git clone <你的專案儲存庫網址>
   cd <專案資料夾名稱>
   ```

2. **安裝 PHP 相依套件**：
   ```bash
   composer install
   ```

3. **建立環境設定檔**：
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

---

### 第三步：設定資料庫與環境變數 (`.env`)

打開專案根目錄下的 `.env` 檔案，修改以下關鍵設定：

```env
APP_NAME="智慧失物招領系統"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# 1. PostgreSQL 資料庫連線設定（以 Render 或本地端為例）
DB_CONNECTION=pgsql
DB_HOST=你的資料庫主機位址
DB_PORT=5432
DB_DATABASE=你的資料庫名稱
DB_USERNAME=你的資料庫帳號
DB_PASSWORD=你的資料庫密碼

# 2. 快取驅動設定（建議使用 file 或 database 以啟用 AI 結果智慧快取）
CACHE_STORE=file

# 3. Ollama AI 服務位址（本地端填 http://127.0.0.1:11434，雲端部署填 ngrok 網址）
OLLAMA_URL=https://your-domain.ngrok-free.dev
```

---

### 第四步：建立資料表與測試資料

執行 Laravel 資料庫遷移指令，建立 `items` 等核心資料表（包含 `title`、`category`、`location` 欄位）：

```bash
php artisan migrate
```

*(選用)* 若有建立測試資料填充檔（Seeder），可執行：
```bash
php artisan db:seed
```

---

### 第五步：部署核心 AI 比對控制器 (`AiController.php`)

確認 `app/Http/Controllers/AiController.php` 已更新為支援**四層優先級加權排序**與**PostgreSQL `ILIKE` 不分大小寫搜尋**的最新版本，並確認 `routes/api.php` 或 `routes/web.php` 已正確註冊路由：

```php
use App\Http\Controllers\AiController;

Route::post('/ai-match', [AiController::class, 'aiMatch']);
```

---

### 第六步：啟動伺服器並測試

1. **啟動 Laravel 開發伺服器**：
   ```bash
   php artisan serve
   ```
2. **開啟瀏覽器測試**：
   前往 `http://127.0.0.1:8000`，在搜尋框輸入任意生活化尋物描述進行測試：
   * **測試情境一（地點＋物品）**：「我昨天在廁所裡不見了衛生紙」 $\rightarrow$ 系統將優先置頂顯示地點在廁所的該物品，並將其他地點的同款物品排在後方。
   * **測試情境二（同義詞與品牌聯想）**：「我的手機不見了」 $\rightarrow$ AI 自動聯想出 `iPhone`、`智慧型手機` 等同義詞並精準撈出資料。

---

## 💡 專題展示（Demo）注意事項與排錯指南

* **冷啟動預熱（Warm-up）**：建議先在網頁上隨意輸入一筆文字點擊「AI 開始比對」，讓 Ollama 將 `llama3:latest` 預先載入記憶體，正式展示時即可達到秒速回應。
* **清除舊快取**：若在開發期間修改了資料庫測試資料，但搜尋結果仍顯示舊狀態，可執行以下指令清除快取：
  ```bash
  php artisan cache:clear
  ```
* **資料庫欄位檢查**：本系統查詢邏輯針對 PostgreSQL 最佳化，全面採用 `ILIKE` 進行不分大小寫比對，並預設查詢 `items` 資料表中的 `title`、`category` 與 `location` 欄位。
