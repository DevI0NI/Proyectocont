document.addEventListener('DOMContentLoaded', () => {
    const groups = Array.from(document.querySelectorAll('[data-sidebar-group]')).map((root) => ({
        root,
        button: root.querySelector('.nav-group-trigger'),
        panel: root.querySelector('.nav-submenu'),
        key: 'proportsa-nav-group-' + root.dataset.sidebarGroup,
    }));
    if (!groups.length) return;
    const mobile = window.matchMedia('(max-width:980px)');
    const collapsed = () => !mobile.matches && document.body.classList.contains('sidebar-collapsed') && !document.body.classList.contains('sidebar-open');
    function position(group) {
        const box = group.button.getBoundingClientRect();
        group.panel.style.left = Math.min(box.right + 12, innerWidth - 252) + 'px';
        group.panel.style.top = Math.max(12, Math.min(box.top, innerHeight - group.panel.offsetHeight - 12)) + 'px';
    }
    function finishClose(group) {
        group.panel.hidden = true;
        group.button.setAttribute('aria-expanded', 'false');
        group.root.appendChild(group.panel);
        group.panel.classList.remove('nav-group-flyout');
        group.panel.style.removeProperty('top');
        group.panel.style.removeProperty('left');
    }
    function transition(group, opening, done) {
        group.animation?.cancel();
        const flyout = group.panel.classList.contains('nav-group-flyout');
        const styles = getComputedStyle(group.panel);
        const expanded = flyout
            ? { opacity:1, transform:'translateX(0)' }
            : { height:group.panel.offsetHeight + 'px', opacity:1, marginTop:styles.marginTop, marginBottom:styles.marginBottom };
        const compact = flyout
            ? { opacity:0, transform:'translateX(-6px)' }
            : { height:'0px', opacity:0, marginTop:'0px', marginBottom:'0px' };
        group.panel.classList.add('nav-submenu-animating');
        const animation = group.panel.animate(opening ? [compact, expanded] : [expanded, compact], {
            duration:220,
            easing:'cubic-bezier(0.2, 0, 0, 1)',
        });
        group.animation = animation;
        animation.onfinish = () => {
            group.animation = null;
            group.panel.classList.remove('nav-submenu-animating');
            done?.();
        };
    }
    function close(group, animate = true) {
        group.animation?.cancel();
        group.button.setAttribute('aria-expanded', 'false');
        if (animate && !group.panel.hidden) transition(group, false, () => finishClose(group));
        else {
            group.panel.classList.remove('nav-submenu-animating');
            finishClose(group);
        }
    }
    function open(group, animate = true) {
        group.animation?.cancel();
        if (collapsed()) {
            groups.forEach((other) => { if (other !== group) close(other); });
            document.body.appendChild(group.panel);
            group.panel.classList.add('nav-group-flyout');
        }
        group.panel.hidden = false;
        group.button.setAttribute('aria-expanded', 'true');
        if (collapsed()) position(group);
        if (animate) transition(group, true);
    }
    function sync() {
        groups.forEach((group) => {
            close(group, false);
            if (!collapsed() && (group.root.dataset.current === 'true' || localStorage.getItem(group.key) === 'true')) open(group, false);
        });
    }
    groups.forEach((group) => {
        group.button.addEventListener('click', () => {
            const showing = group.button.getAttribute('aria-expanded') === 'true';
            if (showing) close(group); else open(group);
            if (!collapsed()) localStorage.setItem(group.key, String(!showing));
        });
        group.button.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                open(group);
                group.panel.querySelector('a')?.focus();
            }
        });
    });
    document.addEventListener('click', (event) => {
        if (!collapsed()) return;
        groups.forEach((group) => {
            if (!group.root.contains(event.target) && !group.panel.contains(event.target)) close(group);
        });
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        groups.forEach((group) => {
            if (!group.panel.hidden && (group.panel.contains(document.activeElement) || group.button === document.activeElement)) {
                close(group);
                group.button.focus();
            } else if (collapsed()) close(group);
        });
    });
    new MutationObserver(sync).observe(document.body, { attributes:true, attributeFilter:['class'] });
    mobile.addEventListener('change', sync);
    window.addEventListener('resize', () => groups.forEach((group) => {
        if (group.panel.classList.contains('nav-group-flyout') && !group.panel.hidden) position(group);
    }));
    document.querySelector('#sidebar')?.addEventListener('scroll', () => {
        if (collapsed()) groups.forEach((group) => close(group));
    });
    sync();
});
