@php
    $unavailable = $unavailable ?? false;
    $requiresPassword = $requiresPassword ?? false;
    $token = $token ?? null;
    $data = $data ?? [];
    $locale = $data['locale'] ?? app()->getLocale() ?? 'ar';
    $isRtl = $locale === 'ar';
    $dir = $isRtl ? 'rtl' : 'ltr';
    $isPriced = array_key_exists('currency_code', $data);
    $currentPage = (int) ($data['page'] ?? 1);
    $totalPages = (int) ($data['pages'] ?? 1);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ !empty($data['title']) ? $data['title'] . ' - ' . ($data['company']['name'] ?? '') : __('catalogs.public_view_title') }}</title>
    <style>
        :root {
            --primary: #0284c7;
            --primary-hover: #0369a1;
            --surface: #ffffff;
            --bg: #f8fafc;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --border-light: #f1f5f9;
            --accent-bg: #f0f9ff;
            --accent-border: #bae6fd;
            --accent-text: #0369a1;
            --danger: #dc2626;
            --radius: 8px;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--bg);
            color: var(--text-main);
            line-height: 1.5;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 16px;
        }

        /* Top Header */
        header {
            background-color: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 16px 0;
            box-shadow: var(--shadow-sm);
        }

        .header-content {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }

        .company-brand {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .company-name {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-main);
        }

        .catalog-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary);
        }

        .catalog-desc {
            font-size: 0.875rem;
            color: var(--text-muted);
            max-width: 600px;
        }

        .company-contact {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 0.8125rem;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            min-width: 44px;
            padding: 8px 16px;
            border-radius: var(--radius);
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            transition: background-color 0.2s, border-color 0.2s;
        }

        .btn:focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }

        .btn-primary {
            background-color: var(--primary);
            color: #ffffff;
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
        }

        .btn-secondary {
            background-color: var(--surface);
            border-color: var(--border);
            color: var(--text-main);
        }

        .btn-secondary:hover {
            background-color: var(--bg);
        }

        .badge-revision {
            display: inline-block;
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 4px;
            background-color: var(--bg);
            color: var(--text-muted);
            border: 1px solid var(--border);
        }

        /* Banner */
        .tax-banner {
            background-color: var(--accent-bg);
            border: 1px solid var(--accent-border);
            color: var(--accent-text);
            border-radius: var(--radius);
            padding: 10px 16px;
            margin: 20px 0;
            font-size: 0.875rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        /* Product Cards Grid */
        main {
            flex: 1;
            padding: 24px 0 40px;
        }

        .products-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
            margin-top: 16px;
        }

        @media (min-width: 600px) {
            .products-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
            }
        }

        @media (min-width: 1024px) {
            .products-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 24px;
            }
        }

        .product-card {
            background-color: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow-sm);
            transition: box-shadow 0.2s, border-color 0.2s;
        }

        .product-card:hover {
            box-shadow: var(--shadow-md);
            border-color: var(--accent-border);
        }

        .image-container {
            width: 100%;
            aspect-ratio: 4 / 3;
            background-color: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }

        .product-image {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        .image-placeholder {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            font-size: 0.75rem;
            width: 100%;
            height: 100%;
        }

        .image-placeholder svg {
            width: 48px;
            height: 48px;
            margin-bottom: 6px;
            opacity: 0.6;
        }

        .card-body {
            padding: 16px;
            display: flex;
            flex-direction: column;
            flex: 1;
            gap: 8px;
        }

        .card-header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 8px;
        }

        .product-name {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-main);
            line-height: 1.3;
        }

        .unit-pill {
            display: inline-block;
            font-size: 0.75rem;
            font-weight: 500;
            background-color: #e2e8f0;
            color: #334155;
            padding: 2px 8px;
            border-radius: 9999px;
            white-space: nowrap;
        }

        .product-sku {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }

        .product-description {
            font-size: 0.8125rem;
            color: #475569;
            flex: 1;
            line-height: 1.4;
        }

        .card-footer-row {
            margin-top: auto;
            padding-top: 12px;
            border-top: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .price-badge {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--text-main);
        }

        .price-currency {
            font-size: 0.8125rem;
            font-weight: normal;
            color: var(--text-muted);
            margin-inline-start: 4px;
        }

        .price-on-request {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-muted);
            font-style: italic;
        }

        /* Pagination */
        .pagination-container {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 32px;
            flex-wrap: wrap;
        }

        .page-link {
            min-height: 44px;
            min-width: 44px;
            padding: 8px 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius);
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            border: 1px solid var(--border);
            background-color: var(--surface);
            color: var(--text-main);
        }

        .page-link:hover {
            background-color: var(--bg);
        }

        .page-link.active {
            background-color: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }

        .page-link:focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }

        /* Landing / Modal States */
        .centered-card-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 70vh;
            padding: 24px;
        }

        .centered-card {
            background-color: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 32px;
            max-width: 440px;
            width: 100%;
            text-align: center;
            box-shadow: var(--shadow-md);
        }

        .centered-icon {
            width: 56px;
            height: 56px;
            margin: 0 auto 16px;
            color: var(--primary);
        }

        .form-input {
            width: 100%;
            min-height: 44px;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: 0.9375rem;
            margin-top: 16px;
            margin-bottom: 16px;
            text-align: center;
        }

        .form-input:focus {
            outline: 2px solid var(--primary);
            border-color: var(--primary);
        }

        .error-message {
            color: var(--danger);
            font-size: 0.875rem;
            margin-bottom: 12px;
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        .tabular-nums {
            font-variant-numeric: tabular-nums;
        }

        /* Footer */
        footer {
            background-color: var(--surface);
            border-top: 1px solid var(--border);
            padding: 20px 0;
            margin-top: auto;
            font-size: 0.8125rem;
            color: var(--text-muted);
            text-align: center;
        }

        /* Print Media Styles */
        @media print {
            body {
                background: #ffffff;
                color: #000000;
            }
            header {
                box-shadow: none;
                border-bottom: 2px solid #000000;
                padding-bottom: 8px;
            }
            .header-actions, .pagination-container, footer, .btn {
                display: none !important;
            }
            .products-grid {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 12px !important;
            }
            .product-card {
                break-inside: avoid;
                box-shadow: none;
                border: 1px solid #cccccc;
            }
        }
    </style>
</head>
<body>
    @if($unavailable)
        <div class="centered-card-wrapper">
            <div class="centered-card">
                <svg class="centered-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
                </svg>
                <h1 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 8px;">{{ __('catalogs.catalog_unavailable_title') }}</h1>
                <p style="color: var(--text-muted); font-size: 0.875rem;">{{ __('catalogs.catalog_unavailable_desc') }}</p>
            </div>
        </div>
    @elseif($requiresPassword)
        <div class="centered-card-wrapper">
            <div class="centered-card">
                <svg class="centered-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
                <h1 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 8px;">{{ __('catalogs.password_required_title') }}</h1>
                <p style="color: var(--text-muted); font-size: 0.875rem;">{{ __('catalogs.password_required_desc') }}</p>

                <form method="POST" action="{{ ('/catalog/'.($token ?? '')) }}">
                    @csrf
                    @if(session('error') || $errors->has('password'))
                        <div class="error-message" role="alert" aria-live="assertive" id="password-error">
                            {{ session('error') ?: $errors->first('password') }}
                        </div>
                    @endif
                    <label for="catalog-password" class="sr-only">{{ __('catalogs.enter_password') }}</label>
                    <input id="catalog-password" type="password" name="password" required minlength="8" maxlength="128" placeholder="{{ __('catalogs.enter_password') }}" class="form-input" autocomplete="current-password" autofocus @if(session('error') || $errors->has('password')) aria-describedby="password-error" aria-invalid="true" @endif>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        {{ __('catalogs.unlock_catalog') }}
                    </button>
                </form>
            </div>
        </div>
    @else
        <header>
            <div class="container">
                <div class="header-content">
                    <div class="company-brand">
                        <div class="company-name">{{ $data['company']['name'] ?? '' }}</div>
                        <h1 class="catalog-title">{{ $data['title'] ?? '' }}</h1>
                        @if(!empty($data['description']))
                            <p class="catalog-desc">{{ $data['description'] }}</p>
                        @endif
                        @if(!empty($data['company']['phone']) || !empty($data['company']['email']))
                            <div class="company-contact">
                                @if(!empty($data['company']['phone']))
                                    <span>{{ $isRtl ? 'هاتف: ' : 'Tel: ' }}<bdi dir="ltr">{{ $data['company']['phone'] }}</bdi></span>
                                @endif
                                @if(!empty($data['company']['email']))
                                    <span>{{ $isRtl ? 'بريد: ' : 'Email: ' }}<bdi dir="ltr">{{ $data['company']['email'] }}</bdi></span>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div class="header-actions">
                        <span class="badge-revision">{{ __('catalogs.revision') }} <bdi class="tabular-nums">{{ $data['revision'] ?? 1 }}</bdi></span>

                        @php
                            $targetLocale = $locale === 'ar' ? 'en' : 'ar';
                            $langParams = array_merge(request()->query(), ['locale' => $targetLocale]);
                            $langUrl = ('/catalog/'.($token ?? '')) . '?' . http_build_query($langParams);
                        @endphp
                        <a href="{{ $langUrl }}" class="btn btn-secondary" hreflang="{{ $targetLocale }}" lang="{{ $targetLocale }}">
                            {{ $locale === 'ar' ? 'English' : 'العربية' }}
                        </a>

                        @php
                            $pdfParams = array_merge(request()->query(), ['format' => 'pdf']);
                            $pdfUrl = ('/catalog/'.($token ?? '')) . '?' . http_build_query($pdfParams);
                        @endphp
                        <a href="{{ $pdfUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-secondary">
                            {{ __('catalogs.download_pdf') }}
                        </a>

                        <button type="button" class="btn btn-secondary btn-print-trigger">
                            {{ __('catalogs.print') }}
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <main>
            <div class="container">
                @if($isPriced && !empty($data['tax_basis']))
                    <div class="tax-banner">
                        <span><strong>{{ __('catalogs.tax_basis') }}:</strong> {{ $data['tax_basis'] }}</span>
                        <span><strong>{{ __('catalogs.currency') }}:</strong> <bdi dir="ltr">{{ $data['currency_code'] }}</bdi></span>
                    </div>
                @endif

                <div class="products-grid">
                    @forelse($data['items'] ?? [] as $item)
                        <article class="product-card">
                            <div class="image-container">
                                @if(!empty($item['image']))
                                    <img src="{{ $item['image'] }}" alt="{{ $item['name'] }}" loading="lazy" class="product-image">
                                @else
                                    <div class="image-placeholder">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                            <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                            <polyline points="21 15 16 10 5 21"></polyline>
                                        </svg>
                                        <span>{{ __('catalogs.no_image') }}</span>
                                    </div>
                                @endif
                            </div>

                            <div class="card-body">
                                <div class="card-header-row">
                                    <h2 class="product-name">{{ $item['name'] }}</h2>
                                    <span class="unit-pill">{{ $item['unit'] }}</span>
                                </div>

                                @if(!empty($item['sku']))
                                    <div class="product-sku">SKU: <bdi dir="ltr">{{ $item['sku'] }}</bdi></div>
                                @endif

                                @if(!empty($item['description']))
                                    <p class="product-description">{{ $item['description'] }}</p>
                                @endif

                                @if(array_key_exists('price', $item))
                                    <div class="card-footer-row">
                                        @if($item['price'] !== null)
                                            <div>
                                                <span class="price-badge"><bdi class="tabular-nums" dir="ltr">{{ $item['price'] }}</bdi></span>
                                                <span class="price-currency">{{ $data['currency_code'] ?? '' }}</span>
                                            </div>
                                        @else
                                            <span class="price-on-request">{{ __('catalogs.price_on_request') }}</span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </article>
                    @empty
                        <div style="grid-column: 1 / -1; text-align: center; padding: 48px 16px; color: var(--text-muted);">
                            <p>{{ __('catalogs.no_products_selected') }}</p>
                        </div>
                    @endforelse
                </div>

                @if($totalPages > 1)
                    <nav class="pagination-container" aria-label="{{ $isRtl ? 'التنقل بين الصفحات' : 'Pagination' }}">
                        @for($p = 1; $p <= min(11, $totalPages); $p++)
                            @php
                                $pageParams = array_merge(request()->query(), ['page' => $p]);
                                $pageUrl = ('/catalog/'.($token ?? '')) . '?' . http_build_query($pageParams);
                            @endphp
                            <a href="{{ $pageUrl }}" class="page-link {{ $p === $currentPage ? 'active' : '' }}" {{ $p === $currentPage ? 'aria-current="page"' : '' }} aria-label="{{ __('catalogs.page_info', ['current' => $p, 'total' => $totalPages]) }}">
                                <bdi class="tabular-nums">{{ $p }}</bdi>
                            </a>
                        @endfor
                    </nav>
                @endif
            </div>
        </main>

        <footer>
            <div class="container">
                <p>&copy; {{ date('Y') }} {{ $data['company']['name'] ?? '' }}. {{ __('catalogs.all_rights_reserved') }}</p>
            </div>
        </footer>

        @php
            $cspNonce = request()->attributes->get('document_csp_nonce') ?? (function_exists('csp_nonce') ? csp_nonce() : null);
        @endphp
        <script @if(!empty($cspNonce)) nonce="{{ $cspNonce }}" @endif>
            document.addEventListener('DOMContentLoaded', function () {
                var printButtons = document.querySelectorAll('.btn-print-trigger');
                for (var i = 0; i < printButtons.length; i++) {
                    printButtons[i].addEventListener('click', function () {
                        window.print();
                    });
                }
            });
        </script>
    @endif
</body>
</html>
