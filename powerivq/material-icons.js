const materialIconsHref = 'https://fonts.googleapis.com/icon?family=Material+Icons';

if (!document.querySelector(`link[href="${materialIconsHref}"]`)) {
    const materialIconsStylesheet = document.createElement('link');
    materialIconsStylesheet.href = materialIconsHref;
    materialIconsStylesheet.rel = 'stylesheet';
    document.head.appendChild(materialIconsStylesheet);
}
