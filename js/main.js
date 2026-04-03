/**
 * Bakudan Ramen - Main JavaScript
 * Navigation, scroll effects, mobile menu
 */

document.addEventListener('DOMContentLoaded', () => {
    // ========================
    // NAVBAR SCROLL EFFECT
    // ========================
    const header = document.querySelector('.site-header');
    if (header) {
        let lastScroll = 0;
        window.addEventListener('scroll', () => {
            const currentScroll = window.scrollY;
            if (currentScroll > 80) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
            lastScroll = currentScroll;
        }, { passive: true });
    }

    // ========================
    // MOBILE MENU
    // ========================
    const hamburger = document.querySelector('.hamburger');
    const mobileNav = document.querySelector('.mobile-nav');

    if (hamburger && mobileNav) {
        hamburger.addEventListener('click', () => {
            const isOpen = hamburger.getAttribute('aria-expanded') === 'true';
            hamburger.setAttribute('aria-expanded', !isOpen);
            mobileNav.classList.toggle('open');
            document.body.style.overflow = isOpen ? '' : 'hidden';

            // Move focus into nav when opening
            if (!isOpen) {
                const firstLink = mobileNav.querySelector('a');
                if (firstLink) firstLink.focus();
            }
        });

        // Close on link click
        mobileNav.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', () => {
                hamburger.setAttribute('aria-expanded', 'false');
                mobileNav.classList.remove('open');
                document.body.style.overflow = '';
            });
        });

        // Close on Escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && mobileNav.classList.contains('open')) {
                hamburger.setAttribute('aria-expanded', 'false');
                mobileNav.classList.remove('open');
                document.body.style.overflow = '';
                hamburger.focus();
            }
        });
    }

    // ========================
    // SMOOTH SCROLL FOR ANCHORS
    // ========================
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const targetId = this.getAttribute('href');
            if (targetId === '#') return;
            const target = document.querySelector(targetId);
            if (target) {
                e.preventDefault();
                const headerHeight = header ? header.offsetHeight : 0;
                const targetPosition = target.getBoundingClientRect().top + window.scrollY - headerHeight;
                window.scrollTo({
                    top: targetPosition,
                    behavior: 'smooth'
                });
                // Set focus on target for accessibility
                target.setAttribute('tabindex', '-1');
                target.focus({ preventScroll: true });
            }
        });
    });

    // ========================
    // ACTIVE NAV LINK
    // ========================
    const currentPage = window.location.pathname.split('/').pop() || 'index.html';
    document.querySelectorAll('.nav-links a:not(.nav-cta)').forEach(link => {
        const href = link.getAttribute('href');
        if (href === currentPage || (currentPage === '' && href === 'index.html')) {
            link.classList.add('active');
        }
    });

    // ========================
    // SCROLL REVEAL (lightweight)
    // ========================
    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        const reveals = document.querySelectorAll('[data-reveal]');
        if (reveals.length > 0) {
            const revealObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                        revealObserver.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

            reveals.forEach(el => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                revealObserver.observe(el);
            });
        }
    }
});
