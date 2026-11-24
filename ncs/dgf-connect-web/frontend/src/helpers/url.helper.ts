const staticBase = process.env.NEXT_PUBLIC_STATIC_BASE_URL;

const UrlHelper = {
    getStaticUrl: (uri: string | null): string => {
        if (!uri) return '';
        return staticBase + '/' + uri;
    },
};

export default UrlHelper;
