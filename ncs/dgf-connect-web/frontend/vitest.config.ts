import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
    plugins: [react()],
    test: {
        environment: 'jsdom',
        globals: true,
        // CI runs are much slower than local; give userEvent-heavy tests headroom
        // over the 5s default so they don't flake under load.
        testTimeout: 20000,
        setupFiles: ['./src/test/setup.ts'],
        include: ['src/**/*.test.{ts,tsx}'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'text-summary', 'html', 'cobertura'],
            reportsDirectory: './coverage',
            include: ['src/**/*.{ts,tsx}'],
            exclude: [
                'src/**/*.test.{ts,tsx}',
                'src/**/*.d.ts',
                'src/test/**',
                'src/locales/**',
                // Thin glue around a runtime dynamic import of pdfjs-dist; the
                // coverage-v8 Rolldown parser can't parse its TS type-import
                // syntax and errors out trying to. Untestable, so exclude it.
                'src/pages/admin/pdf-template/partials/editor/pdf_renderer.tsx',
            ],
        },
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './src'),
        },
    },
    ssr: {
        noExternal: true,
    },
});
