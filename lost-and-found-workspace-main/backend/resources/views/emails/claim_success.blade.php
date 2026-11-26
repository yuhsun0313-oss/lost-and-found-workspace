<!DOCTYPE html>
<html lang="zh-Hant">
<head>
  <meta charset="UTF-8">
  <title>物品驗證成功與面交溝通通知</title>
</head>
<body style="font-family: Arial, 'Microsoft JhengHei', sans-serif; background-color: #f8fafc; color: #0f172a; padding: 24px;">
  <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 16px; padding: 28px; border: 1px solid #e2e8f0;">
    @if ($recipientRole === 'publisher')
      @if ($item->type === 'lost')
        <h2 style="color: #1e3a8a; margin-top: 0;">🎉 您尋找的遺失物已有好消息！</h2>
        <p>您好，您於「校園AI尋物通」刊登的「正在尋找」物品，已有熱心拾獲人回報找到並通過 <strong>{{ $verifiedBy }}</strong>，系統已完成結案。</p>
      @else
        <h2 style="color: #1e3a8a; margin-top: 0;">🎉 您拾獲的招領物品已完成失主驗證！</h2>
        <p>您好，您於「校園AI尋物通」刊登的「已拾獲」物品，已有失主通過 <strong>{{ $verifiedBy }}</strong>，系統已完成結案。</p>
      @endif
    @else
      <h2 style="color: #1e3a8a; margin-top: 0;">🎉 您的認領／回報申請已通過人工審核！</h2>
      <p>您好，您提交的現場驗證照片已通過 <strong>{{ $verifiedBy }}</strong>，系統已完成結案。</p>
    @endif

    <div style="background: #f1f5f9; border-radius: 12px; padding: 16px; margin: 18px 0;">
      <p style="margin: 4px 0;"><strong>物品名稱：</strong>{{ $item->title }}</p>
      <p style="margin: 4px 0;"><strong>物品分類：</strong>{{ $item->category }}</p>
      <p style="margin: 4px 0;"><strong>遺失／拾獲地點：</strong>{{ $item->location ?? '未填寫' }}</p>
      <p style="margin: 4px 0;"><strong>物品編號：</strong>#{{ $item->id }}</p>
      @if (!empty($partnerEmail))
        <p style="margin: 8px 0 0 0; color: #1e3a8a;">
          <strong>對方聯絡信箱（面交溝通）：</strong>{{ $partnerEmail }}
        </p>
      @endif
    </div>

    <p style="font-size: 13px; color: #475569;">請透過上方聯絡信箱與對方聯繫確認面交時間與校內交接地點。</p>
    <p style="font-size: 12px; color: #64748b;">校園AI尋物通 系統自動通知</p>
  </div>
</body>
</html>
