'use strict';

/** Shared EV Stats browser interactions. */
(() => {
    const isStandalonePwa = () => window.matchMedia('(display-mode: standalone)').matches
        || window.matchMedia('(display-mode: minimal-ui)').matches
        || window.navigator.standalone === true;

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
            navigator.serviceWorker.register('service-worker.js').catch(() => {
                // PWA support is optional; the web application remains usable without it.
            });
        });
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
    initAutoSubmitSelects();
    initVehiclePickers();
    initMobileMoreMenu();
    initConfirmationForms();
    initSelfVehicleForm();
})();
