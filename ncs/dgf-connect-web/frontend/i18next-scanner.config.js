module.exports = {
    input: ['src/**/*.{ts,tsx}'],
    options: {
        debug: true,
        sort: true,
        func: {
            list: ['t'],
            extensions: ['.ts', '.tsx'],
        },
        trans: false,
        lngs: ['vi', 'ja', 'en'],
        ns: ['translation', 'enum', 'error', 'validation', 'route', 'field', 'role', 'support_type'],
        defaultLng: 'vi',
        defaultNs: 'translation',
        defaultValue: (lng, ns, key) => key,
        resource: {
            loadPath: 'src/locales/{{lng}}/{{ns}}.json',
            savePath: 'src/locales/{{lng}}/{{ns}}.json',
            jsonIndent: 4,
        },
        nsSeparator: '|',
        keySeparator: '#',
    },
};
