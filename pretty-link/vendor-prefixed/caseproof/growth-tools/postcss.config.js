module.exports = {
  plugins: {
    tailwindcss: {},
    autoprefixer: {},
    'postcss-replace': {
      pattern: /(--tw|\*, ::before, ::after)/g,
      data: {
        '--tw': '--cpgt', // Prefixing
        '*, ::before, ::after': ':root', // So variables does not pollute every element
      }
    },
    'postcss-minify': {},
  }
}
