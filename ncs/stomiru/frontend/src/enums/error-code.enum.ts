export type ErrorCode =
  | "E_BAD_REQUEST"
  | "E_UNAUTHORIZED"
  | "E_FORBIDDEN"
  | "E_NOT_FOUND"
  | "E_METHOD_NOT_ALLOWED"
  | "E_UNPROCESSABLE_ENTITY"
  | "E_INTERNAL_SERVER_ERROR"
  | "AUTH_INVALID_TOKEN"
  | "AUTH_INVALID_CREDENTIALS"
  | "AUTH_USER_DISABLED";

export const ErrorCodeEnum: {
  [key in ErrorCode]: { value: string; text: string };
} = Object.freeze({
  E_BAD_REQUEST: { value: "E_BAD_REQUEST", text: "Bad Request" },
  E_UNAUTHORIZED: { value: "E_UNAUTHORIZED", text: "Unauthorized" },
  E_FORBIDDEN: { value: "E_FORBIDDEN", text: "Forbidden" },
  E_NOT_FOUND: { value: "E_NOT_FOUND", text: "Not Found" },
  E_METHOD_NOT_ALLOWED: {
    value: "E_METHOD_NOT_ALLOWED",
    text: "Method Not Allowed",
  },
  E_UNPROCESSABLE_ENTITY: {
    value: "E_UNPROCESSABLE_ENTITY",
    text: "Unprocessable Entity",
  },
  E_INTERNAL_SERVER_ERROR: {
    value: "E_INTERNAL_SERVER_ERROR",
    text: "Internal Server Error",
  },
  AUTH_INVALID_TOKEN: { value: "AUTH_INVALID_TOKEN", text: "Invalid Token" },
  AUTH_INVALID_CREDENTIALS: {
    value: "AUTH_INVALID_CREDENTIALS",
    text: "Invalid Credentials",
  },
  AUTH_USER_DISABLED: { value: "AUTH_USER_DISABLED", text: "User Disabled" },
});
