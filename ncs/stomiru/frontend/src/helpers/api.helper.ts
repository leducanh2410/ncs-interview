import { authAtom, globalStore } from "@/global.state";
import type { ApiResponse } from "@/types/api";
import { ApiConfig } from "@/configs/api.config";
import { ErrorCodeEnum } from "@/enums/error-code.enum";
import CommonHelper from "@/helpers/common.helper";
import { AUTH_TOKEN } from "@/constants/auth-constant";

export interface RequestOptions {
  headers?: Record<string, string>;
  notThrowError?: boolean;
  abortSignal?: AbortSignal;
}

export type ParsedUrlQuery = Record<
  string,
  number | string | string[] | undefined
>;

export type GetRequestOptions = RequestOptions & { params?: ParsedUrlQuery };

export class ApiError extends Error {
  status: number;
  message: string;
  data: Record<string, unknown>;

  constructor({
    status,
    message,
    data,
  }: {
    status: number;
    message: string;
    data: Record<string, unknown>;
  }) {
    super(message);
    this.status = status;
    this.message = message;
    this.data = data;
  }
}

export class ApiValidationError extends ApiError {
  data: Record<string, string[]>;

  constructor({
    status,
    message,
    data,
  }: {
    status: number;
    message: string;
    data: Record<string, string[]>;
  }) {
    super({ status, message, data });
    this.data = data;
  }
}

const getUrl = (uri: string) => {
  return ApiConfig.BASE + "/" + uri;
};

const isPublicAuthPath = (pathname: string) => {
  return pathname.startsWith("/auth/") || pathname.startsWith("/setup-password");
};

const prepareHeaders = (headers: Record<string, string> = {}) => {
  const token = localStorage.getItem(AUTH_TOKEN);
  if (token && !headers.Authorization) {
    headers.Authorization = `Bearer ${token}`;
  }
  headers.Accept = "application/json";
  headers["Content-Type"] = "application/json";
  return headers;
};

const getOrDelete = async <T>(
  uri: string,
  method: "GET" | "DELETE",
  options: GetRequestOptions = {}
): Promise<ApiResponse<T>> => {
  let url = getUrl(uri);
  if (options.params) {
    const queryString = CommonHelper.urlQueryToString(options.params);
    url += "?" + queryString;
  }
  const response = await fetch(url, {
    method,
    headers: prepareHeaders(options.headers),
    signal: options.abortSignal,
  });

  const body = (await response.json().catch(() => {
    /* empty */
  })) as ApiResponse<T>;
  if (!response.ok && !options.notThrowError) {
    throwApiError(response, body);
  }

  return body;
};

const requestWithData = async <T>(
  uri: string,
  method: "POST" | "PUT" | "PATCH",
  data: Record<string, unknown> | null,
  options: RequestOptions = {}
): Promise<ApiResponse<T>> => {
  const response = await fetch(getUrl(uri), {
    method,
    headers: prepareHeaders(options.headers),
    body: JSON.stringify(data),
    signal: options.abortSignal,
  });

  const body = (await response.json().catch(() => {
    /* empty */
  })) as ApiResponse<T>;
  if (!response.ok && !options.notThrowError) {
    throwApiError(response, body);
  }

  return body;
};

const isValidationErrorData = (
  data: unknown
): data is Record<string, string[]> => {
  if (!data || typeof data !== "object" || Array.isArray(data)) {
    return false;
  }

  return Object.values(data).every((value) => {
    return (
      Array.isArray(value) &&
      value.every((message) => typeof message === "string")
    );
  });
};

const toErrorData = (data: unknown): Record<string, unknown> => {
  if (!data || typeof data !== "object" || Array.isArray(data)) {
    return {};
  }

  return data as Record<string, unknown>;
};

const throwApiError = (response: Response, body: ApiResponse<unknown>) => {
  const errorMessage = body.error ?? "";
  const errorData = body.data;

  if (
    response.status === 401 ||
    errorMessage === ErrorCodeEnum.AUTH_INVALID_TOKEN.value ||
    errorMessage === ErrorCodeEnum.AUTH_USER_DISABLED.value ||
    errorMessage === ErrorCodeEnum.E_UNAUTHORIZED.value
  ) {
    localStorage.removeItem(AUTH_TOKEN);
    globalStore.set(authAtom, null);

    if (!isPublicAuthPath(window.location.pathname)) {
      window.location.href = "/auth/login";
      return;
    }
  }

  if (
    errorMessage === ErrorCodeEnum.E_UNPROCESSABLE_ENTITY.value &&
    isValidationErrorData(errorData)
  ) {
    throw new ApiValidationError({
      status: response.status,
      message: errorMessage,
      data: errorData,
    });
  } else {
    throw new ApiError({
      status: response.status,
      message: errorMessage,
      data: toErrorData(errorData),
    });
  }
};

const ApiHelper = {
  get: async <T>(
    uri: string,
    options: GetRequestOptions = {}
  ): Promise<ApiResponse<T>> => {
    return getOrDelete(uri, "GET", options);
  },
  post: async <T>(
    uri: string,
    data: Record<string, unknown> | null,
    options: RequestOptions = {}
  ): Promise<ApiResponse<T>> => {
    return requestWithData(uri, "POST", data, options);
  },
  postForm: async <T>(
    uri: string,
    data: FormData,
    options: RequestOptions = {}
  ): Promise<ApiResponse<T>> => {
    const headers = prepareHeaders(options.headers);
    delete headers["Content-Type"];
    const response = await fetch(getUrl(uri), {
      method: "POST",
      headers: headers,
      body: data,
      signal: options.abortSignal,
    });

    const body = (await response.json().catch(() => {
      /* empty */
    })) as ApiResponse<T>;
    if (!response.ok && !options.notThrowError) {
      throwApiError(response, body);
    }

    return body;
  },
  put: async <T>(
    uri: string,
    data: Record<string, unknown>,
    options: RequestOptions = {}
  ): Promise<ApiResponse<T>> => {
    return requestWithData(uri, "PUT", data, options);
  },
  patch: async <T>(
    uri: string,
    data: Record<string, unknown>,
    options: RequestOptions = {}
  ): Promise<ApiResponse<T>> => {
    return requestWithData(uri, "PATCH", data, options);
  },
  delete: async <T>(
    uri: string,
    options: GetRequestOptions = {}
  ): Promise<ApiResponse<T>> => {
    return getOrDelete(uri, "DELETE", options);
  },
};

export default ApiHelper;

export function applyApiValidationErrors<T extends Record<string, unknown>>(
  error: unknown,
  form: {
    setError: (name: keyof T, error: { message?: string }) => void;
  },
  mapping?: Partial<Record<string, keyof T>>,
  messageMapping?: Partial<Record<string, string>>
) {
  if (error instanceof ApiValidationError) {
    const errors: Record<string, string[]> = error.data;
    Object.entries(errors).forEach(([key, messages]) => {
      const field = mapping?.[key] ?? key;
      const backendMessage = messages?.[0];
      const normalizedMessage = backendMessage?.trim();
      const localizedMessage =
        (normalizedMessage && messageMapping?.[normalizedMessage]) ||
        (normalizedMessage &&
          messageMapping?.[normalizedMessage.replace(/[.!?。]+$/, "")]) ||
        backendMessage;

      if (localizedMessage) {
        form.setError(field as keyof T, {
          message: localizedMessage,
        });
      }
    });
  }
}
