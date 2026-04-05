// BF10 - Script principal

// Menú móvil
const menuToggle = document.getElementById('menu-toggle');
const nav = document.getElementById('nav');

menuToggle.addEventListener('click', () => {
    menuToggle.classList.toggle('is-open');
    nav.classList.toggle('is-open');
});

nav.querySelectorAll('a').forEach(link => {
    link.addEventListener('click', () => {
        menuToggle.classList.remove('is-open');
        nav.classList.remove('is-open');
    });
});

// Header con sombra al hacer scroll
const header = document.getElementById('header');
window.addEventListener('scroll', () => {
    if (window.scrollY > 10) {
        header.style.boxShadow = 'var(--shadow-sm)';
    } else {
        header.style.boxShadow = 'none';
    }
});

// Fade-in on scroll
const fadeElements = document.querySelectorAll('.fade-in');
const observerOptions = {
    threshold: 0.15,
    rootMargin: '0px 0px -40px 0px'
};

const fadeObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            fadeObserver.unobserve(entry.target);
        }
    });
}, observerOptions);

fadeElements.forEach(el => fadeObserver.observe(el));

// FAQ Accordion
document.querySelectorAll('.faq__pregunta').forEach(btn => {
    btn.addEventListener('click', () => {
        const item = btn.parentElement;
        const isOpen = item.classList.contains('is-open');

        // Cerrar todos
        document.querySelectorAll('.faq__item.is-open').forEach(openItem => {
            openItem.classList.remove('is-open');
            openItem.querySelector('.faq__pregunta').setAttribute('aria-expanded', 'false');
        });

        // Abrir el clickado si estaba cerrado
        if (!isOpen) {
            item.classList.add('is-open');
            btn.setAttribute('aria-expanded', 'true');
        }
    });
});
