import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    // 'selector' (not the default 'media'): SRS Sec. 20.18 requires the
    // theme to be a saved per-user choice, not just whatever the OS
    // reports — that only works if dark: utilities key off a class on
    // <html> that the app controls, not purely prefers-color-scheme.
    darkMode: 'selector',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms],
};
