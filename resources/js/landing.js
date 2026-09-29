const header = document.querySelector('[data-landing-header]');
const menu = document.querySelector('[data-menu]');
const menuToggle = document.querySelector('[data-menu-toggle]');
const navLinks = [...document.querySelectorAll('[data-nav-link]')];
const revealItems = [...document.querySelectorAll('[data-reveal]')];
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const setHeaderState = () => {
    header?.classList.toggle('is-scrolled', window.scrollY > 10);
};

setHeaderState();
window.addEventListener('scroll', setHeaderState, { passive: true });

menuToggle?.addEventListener('click', () => {
    const isOpen = menu?.classList.toggle('is-open') ?? false;
    menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
});

navLinks.forEach((link) => {
    link.addEventListener('click', () => {
        menu?.classList.remove('is-open');
        menuToggle?.setAttribute('aria-expanded', 'false');
    });
});

document.addEventListener('click', (event) => {
    if (!menu || !menuToggle || !menu.classList.contains('is-open')) return;

    const target = event.target;
    if (!(target instanceof Node)) return;

    if (!menu.contains(target) && !menuToggle.contains(target)) {
        menu.classList.remove('is-open');
        menuToggle.setAttribute('aria-expanded', 'false');
    }
});

if ('IntersectionObserver' in window) {
    const sectionObserver = new IntersectionObserver((entries) => {
        const visible = entries
            .filter((entry) => entry.isIntersecting)
            .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];

        if (!visible) return;

        const currentId = visible.target.id;
        navLinks.forEach((link) => {
            link.classList.toggle('is-active', link.getAttribute('href') === `#${currentId}`);
        });
    }, {
        rootMargin: '-24% 0px -62% 0px',
        threshold: [0.05, 0.2, 0.45],
    });

    ['inicio', 'diferente', 'como-funciona', 'para-docentes'].forEach((id) => {
        const section = document.getElementById(id);
        if (section) sectionObserver.observe(section);
    });

    const revealObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;

            const siblings = [...entry.target.parentElement?.querySelectorAll?.('[data-reveal]') ?? []];
            const index = Math.max(0, siblings.indexOf(entry.target));

            entry.target.style.transitionDelay = reducedMotion ? '0ms' : `${Math.min(index * 65, 260)}ms`;
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        });
    }, { threshold: 0.1 });

    revealItems.forEach((item) => revealObserver.observe(item));
} else {
    revealItems.forEach((item) => item.classList.add('is-visible'));
}

/* Interactive product demo */
const demo = document.querySelector('[data-demo]');
const demoTabs = demo ? [...demo.querySelectorAll('[data-demo-tab]')] : [];
const demoPanels = demo ? [...demo.querySelectorAll('[data-demo-panel]')] : [];
const demoStatus = demo?.querySelector('[data-demo-status]');

const demoStatuses = {
    periodo: 'En preparación',
    curriculo: 'Revisando',
    documento: 'Lista',
};

let activeDemoIndex = 0;
let demoTimer = null;

const activateDemo = (key, { userInitiated = false } = {}) => {
    const nextIndex = demoTabs.findIndex((tab) => tab.dataset.demoTab === key);
    if (nextIndex === -1) return;

    activeDemoIndex = nextIndex;

    demoTabs.forEach((tab) => {
        const isActive = tab.dataset.demoTab === key;
        tab.classList.toggle('is-active', isActive);
        tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    demoPanels.forEach((panel) => {
        panel.classList.toggle('is-active', panel.dataset.demoPanel === key);
    });

    if (demoStatus) demoStatus.textContent = demoStatuses[key] ?? 'En preparación';
    if (userInitiated) restartDemoTimer();
};

const startDemoTimer = () => {
    if (reducedMotion || demoTabs.length < 2) return;

    demoTimer = window.setInterval(() => {
        const nextIndex = (activeDemoIndex + 1) % demoTabs.length;
        activateDemo(demoTabs[nextIndex].dataset.demoTab);
    }, 4800);
};

const restartDemoTimer = () => {
    if (demoTimer) window.clearInterval(demoTimer);
    demoTimer = null;
    startDemoTimer();
};

demoTabs.forEach((tab) => {
    tab.addEventListener('click', () => {
        activateDemo(tab.dataset.demoTab, { userInitiated: true });
    });
});

demo?.addEventListener('mouseenter', () => {
    if (!demoTimer) return;
    window.clearInterval(demoTimer);
    demoTimer = null;
});

demo?.addEventListener('mouseleave', () => {
    if (!demoTimer) startDemoTimer();
});

startDemoTimer();

/* Subtle pointer depth for the product preview */
const tilt = document.querySelector('[data-tilt]');

if (tilt && !reducedMotion && window.matchMedia('(pointer:fine)').matches) {
    const maxTilt = 4.2;

    tilt.addEventListener('pointermove', (event) => {
        const rect = tilt.getBoundingClientRect();
        const x = (event.clientX - rect.left) / rect.width;
        const y = (event.clientY - rect.top) / rect.height;

        const tiltY = (x - .5) * maxTilt * 2;
        const tiltX = (.5 - y) * maxTilt * 2;

        tilt.style.setProperty('--tilt-x', `${tiltX.toFixed(2)}deg`);
        tilt.style.setProperty('--tilt-y', `${tiltY.toFixed(2)}deg`);
        tilt.style.setProperty('--tilt-lift', '-4px');
    });

    tilt.addEventListener('pointerleave', () => {
        tilt.style.setProperty('--tilt-x', '0deg');
        tilt.style.setProperty('--tilt-y', '0deg');
        tilt.style.setProperty('--tilt-lift', '0px');
    });
}

/* Floating hero callouts react slightly to the pointer */
const floatingCards = [...document.querySelectorAll('[data-float]')];
const heroVisual = document.querySelector('.landing-hero__visual');

if (heroVisual && floatingCards.length && !reducedMotion && window.matchMedia('(pointer:fine)').matches) {
    heroVisual.addEventListener('pointermove', (event) => {
        const rect = heroVisual.getBoundingClientRect();
        const dx = ((event.clientX - rect.left) / rect.width) - .5;
        const dy = ((event.clientY - rect.top) / rect.height) - .5;

        floatingCards.forEach((card, index) => {
            const strength = 4 + (index * 2);
            card.style.marginLeft = `${(dx * strength).toFixed(1)}px`;
            card.style.marginTop = `${(dy * strength).toFixed(1)}px`;
        });
    });

    heroVisual.addEventListener('pointerleave', () => {
        floatingCards.forEach((card) => {
            card.style.marginLeft = '0px';
            card.style.marginTop = '0px';
        });
    });
}
