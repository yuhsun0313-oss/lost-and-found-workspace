<!DOCTYPE html>
<html lang="zh-Hant">
<head>
  <meta charset="UTF-8">
  <title>認領成功通知</title>
</head>
<body style="font-family: Arial, 'Microsoft JhengHei', sans-serif; background-color: #f8fafc; color: #0f172a; padding: 24px;">
  <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 16px; padding: 28px; border: 1px solid #e2e8f0;">
    <h2 style="color: #1e3a8a; margin-top: 0;">🎉 失物認領成功通知</h2>
    <p>您好，您申請認領的校園失物已通過 <strong>{{ $verifiedBy }}</strong>，系統已完成結案處理。</p>
    <div style="background: #f1f5f9; border-radius: 12px; padding: 16px; margin: 18px 0;">
      <p style="margin: 4px 0;"><strong>物品名稱：</strong>{{ $item->title }}</p>
      <p style="margin: 4px 0;"><strong>物品分類：</strong>{{ $item->category }}</p>
      <p style="margin: 4px 0;"><strong>遺失／拾獲地點：</strong>{{ $item->location ?? '未填寫' }}</p>
      <p style="margin: 4px 0;"><strong>物品編號：</strong>#{{ $item->id }}</p>
    </div>
    <p style="font-size: 13px; color: #64748b;">感謝您使用「校園AI尋物通」，若有任何疑問請聯繫校園失物招領管理處。</p>
  </div>
</body>
</html>
