const LogCategoryEnum = Object.freeze({
  EmployeeManagement: { value: "employee_management", label: "従業員管理" },
  CompanyManagement: { value: "company_management", label: "法人管理" },
  StressCheckManagement: {
    value: "stress_check_management",
    label: "ストレスチェック",
  },
  ExamineeList: { value: "examinee_list", label: "受検者一覧" },
  ProfileEdit: { value: "profile_edit", label: "プロフィール編集" },
});

export const LogActionEnum = Object.freeze({
  Create: { value: "create", label: "作成" },
  Update: { value: "update", label: "変更" },
  Delete: { value: "delete", label: "削除" },
  CsvRegister: { value: "csv_register", label: "CSV登録" },
  NewRegister: { value: "new_register", label: "新規登録" },
  CsvUpdate: { value: "csv_update", label: "CSV変更" },
  StatusUpdate: { value: "status_update", label: "ステータス変更" },
  RoleUpdate: { value: "role_update", label: "権限変更" },
  CsvOutput: { value: "csv_output", label: "CSV出力" },
  DobUpdate: { value: "dob_update", label: "生年月日変更" },
  NameUpdate: { value: "name_update", label: "氏名変更" },
});

export default LogCategoryEnum;
