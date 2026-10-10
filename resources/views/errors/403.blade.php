@php
    $errorLocale = in_array(app()->getLocale(), ['ar', 'en'], true) ? app()->getLocale() : 'ar';
@endphp
@extends('errors.layout')

@section('title'){{ __('errors.forbidden.title', [], $errorLocale) }}@endsection

@section('content')
    <p class="status"><bdi>403</bdi></p>
    <h1 id="error-title">{{ __('errors.forbidden.title', [], $errorLocale) }}</h1>
    <p>{{ __('errors.forbidden.message', [], $errorLocale) }}</p>
@endsection
