const StressCheckStatusEnum = Object.freeze({
  NotStarted: {
    value: "not_started",
    label: "stressCheckList-staff.notStarted",
  },
  InProgress: {
    value: "in_progress",
    label: "stressCheckList-staff.inProgress",
  },
  Finished: { value: "finished", label: "stressCheckList-staff.ended" },
});

export default StressCheckStatusEnum;
