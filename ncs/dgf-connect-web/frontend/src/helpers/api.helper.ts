import { ParsedUrlQuery } from 'querystring';
import { ApiResponse } from '@/types/api';
import { ApiConfig } from '@/configs/api.config';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import CommonHelper from '@/helpers/common.helper';

type RequestOptions = {
    headers?: Record<string, string>;
    notThrowError?: boolean;
    abortSignal?: AbortSignal;
};

export type GetRequestOptions = RequestOptions & { params?: ParsedUrlQuery };

export class ApiError extends Error {
    status: number;
    message: string;
    data: Record<string, any>;

    constructor({ status, message, data }: { status: number; message: string; data: Record<string, any> }) {
        super();
        this.status = status;
        this.message = message;
        this.data = data;
    }
}

export class ApiValidationError extends ApiError {
    data: Record<string, string[]>;

    constructor({ status, message, data }: { status: number; message: string; data: Record<string, string[]> }) {
        super({ status, message, data });
        this.data = data;
    }
}

const getUrl = (uri: string) => {
    return ApiConfig.BASE_URL + '/' + uri;
};

const prepareHeaders = (headers: Record<string, string> = {}) => {
    const token = localStorage.getItem('access_token');
    if (token) {
        headers['Authorization'] = `Bearer ${token}`;
    }
    headers['Accept'] = 'application/json';
    headers['Content-Type'] = 'application/json';
    return headers;
};

const getOrDelete = async <T>(
    uri: string,
    method: 'GET' | 'DELETE',
    options: GetRequestOptions = {},
): Promise<ApiResponse<T>> => {
    let url = getUrl(uri);
    if (options.params) {
        const queryString = CommonHelper.urlQueryToString(options.params);
        url += '?' + queryString;
    }
    const response = await fetch(url, {
        method,
        headers: prepareHeaders(options.headers),
        signal: options.abortSignal,
    });

    const body = await response.json();
    if (!response.ok && !options.notThrowError) {
        throwApiError(response, body);
    }

    return body;
};

const requestWithData = async <T>(
    uri: string,
    method: 'POST' | 'PUT' | 'PATCH',
    data: Record<string, any> | null,
    options: RequestOptions = {},
): Promise<ApiResponse<T>> => {
    const response = await fetch(getUrl(uri), {
        method,
        headers: prepareHeaders(options.headers),
        body: JSON.stringify(data),
        signal: options.abortSignal,
    });

    const body = await response.json();
    if (!response.ok && !options.notThrowError) {
        throwApiError(response, body);
    }

    return body;
};

const throwApiError = (response: Response, body: any) => {
    if (body.error === ErrorCodeEnum.E_UNPROCESSABLE_ENTITY.value) {
        throw new ApiValidationError({ status: response.status, message: body.error, data: body.data });
    } else {
        throw new ApiError({ status: response.status, message: body.error, data: body.data });
    }
};

const ApiHelper = {
    get: async <T>(uri: string, options: GetRequestOptions = {}): Promise<ApiResponse<T>> => {
        return getOrDelete(uri, 'GET', options);
    },
    post: async <T>(
        uri: string,
        data: Record<string, any> | null,
        options: RequestOptions = {},
    ): Promise<ApiResponse<T>> => {
        return requestWithData(uri, 'POST', data, options);
    },
    postForm: async <T>(uri: string, data: FormData, options: RequestOptions = {}): Promise<ApiResponse<T>> => {
        const headers = prepareHeaders(options.headers);
        delete headers['Content-Type'];
        const response = await fetch(getUrl(uri), {
            method: 'POST',
            headers: headers,
            body: data,
            signal: options.abortSignal,
        });

        const body = await response.json();
        if (!response.ok && !options.notThrowError) {
            throwApiError(response, body);
        }

        return body;
    },
    downloadBlob: (blob: Blob, filename: string) => {
        const objectUrl = URL.createObjectURL(blob);
        const link: HTMLAnchorElement = document.createElement('a');
        link.href = objectUrl;
        link.download = filename;
        link.click();
        link.remove();
        URL.revokeObjectURL(objectUrl);
    },
    downloadBinary: async (uri: string, options: GetRequestOptions = {}): Promise<Blob> => {
        const headers = prepareHeaders(options.headers);
        delete headers['Accept'];
        delete headers['Content-Type'];
        let url = getUrl(uri);
        if (options.params) {
            const queryString = CommonHelper.urlQueryToString(options.params);
            url += '?' + queryString;
        }
        const response = await fetch(url, {
            method: 'GET',
            headers,
            signal: options.abortSignal,
        });

        if (!response.ok && !options.notThrowError) {
            throw new Error(response.body?.toString());
        }

        return response.blob();
    },
    put: async <T>(uri: string, data: Record<string, any>, options: RequestOptions = {}): Promise<ApiResponse<T>> => {
        return requestWithData(uri, 'PUT', data, options);
    },
    patch: async <T>(uri: string, data: Record<string, any>, options: RequestOptions = {}): Promise<ApiResponse<T>> => {
        return requestWithData(uri, 'PATCH', data, options);
    },
    delete: async <T>(uri: string, options: GetRequestOptions = {}): Promise<ApiResponse<T>> => {
        return getOrDelete(uri, 'DELETE', options);
    },
};

export default ApiHelper;
