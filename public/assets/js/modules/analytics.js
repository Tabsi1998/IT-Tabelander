import { analyticsConsentGranted, trackAnalyticsEvent } from './consent.js';

const conversionEvents = Object.freeze({
    contactSuccess: 'contact_form_success',
    phoneClick: 'phone_click',
    emailClick: 'email_click',
    primaryCtaClick: 'primary_cta_click',
});

const pageType = document.body?.dataset.pageKey || 'unknown';
let contactSuccessTracked = false;

const conversionParameters = (location = 'content') => ({
    page_type: pageType,
    location,
});

const trackContactSuccess = () => {
    const status = document.body?.dataset.contactStatus || '';
    if (contactSuccessTracked || !['success', 'partial'].includes(status) || !analyticsConsentGranted()) {
        return;
    }

    contactSuccessTracked = trackAnalyticsEvent(conversionEvents.contactSuccess, {
        page_type: pageType,
        contact_status: status,
    });

    if (contactSuccessTracked && window.history?.replaceState) {
        const url = new URL(window.location.href);
        url.searchParams.delete('contact');
        window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
    }
};

document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target.closest('a, button') : null;
    if (!target) {
        return;
    }

    const location = target.dataset.conversionLocation || 'content';
    const href = target instanceof HTMLAnchorElement ? target.getAttribute('href') || '' : '';

    if (href.startsWith('tel:')) {
        trackAnalyticsEvent(conversionEvents.phoneClick, conversionParameters(location));
    } else if (href.startsWith('mailto:')) {
        trackAnalyticsEvent(conversionEvents.emailClick, conversionParameters(location));
    } else if (target.dataset.conversion === 'primary-cta') {
        trackAnalyticsEvent(conversionEvents.primaryCtaClick, conversionParameters(location));
    }
});

window.addEventListener('it-tabelander:analytics-consent', (event) => {
    if (event.detail?.analytics === true) {
        trackContactSuccess();
    }
});

trackContactSuccess();
