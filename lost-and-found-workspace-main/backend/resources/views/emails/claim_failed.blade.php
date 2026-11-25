<!DOCTYPE html>
<html lang="zh-Hant">
<head>
  <meta charset="UTF-8">
  <title>認領失敗／需重新驗證通知</title>
</head>
<body style="font-family: Arial, 'Microsoft JhengHei', sans-serif; background-color: #f8fafc; color: #0f172a; padding: 24px;">
  <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 16px; padding: 28px; border: 1px solid #e2e8f0;">
    <h2 style="color: #dc2626; margin-top: 0;">⚠️ 認領失敗／需重新驗證通知</h2>
    <p>您好，您先前提交認領的失物未能完成認領驗證，系統已將您的 VLM 驗證失敗次數歸零，您可重新發起最多 3 次 AI 視覺驗證。</p>
    <div style="background: #fef2f2; border-radius: 12px; padding: 16px; margin: 18px 0;">
      <p style="margin: 4px 0;"><strong>物品名稱：</strong>{{ $item->title }}</p>
      <p style="margin: 4px 0;"><strong>遺失／拾獲地點：</strong>{{ $item->location ?? '未填寫' }}</p>
      <p style="margin: 4px 0;"><strong>原因說明：</strong>{{ $reason }}</p>
    </div>
    <p>請重新前往「校園AI尋物通」選擇該物品並點擊「已找到，驗證並刪除」，在光線充足處拍攝清晰的現場物品照片再行驗證。</p>
    <p style="font-size: 13px; color: #64748b;">校園AI尋物通 管理系統</p>
  </div>
</body>
</html>
