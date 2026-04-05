// BF10 - Script principal

// Menú móvil
const menuToggle = document.getElementById('menu-toggle');
const nav = document.getElementById('nav');

menuToggle.addEventListener('click', () => {
    menuToggle.classList.toggle('is-open');
    nav.classList.toggle('is-open');
});

// Cerrar menú al hacer click en un enlace
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
