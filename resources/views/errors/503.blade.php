<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تحديث النظام &bull; System Update</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 1.25rem;
            color: #1e293b;
            background: #f8fafc;
            font-family: system-ui, -apple-system, sans-serif;
            line-height: 1.6;
        }
        main {
            width: 100%;
            max-width: 32rem;
            padding: 2rem;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            background: #ffffff;
            text-align: center;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .icon {
            width: 48px;
            height: 48px;
            margin: 0 auto 1.25rem;
            border-radius: 0.75rem;
            background: #eff6ff;
            color: #255fd6;
            display: grid;
            place-items: center;
        }
        h1 { margin: 0 0 0.5rem; color: #0f172a; font-size: 1.35rem; font-weight: 800; }
        p { margin: 0 0 1rem; color: #475569; font-size: 0.95rem; }
        .divider { border: 0; border-top: 1px solid #f1f5f9; margin: 1.25rem 0; }
        .en-section { direction: ltr; font-size: 0.875rem; color: #64748b; }
        .en-section h2 { margin: 0 0 0.25rem; color: #334155; font-size: 1.1rem; font-weight: 700; }
        .status-pill {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            background: #eff6ff;
            color: #255fd6;
            font-size: 0.75rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body @if (isset($retryAfter)) data-accounting-upgrade="true" @endif>
    <main>
        <div class="icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 21h5v-5"/></svg>
        </div>
        <span class="status-pill">قيد التحديث &bull; Maintenance</span>
        <h1 id="upgrade-title-ar">التطبيق قيد التحديث. سنعود قريباً.</h1>
        <p>نعمل حالياً على تثبيت تحسينات جديدة على النظام. يرجى الانتظار والمحاولة لاحقاً.</p>
        <hr class="divider">
        <div class="en-section">
            <h2 id="upgrade-title-en">We're updating the app. Back shortly.</h2>
            <p>System upgrades are in progress. Please wait a moment and refresh.</p>
        </div>
    </main>
</body>
</html>
