const ResponseStatusEnum = Object.freeze({
  Answered: { value: "answered", label: "stressCheckList-staff.answered" },
  InProgress: {
    value: "in_progress",
    label: "stressCheckList-staff.answering",
  },
  Unanswered: {
    value: "unanswered",
    label: "stressCheckList-staff.unanswered",
  },
  Answering: { value: "answering", label: "stressCheckPage-staff.answering" },
});

export default ResponseStatusEnum;
