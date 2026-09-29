const header = document.querySelector('[data-landing-header]');
const menu = document.querySelector('[data-menu]');
const menuToggle = document.querySelector('[data-menu-toggle]');
const navLinks = [...document.querySelectorAll('[data-nav-link]')];
const revealItems = [...document.querySelectorAll('[data-reveal]')];

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
    if (!menu || !menuToggle || !menu.classList.contains('is-open')) {
        return;
    }

    const target = event.target;
    if (!(target instanceof Node)) {
        return;
    }

    if (!menu.contains(target) && !menuToggle.contains(target)) {
        menu.classList.remove('is-open');
        menuToggle.setAttribute('aria-expanded', 'false');
    }
});

const sectionObserver = 'IntersectionObserver' in window
    ? new IntersectionObserver((entries) => {
        const visible = entries
            .filter((entry) => entry.isIntersecting)
            .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];

        if (!visible) {
            return;
        }

        const currentId = visible.target.id;
        navLinks.forEach((link) => {
            link.classList.toggle('is-active', link.getAttribute('href') === `#${currentId}`);
        });
    }, {
        rootMargin: '-28% 0px -58% 0px',
        threshold: [0.05, 0.25, 0.5],
    })
    : null;

['inicio', 'como-funciona', 'para-docentes'].forEach((id) => {
    const section = document.getElementById(id);
    if (section && sectionObserver) {
        sectionObserver.observe(section);
    }
});

if ('IntersectionObserver' in window) {
    const revealObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) {
                return;
            }

            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        });
    }, { threshold: 0.12 });

    revealItems.forEach((item) => revealObserver.observe(item));
} else {
    revealItems.forEach((item) => item.classList.add('is-visible'));
}

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
    if (nextIndex === -1) {
        return;
    }

    activeDemoIndex = nextIndex;

    demoTabs.forEach((tab) => {
        const isActive = tab.dataset.demoTab === key;
        tab.classList.toggle('is-active', isActive);
        tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    demoPanels.forEach((panel) => {
        panel.classList.toggle('is-active', panel.dataset.demoPanel === key);
    });

    if (demoStatus) {
        demoStatus.textContent = demoStatuses[key] ?? 'En preparación';
    }

    if (userInitiated) {
        restartDemoTimer();
    }
};

const startDemoTimer = () => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || demoTabs.length < 2) {
        return;
    }

    demoTimer = window.setInterval(() => {
        const nextIndex = (activeDemoIndex + 1) % demoTabs.length;
        activateDemo(demoTabs[nextIndex].dataset.demoTab);
    }, 5200);
};

const restartDemoTimer = () => {
    if (demoTimer) {
        window.clearInterval(demoTimer);
    }
    startDemoTimer();
};

demoTabs.forEach((tab) => {
    tab.addEventListener('click', () => {
        activateDemo(tab.dataset.demoTab, { userInitiated: true });
    });
});

demo?.addEventListener('mouseenter', () => {
    if (demoTimer) {
        window.clearInterval(demoTimer);
        demoTimer = null;
    }
});

demo?.addEventListener('mouseleave', () => {
    if (!demoTimer) {
        startDemoTimer();
    }
});

startDemoTimer();
