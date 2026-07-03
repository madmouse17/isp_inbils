import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';

export default tseslint.config(
    // Global ignores
    {
        ignores: [
            'node_modules/**',
            'public/**',
            'vendor/**',
            'storage/**',
            'bootstrap/**',
            'resources/css/**',
            '*.js',
            '*.cjs',
            '*.mjs',
            'vite.config.js',
            'postcss.config.js',
            'tailwind.config.js',
        ],
    },

    // Base JS rules
    js.configs.recommended,

    // TypeScript strict
    ...tseslint.configs.recommendedTypeChecked,
    {
        languageOptions: {
            parserOptions: {
                projectService: true,
                tsconfigRootDir: import.meta.dirname,
            },
        },
    },

    // React
    {
        files: ['resources/js/**/*.tsx', 'resources/js/**/*.ts'],
        plugins: {
            react,
            'react-hooks': reactHooks,
        },
        languageOptions: {
            globals: {
                window: 'readonly',
                document: 'readonly',
                localStorage: 'readonly',
                console: 'readonly',
                route: 'readonly',
                Ziggy: 'readonly',
            },
        },
        settings: {
            react: { version: '18' },
        },
        rules: {
            ...react.configs.recommended.rules,
            ...reactHooks.configs.recommended.rules,
            'react/react-in-jsx-scope': 'off',
            'react/prop-types': 'off',
            'react/jsx-key': 'warn',
            'react/jsx-curly-brace-presence': ['warn', { props: 'never', children: 'never' }],
            'react/self-closing-comp': 'warn',
        },
    },

    // Overrides for barrel exports and pages (allow default exports)
    {
        files: ['resources/js/Pages/**/*.tsx'],
        rules: {
            'react/display-name': 'off',
        },
    },

    // General overrides
    {
        rules: {
            '@typescript-eslint/no-explicit-any': 'warn',
            '@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
            '@typescript-eslint/consistent-type-imports': ['warn', { prefer: 'type-imports' }],
            'no-console': ['warn', { allow: ['warn', 'error'] }],
            'prefer-const': 'error',
            'no-var': 'error',
        },
    },
);
