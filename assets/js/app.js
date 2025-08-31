// /htdocs/it_repair/assets/js/app.js
// Small enhancements, no external libs
const menuToggle = document.querySelector('.menu-toggle'); // Renamed from 'toggle'
const header = document.querySelector('.site-header');

if (menuToggle){ // Use the new name
  menuToggle.addEventListener('click', () => { // Use the new name
    const open = header.classList.toggle('open');
    menuToggle.setAttribute('aria-expanded', String(open)); // Use the new name
  });
}

// Gentle focus outline for keyboard users
document.addEventListener('keydown', (e)=>{
  if(e.key === 'Tab'){ document.body.classList.add('show-focus'); }
});