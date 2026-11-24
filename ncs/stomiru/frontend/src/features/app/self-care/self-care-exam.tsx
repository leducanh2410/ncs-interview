import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useSuspenseQuery } from "@tanstack/react-query";
import type { ExamResultData } from "@/types/models/stress-check";
import RouteConfig from "@/configs/route.config";
import BackButton from "@/components/custom/back-button";
import { FullPageLoader } from "@/components/custom/loader";
import ArrowBackIcon from "@/components/icons/arrow-back-icon";
import ArrowForwardIcon from "@/components/icons/arrow-forward-icon";
import { Button } from "@/components/ui/button";
import { QuestionBlock } from "@/features/app/stress-check/exam/question-block";
import { SelfCareResultView } from "./self-care-result-view";
import { SelfCareStopAlertModal } from "./self-care-stop-alert-modal";
import {
  selfCareExamQueryOptions,
  useSubmitSelfCareExamMutation,
} from "./self-care.api";
import { useTranslation } from "@/hooks/use-translation";

const SelfCareExam = () => {
  const { t, tVar } = useTranslation();
  const { data: examResponse } = useSuspenseQuery(selfCareExamQueryOptions());
  const submitMutation = useSubmitSelfCareExamMutation();

  const examData = examResponse.data;
  const questionCategories = examData?.question_categories ?? [];
  const questions = examData?.questions ?? [];

  const [answers, setAnswers] = useState<Record<number, number>>({});
  const [currentCategoryIndex, setCurrentCategoryIndex] = useState(0);
  const [showStopAlert, setShowStopAlert] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [resultData, setResultData] = useState<ExamResultData | null>(null);

  const firstUnansweredRef = useRef<HTMLDivElement>(null);

  const currentCategory = questionCategories[currentCategoryIndex] || null;
  const currentQuestions = useMemo(() => {
    if (!currentCategory) return [];
    return questions.filter(
      (question) => question.question_category_id === currentCategory.id
    );
  }, [currentCategory, questions]);

  // Auto-scroll to first unanswered question in the current category
  useEffect(() => {
    if (firstUnansweredRef.current) {
      setTimeout(() => {
        firstUnansweredRef.current?.scrollIntoView({
          behavior: "smooth",
          block: "center",
        });
      }, 100);
    }
  }, [currentCategoryIndex]);

  const prevCategory =
    currentCategoryIndex > 0
      ? questionCategories[currentCategoryIndex - 1]
      : null;
  const nextCategory =
    currentCategoryIndex < questionCategories.length - 1
      ? questionCategories[currentCategoryIndex + 1]
      : null;
  const isLastCategory = currentCategoryIndex === questionCategories.length - 1;
  const isFirstCategory = currentCategoryIndex === 0;

  const handleSelectOption = useCallback(
    (questionId: number, optionId: number) => {
      setAnswers((prev) => ({
        ...prev,
        [questionId]: optionId,
      }));
    },
    []
  );

  const answeredCount = Object.keys(answers).length;
  const totalQuestions = questions?.length || 0;

  const buildAnswersPayload = useCallback(
    (answersData: Record<number, number>) =>
      Object.entries(answersData).map(([questionId, optionId]) => ({
        question_id: Number(questionId),
        option_id: optionId,
      })),
    []
  );

  // Submit answers and get result inline
  const submitAnswers = async () => {
    const allAnswered = answeredCount >= totalQuestions && totalQuestions > 0;
    if (!allAnswered) return;

    try {
      setIsSubmitting(true);
      const response = await submitMutation.mutateAsync({
        answers: buildAnswersPayload(answers),
        is_submitted: true,
      });

      if (response.data) {
        setResultData(response.data);
        window.scrollTo({ top: 0, behavior: "smooth" });
      }
    } catch (err) {
      console.error("Failed to submit self-care exam:", err);
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleBack = () => {
    if (answeredCount > 0) {
      setShowStopAlert(true);
    } else {
      window.location.assign(RouteConfig.APP_STRESS_CHECK.path);
    }
  };

  const goToPrevCategory = () => {
    if (isFirstCategory) {
      handleBack();
    } else {
      setCurrentCategoryIndex((prev) => prev - 1);
      window.scrollTo({ top: 0, behavior: "smooth" });
    }
  };

  const goToNextCategory = () => {
    if (isLastCategory) {
      submitAnswers();
    } else {
      setCurrentCategoryIndex((prev) => prev + 1);
      window.scrollTo({ top: 0, behavior: "smooth" });
    }
  };

  const getPrevButtonText = () => {
    if (isFirstCategory) {
      return t("stressCheckPage-staff.returnHome");
    }
    return prevCategory ? prevCategory.topic : "";
  };

  const getNextButtonText = () => {
    if (isLastCategory) {
      return t("stressCheckPage-staff.seeResult");
    }
    return nextCategory ? nextCategory.topic : "";
  };

  // If we have results, show the result view
  if (resultData) {
    return (
      <SelfCareResultView
        resultData={resultData}
        onBack={() => window.location.assign(RouteConfig.APP_STRESS_CHECK.path)}
      />
    );
  }

  if (isSubmitting) {
    return <FullPageLoader />;
  }

  return (
    <>
      <div className="mt-4 max-w-[992px] px-4 w-full mx-auto flex flex-col gap-4 pb-25">
        <div className="py-10 pb-2">
          <BackButton
            onClick={handleBack}
            label={t("stressCheckPage-staff.returnHome")}
          />
        </div>
        <div className="px-6 flex flex-col gap-6">
          <div className="pt-10 pb-8 flex flex-col gap-4">
            <div className="font-bold text-[16px] leading-[19px] text-primary">
              {currentCategory?.topic}
            </div>
            <div className="font-bold text-[24px] leading-[34px] whitespace-pre-wrap">
              {currentCategory?.title}
            </div>
          </div>
          <div className="px-4 flex flex-col gap-6">
            {currentQuestions.map((question, index) => {
              const isUnanswered = !answers[question.id];
              const isFirstUnanswered =
                isUnanswered &&
                !currentQuestions.slice(0, index).some((q) => !answers[q.id]);

              return (
                <QuestionBlock
                  key={`q_${question.id}`}
                  ref={isFirstUnanswered ? firstUnansweredRef : null}
                  question={question}
                  no={index + 1}
                  selectedOptionId={answers[question.id] || null}
                  onSelect={(optionId) =>
                    handleSelectOption(question.id, optionId)
                  }
                />
              );
            })}
          </div>
        </div>
      </div>

      <div className="fixed bottom-0 left-0 right-0 bg-white rounded-t-[24px] shadow-[0px_-1px_8px_0px_rgba(0,0,0,0.25)] px-10 py-6 flex items-center justify-between z-50">
        <Button
          type="button"
          variant="outline"
          className="border-2 border-primary"
          onClick={goToPrevCategory}
        >
          <ArrowBackIcon data-icon="inline-start" svgClassName="size-[18px]" />
          <span>{getPrevButtonText()}</span>
        </Button>

        <div className="flex items-center gap-[2px] font-bold text-[18px] leading-[22px] absolute left-1/2 -translate-x-1/2">
          <span className="text-primary flex items-center">
            <span>
              {tVar(
                "stressCheckPage-staff.answeredQuestions",
                { name: `${answeredCount}` },
                {
                  name: (value) => (
                    <span className="text-[24px] leading-[29px]">{value}</span>
                  ),
                }
              )}
            </span>
          </span>
          <span className="text-[24px] leading-[29px] w-5 text-center">/</span>
          <span className="text-black flex items-center">
            <span>
              {tVar(
                "stressCheckPage-staff.totalQuestions",
                { name: `${totalQuestions}` },
                {
                  name: (value) => (
                    <span className="text-[24px] leading-[29px]">{value}</span>
                  ),
                }
              )}
            </span>
          </span>
        </div>

        <Button
          type="button"
          onClick={goToNextCategory}
          disabled={isSubmitting}
        >
          <span>{getNextButtonText()}</span>
          <ArrowForwardIcon data-icon="inline-end" svgClassName="size-[18px]" />
        </Button>
      </div>

      <SelfCareStopAlertModal
        open={showStopAlert}
        setOpen={setShowStopAlert}
        onExit={() => window.location.assign(RouteConfig.APP_STRESS_CHECK.path)}
      />
    </>
  );
};

export default SelfCareExam;
