// Script สำหรับ Masonry Grid
window.addEventListener('load', function() {
    var elem = document.querySelector('.row[data-masonry]');
    if (elem) {
        new Masonry(elem, {
            percentPosition: true
        });
    }
});