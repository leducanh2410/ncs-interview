export const EmployeeStatusValues = ["active", "on_leave", "retired"] as const;
export type EmployeeStatus = (typeof EmployeeStatusValues)[number];

const EmployeeStatusEnum = Object.freeze({
  Active: { value: "active", label: "在籍中" },
  OnLeave: { value: "on_leave", label: "休職中" },
  Retired: { value: "retired", label: "退職" },
});

export default EmployeeStatusEnum;
