// 後端程式碼範例 (例如 server.js)
const express = require('express');
const router = express.Router();

router.post('/api/ai-match', async (req, res) => {
  try {
    const { userDescription } = req.body;
    
    // 1. 從資料庫取得目前的失物清單
    const items = await db.query('SELECT * FROM items'); 

    // 2. 組合 Prompt 傳給 Ollama
    const prompt = `
      你是一個失物招領 AI 助手。使用者遺失了："${userDescription}"
      以下是資料庫清單：${JSON.stringify(items)}
      請從中挑選最符合的前 3 筆物品，並嚴格只回傳 JSON 陣列的 ID，例如：["1", "3"]。
    `;

    // 3. 呼叫本機 Ollama API
    const ollamaRes = await fetch('http://localhost:11434/api/generate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        model: 'llama3',
        prompt: prompt,
        stream: false,
        format: 'json'
      })
    });

    const aiData = await ollamaRes.json();
    const matchedIds = JSON.parse(aiData.response);

    // 4. 過濾出實際物品回傳給前端
    const matchedItems = items.filter(item => matchedIds.includes(String(item.id)));
    res.json({ success: true, matches: matchedItems });

  } catch (error) {
    console.error('Ollama 連動錯誤：', error);
    res.status(500).json({ success: false, message: 'AI 服務連線失敗' });
  }
});