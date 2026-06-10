<?php
declare(strict_types=1);

$siteConfig = require __DIR__ . '/site-config.php';

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function config(string $keyPath, mixed $default = null): mixed
{
    global $siteConfig;

    $segments = explode('.', $keyPath);
    $current = $siteConfig;

    foreach ($segments as $segment) {
        if (!is_array($current) || !array_key_exists($segment, $current)) {
            return $default;
        }

        $current = $current[$segment];
    }

    return $current;
}

function asset_url(string $path): string
{
    return '/public/assets/' . ltrim($path, '/');
}

function page_url(string $page = ''): string
{
    return $page === '' ? '/' : '/' . ltrim($page, '/');
}

function company_address_lines(array $company): array
{
    return [
        trim(($company['street'] ?? '') . ''),
        trim(($company['postalCode'] ?? '') . ' ' . ($company['city'] ?? '')),
        trim(($company['country'] ?? '') . ''),
    ];
}

function company_address_inline(array $company): string
{
    return implode(', ', array_filter(company_address_lines($company)));
}

function company_has_google_reviews(array $company): bool
{
    return trim((string) ($company['googlePlaceId'] ?? '')) !== ''
        && trim((string) ($company['googleApiKey'] ?? '')) !== '';
}

function company_has_manual_reviews(array $company): bool
{
    return !empty($company['manualTestimonials']) && is_array($company['manualTestimonials']);
}

function legal_notice_pending(array $company): bool
{
    return legal_missing_fields($company) !== [];
}

function legal_missing_fields(array $company): array
{
    $requiredFields = [
        'owner' => 'Inhaber',
        'street' => 'Straße',
        'postalCode' => 'Postleitzahl',
        'city' => 'Ort',
        'country' => 'Land',
        'phone' => 'Telefon',
        'email' => 'E-Mail',
        'supervisoryAuthority' => 'Aufsichtsbehörde',
        'supervisoryAuthorityUrl' => 'Aufsichtsbehörde URL',
        'chamber' => 'Kammer',
        'chamberUrl' => 'Kammer URL',
        'profession' => 'Berufsbezeichnung',
        'professionDetail' => 'Berufszweig',
        'memberState' => 'Verleihungsstaat',
        'tradeRegulationLabel' => 'Rechtsvorschrift',
        'tradeRegulationUrl' => 'Rechtsvorschrift URL',
        'mediaLine' => 'Blattlinie',
        'websitePurpose' => 'Unternehmensgegenstand',
    ];

    $placeholderPatterns = [
        '/eintragen/i',
        '/platzhalter/i',
        '/zuständig/i',
        '/falls vorhanden/i',
        '/nur bei/i',
    ];

    $missing = [];

    foreach ($requiredFields as $key => $label) {
        $value = trim((string) ($company[$key] ?? ''));

        if ($value === '') {
            $missing[] = $label;
            continue;
        }

        foreach ($placeholderPatterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                $missing[] = $label;
                continue 2;
            }
        }
    }

    if (!filter_var((string) ($company['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        $missing[] = 'E-Mail';
    }

    return array_values(array_unique($missing));
}

function canonical_url(string $path = ''): string
{
    $base = rtrim((string) config('meta.canonicalBaseUrl', ''), '/');
    $suffix = $path === '' ? '/' : '/' . ltrim($path, '/');

    return $base . $suffix;
}

function phone_href(string $number): string
{
    $clean = preg_replace('/[^\d+]/', '', $number);

    return $clean === null ? $number : $clean;
}

function theme_toggle_markup(string $className = ''): string
{
    $classes = trim('theme-toggle' . ($className !== '' ? ' ' . $className : ''));

    return '<button class="' . e($classes) . '" type="button" data-theme-toggle aria-label="Farbschema wechseln" title="Farbschema wechseln. Standard ist automatisch nach System.">'
        . '<svg class="theme-glyph theme-glyph-sun" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4.2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 2.5v2.6M12 18.9v2.6M4.9 4.9l1.9 1.9M17.2 17.2l1.9 1.9M2.5 12h2.6M18.9 12h2.6M4.9 19.1l1.9-1.9M17.2 6.8l1.9-1.9" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8"/></svg>'
        . '<svg class="theme-glyph theme-glyph-moon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18.5 14.2A7 7 0 0 1 9.8 5.5a7.9 7.9 0 1 0 8.7 8.7Z" fill="none" stroke="currentColor" stroke-linejoin="round" stroke-width="1.8"/></svg>'
        . '</button>';
}

function site_favicon_markup(): string
{
    $favicon = asset_url('img/it-tabelander-mark.png');

    return '<link rel="icon" type="image/png" href="' . e($favicon) . '">'
        . '<link rel="apple-touch-icon" href="' . e($favicon) . '">';
}

function cookie_notice_markup(): string
{
    return '<aside class="cookie-notice" data-cookie-notice hidden>'
        . '<div class="cookie-notice-copy">'
        . '<p class="cookie-notice-title">Datenschutz-Einstellungen</p>'
        . '<p>Technisch notwendige Cookies schützen das Formular. Google Analytics wird nur nach aktiver Zustimmung geladen und kann später wieder abgelehnt werden.</p>'
        . '</div>'
        . '<div class="cookie-notice-actions">'
        . '<a href="' . e(page_url('datenschutz.php')) . '">Datenschutz</a>'
        . '<button class="button button-secondary button-compact" type="button" data-cookie-reject>Ablehnen</button>'
        . '<button class="button button-primary button-compact" type="button" data-cookie-accept>Analytics akzeptieren</button>'
        . '</div>'
        . '</aside>';
}

function analytics_bootstrap_script(): string
{
    $measurementId = trim((string) config('analytics.googleMeasurementId', ''));

    if ($measurementId === '') {
        return '';
    }

    return '<script>'
        . 'window.IT_TABELANDER_ANALYTICS_ID=' . json_encode($measurementId, JSON_UNESCAPED_SLASHES) . ';'
        . 'window.dataLayer=window.dataLayer||[];'
        . 'window.gtag=window.gtag||function(){window.dataLayer.push(arguments);};'
        . 'window.gtag("consent","default",{analytics_storage:"denied",ad_storage:"denied",ad_user_data:"denied",ad_personalization:"denied",functionality_storage:"granted",security_storage:"granted"});'
        . '</script>';
}

function theme_bootstrap_script(): string
{
    return <<<'JS'
(() => {
    try {
        const stored = localStorage.getItem('it-tabelander-theme');
        const choice = stored === 'light' || stored === 'dark' ? stored : 'auto';
        const root = document.documentElement;
        if (choice === 'light' || choice === 'dark') {
            root.dataset.theme = choice;
        } else {
            delete root.dataset.theme;
        }
        root.dataset.themeChoice = choice;
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const resolved = choice === 'auto' ? (prefersDark ? 'dark' : 'light') : choice;
        root.dataset.resolvedTheme = resolved;
        const meta = document.querySelector('meta[name="theme-color"]');
        if (meta) {
            meta.setAttribute('content', resolved === 'dark' ? '#08141d' : '#f4f7fb');
        }
    } catch (error) {
        // Keep the default color scheme if storage is unavailable.
    }
})();
JS;
}
