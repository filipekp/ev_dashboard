'use strict';

/** Shared EV Stats browser interactions. */
(() => {
    const isStandalonePwa = () => window.matchMedia('(display-mode: standalone)').matches
        || window.matchMedia('(display-mode: minimal-ui)').matches
        || window.navigator.standalone === true;

    const getPwaCacheVersion = () => document.documentElement.dataset.pwaCacheVersion || 'local';

    const versionedPwaUrl = (path) => {
        const separator = path.includes('?') ? '&' : '?';
        return `${path}${separator}v=${encodeURIComponent(getPwaCacheVersion())}`;
    };

    const PWA_INSTALL_DISMISS_KEY = 'evstats-pwa-install-dismissed-at';
    const PWA_INSTALL_DISMISS_MS = 14 * 24 * 60 * 60 * 1000;

    const isMobileDevice = () => {
        if (navigator.userAgentData && navigator.userAgentData.mobile === true) {
            return true;
        }

        const userAgent = navigator.userAgent || '';
        const isTouchIpad = navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1;
        return /Android|iPhone|iPad|iPod|Mobile/i.test(userAgent) || isTouchIpad;
    };

    const isIosDevice = () => {
        const userAgent = navigator.userAgent || '';
        const isTouchIpad = navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1;
        return /iPhone|iPad|iPod/i.test(userAgent) || isTouchIpad;
    };

    const readInstallDismissedAt = () => {
        try {
            const value = Number.parseInt(window.localStorage.getItem(PWA_INSTALL_DISMISS_KEY) || '', 10);
            return Number.isFinite(value) ? value : 0;
        } catch (error) {
            return 0;
        }
    };

    const rememberInstallDismissal = () => {
        try {
            window.localStorage.setItem(PWA_INSTALL_DISMISS_KEY, String(Date.now()));
        } catch (error) {
            // localStorage can be unavailable in private/restricted browsing modes.
        }
    };

    const shouldOfferPwaInstall = () => {
        if (isStandalonePwa() || !isMobileDevice()) {
            return false;
        }

        const dismissedAt = readInstallDismissedAt();
        return dismissedAt === 0 || Date.now() - dismissedAt >= PWA_INSTALL_DISMISS_MS;
    };

    const createPwaInstallOffer = (platform, deferredPrompt = null) => {
        if (!shouldOfferPwaInstall() || document.querySelector('[data-pwa-install-offer]')) {
            return null;
        }

        const offer = document.createElement('aside');
        offer.className = 'pwa-install-offer';
        offer.setAttribute('data-pwa-install-offer', '');
        offer.setAttribute('role', 'dialog');
        offer.setAttribute('aria-label', 'Instalace EV Stats');

        if (document.querySelector('.mobile-bottom-nav')) {
            offer.classList.add('pwa-install-offer--above-nav');
        }

        const icon = document.createElement('div');
        icon.className = 'pwa-install-offer__icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = '⚡';

        const content = document.createElement('div');
        content.className = 'pwa-install-offer__content';

        const title = document.createElement('strong');
        title.textContent = 'Nainstalovat EV Stats';

        const description = document.createElement('span');
        description.textContent = platform === 'ios'
            ? 'Přidejte si EV Stats na plochu a používejte ji jako běžnou aplikaci.'
            : 'Mějte svou digitální garáž přímo na ploše telefonu.';

        content.append(title, description);

        const actions = document.createElement('div');
        actions.className = 'pwa-install-offer__actions';

        const primaryButton = document.createElement('button');
        primaryButton.type = 'button';
        primaryButton.className = 'pwa-install-offer__primary';
        primaryButton.textContent = platform === 'ios' ? 'Jak nainstalovat' : 'Nainstalovat';

        const dismissButton = document.createElement('button');
        dismissButton.type = 'button';
        dismissButton.className = 'pwa-install-offer__dismiss';
        dismissButton.textContent = 'Teď ne';

        const closeOffer = (remember = false) => {
            if (remember) {
                rememberInstallDismissal();
            }
            offer.remove();
        };

        dismissButton.addEventListener('click', () => closeOffer(true));

        if (platform === 'ios') {
            primaryButton.addEventListener('click', () => {
                if (offer.querySelector('.pwa-install-offer__ios-help')) {
                    return;
                }

                const help = document.createElement('div');
                help.className = 'pwa-install-offer__ios-help';
                help.innerHTML = '<b>Na iPhonu/iPadu:</b> klepněte v prohlížeči na <span aria-label="Sdílet">Sdílet ↑</span> a vyberte <b>Přidat na plochu</b>.';
                content.appendChild(help);
                primaryButton.textContent = 'Rozumím';
                primaryButton.addEventListener('click', () => closeOffer(true), { once: true });
            }, { once: true });
        } else if (deferredPrompt) {
            primaryButton.addEventListener('click', async () => {
                primaryButton.disabled = true;
                try {
                    await deferredPrompt.prompt();
                    const choice = await deferredPrompt.userChoice;
                    if (choice && choice.outcome === 'dismissed') {
                        rememberInstallDismissal();
                    }
                    closeOffer(false);
                } catch (error) {
                    primaryButton.disabled = false;
                }
            });
        }

        actions.append(primaryButton, dismissButton);
        offer.append(icon, content, actions);
        document.body.appendChild(offer);

        return offer;
    };

    const initPwaInstallOffer = () => {
        if (!shouldOfferPwaInstall()) {
            return;
        }

        let deferredPrompt = null;

        window.addEventListener('beforeinstallprompt', (event) => {
            if (!shouldOfferPwaInstall()) {
                return;
            }

            event.preventDefault();
            deferredPrompt = event;
            createPwaInstallOffer('native', deferredPrompt);
        });

        window.addEventListener('appinstalled', () => {
            const offer = document.querySelector('[data-pwa-install-offer]');
            if (offer) {
                offer.remove();
            }
            deferredPrompt = null;
        });

        if (isIosDevice()) {
            window.setTimeout(() => {
                createPwaInstallOffer('ios');
            }, 900);
        }
    };

    const initPwa = () => {
        if (isStandalonePwa()) {
            document.querySelectorAll('a[href^="logout.php"]').forEach((link) => {
                const url = new URL(link.getAttribute('href'), window.location.href);
                url.searchParams.set('pwa', '1');
                link.setAttribute('href', `${url.pathname.split('/').pop()}${url.search}`);
            });
        }

        initPwaInstallOffer();

        if (!('serviceWorker' in navigator)) {
            return;
        }

        window.addEventListener('load', () => {
            navigator.serviceWorker.register(versionedPwaUrl('service-worker.js'), {
                updateViaCache: 'none'
            }).then((registration) => {
                registration.update().catch(() => {
                    // A failed update check must not affect normal application usage.
                });
            }).catch(() => {
                // PWA support is optional; the web application remains usable without it.
            });
        });
    };


    const initThemeSwitcher = () => {
        const labels = { light: 'Světlý', dark: 'Tmavý', auto: 'Podle systému' };
        const icons = { light: '☀', dark: '●', auto: '◐' };
        const bootstrapIcons = { light: 'bi-sun', dark: 'bi-moon-stars', auto: 'bi-circle-half' };
        const media = window.matchMedia('(prefers-color-scheme: dark)');

        const resolve = preference => preference === 'auto'
            ? (media.matches ? 'dark' : 'light')
            : preference;

        const readPreference = () => {
            try {
                const value = window.localStorage.getItem('evstats-theme') || 'auto';
                return ['light', 'dark', 'auto'].includes(value) ? value : 'auto';
            } catch (error) {
                return 'auto';
            }
        };

        const refreshCharts = () => {
            if (!window.Chart || !window.Chart.instances) return;
            const styles = getComputedStyle(document.documentElement);
            const grid = styles.getPropertyValue('--chart-grid').trim() || 'rgba(148,163,184,.12)';
            const text = styles.getPropertyValue('--chart-text').trim() || '#64748b';
            const strong = styles.getPropertyValue('--cockpit-text').trim() || text;
            const muted = styles.getPropertyValue('--cockpit-muted').trim() || text;
            const panel = styles.getPropertyValue('--cockpit-panel').trim() || '#101225';
            window.Chart.defaults.color = text;
            Object.values(window.Chart.instances).forEach(chart => {
                Object.values(chart.options.scales || {}).forEach(scale => {
                    if (scale.grid) scale.grid.color = grid;
                    if (scale.angleLines) scale.angleLines.color = grid;
                    if (scale.ticks) scale.ticks.color = text;
                    if (scale.pointLabels) scale.pointLabels.color = text;
                    if (scale.title) scale.title.color = text;
                });
                if (chart.options.plugins?.legend?.labels) chart.options.plugins.legend.labels.color = text;
                if (chart.options.plugins?.tooltip) {
                    chart.options.plugins.tooltip.backgroundColor = panel;
                    chart.options.plugins.tooltip.titleColor = strong;
                    chart.options.plugins.tooltip.bodyColor = muted;
                    chart.options.plugins.tooltip.borderColor = grid;
                }
                chart.update('none');
            });
        };

        const paint = preference => {
            const resolved = resolve(preference);
            document.documentElement.dataset.themePreference = preference;
            document.documentElement.setAttribute('data-bs-theme', resolved);
            document.querySelectorAll('[data-theme-label]').forEach(el => { el.textContent = labels[preference]; });
            document.querySelectorAll('[data-theme-icon]').forEach(el => { el.textContent = icons[preference]; });
            document.querySelectorAll('[data-theme-bootstrap-icon]').forEach(el => {
                el.classList.remove('bi-sun', 'bi-moon-stars', 'bi-circle-half');
                el.classList.add(bootstrapIcons[preference]);
            });
            document.querySelectorAll('[data-theme-choice]').forEach(button => {
                button.classList.toggle('active', button.dataset.themeChoice === preference);
                button.setAttribute('aria-pressed', button.dataset.themeChoice === preference ? 'true' : 'false');
            });
            refreshCharts();
        };

        paint(readPreference());

        document.querySelectorAll('[data-theme-choice]').forEach(button => {
            button.addEventListener('click', () => {
                const preference = button.dataset.themeChoice || 'auto';
                try { window.localStorage.setItem('evstats-theme', preference); } catch (error) {}
                paint(preference);
            });
        });

        const onSystemThemeChanged = () => {
            if (readPreference() === 'auto') {
                paint('auto');
            }
        };
        if (typeof media.addEventListener === 'function') {
            media.addEventListener('change', onSystemThemeChanged);
        } else if (typeof media.addListener === 'function') {
            media.addListener(onSystemThemeChanged);
        }
    };

    const initAutoSubmitSelects = () => {
        document.querySelectorAll('select[data-auto-submit]').forEach((select) => {
            select.addEventListener('change', () => {
                const form = select.form;
                if (!form) {
                    return;
                }

                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                    return;
                }

                form.submit();
            });
        });
    };

    const initVehiclePickers = () => {
        document.querySelectorAll('[data-vehicle-picker]').forEach((picker) => {
            const trigger = picker.querySelector('.vehicle-picker-trigger');
            const menu = picker.querySelector('.vehicle-picker-menu');
            if (!trigger || !menu) {
                return;
            }

            trigger.addEventListener('click', () => {
                const open = !menu.hidden;
                document.querySelectorAll('.vehicle-picker-menu').forEach((item) => {
                    item.hidden = true;
                });
                menu.hidden = open;
                trigger.setAttribute('aria-expanded', open ? 'false' : 'true');
            });
        });

        document.addEventListener('click', (event) => {
            document.querySelectorAll('[data-vehicle-picker]').forEach((picker) => {
                if (picker.contains(event.target)) {
                    return;
                }

                const menu = picker.querySelector('.vehicle-picker-menu');
                const trigger = picker.querySelector('.vehicle-picker-trigger');
                if (menu) {
                    menu.hidden = true;
                }
                if (trigger) {
                    trigger.setAttribute('aria-expanded', 'false');
                }
            });
        });
    };

    const initMobileMoreMenu = () => {
        const menu = document.querySelector('[data-mobile-more-menu]');
        const backdrop = document.querySelector('[data-mobile-more-backdrop]');
        const openButton = document.querySelector('[data-mobile-more-open]');
        const closeButton = document.querySelector('[data-mobile-more-close]');

        const setOpen = (open) => {
            if (!menu || !backdrop || !openButton) {
                return;
            }
            menu.hidden = !open;
            backdrop.hidden = !open;
            openButton.setAttribute('aria-expanded', open ? 'true' : 'false');
            document.body.classList.toggle('mobile-more-open', open);
            if (open && closeButton) {
                closeButton.focus();
            }
        };

        if (openButton) {
            openButton.addEventListener('click', () => setOpen(true));
        }
        if (closeButton) {
            closeButton.addEventListener('click', () => setOpen(false));
        }
        if (backdrop) {
            backdrop.addEventListener('click', () => setOpen(false));
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && menu && !menu.hidden) {
                setOpen(false);
            }
        });
    };


    const initCommandPalette = () => {
        const modal = document.getElementById('commandPalette');
        const input = modal?.querySelector('[data-command-search]');
        const items = Array.from(modal?.querySelectorAll('[data-command-item]') || []);
        const empty = modal?.querySelector('[data-command-empty]');

        const filter = () => {
            const query = (input?.value || '').trim().toLocaleLowerCase('cs');
            let visible = 0;
            items.forEach((item) => {
                const matches = query === '' || (item.textContent || '').toLocaleLowerCase('cs').includes(query);
                item.hidden = !matches;
                if (matches) visible += 1;
            });
            if (empty) empty.hidden = visible !== 0;
        };

        input?.addEventListener('input', filter);
        modal?.addEventListener('shown.bs.modal', () => {
            if (input) {
                input.value = '';
                filter();
                input.focus();
            }
        });
        input?.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') return;
            const first = items.find((item) => !item.hidden);
            if (first) first.click();
        });

        document.addEventListener('keydown', (event) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                if (modal && window.bootstrap) {
                    bootstrap.Modal.getOrCreateInstance(modal).show();
                }
            }
        });
    };

    const initBootstrapEnhancements = () => {
        if (!window.bootstrap) return;
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
            bootstrap.Tooltip.getOrCreateInstance(element);
        });
    };

    const initModalScrolling = () => {
        if (!window.bootstrap) {
            return;
        }

        document.querySelectorAll('.modal-dialog-scrollable .modal-content > form').forEach((form) => {
            if (form.querySelector('.modal-body') || form.querySelector('.modal-footer')) {
                form.classList.add('modal-scroll-form');
            }
        });

        const syncRootLock = () => {
            const hasOpenModal = document.querySelector('.modal.show') !== null;
            document.documentElement.classList.toggle('modal-open-root', hasOpenModal);
        };

        document.addEventListener('show.bs.modal', () => {
            document.documentElement.classList.add('modal-open-root');
        });
        document.addEventListener('hidden.bs.modal', () => {
            window.requestAnimationFrame(syncRootLock);
        });

        syncRootLock();
    };

    const initConfirmationForms = () => {
        document.addEventListener('submit', (event) => {
            const form = event.target instanceof HTMLFormElement ? event.target : null;
            if (!form || !form.hasAttribute('data-confirm')) {
                return;
            }

            const message = form.getAttribute('data-confirm') || 'Pokračovat?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    };

    const initSelfVehicleForm = () => {
        const powertrain = document.getElementById('selfVehiclePowertrain');
        if (!powertrain) {
            return;
        }

        const electricFields = document.querySelectorAll('#addVehicleModal [data-electric-field]');
        const fuelFields = document.querySelectorAll('#addVehicleModal [data-fuel-field]');
        const updateFields = () => {
            const electric = ['BEV', 'PHEV'].includes(powertrain.value);
            const fuel = ['PHEV', 'HEV', 'PETROL', 'DIESEL', 'LPG', 'CNG'].includes(powertrain.value);
            electricFields.forEach((field) => {
                field.hidden = !electric;
            });
            fuelFields.forEach((field) => {
                field.hidden = !fuel;
            });
        };

        powertrain.addEventListener('change', updateFields);
        updateFields();
    };

    initPwa();
    initThemeSwitcher();
    initAutoSubmitSelects();
    initVehiclePickers();
    initMobileMoreMenu();
    initCommandPalette();
    initBootstrapEnhancements();
    initModalScrolling();
    initConfirmationForms();
    initSelfVehicleForm();
})();
