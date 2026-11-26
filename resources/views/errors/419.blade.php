@php
    try {
        $activeLocale = app()->getLocale();
    } catch (\Throwable) {
        $activeLocale = 'ar';
    }
    $errorLocale = in_array($activeLocale, ['ar', 'en'], true) ? $activeLocale : 'ar';
@endphp
@extends('errors.layout')

@section('title'){{ __('errors.expired.title', [], $errorLocale) }}@endsection

@section('content')
    <p class="status"><bdi>419</bdi></p>
    <h1 id="error-title">{{ __('errors.expired.title', [], $errorLocale) }}</h1>
    <p>{{ __('errors.expired.message', [], $errorLocale) }}</p>
@endsection
