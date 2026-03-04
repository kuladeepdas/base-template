export default [
    {
        files: ["src/js/**/*.js"],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: "module",
            globals: {
                window: "readonly",
                document: "readonly",
                console: "readonly",
                process: "readonly",
                module: "readonly",
                require: "readonly",
            },
        },
        rules: {
            "no-undef": "off",
            "no-unused-vars": "warn",
            indent: ["error", 4],
            "comma-dangle": ["error", "always-multiline"],
            "no-var": "error",
            "prefer-const": "error",
            quotes: ["error", "double", { avoidEscape: true }],
            semi: ["error", "always"],
        },
    },
];
