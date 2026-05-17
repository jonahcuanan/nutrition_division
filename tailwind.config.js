/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './*.php',
    './javascript/**/*.js',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
