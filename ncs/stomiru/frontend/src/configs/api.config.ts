const viteBase = import.meta.env.VITE_API_BASE_URL;

export const ApiConfig = {
  BASE: viteBase + "/api",

  AUTH_LOGIN: "auth/login",
  AUTH_CHECK: "auth/check",
  AUTH_LOGOUT: "auth/logout",
  AUTH_FORGOT_PASSWORD: "auth/forgot-password",

  AUTH_REGISTER: "submit-application",

  SETUP_PASSWORD_ADMIN: "invitation/:userId/setup-password",
  SET_FIRST_PASSWORD: "employee/invitation/:userId/setup-password",

  SUPER_ADMIN_COMPANIES: "super-admin/company",
  SUPER_ADMIN_COMPANY_APPROVE: "super-admin/company/:id/approve",
  SUPER_ADMIN_COMPANY_RESTORE: "super-admin/company/:id/restore",
  SUPER_ADMIN_DELETE_ADMIN: "super-admin/admin/:id",
  SUPER_ADMIN_DELETE_EMPLOYEE: "super-admin/employee/:id",

  SUPER_ADMIN_COMPANY_LOG: "super-admin/company/:id/log",

  // admin
  ADMIN_DASHBOARD: "admins/dashboard",
  ADMIN_STRESS_PROJECTS: "admins/stress-check",
  ADMIN_STRESS_PROJECT: "admins/stress-check",
  ADMIN_STRESS_PROJECT_REPORT: "admins/stress-check/:id/report",
  ADMIN_EMPLOYEES: "admins/employee",

  ADMIN_EXAMINEES: "admins/stress-check/:id/examinees",
  ADMIN_EXAMINEES_EXPORT: "admins/stress-check/:id/examinees/export",

  ADMIN_EXAMINEE_RESULT: "admins/stress-check/:id/examinees/:examineeId",

  ADMIN_EMPLOYEE_EXPORT: "admins/employee/export",
  ADMIN_EMPLOYEE_IMPORT: "admins/employee/import",

  ADMIN_OPERATORS: "admins",

  ADMIN_ADMINS: "admins",
  ADMIN_RESEND_INVITATION: "admins/:id/resend",
  ADMIN_REQUEST_DELETE: "admins/:id/request-delete",

  ADMIN_CONFIRM_DELETE_EMPLOYEE: "admins/employee/:id/confirm-request-delete",

  // app: staff / manager
  APP_STRESS_CHECKS: "staff/stress-check",
  APP_STRESS_CHECK_ACTIVE: "staff/stress-check/active",
  APP_STRESS_CHECK_DETAIL: "staff/stress-check/:id",
  APP_STRESS_CHECK_EXAM: "staff/stress-check/:id/exam",
  APP_STRESS_CHECK_RESULT: "staff/stress-check/:id/exam/result",

  APP_STRESS_CHECK_STAFF_REPORT: "staff/stress-check/:id/report",

  // self-care
  APP_SELF_CARE_EXAM: "staff/self-care/exam",

  STAFF_PROFILE: 'staff/profile',
  STAFF_SET_FIRST_PASSWORD: 'staff/set-first-password',
};
