const RoleEnum = Object.freeze({
  SuperAdmin: { value: "super_admin", label: "システム管理者" },
  Admin: { value: "admin", label: "管理者" },
  Doctor: { value: "doctor", label: "医師" },
  Staff: { value: "staff", label: "従業員" },
  StaffManager: { value: "staff_manager", label: "従業員管理者" },
});

export default RoleEnum;
