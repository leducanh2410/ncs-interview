export const AuthorityValues = ["staff", "staff_manager"] as const;
export type Authority = (typeof AuthorityValues)[number];

const AuthorityEnum = Object.freeze({
  Staff: { value: "staff", label: "従業員" },
  StaffManager: { value: "staff_manager", label: "マネージャー" },
});

export default AuthorityEnum;

export const AdminAuthorityValues = ["admin", "doctor"] as const;
export type AdminAuthority = (typeof AdminAuthorityValues)[number];

export const AdminAuthorityEnum = Object.freeze({
  Admin: { value: "admin", label: "管理者" },
  Doctor: { value: "doctor", label: "産業医" },
});
