const UserStatusEnum = Object.freeze({
  Active: { value: "active", label: "認証済" },
  Pending: { value: "pending", label: "未認証" },
  PendingDeletion: { value: "pending_deletion", label: "削除申請中" },
});

export default UserStatusEnum;
