/** @type {import('tailwindcss').Config} */
const plugin = require('tailwindcss/plugin')
module.exports = {
  content: [
    './src/views/**/*.phtml',
  ],
  prefix: 'cpgt-',
  theme: {
    extend: {
      colors: {
        'primary': '#06429E',
        'main-border-color': '#DCDCDC',
        'primary-lighter': '#1481B3',
        'main-text': '#5B748A'
      }
    },
  },
  corePlugins: {
    preflight: false,
  },
  plugins: [],
}
