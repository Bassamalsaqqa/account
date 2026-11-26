<span class="tabular-nums" dir="ltr" title="{{ config('release.source_sha') ?? __('app.release_build_unknown') }}">
    {{ config('release.version', 'unknown') }} · {{ config('release.source_sha') ? substr(config('release.source_sha'), 0, 7) : __('app.release_build_unknown') }}
</span>
