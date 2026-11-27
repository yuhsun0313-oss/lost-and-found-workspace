<!DOCTYPE html>
<html lang="zh-Hant">
<head>
  <meta charset="UTF-8">
  <title>人工審核駁回／請重新上傳圖片驗證通知</title>
</head>
<body style="font-family: Arial, 'Microsoft JhengHei', sans-serif; background-color: #f8fafc; color: #0f172a; padding: 24px;">
  <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 16px; padding: 28px; border: 1px solid #e2e8f0;">
    <h2 style="color: #dc2626; margin-top: 0;">⚠️ 人工審核駁回（退回）通知</h2>
    <p>您好，您先前提交的物品驗證申請<strong>已被管理員人工審核駁回</strong>。系統已重置您對此物品的驗證次數，您可重新獲得最多 3 次 AI 視覺驗證機會。</p>
    <div style="background: #fef2f2; border-radius: 12px; padding: 16px; margin: 18px 0;">
      <p style="margin: 4px 0;"><strong>物品名稱：</strong>{{ $item->title }}</p>
      <p style="margin: 4px 0;"><strong>遺失／拾獲地點：</strong>{{ $item->location ?? '未填寫' }}</p>
      <p style="margin: 4px 0;"><strong>駁回原因說明：</strong>{{ $reason }}</p>
    </div>
    <p><strong>請重新上傳圖片並進行驗證：</strong>請回到「校園AI尋物通」選擇該物品並點擊「已找到，驗證並刪除」，於光線充足處拍攝清晰的現場物品照片再次提交驗證。</p>
    <p style="font-size: 13px; color: #64748b;">校園AI尋物通 管理系統</p>
  </div>
</body>
</html>
