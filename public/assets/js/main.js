import './modules/theme.js';
import './modules/consent.js';
void import('./modules/analytics.js');

if (document.querySelector('.nav-toggle')) {
    void import('./modules/navigation.js');
}

if (document.querySelector('[data-reveal]')) {
    void import('./modules/reveal.js');
}

if (document.querySelector('[data-service-carousel]')) {
    void import('./modules/services-carousel.js');
}

if (document.querySelector('[data-service-accordion]')) {
    void import('./modules/service-accordion.js');
}

if (document.querySelector('[data-count-up]')) {
    void import('./modules/count-up.js');
}

if (document.getElementById('reviews-track')) {
    void import('./modules/reviews.js');
}

if (document.querySelector('.contact-form')) {
    void import('./modules/contact.js');
}
