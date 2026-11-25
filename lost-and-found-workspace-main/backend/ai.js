// Node.js / Express AI 語意聯想與合理性評估路由 (backend/ai.js)
const express = require('express');
const router = express.Router();

const SYSTEM_PROMPT = `你是一位專業的「校園AI尋物通」失物招領語意分析與防呆稽核專家，必須嚴格遵守以下規則並只輸出合法的純 JSON（不得包含 markdown 標記、註解或額外說明文字）：

1. 【全品項與品牌型號聯想 (expanded_items)】：
   從使用者描述提取核心物品並做最大化語意擴展，涵蓋主流品牌、常見型號、中英文俗稱、外觀特徵與配件。
   範例：「手機」-> ["手機", "智慧型手機", "iPhone", "iPhone 13", "iPhone 14", "iPhone 15", "iPhone 16", "Samsung", "Galaxy", "Google Pixel", "ASUS", "ROG Phone", "Zenfone", "OPPO", "Sony", "Xperia", "小米", "Redmi", "手機殼", "保護殼", "鏡頭貼"]。

2. 【校園地點同義詞擴展 (expanded_locations)】：
   從描述提取校園地點並擴展正式全稱、簡稱、大樓別稱與房號變體（含空格/無空格/館/教室）。
   範例：「綜三502教室」-> ["綜三502教室", "綜三502", "綜三 502", "綜三館502", "綜合教學三館502", "綜合領域三館502教室", "綜合三館502教室", "綜三"]。

3. 【常識與合理性評估 (is_reasonable, reasonableness_score, reason)】：
   比對「遺失物品」與「遺失地點」在現實生活中的合理性，偵測惡作劇、亂碼或不合常理描述（如「大象掉在飲水機」、「核彈在鉛筆盒」）。
   - 合理情境：is_reasonable = true，reasonableness_score = 70~100。
   - 惡作劇或不合常理：is_reasonable = false，reasonableness_score = 0~39，於 reason 說明原因。

4. 【輸出格式（嚴格純 JSON）】：
{
  "expanded_items": ["..."],
  "expanded_locations": ["..."],
  "is_reasonable": true,
  "reasonableness_score": 95,
  "reason": "..."
}`;

router.post('/api/ai-match', async (req, res) => {
  try {
    const { userDescription = '' } = req.body;
    const trimmed = String(userDescription).trim();

    if (!trimmed) {
      return res.json({
        success: true,
        is_reasonable: true,
        reasonableness_score: 100,
        reason: '',
        expanded_items: [],
        expanded_locations: [],
        matches: [],
      });
    }

    const ollamaUrl = (process.env.OLLAMA_URL || 'https://tapping-carol-engaging.ngrok-free.dev').replace(/\/+$/, '');
    const model = process.env.OLLAMA_TEXT_MODEL || 'llama3';

    const ollamaRes = await fetch(`${ollamaUrl}/api/generate`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'ngrok-skip-browser-warning': 'true',
      },
      body: JSON.stringify({
        model,
        system: SYSTEM_PROMPT,
        prompt: `使用者輸入的尋物描述如下，請依規範回傳純 JSON：\n「${trimmed}」`,
        stream: false,
        format: 'json',
        options: {
          temperature: 0.2,
          num_predict: 512,
        },
      }),
    });

    if (!ollamaRes.ok) {
      throw new Error(`Ollama API 回應異常 (${ollamaRes.status})`);
    }

    const aiRaw = await ollamaRes.json();
    const cleaned = String(aiRaw.response || '')
      .replace(/```json|```|`/g, '')
      .trim();
    const start = cleaned.indexOf('{');
    const end = cleaned.lastIndexOf('}');
    const parsed = start !== -1 && end !== -1 ? JSON.parse(cleaned.slice(start, end + 1)) : {};

    const expandedItems = Array.isArray(parsed.expanded_items)
      ? parsed.expanded_items.map(s => String(s).trim()).filter(s => s.length > 1)
      : [];
    const expandedLocations = Array.isArray(parsed.expanded_locations)
      ? parsed.expanded_locations.map(s => String(s).trim()).filter(s => s.length > 1)
      : [];
    const isReasonable = parsed.is_reasonable !== undefined ? Boolean(parsed.is_reasonable) : true;
    const reasonablenessScore = Math.max(0, Math.min(100, Number(parsed.reasonableness_score ?? 85)));
    const reason = String(parsed.reason || '');

    if (!isReasonable || reasonablenessScore < 40) {
      return res.json({
        success: true,
        is_reasonable: false,
        reasonableness_score: reasonablenessScore,
        reason: reason || '系統判斷此描述與現實校園失物常理不符，請重新輸入真實物品與地點。',
        expanded_items: expandedItems,
        expanded_locations: expandedLocations,
        matches: [],
      });
    }

    // 使用 PostgreSQL 參數化 ILIKE 進行模糊搜尋並排除已結案資料
    const itemPatterns = expandedItems.map(k => `%${k}%`);
    const locPatterns = expandedLocations.map(l => `%${l}%`);

    const queryResult = await db.query(
      `
      SELECT *
      FROM items
      WHERE COALESCE(review_status, 'pending') <> 'completed'
        AND (
          (cardinality($1::text[]) > 0 AND (title ILIKE ANY($1) OR category ILIKE ANY($1) OR COALESCE(description, '') ILIKE ANY($1)))
          OR
          (cardinality($2::text[]) > 0 AND location ILIKE ANY($2))
        )
      ORDER BY id DESC
      `,
      [itemPatterns, locPatterns]
    );

    const primaryItem = (expandedItems[0] || '').toLowerCase();
    const secondaryItems = expandedItems.slice(1).map(s => s.toLowerCase());
    const locsLower = expandedLocations.map(l => l.toLowerCase());

    const scored = queryResult.rows
      .map(item => {
        const text = `${item.title || ''} ${item.category || ''} ${item.description || ''}`.toLowerCase();
        const locText = String(item.location || '').toLowerCase();
        const matchPrimary = primaryItem && text.includes(primaryItem);
        const matchExpanded = !matchPrimary && secondaryItems.some(k => text.includes(k));
        const matchLocation = locsLower.some(l => locText.includes(l));

        let score = 0;
        if (matchPrimary && matchLocation) score = 100;
        else if (matchExpanded && matchLocation) score = 80;
        else if (matchPrimary) score = 60;
        else if (matchExpanded) score = 40;
        else if (matchLocation) score = 10;

        return { item, score };
      })
      .filter(entry => entry.score > 0);

    const hasItemHit = scored.some(entry => entry.score >= 40);
    const filtered = (hasItemHit ? scored.filter(entry => entry.score >= 40) : scored)
      .sort((a, b) => b.score - a.score || b.item.id - a.item.id)
      .map(entry => entry.item);

    return res.json({
      success: true,
      is_reasonable: true,
      reasonableness_score: reasonablenessScore,
      reason,
      expanded_items: expandedItems,
      expanded_locations: expandedLocations,
      matches: filtered,
    });
  } catch (error) {
    console.error('Ollama 連動錯誤：', error);
    return res.status(500).json({ success: false, message: 'AI 服務連線失敗' });
  }
});

module.exports = router;


module.exports = router;