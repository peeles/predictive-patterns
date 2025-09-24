import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import globals from 'globals';

const vueConfig = pluginVue.configs['flat/vue3-essential'];

export default [
  {
    files: ['**/*.{js,vue}'],
    ignores: ['node_modules/**', 'dist/**']
  },
  {
    files: ['**/*.js'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: {
        ...globals.browser,
        ...globals.node
      }
    },
    rules: {
      ...js.configs.recommended.rules
    }
  },
  {
    ...vueConfig,
    files: ['**/*.vue'],
    languageOptions: {
      ...vueConfig.languageOptions,
      globals: {
        ...globals.browser
      }
    }
  }
];
