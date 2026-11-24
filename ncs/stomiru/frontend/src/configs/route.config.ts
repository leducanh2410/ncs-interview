import type { AnyRoute } from "@tanstack/react-router";
import { Route as LoginRoute } from "@/routes/(auth)/auth/login";
import { Route as AdminDashboardRoute } from "@/routes/_authenticated/admin/dashboard";
import { Route as AdminEmployeeDetailRoute } from "@/routes/_authenticated/admin/employee/$employeeId.index";
import { Route as AdminEmployeeUpdateRoute } from "@/routes/_authenticated/admin/employee/$employeeId.update";
import { Route as AdminEmployeeCreateRoute } from "@/routes/_authenticated/admin/employee/create";
import { Route as AdminEmployeeRoute } from "@/routes/_authenticated/admin/employee/index";
import { Route as AdminOperatorUpdateRoute } from "@/routes/_authenticated/admin/operator/$operatorId.update";
import { Route as AdminOperatorCreateRoute } from "@/routes/_authenticated/admin/operator/create";
import { Route as AdminOperatorRoute } from "@/routes/_authenticated/admin/operator/index";
import { Route as AdminAdminRoute } from "@/routes/_authenticated/admin/operator/index";
import { Route as AdminStressProjectDetailRoute } from "@/routes/_authenticated/admin/stress-project/$stressProjectId";
import { Route as AdminStressProjectUpdateRoute } from "@/routes/_authenticated/admin/stress-project/$stressProjectId/update";
import { Route as AdminStressProjectCreateRoute } from "@/routes/_authenticated/admin/stress-project/create";
import { Route as AdminStressProjectRoute } from "@/routes/_authenticated/admin/stress-project/index";
import { Route as AdminExamineeRoute } from "@/routes/_authenticated/admin/stress-project/$stressProjectId/examinee";
import { Route as AdminExamineeResultRoute } from "@/routes/_authenticated/admin/stress-project/$stressProjectId/examinee/$examineeId.result";
import { Route as AdminStressProjectReportRoute } from "@/routes/_authenticated/admin/stress-project/$stressProjectId/report";
import { Route as AppSelfCareRoute } from "@/routes/_authenticated/app/self-care";
import { Route as AppProfileRoute } from "@/routes/_authenticated/app/profile/index";
import { Route as AppProfileUpdateRoute } from "@/routes/_authenticated/app/profile/update";
import { Route as AppStressCheckRoute } from "@/routes/_authenticated/app/stress-check";
import { Route as AppStressCheckExamRoute } from "@/routes/_authenticated/app/stress-check/$stressCheckId.exam";
import { Route as AppStressCheckResultRoute } from "@/routes/_authenticated/app/stress-check/$stressCheckId.result";
import { Route as AppStressCheckReportRoute } from "@/routes/_authenticated/app/stress-check/$stressCheckId.report";
import { Route as DashboardRoute } from "@/routes/_authenticated/index";
import { Route as CompanyDetailRoute } from "@/routes/_authenticated/super-admin/companies/$companyId";
import { Route as CompanyAdminCreateRoute } from "@/routes/_authenticated/super-admin/companies/$companyId/admin/create";
import { Route as CompanyAdminUpdateRoute } from "@/routes/_authenticated/super-admin/companies/$companyId/admin/$adminId.update";
import { Route as CompanyLogRoute } from "@/routes/_authenticated/super-admin/companies/$companyId/log/index";
import { Route as CompanyEmployeeRoute } from "@/routes/_authenticated/super-admin/companies/$companyId/employee/index";
import { Route as SuperAdminCompanyEmployeeCreateRoute } from "@/routes/_authenticated/super-admin/companies/$companyId/employee/create";
import { Route as SuperAdminCompanyEmployeeDetailRoute } from "@/routes/_authenticated/super-admin/companies/$companyId/employee/$employeeId.index";
import { Route as SuperAdminCompanyEmployeeUpdateRoute } from "@/routes/_authenticated/super-admin/companies/$companyId/employee/$employeeId.update";
import { Route as SuperAdminCompanyStressProjectDetailRoute } from "@/routes/_authenticated/super-admin/companies/$companyId/stress-project/$stressProjectId";
import { Route as SuperAdminCompanyStressProjectUpdateRoute } from "@/routes/_authenticated/super-admin/companies/$companyId/stress-project/$stressProjectId/update";
import { Route as SuperAdminCompanyStressProjectCreateRoute } from "@/routes/_authenticated/super-admin/companies/$companyId/stress-project/create";
import { Route as SuperAdminCompanyStressProjectExamineeRoute } from "@/routes/_authenticated/super-admin/companies/$companyId/stress-project/$stressProjectId/examinee";
import { Route as SuperAdminCompanyStressProjectExamineeResultRoute } from "@/routes/_authenticated/super-admin/companies/$companyId/stress-project/$stressProjectId/examinee/$examineeId.result";
import { Route as SuperAdminCompanyStressProjectReportRoute } from "@/routes/_authenticated/super-admin/companies/$companyId/stress-project/$stressProjectId/report";
import { Route as CompanyUpdateRoute } from "@/routes/_authenticated/super-admin/companies/$companyId/update";
import { Route as CompanyCreateRoute } from "@/routes/_authenticated/super-admin/companies/create";
import { Route as CompanyRoute } from "@/routes/_authenticated/super-admin/companies/index";
import { Route as ForgotPasswordRoute } from "@/routes/(auth)/auth/forgot-password";

export interface RouteInfo {
  path: string;
  name: string;
  route: Omit<AnyRoute, "to"> & { to: string };
}

export type RouteName =
  | "LOGIN"
  | "FORGOT_PASSWORD"
  | "HOME"
  | "APP_STRESS_CHECK"
  | "APP_STRESS_CHECK_EXAM"
  | "APP_STRESS_CHECK_RESULT"
  | "APP_STRESS_CHECK_REPORT"
  | "APP_SELF_CARE"
  | "APP_PROFILE"
  | "APP_PROFILE_UPDATE"
  | "SUPER_ADMIN_COMPANY"
  | "SUPER_ADMIN_COMPANY_CREATE"
  | "SUPER_ADMIN_COMPANY_DETAIL"
  | "SUPER_ADMIN_COMPANY_UPDATE"
  | "SUPER_ADMIN_COMPANY_ADMIN_CREATE"
  | "SUPER_ADMIN_COMPANY_ADMIN_UPDATE"
  | "SUPER_ADMIN_COMPANY_STRESS_PROJECT_DETAIL"
  | "SUPER_ADMIN_COMPANY_STRESS_PROJECT_CREATE"
  | "SUPER_ADMIN_COMPANY_STRESS_PROJECT_UPDATE"
  | "SUPER_ADMIN_COMPANY_STRESS_PROJECT_EXAMINEE"
  | "SUPER_ADMIN_COMPANY_STRESS_PROJECT_EXAMINEE_RESULT"
  | "SUPER_ADMIN_COMPANY_STRESS_PROJECT_REPORT"
  | "SUPER_ADMIN_COMPANY_EMPLOYEE"
  | "SUPER_ADMIN_COMPANY_EMPLOYEE_CREATE"
  | "SUPER_ADMIN_COMPANY_EMPLOYEE_DETAIL"
  | "SUPER_ADMIN_COMPANY_EMPLOYEE_UPDATE"
  | "SUPER_ADMIN_COMPANY_LOG"
  | "ADMIN_DASHBOARD"
  | "ADMIN_STRESS_PROJECT"
  | "ADMIN_STRESS_PROJECT_CREATE"
  | "ADMIN_STRESS_PROJECT_DETAIL"
  | "ADMIN_STRESS_PROJECT_UPDATE"
  | "ADMIN_STRESS_PROJECT_REPORT"
  | "ADMIN_EXAMINEE"
  | "ADMIN_EXAMINEE_RESULT"
  | "ADMIN_EMPLOYEE"
  | "ADMIN_EMPLOYEE_CREATE"
  | "ADMIN_EMPLOYEE_DETAIL"
  | "ADMIN_EMPLOYEE_UPDATE"
  | "ADMIN_OPERATOR"
  | "ADMIN_OPERATOR_CREATE"
  | "ADMIN_OPERATOR_UPDATE"
  | "ADMIN_ADMIN";

const RouteConfig: Record<RouteName, RouteInfo> = {
  LOGIN: {
    path: "/auth/login",
    name: "Login",
    route: LoginRoute,
  },
  FORGOT_PASSWORD: {
    path: "/auth/forgot-password",
    name: "Forgot Password",
    route: ForgotPasswordRoute,
  },
  HOME: {
    path: "/",
    name: "Home",
    route: DashboardRoute,
  },
  APP_STRESS_CHECK: {
    path: "/app/stress-check",
    name: "App Stress Check",
    route: AppStressCheckRoute,
  },
  APP_STRESS_CHECK_EXAM: {
    path: "/app/stress-check/:stressCheckId/exam",
    name: "App Stress Check Exam",
    route: AppStressCheckExamRoute,
  },
  APP_STRESS_CHECK_RESULT: {
    path: "/app/stress-check/:stressCheckId/result",
    name: "App Stress Check Result",
    route: AppStressCheckResultRoute,
  },
  APP_STRESS_CHECK_REPORT: {
    path: "/app/stress-check/:stressCheckId/report",
    name: "App Stress Check Report",
    route: AppStressCheckReportRoute,
  },
  APP_SELF_CARE: {
    path: "/app/self-care",
    name: "App Self Care",
    route: AppSelfCareRoute,
  },
  APP_PROFILE: {
    path: "/app/profile",
    name: "App Profile",
    route: AppProfileRoute,
  },
  APP_PROFILE_UPDATE: {
    path: "/app/profile/update",
    name: "App Profile Update",
    route: AppProfileUpdateRoute,
  },
  SUPER_ADMIN_COMPANY: {
    path: "/super-admin/companies",
    name: "Companies",
    route: CompanyRoute,
  },
  SUPER_ADMIN_COMPANY_CREATE: {
    path: "/super-admin/companies/create",
    name: "Create Company",
    route: CompanyCreateRoute,
  },
  SUPER_ADMIN_COMPANY_DETAIL: {
    path: "/super-admin/companies/:companyId",
    name: "Company Detail",
    route: CompanyDetailRoute,
  },
  SUPER_ADMIN_COMPANY_UPDATE: {
    path: "/super-admin/companies/:companyId/update",
    name: "Update Company",
    route: CompanyUpdateRoute,
  },
  SUPER_ADMIN_COMPANY_EMPLOYEE: {
    path: "/super-admin/companies/:companyId/employee",
    name: "Company Employee",
    route: CompanyEmployeeRoute,
  },
  SUPER_ADMIN_COMPANY_EMPLOYEE_CREATE: {
    path: "/super-admin/companies/:companyId/employee/create",
    name: "Company Employee Create",
    route: SuperAdminCompanyEmployeeCreateRoute,
  },
  SUPER_ADMIN_COMPANY_EMPLOYEE_DETAIL: {
    path: "/super-admin/companies/:companyId/employee/:employeeId",
    name: "Company Employee Detail",
    route: SuperAdminCompanyEmployeeDetailRoute,
  },
  SUPER_ADMIN_COMPANY_EMPLOYEE_UPDATE: {
    path: "/super-admin/companies/:companyId/employee/:employeeId/update",
    name: "Company Employee Update",
    route: SuperAdminCompanyEmployeeUpdateRoute,
  },
  SUPER_ADMIN_COMPANY_LOG: {
    path: "/super-admin/companies/:companyId/log",
    name: "Company Log",
    route: CompanyLogRoute,
  },
  SUPER_ADMIN_COMPANY_STRESS_PROJECT_CREATE: {
    path: "/super-admin/companies/:companyId/stress-project/create",
    name: "Create Stress Project",
    route: SuperAdminCompanyStressProjectCreateRoute,
  },
  SUPER_ADMIN_COMPANY_STRESS_PROJECT_DETAIL: {
    path: "/super-admin/companies/:companyId/stress-project/:stressProjectId",
    name: "Stress Project Detail",
    route: SuperAdminCompanyStressProjectDetailRoute,
  },
  SUPER_ADMIN_COMPANY_STRESS_PROJECT_UPDATE: {
    path: "/super-admin/companies/:companyId/stress-project/:stressProjectId/update",
    name: "Update Stress Project",
    route: SuperAdminCompanyStressProjectUpdateRoute,
  },
  SUPER_ADMIN_COMPANY_STRESS_PROJECT_EXAMINEE: {
    path: "/super-admin/companies/:companyId/stress-project/:stressProjectId/examinee",
    name: "Examinees",
    route: SuperAdminCompanyStressProjectExamineeRoute,
  },
  SUPER_ADMIN_COMPANY_STRESS_PROJECT_EXAMINEE_RESULT: {
    path: "/super-admin/companies/:companyId/stress-project/:stressProjectId/examinee/:examineeId/result",
    name: "Examinee Result",
    route: SuperAdminCompanyStressProjectExamineeResultRoute,
  },
  SUPER_ADMIN_COMPANY_STRESS_PROJECT_REPORT: {
    path: "/super-admin/companies/:companyId/stress-project/:stressProjectId/report",
    name: "Report",
    route: SuperAdminCompanyStressProjectReportRoute,
  },
  SUPER_ADMIN_COMPANY_ADMIN_CREATE: {
    path: "/super-admin/companies/:companyId/admin/create",
    name: "Create Admin",
    route: CompanyAdminCreateRoute,
  },
  SUPER_ADMIN_COMPANY_ADMIN_UPDATE: {
    path: "/super-admin/companies/:companyId/admin/:adminId/update",
    name: "Update Admin",
    route: CompanyAdminUpdateRoute,
  },
  ADMIN_DASHBOARD: {
    path: "/admin/dashboard",
    name: "Admin Dashboard",
    route: AdminDashboardRoute,
  },
  ADMIN_STRESS_PROJECT: {
    path: "/admin/stress-project",
    name: "Admin Stress Project",
    route: AdminStressProjectRoute,
  },
  ADMIN_STRESS_PROJECT_CREATE: {
    path: "/admin/stress-project/create",
    name: "Create Stress Project",
    route: AdminStressProjectCreateRoute,
  },
  ADMIN_STRESS_PROJECT_DETAIL: {
    path: "/admin/stress-project/:stressProjectId",
    name: "Admin Stress Project Detail",
    route: AdminStressProjectDetailRoute,
  },
  ADMIN_STRESS_PROJECT_UPDATE: {
    path: "/admin/stress-project/:stressProjectId/update",
    name: "Admin Stress Project Update",
    route: AdminStressProjectUpdateRoute,
  },
  ADMIN_STRESS_PROJECT_REPORT: {
    path: "/admin/stress-project/:stressProjectId/report",
    name: "Admin Stress Project Report",
    route: AdminStressProjectReportRoute,
  },
  ADMIN_EXAMINEE: {
    path: "/admin/stress-project/:stressProjectId/examinee",
    name: "Admin Examinees",
    route: AdminExamineeRoute,
  },
  ADMIN_EXAMINEE_RESULT: {
    path: "/admin/stress-project/:stressProjectId/examinee/:examineeId/result",
    name: "Admin Examinee Result",
    route: AdminExamineeResultRoute,
  },
  ADMIN_EMPLOYEE: {
    path: "/admin/employee",
    name: "Admin Employees",
    route: AdminEmployeeRoute,
  },
  ADMIN_EMPLOYEE_CREATE: {
    path: "/admin/employee/create",
    name: "Create Employee",
    route: AdminEmployeeCreateRoute,
  },
  ADMIN_EMPLOYEE_DETAIL: {
    path: "/admin/employee/:employeeId",
    name: "Employee Detail",
    route: AdminEmployeeDetailRoute,
  },
  ADMIN_EMPLOYEE_UPDATE: {
    path: "/admin/employee/:employeeId/update",
    name: "Update Employee",
    route: AdminEmployeeUpdateRoute,
  },
  ADMIN_ADMIN: {
    path: "/admin/admins",
    name: "Admin Admins",
    route: AdminAdminRoute,
  },
  ADMIN_OPERATOR: {
    path: "/admin/operator",
    name: "Admin Operators",
    route: AdminOperatorRoute,
  },
  ADMIN_OPERATOR_CREATE: {
    path: "/admin/operator/create",
    name: "Create Operator",
    route: AdminOperatorCreateRoute,
  },
  ADMIN_OPERATOR_UPDATE: {
    path: "/admin/operator/:operatorId/update",
    name: "Update Operator",
    route: AdminOperatorUpdateRoute,
  },
};

export default RouteConfig;
