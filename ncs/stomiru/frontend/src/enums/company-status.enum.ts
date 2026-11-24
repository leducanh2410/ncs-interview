const CompanyStatusEnum = Object.freeze({
  Pending: { value: "pending", label: "申請中" },
  Active: { value: "active", label: "契約中" },
  Cancelled: { value: "cancelled", label: "解約" },
});

export default CompanyStatusEnum;
