import React, { useEffect, useRef, useState } from 'react';
import type * as PdfjsTypes from 'pdfjs-dist';
import { Box, Center, Loader, Text } from '@mantine/core';
import i18n from '@/locales/i18n';

type PdfjsLib = typeof PdfjsTypes;

let pdfjsLibCache: PdfjsLib | null = null;
const t = i18n.t.bind(i18n);

const loadPdfjsLib = async (): Promise<PdfjsLib> => {
    if (pdfjsLibCache) return pdfjsLibCache;
    // webpackIgnore tells webpack to leave this as a runtime import, bypassing bundling
    // @ts-ignore: runtime import from public directory
    const lib = (await import(/* webpackIgnore: true */ '/pdf.min.js')) as PdfjsLib;
    lib.GlobalWorkerOptions.workerSrc = '/pdf.worker.min.js';
    pdfjsLibCache = lib;
    return lib;
};

type PdfRendererProps = {
    fileUrl: string;
    currentPage: number;
    zoom: number;
    onPageDimensions?: (width: number, height: number) => void;
};

const fetchPdfData = async (url: string): Promise<ArrayBuffer> => {
    const token = localStorage.getItem('access_token');
    const headers: Record<string, string> = {};
    if (token) {
        headers.Authorization = `Bearer ${token}`;
    }

    const response = await fetch(url, { headers });
    if (!response.ok) {
        throw new Error(`Failed to fetch PDF: ${response.status}`);
    }
    return response.arrayBuffer();
};

const PdfRenderer = ({ fileUrl, currentPage, zoom, onPageDimensions }: PdfRendererProps) => {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const pdfDocRef = useRef<PdfjsTypes.PDFDocumentProxy | null>(null);
    const renderTaskRef = useRef<PdfjsTypes.RenderTask | null>(null);
    const [docLoaded, setDocLoaded] = useState(false);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [canvasSize, setCanvasSize] = useState({ width: 0, height: 0 });

    // Load PDF document when fileUrl changes
    useEffect(() => {
        if (!fileUrl) return;

        let cancelled = false;

        setIsLoading(true);
        setError(null);
        setDocLoaded(false);

        const load = async () => {
            try {
                if (pdfDocRef.current) {
                    pdfDocRef.current.destroy();
                    pdfDocRef.current = null;
                }

                const [pdfjsLib, data] = await Promise.all([loadPdfjsLib(), fetchPdfData(fileUrl)]);
                if (cancelled) return;

                const doc = await pdfjsLib.getDocument({ data }).promise;
                if (cancelled) {
                    doc.destroy();
                    return;
                }

                pdfDocRef.current = doc;
                setDocLoaded(true);
            } catch (err) {
                if (!cancelled) {
                    console.error('PDF load error:', err);
                    setError(t('Failed to load PDF document.'));
                    setIsLoading(false);
                }
            }
        };

        load();

        return () => {
            cancelled = true;
            if (renderTaskRef.current) {
                renderTaskRef.current.cancel();
            }
        };
    }, [fileUrl]);

    // Render page when doc is loaded or page/zoom changes
    useEffect(() => {
        const pdfDoc = pdfDocRef.current;
        const canvas = canvasRef.current;
        if (!docLoaded || !pdfDoc || !canvas) return;
        if (currentPage < 1 || currentPage > pdfDoc.numPages) return;

        let cancelled = false;

        if (renderTaskRef.current) {
            renderTaskRef.current.cancel();
            renderTaskRef.current = null;
        }

        const render = async () => {
            try {
                const page = await pdfDoc.getPage(currentPage);
                if (cancelled) return;

                const viewport = page.getViewport({ scale: zoom });
                const width = Math.floor(viewport.width);
                const height = Math.floor(viewport.height);

                canvas.width = width;
                canvas.height = height;
                setCanvasSize({ width, height });

                onPageDimensions?.(viewport.width / zoom, viewport.height / zoom);

                const renderTask = page.render({ canvas, viewport });
                renderTaskRef.current = renderTask;
                await renderTask.promise;
                renderTaskRef.current = null;

                if (!cancelled) {
                    setIsLoading(false);
                }
            } catch (err: any) {
                if (!cancelled && err?.name !== 'RenderingCancelledException') {
                    console.error('PDF render error:', err);
                    setError(t('Failed to render PDF page.'));
                    setIsLoading(false);
                }
            }
        };

        render();

        return () => {
            cancelled = true;
        };
    }, [docLoaded, currentPage, zoom, onPageDimensions]);

    if (error) {
        return (
            <Center h={400}>
                <Text c="red">{error}</Text>
            </Center>
        );
    }

    return (
        <Box pos="relative" style={{ display: 'inline-block' }}>
            {isLoading && (
                <Center
                    pos="absolute"
                    top={0}
                    left={0}
                    w={canvasSize.width || 400}
                    h={canvasSize.height || 600}
                    style={{ zIndex: 1 }}
                >
                    <Loader />
                </Center>
            )}
            <canvas
                ref={canvasRef}
                style={{
                    display: 'block',
                    width: canvasSize.width,
                    height: canvasSize.height,
                }}
            />
        </Box>
    );
};

export default PdfRenderer;
