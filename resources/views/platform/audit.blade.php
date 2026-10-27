@extends('layouts.platform')
@section('content')
<h2 class="mb-4 text-xl font-bold">{{ __('platform.audit') }}</h2>
<ul class="space-y-3">@foreach($events as $event)<li class="rounded-lg border bg-white p-4"><p class="break-all font-mono" dir="ltr">{{ $event->event_key }}</p><p>{{ __('platform.actor') }}: {{ $event->actor_user_id ?? '—' }} · {{ __('platform.target') }}: {{ $event->company_id ?? '—' }}</p><time dir="ltr">{{ $event->created_at }} UTC</time></li>@endforeach</ul>
<div class="mt-4">{{ $events->links() }}</div>
@endsection
