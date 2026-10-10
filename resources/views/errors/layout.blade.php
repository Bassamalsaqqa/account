@php
    $errorLocale = in_array($errorLocale ?? null, ['ar', 'en'], true) ? $errorLocale : 'ar';
@endphp
<!DOCTYPE html>
<html lang="{{ $errorLocale }}" dir="{{ $errorLocale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 1.25rem;
            color: #243247;
            background: #f4f6fa;
            font-family: system-ui, sans-serif;
            line-height: 1.75;
        }
        main {
            width: 100%;
            max-width: 36rem;
            padding: clamp(1.25rem, 5vw, 2.5rem);
            border: 1px solid #c5ceda;
            border-radius: .75rem;
            background: #fff;
            overflow-wrap: anywhere;
        }
        h1 { margin: 0 0 .75rem; color: #172b4d; font-size: 1.75rem; line-height: 1.4; }
        p { margin: 0 0 1.5rem; }
        .status { margin-bottom: .5rem; color: #526174; font-weight: 600; text-align: start; }
        a {
            display: inline-block;
            padding: .65rem 1rem;
            border: 2px solid #174e9c;
            border-radius: .375rem;
            color: #fff;
            background: #174e9c;
            font-weight: 600;
            text-decoration: underline;
            text-underline-offset: .2em;
        }
        a:hover { background: #123b76; border-color: #123b76; }
        a:focus-visible { outline: 3px solid #174e9c; outline-offset: 4px; }
    </style>
</head>
<body>
    <main aria-labelledby="error-title">
        @yield('content')
        <a href="/">{{ __('errors.home', [], $errorLocale) }}</a>
    </main>
</body>
</html>
