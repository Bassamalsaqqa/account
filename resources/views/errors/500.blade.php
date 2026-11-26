@php
    try {
        $activeLocale = app()->getLocale();
    } catch (\Throwable) {
        $activeLocale = 'ar';
    }
    $errorLocale = in_array($activeLocale, ['ar', 'en'], true) ? $activeLocale : 'ar';
@endphp
@extends('errors.layout')

@section('title'){{ __('errors.server_error.title', [], $errorLocale) }}@endsection

@section('content')
    <p class="status"><bdi>500</bdi></p>
    <h1 id="error-title">{{ __('errors.server_error.title', [], $errorLocale) }}</h1>
    <p>{{ __('errors.server_error.message', [], $errorLocale) }}</p>
@endsection
