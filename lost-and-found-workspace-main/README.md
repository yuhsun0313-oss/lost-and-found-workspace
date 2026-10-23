# ◈ 校園失物雷達系統 (Campus Lost & Found)

本專案是一個整合前後端的校園失物招領平台，旨在協助校園師生快速刊登、搜尋、配對與管理遺失物或拾獲物。系統支援圖片上傳預覽、帳號驗證與即時資料庫互動。

## 🛠️ 系統技術堆疊 (Tech Stack)

- **前端 (Frontend)**：Vue 3 (Composition API) + Tailwind CSS + Vite
- **後端 (Backend)**：Laravel (PHP) + MySQL
- **AI 擴充規劃**：YOLO / Ollama (影像辨識與智慧標籤)
- **版控協作 (Version Control)**：Git & GitHub

## 📂 專案資料夾結構

```
lost-and-found-workspace/
├── backend/       # Laravel 後端專案
├── frontend/      # Vue 前端專案
└── README.md      # 專案說明文件
```

## 🚀 環境建立與執行完整指南

請依照以下步驟，在各自的本機電腦設定並啟動專案：

### 1. 複製專案至本地

打開終端機（PowerShell / Git Bash），執行以下指令將 GitHub 專案複製到本機：

```
git clone https://github.com/YourAccount/lost-and-found-system.git
cd lost-and-found-system
```

### 2. 後端設定與啟動 (Laravel)

1. **進入後端資料夾並安裝 PHP 套件**：

   ```
   cd backend
   composer install
   ```
2. **建立與設定 `.env` 檔案**： 複製環境設定範本：

   ```
   cp .env.example .env
   ```
   使用 VS Code 或記事本開啟 `backend/.env`，修改 MySQL 資料庫連線設定（預設使用 Laragon/XAMPP）：

   ```
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=lost_and_found
   DB_USERNAME=root
   DB_PASSWORD=
   ```
   *(請先開啟 Laragon/XAMPP 的 MySQL，並手動建立一個名為 `lost_and_found` 的資料庫)*
3. **產生應用程式金鑰**：

   ```
   php artisan key:generate
   ```
4. **建立資料庫資料表 (Migration)**：

   ```
   php artisan migrate
   ```
5. **啟動後端 API 伺服器**：

   ```
   php artisan serve
   ```
   *後端 API 服務將運行於：`http://127.0.0.1:8000`*

### 3. 前端設定與啟動 (Vue)

開啟**第二個**終端機視窗，進入前端資料夾：

1. **安裝 NPM 套件**：

   ```
   cd lost-and-found-system/frontend
   npm install
   ```
2. **啟動前端開發伺服器**：

   ```
   npm run dev
   ```
   *前端畫面將運行於：`http://localhost:5173`*

## 💡 日常開發與 Git 協作流程

組員在開發新功能或更新程式碼時，請遵守以下流程：

### 取得最新程式碼 (開工前)

```
git pull origin main
```

### 上傳新進度 (完成功能後)

```
git add .
git commit -m "feat: 新增 [功能名稱]"
git push origin main
```

## 📌 系統已完成功能清單

- [x] **失物/拾獲刊登**：填寫名稱、分類、地點、時間與圖片。
- [x] **圖片上傳預覽**：支援格式檢查與 Base64 即時預覽。
- [x] **非同步 API 串接**：前後端以 API 通訊，自動寫入/讀取 MySQL 資料庫。
- [x] **即時刪除與管理**：卡片即時刪除，並同步自資料庫移除。
- [x] **使用者驗證機制**：包含隨機圖形/字串驗證碼與登入流程。
