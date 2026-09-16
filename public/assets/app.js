'use strict';

/** Shared EV Stats browser interactions. */
(() => {
    const initPwa = () => {
        if (!('serviceWorker' in navigator)) {
            return;
        }

        window.addEventListener('load', () => {
            navigator.serviceWorker.register('service-worker.js').catch(() => {
                // PWA support is optional; the web application remains usable without it.
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
    initVehiclePickers();
    initMobileMoreMenu();
    initConfirmationForms();
    initSelfVehicleForm();
})();
