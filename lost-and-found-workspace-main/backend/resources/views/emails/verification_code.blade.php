<!DOCTYPE html>
<html lang="zh-Hant">
<head>
  <meta charset="UTF-8">
  <title>{{ $actionLabel }}驗證碼</title>
</head>
<body style="font-family: Arial, 'Microsoft JhengHei', sans-serif; background-color: #f8fafc; color: #0f172a; padding: 24px;">
  <div style="max-width: 520px; margin: 0 auto; background: #ffffff; border-radius: 16px; padding: 28px; border: 1px solid #e2e8f0;">
    <h2 style="color: #1e3a8a; margin-top: 0;">🔐 {{ $actionLabel }}安全驗證碼</h2>
    <p>{{ $userName !== '' ? $userName . ' 您好，' : '您好，' }}您正在「校園AI尋物通」進行 <strong>{{ $actionLabel }}</strong> 操作，請輸入以下 6 位數驗證碼（10 分鐘內有效）：</p>
    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 12px; padding: 18px; margin: 20px 0; text-align: center;">
      <span style="font-size: 30px; font-weight: 900; letter-spacing: 6px; color: #1e3a8a;">{{ $code }}</span>
    </div>
    <p style="font-size: 13px; color: #64748b;">若非您本人操作，請立即忽略此信件並確認帳號密碼安全。</p>
    <p style="font-size: 12px; color: #94a3b8;">校園AI尋物通 管理系統</p>
  </div>
</body>
</html>
