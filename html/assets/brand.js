(() => {
    const path = window.location.pathname;
    const testMode = path.startsWith('/pruebas/');
    const prefix = testMode ? '/pruebas' : '';
    const assets = `${prefix}/assets`;
    const role = (document.body.dataset.corozaoRole || document.querySelector('.navbar .badge')?.textContent || '').trim();
    const nav = document.querySelector('nav.navbar, header.navbar');
    const main = document.querySelector('main');

    if (!nav || !main) return;
    document.body.classList.add('brand-app');
    if (path.endsWith('/reportes.php')) document.body.classList.add('brand-report');

    const anchor = nav.querySelector('.navbar-brand');
    if (anchor) {
        anchor.href = `${prefix}/`;
        anchor.replaceChildren();
        const mark = document.createElement('img');
        mark.src = `${assets}/img/brand/corozao-mark.svg?v=2`;
        mark.alt = '';
        mark.setAttribute('aria-hidden', 'true');
        const word = document.createElement('span');
        word.textContent = 'Corozao';
        anchor.append(mark, word);
    }

    const links = [
        ['Inicio', '/', '⌂', ['ADMINISTRADOR', 'CAJERO', 'MESERO']],
        ['Productos', '/productos.php', '▦', ['ADMINISTRADOR', 'CAJERO', 'MESERO']],
        ['Comercial por bodega', '/comercial_bodegas.php', '◇', ['ADMINISTRADOR', 'CAJERO']],
        ['Proveedores', '/proveedores.php', '♧', ['ADMINISTRADOR']],
        ['Compras', '/compras.php', '＋', ['ADMINISTRADOR']],
        ['Pedidos', '/pedidos.php', '▤', ['ADMINISTRADOR', 'CAJERO', 'MESERO']],
        ['Ventas y caja', '/ventas.php', '$', ['ADMINISTRADOR', 'CAJERO']],
        ['Devoluciones', '/devoluciones.php', '↶', ['ADMINISTRADOR', 'CAJERO']],
        ['Inventario', '/inventario.php', '▥', ['ADMINISTRADOR', 'CAJERO']],
        ['Reportes y conteos', '/reportes.php', '▧', ['ADMINISTRADOR']],
        ['Transferencias', '/transferencias.php', '⇄', ['ADMINISTRADOR']],
        ['Ajustes', '/ajustes.php', '±', ['ADMINISTRADOR']],
        ['Cargas iniciales', '/cargas_iniciales.php', '⇧', ['ADMINISTRADOR']],
        ['Asignaciones de bodega', '/asignaciones_bodega.php', '⌖', ['ADMINISTRADOR']],
        ['Usuarios', '/usuarios.php', '♙', ['ADMINISTRADOR']]
    ];
    const sidebar = document.createElement('aside');
    sidebar.className = 'brand-sidebar';
    sidebar.setAttribute('aria-label', 'Navegación principal');
    const head = document.createElement('div');
    head.className = 'brand-sidebar-head';
    const logoLink = document.createElement('a');
    logoLink.href = `${prefix}/`;
    logoLink.setAttribute('aria-label', 'Corozao, inicio');
    const logo = document.createElement('img');
    logo.className = 'brand-logo-full'; logo.src = `${assets}/img/brand/corozao-logo.jpeg`;
    logo.alt = 'Corozao by Mi Sub';
    const compact = document.createElement('img');
    compact.className = 'brand-logo-mark'; compact.src = `${assets}/img/brand/corozao-mark.svg?v=2`;
    compact.alt = '';
    logoLink.append(logo, compact);
    const collapse = document.createElement('button');
    collapse.className = 'brand-collapse'; collapse.type = 'button'; collapse.textContent = '‹';
    collapse.setAttribute('aria-label', 'Contraer menú lateral');
    collapse.setAttribute('aria-expanded', 'true');
    head.append(logoLink, collapse);
    const label = document.createElement('div'); label.className = 'brand-sidebar-label'; label.textContent = 'Menú principal';
    const list = document.createElement('nav'); list.className = 'brand-sidebar-nav';
    list.setAttribute('aria-label', 'Módulos');
    const current = path.replace(prefix, '') || '/';
    links.forEach(([text, href, icon, roles]) => {
        if (role && !roles.includes(role)) return;
        const item = document.createElement('a');
        item.className = 'brand-sidebar-link'; item.href = `${prefix}${href}`;
        if (current === href || (href === '/' && current === '/index.php')) item.setAttribute('aria-current', 'page');
        item.title = text;
        const symbol = document.createElement('span'); symbol.className = 'brand-sidebar-icon';
        symbol.setAttribute('aria-hidden', 'true'); symbol.textContent = icon;
        const name = document.createElement('span'); name.textContent = text;
        item.append(symbol, name); list.append(item);
    });
    const foot = document.createElement('div'); foot.className = 'brand-sidebar-foot';
    const roleName = document.createElement('strong'); roleName.textContent = role || 'Sesión activa';
    const caption = document.createElement('span'); caption.textContent = 'Sistema de gestión';
    foot.append(roleName, caption);
    sidebar.append(head, label, list, foot);

    const topbar = document.createElement('div'); topbar.className = 'brand-topbar';
    nav.parentNode.insertBefore(topbar, nav); topbar.append(nav);
    const mobile = document.createElement('button');
    mobile.className = 'brand-mobile-toggle'; mobile.type = 'button'; mobile.textContent = '☰';
    mobile.setAttribute('aria-label', 'Abrir menú principal'); mobile.setAttribute('aria-expanded', 'false');
    nav.querySelector('.container-xxl, .container, .container-fluid')?.prepend(mobile);
    const backdrop = document.createElement('button'); backdrop.className = 'brand-backdrop';
    backdrop.type = 'button'; backdrop.setAttribute('aria-label', 'Cerrar menú');
    document.body.prepend(backdrop, sidebar);

    const collapsedKey = 'corozao_menu_contraido';
    if (window.innerWidth >= 768 && localStorage.getItem(collapsedKey) === '1') {
        document.body.classList.add('brand-sidebar-collapsed');
        collapse.textContent = '›'; collapse.setAttribute('aria-expanded', 'false');
        collapse.setAttribute('aria-label', 'Expandir menú lateral');
    }
    collapse.addEventListener('click', () => {
        const closed = document.body.classList.toggle('brand-sidebar-collapsed');
        localStorage.setItem(collapsedKey, closed ? '1' : '0');
        collapse.textContent = closed ? '›' : '‹';
        collapse.setAttribute('aria-expanded', closed ? 'false' : 'true');
        collapse.setAttribute('aria-label', closed ? 'Expandir menú lateral' : 'Contraer menú lateral');
    });
    const setMobile = open => {
        document.body.classList.toggle('brand-menu-open', open);
        mobile.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    mobile.addEventListener('click', () => setMobile(!document.body.classList.contains('brand-menu-open')));
    backdrop.addEventListener('click', () => setMobile(false));
    list.addEventListener('click', event => { if (event.target.closest('a')) setMobile(false); });
})();
