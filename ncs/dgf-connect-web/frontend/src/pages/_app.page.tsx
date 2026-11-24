import React, { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import type { AppProps } from 'next/app';
import { Inter as GoogleFont } from 'next/font/google';
import Head from 'next/head';
import { MantineProvider } from '@mantine/core';
import { DatesProvider } from '@mantine/dates';
import { Notifications } from '@mantine/notifications';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ReactQueryDevtools } from '@tanstack/react-query-devtools';
import { Provider } from 'jotai';
import '@/locales/i18n';
import Layout from '@/layout/Layout';
import theme from '@/configs/theme.config';
import DateHelper, { resolveDateLocale } from '@/helpers/date.helper';
import '@mantine/carousel/styles.css';
import '@mantine/charts/styles.css';
import '@mantine/core/styles.css';
import '@mantine/dates/styles.css';
import '@mantine/dropzone/styles.css';
import '@mantine/notifications/styles.css';
import '@mantine/tiptap/styles.css';
import 'mantine-datatable/styles.layer.css';
import '@/pages/global.css';

const font = GoogleFont({ subsets: ['vietnamese'] });

DateHelper.initDayjs();

const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: 300000 } } });

const LocalizedDatesProvider = ({ children }: { children: React.ReactNode }) => {
    const { i18n: instance } = useTranslation();
    const locale = resolveDateLocale(instance.resolvedLanguage ?? instance.language);

    useEffect(() => {
        DateHelper.initDayjs(locale);
    }, [locale]);

    return <DatesProvider settings={{ locale }}>{children}</DatesProvider>;
};

export default function App({ Component, pageProps }: AppProps) {
    return (
        <>
            <Head>
                <title>DGF Connect</title>
                <meta name="description" content="DGF Connect" />
                <meta name="viewport" content="width=device-width, initial-scale=1" />
                <link rel="icon" href="/logo.png" />
            </Head>
            <main className={font.className}>
                <MantineProvider theme={theme} classNamesPrefix="dgf">
                    <Notifications position="top-right" limit={5} autoClose={5000} pauseResetOnHover="notification" />
                    <LocalizedDatesProvider>
                        <QueryClientProvider client={queryClient}>
                            <Provider>
                                <Layout>
                                    <Component {...pageProps} />
                                </Layout>
                            </Provider>

                            <ReactQueryDevtools initialIsOpen={false} />
                        </QueryClientProvider>
                    </LocalizedDatesProvider>
                </MantineProvider>
            </main>
        </>
    );
}
