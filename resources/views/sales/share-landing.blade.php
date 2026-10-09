<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">
    <title>{{ __('sharing.neutral_title') }}</title>
    <style>body{font-family:Arial,sans-serif;background:#f1f5f9;color:#17253c;margin:0;padding:24px}main{max-width:420px;margin:12vh auto;background:white;border:1px solid #dce3ec;border-radius:16px;padding:28px}h1{font-size:22px}p{line-height:1.7}label{display:block;margin-top:16px}input,button{box-sizing:border-box;min-height:44px;width:100%;margin-top:8px;border-radius:8px;padding:10px;border:1px solid #94a3b8}button{background:#255fd6;color:white;font-weight:bold;cursor:pointer}button:focus-visible,input:focus-visible{outline:3px solid #17253c;outline-offset:2px}</style>
</head>
<body><main>
    <h1>{{ __('sharing.neutral_title') }}</h1>
    @if($unavailable ?? false)
        <p role="alert">{{ __('sharing.unavailable') }}</p>
    @else
        <p>{{ __('sharing.deliberate_notice') }}</p>
        <form method="POST" action="{{ route('public.share', ['token'=>$token]) }}">
            @csrf
            @if($requiresPassword ?? false)
                <label for="share-password">{{ __('sharing.password') }}</label>
                <input id="share-password" name="password" type="password" required maxlength="128" autocomplete="current-password">
            @endif
            <button type="submit">{{ __('sharing.view_document') }}</button>
        </form>
        <p>{{ __('sharing.cookie_notice') }}</p>
    @endif
</main></body></html>
