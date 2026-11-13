import { Dropdown } from 'bootstrap';

/**
 * Abre un dropdown de Bootstrap al pasar el puntero por encima.
 *
 * Solo se activa en pantallas grandes con puntero real (>= 992px y hover),
 * así que en móvil y en tablets táctiles se mantiene el clic normal.
 *
 * Uso: en el disparador del dropdown
 *   <button data-bs-toggle="dropdown" data-dropdown-hover>...</button>
 */
const DESKTOP_QUERY = '(min-width: 992px) and (hover: hover)';
const CLOSE_DELAY = 220;

// Un solo MediaQueryList para todos los dropdowns: se evaluá en el momento
// de cada evento, así que al redimensionar la ventana el comportamiento
// cambia solo, sin recargar.
const mediaQuery = window.matchMedia(DESKTOP_QUERY);

function setup(wrapper, trigger) {
    if (wrapper.dataset.dropdownHoverReady === 'true') {
        return;
    }

    wrapper.dataset.dropdownHoverReady = 'true';

    let closeTimer = null;
    // Se pone en true cuando el usuario navega con el teclado dentro del
    // dropdown: en ese caso alejar el ratón no debe cerrarlo.
    let keyboardMode = false;

    const isOpen = () => trigger.getAttribute('aria-expanded') === 'true';
    const dropdown = () => Dropdown.getOrCreateInstance(trigger);

    const clearCloseTimer = () => {
        if (closeTimer !== null) {
            window.clearTimeout(closeTimer);
            closeTimer = null;
        }
    };

    const close = () => {
        clearCloseTimer();

        if (isOpen()) {
            dropdown().hide();
        }
    };

    wrapper.addEventListener('mouseenter', () => {
        if (!mediaQuery.matches) {
            return;
        }

        keyboardMode = false;
        clearCloseTimer();

        if (!isOpen()) {
            dropdown().show();
        }
    });

    wrapper.addEventListener('mouseleave', () => {
        if (!mediaQuery.matches || keyboardMode) {
            return;
        }

        clearCloseTimer();

        // Entre el botón y el menú hay un margen de 10px: se espera un poco
        // antes de cerrar para que al cruzar ese hueco no parpadee.
        closeTimer = window.setTimeout(() => {
            if (wrapper.matches(':hover') || keyboardMode) {
                return; // el puntero volvió a entrar, o el foco está en el menú
            }

            if (isOpen()) {
                dropdown().hide();
            }
        }, CLOSE_DELAY);
    });

    // Navegación por teclado: si el foco entra en el dropdown con Tab o con
    // las flechas, se queda abierto aunque el ratón esté fuera.
    wrapper.addEventListener('keydown', (event) => {
        const key = event.key || '';

        if (key === 'Tab' || key.startsWith('Arrow')) {
            keyboardMode = true;
        } else if (key === 'Escape') {
            keyboardMode = false;
            close();
        }
    });

    // Al salir del dropdown con el teclado se cierra.
    wrapper.addEventListener('focusout', (event) => {
        if (event.relatedTarget && wrapper.contains(event.relatedTarget)) {
            return;
        }

        keyboardMode = false;

        if (!wrapper.matches(':hover')) {
            close();
        }
    });

    // Estado limpio cada vez que Bootstrap cierra el menú, para que un cierre
    // por teclado no bloquee el hover en la siguiente apertura.
    wrapper.addEventListener('hidden.bs.dropdown', () => {
        keyboardMode = false;
        clearCloseTimer();
    });
}

export function initDropdownHover() {
    document.querySelectorAll('[data-dropdown-hover]').forEach((trigger) => {
        const wrapper = trigger.closest('.dropdown');

        if (wrapper) {
            setup(wrapper, trigger);
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDropdownHover);
} else {
    initDropdownHover();
}

// Con Turbo el <body> se reemplaza en cada navegación: hay que re-enganchar.
document.addEventListener('turbo:load', initDropdownHover);
