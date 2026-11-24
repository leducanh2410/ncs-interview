import type { ComponentType } from "react";
import type { StressReportData } from "@/types/models/stress-report";
import ConfusedFaceIcon from "@/components/icons/confused-face";
import GrinningFaceIcon from "@/components/icons/grinning-face-icon";
import type { SvgIconProps } from "@/components/svg-icon";

interface ReportAnalysisCardsProps {
  stressFactors: StressReportData["stress_factors"];
  support: StressReportData["support"];
}

// Icon: Popcorn (心理的な仕事の負担) - icon/popcorn
const PopcornIcon = ({ size = 24, className }: SvgIconProps) => (
  <span role="img" className={className}>
    <svg
      xmlns="http://www.w3.org/2000/svg"
      width="24"
      height="24"
      viewBox="0 0 24 24"
      fill="none"
    >
      <path
        opacity="0.8"
        d="M14.7751 1.78854C15.6557 2.04015 16.2701 2.62835 16.6336 3.4074C16.7378 3.63065 16.8154 3.85058 16.8718 4.06074L16.8874 4.12606L16.9207 4.13523C17.1248 4.19379 17.3378 4.27397 17.5544 4.38061L17.7721 4.49625C18.1672 4.72206 18.5163 5.0213 18.8004 5.40006C19.0863 5.7812 19.2504 6.21886 19.3091 6.68853C19.343 6.95977 19.3418 7.23114 19.312 7.50067L20.0004 7.50006C20.6402 7.50006 21.1154 8.09248 20.9766 8.71699L18.276 20.8673L18.187 21.242C18.0024 21.9761 17.8568 22.2336 17.4497 22.5602C17.0425 22.8867 16.7598 22.973 16.003 22.9939L16.1425 22.99C16.1341 22.9912 16.1256 22.9923 16.117 22.9933L16.0004 23.0001H8.00041C7.9525 23.0001 7.90539 22.9967 7.85928 22.9902L7.83129 22.9879C7.19681 22.9583 6.92696 22.8616 6.55114 22.5602C6.14399 22.2336 5.99837 21.9761 5.81383 21.242L5.72481 20.8673L3.02422 8.71699C2.88544 8.09248 3.36066 7.50006 4.00041 7.50006L4.83688 7.49997C4.83522 7.35209 4.84196 7.204 4.85837 7.0563C4.92323 6.47253 5.13603 5.95023 5.5433 5.54296C5.92206 5.16419 6.4035 4.93239 6.95014 4.81091C7.11813 4.77358 7.28665 4.74754 7.45421 4.73111L7.64641 4.71706L7.65095 4.70131C7.66954 4.63557 7.69014 4.569 7.71292 4.50183L7.78793 4.29867C8.03978 3.66903 8.44871 3.15786 9.05319 2.85562C9.43831 2.66306 9.86275 2.57817 10.3129 2.57817C10.7059 2.57817 11.1019 2.64165 11.4951 2.75053L11.6694 2.80406L11.6992 2.77218L11.8602 2.61282C12.6686 1.84974 13.6627 1.47069 14.7751 1.78854ZM18.7534 9.50006L15.463 9.49978L15.037 20.9998L15.3961 21.0001L16.1934 20.9941L16.1949 20.9831L16.2854 20.5981L18.7534 9.50006ZM13.463 9.49978H10.537L10.963 20.9958L13.037 20.9978L13.463 9.49978ZM8.537 9.49978L5.24641 9.50006L7.62854 20.217L7.80641 20.9941L8.962 20.9948L8.537 9.49978ZM13.233 4.06723C13.1402 4.15485 13.0605 4.24298 12.9972 4.32072L12.8933 4.45911L12.8595 4.51184C12.6121 4.92717 12.1075 5.10133 11.6629 4.94145L11.3585 4.8105C11.2999 4.78765 11.232 4.76285 11.1568 4.73779C10.855 4.63717 10.56 4.57817 10.3129 4.57817C10.1537 4.57817 10.0313 4.60266 9.94762 4.64447C9.8321 4.70223 9.72903 4.83107 9.64488 5.04144C9.55672 5.26185 9.51516 5.50006 9.50376 5.66247L9.50041 5.75006C9.50041 6.32938 9.01273 6.77871 8.44953 6.74891L8.24083 6.72529C7.96025 6.69778 7.65005 6.70417 7.384 6.76329C7.18064 6.80848 7.03708 6.8776 6.95751 6.95717C6.90645 7.00824 6.86461 7.11093 6.84614 7.27716C6.83839 7.34689 6.83574 7.42157 6.83766 7.49942L17.2871 7.4994C17.3305 7.29618 17.3453 7.10247 17.3245 6.9366C17.3071 6.79689 17.2661 6.68768 17.2004 6.60006C17.0865 6.44821 16.9458 6.32763 16.7798 6.23274C16.5215 6.08516 16.2513 6.01963 16.0783 6.00392L16.0004 6.00006C15.4876 6.00006 15.0649 5.61402 15.0071 5.11669L15.0004 5.00006C15.0004 4.84074 14.9529 4.53517 14.8213 4.25318C14.6876 3.96672 14.5042 3.79117 14.2257 3.71157C13.9006 3.61869 13.5778 3.74176 13.233 4.06723Z"
        fill="black"
      />
      <path
        d="M8.53669 9.5L5.24609 9.50028L7.62823 20.2172L7.80609 20.9943L8.96169 20.995L8.53669 9.5Z"
        fill="#91D5FF"
      />
      <path
        d="M13.4627 9.5H10.5367L10.9627 20.996L13.0367 20.998L13.4627 9.5Z"
        fill="#91D5FF"
      />
      <path
        d="M18.7531 9.50028L15.4627 9.5L15.0367 21L15.3957 21.0003L16.1931 20.9943L16.1945 20.9833L16.2851 20.5983L18.7531 9.50028Z"
        fill="#91D5FF"
      />
    </svg>
  </span>
);

// Icon: Dashboard (仕事のコントロール度) - icon/dashboard-one
const DashboardIcon = ({ size = 24, className }: SvgIconProps) => (
  <span role="img" className={className}>
    <svg
      xmlns="http://www.w3.org/2000/svg"
      width="24"
      height="24"
      viewBox="0 0 24 24"
      fill="none"
    >
      <g opacity="0.8">
        <path
          fillRule="evenodd"
          clipRule="evenodd"
          d="M11 10V12.1707C9.83481 12.5825 9 13.6938 9 15C9 16.6569 10.3431 18 12 18C13.6569 18 15 16.6569 15 15C15 13.6938 14.1652 12.5825 13 12.1707V10C13 9.44772 12.5523 9 12 9C11.4477 9 11 9.44772 11 10ZM11 15C11 14.4477 11.4477 14 12 14C12.5523 14 13 14.4477 13 15C13 15.5523 12.5523 16 12 16C11.4477 16 11 15.5523 11 15Z"
          fill="black"
        />
        <path
          d="M13 6C13 5.44772 12.5523 5 12 5C11.4477 5 11 5.44772 11 6V7C11 7.55228 11.4477 8 12 8C12.5523 8 13 7.55228 13 7V6Z"
          fill="black"
        />
        <path
          d="M3.5 14C3.5 13.4477 3.94772 13 4.5 13H5.5C6.05228 13 6.5 13.4477 6.5 14C6.5 14.5523 6.05228 15 5.5 15H4.5C3.94772 15 3.5 14.5523 3.5 14Z"
          fill="black"
        />
        <path
          d="M7.20711 8.29289C6.81658 7.90237 6.18342 7.90237 5.79289 8.29289C5.40237 8.68342 5.40237 9.31658 5.79289 9.70711L6.49999 10.4142C6.89052 10.8047 7.52368 10.8047 7.91421 10.4142C8.30473 10.0237 8.30473 9.39052 7.91421 8.99999L7.20711 8.29289Z"
          fill="black"
        />
        <path
          d="M17.5 14C17.5 13.4477 17.9477 13 18.5 13H19.5C20.0523 13 20.5 13.4477 20.5 14C20.5 14.5523 20.0523 15 19.5 15H18.5C17.9477 15 17.5 14.5523 17.5 14Z"
          fill="black"
        />
        <path
          d="M18.4142 9.70711C18.8047 9.31658 18.8047 8.68342 18.4142 8.29289C18.0237 7.90237 17.3905 7.90237 17 8.29289L16.2929 8.99999C15.9024 9.39052 15.9024 10.0237 16.2929 10.4142C16.6834 10.8047 17.3166 10.8047 17.7071 10.4142L18.4142 9.70711Z"
          fill="black"
        />
        <path
          fillRule="evenodd"
          clipRule="evenodd"
          d="M12 2.5C5.92487 2.5 1 7.42487 1 13.5C1 16.3121 1.91778 19.175 3.76488 21.1779C3.9542 21.3832 4.22074 21.5 4.5 21.5H19.5C19.7793 21.5 20.0458 21.3832 20.2351 21.1779C22.0822 19.175 23 16.3121 23 13.5C23 7.42487 18.0751 2.5 12 2.5ZM3 13.5C3 8.52943 7.02943 4.5 12 4.5C16.9706 4.5 21 8.52943 21 13.5C21 15.757 20.3068 17.9487 19.0439 19.5H4.95613C3.6932 17.9487 3 15.757 3 13.5Z"
          fill="black"
        />
      </g>
      <path
        d="M12 14C11.4477 14 11 14.4477 11 15C11 15.5523 11.4477 16 12 16C12.5523 16 13 15.5523 13 15C13 14.4477 12.5523 14 12 14Z"
        fill="#91D5FF"
      />
    </svg>
  </span>
);

// Icon: User Business (上司からのサポート) - icon/user-business
const UserBusinessIcon = ({ size = 24, className }: SvgIconProps) => (
  <span role="img" className={className}>
    <svg
      xmlns="http://www.w3.org/2000/svg"
      width="24"
      height="24"
      viewBox="0 0 24 24"
      fill="none"
    >
      <path
        opacity="0.8"
        fillRule="evenodd"
        clipRule="evenodd"
        d="M12 3C10.3431 3 9 4.34315 9 6C9 7.65685 10.3431 9 12 9C13.6569 9 15 7.65685 15 6C15 4.34315 13.6569 3 12 3ZM7 6C7 3.23858 9.23858 1 12 1C14.7614 1 17 3.23858 17 6C17 8.76142 14.7614 11 12 11C9.23858 11 7 8.76142 7 6ZM2 22C2 16.4772 6.47717 12 12 12C17.5228 12 22 16.4772 22 22C22 22.5523 21.5523 23 21 23C20.4477 23 20 22.5523 20 22C20 18.0562 17.1462 14.7789 13.391 14.1205L14.9558 19.2059C15.0531 19.5222 14.9876 19.8663 14.7809 20.1247L12.7809 22.6247C12.5911 22.8619 12.3038 23 12 23C11.6962 23 11.4089 22.8619 11.2191 22.6247L9.21913 20.1247C9.01238 19.8663 8.94689 19.5222 9.04422 19.2059L10.609 14.1205C6.85378 14.7789 4 18.0562 4 22C4 22.5523 3.55228 23 3 23C2.44772 23 2 22.5523 2 22ZM12 16.4004L11.1114 19.2884L12 20.3992L12.8886 19.2884L12 16.4004Z"
        fill="black"
      />
      <path
        d="M9 6C9 4.34315 10.3431 3 12 3C13.6569 3 15 4.34315 15 6C15 7.65685 13.6569 9 12 9C10.3431 9 9 7.65685 9 6Z"
        fill="#91D5FF"
      />
    </svg>
  </span>
);

// Icon: Turn Around (同僚からのサポート) - icon/turn-around
const TurnAroundIcon = ({ size = 24, className }: SvgIconProps) => (
  <span role="img" className={className}>
    <svg
      xmlns="http://www.w3.org/2000/svg"
      width="24"
      height="24"
      viewBox="0 0 24 24"
      fill="none"
    >
      <g opacity="0.8">
        <path
          fillRule="evenodd"
          clipRule="evenodd"
          d="M12 1C10.067 1 8.5 2.567 8.5 4.5C8.5 6.43298 10.067 8 12 8C13.933 8 15.5 6.43298 15.5 4.5C15.5 2.567 13.933 1 12 1ZM10.5 4.5C10.5 3.67158 11.1716 3 12 3C12.8284 3 13.5 3.67158 13.5 4.5C13.5 5.32841 12.8284 6 12 6C11.1716 6 10.5 5.32841 10.5 4.5Z"
          fill="black"
        />
        <path
          fillRule="evenodd"
          clipRule="evenodd"
          d="M10.4422 9C10.0335 8.99995 9.75531 8.99992 9.50998 9.02407C7.13606 9.25788 5.25788 11.136 5.02407 13.5099C4.99992 13.7553 4.99995 14.0335 5 14.4422L5 14.5C5 15.0523 5.44772 15.5 6 15.5H18C18.5523 15.5 19 15.0523 19 14.5L19 14.4422C19.0001 14.0335 19.0001 13.7553 18.9759 13.51C18.7421 11.1361 16.864 9.25789 14.4901 9.02407C14.2447 8.99992 13.9665 8.99995 13.5578 9H10.4422ZM7.04188 13.5C7.26522 12.1742 8.35096 11.1479 9.70592 11.0144H14.2941C15.649 11.1479 16.7348 12.1742 16.9581 13.5H7.04188ZM10.5 11C10.0119 11 9.84028 11.0012 9.70592 11.0144H14.2941C14.1597 11.0012 13.9881 11 13.5 11H10.5Z"
          fill="black"
        />
        <path
          d="M4.34084 12.8495C4.63978 13.3139 4.50566 13.9327 4.04128 14.2316C3.19543 14.7761 3 15.2355 3 15.4989C3 15.6876 3.0915 15.9686 3.47296 16.3293C3.85653 16.6921 4.46523 17.0648 5.30032 17.3988C6.41001 17.8427 7.83238 18.1837 9.44742 18.3616L8.79289 17.7071C8.40237 17.3166 8.40237 16.6834 8.79289 16.2929C9.18342 15.9024 9.81658 15.9024 10.2071 16.2929L12.4097 18.4955C14.9089 18.4535 17.1208 18.0304 18.6997 17.3988C19.5348 17.0648 20.1435 16.6921 20.527 16.3293C20.9085 15.9686 21 15.6876 21 15.4989C21 15.2355 20.8046 14.7761 19.9587 14.2316C19.4943 13.9326 19.3602 13.3138 19.6592 12.8495C19.9581 12.3851 20.5769 12.251 21.0413 12.5499C22.0971 13.2296 23 14.215 23 15.4989C23 16.4147 22.5319 17.1861 21.9013 17.7824C21.2728 18.3768 20.417 18.866 19.4425 19.2558C17.5837 19.9993 15.1126 20.4529 12.4186 20.4956L10.2071 22.7071C9.81658 23.0976 9.18342 23.0976 8.79289 22.7071C8.40237 22.3166 8.40237 21.6834 8.79289 21.2929L9.68989 20.3959C7.73912 20.219 5.97124 19.8213 4.55754 19.2558C3.58299 18.866 2.72723 18.3768 2.09874 17.7824C1.46814 17.1861 1 16.4147 1 15.4989C1 14.215 1.90284 13.2296 2.95872 12.5499C3.42311 12.251 4.04191 12.3851 4.34084 12.8495Z"
          fill="black"
        />
      </g>
      <path
        d="M12.0001 3C11.1717 3 10.5001 3.67158 10.5001 4.5C10.5001 5.32841 11.1717 6 12.0001 6C12.8285 6 13.5001 5.32841 13.5001 4.5C13.5001 3.67158 12.8285 3 12.0001 3Z"
        fill="#91D5FF"
      />
      <path
        d="M9.70603 11.0144C8.35107 11.1479 7.26533 12.1742 7.04199 13.5H16.9582C16.7349 12.1742 15.6492 11.1479 14.2942 11.0144H9.70603Z"
        fill="#91D5FF"
      />
    </svg>
  </span>
);

export const ReportAnalysisCards = ({
  stressFactors,
  support,
}: ReportAnalysisCardsProps) => {
  const cards: Array<{
    key: string;
    title: string;
    data: { current: number; previous: number | null; national: number };
    icon: ComponentType<SvgIconProps>;
  }> = [
    {
      key: "psychological_burden",
      title: "心理的な仕事の負担(量)",
      data: stressFactors.psychological_burden_quantity,
      icon: PopcornIcon,
    },
    {
      key: "job_control",
      title: "仕事のコントロール度",
      data: stressFactors.job_control,
      icon: DashboardIcon,
    },
    {
      key: "supervisor_support",
      title: "上司からのサポート",
      data: support.supervisor_support,
      icon: UserBusinessIcon,
    },
    {
      key: "colleague_support",
      title: "同僚からのサポート",
      data: support.colleague_support,
      icon: TurnAroundIcon,
    },
  ];

  const getEvaluation = (key: string, current: number, national: number) => {
    if (key === "psychological_burden") {
      return current <= national ? "good" : "caution";
    }
    return current >= national ? "good" : "caution";
  };

  return (
    <div className="flex flex-col gap-4 pt-6">
      <div className="flex gap-4 items-start">
        <div className="bg-primary-light h-[22px] shrink-0 w-2" />
        <h2 className="font-bold text-[18px] leading-[22px]">
          総合健康リスク内訳
        </h2>
      </div>

      <div className="flex flex-col gap-1 px-6">
        <div className="grid grid-cols-2 gap-2">
          {cards.map((card) => {
            const evaluation = getEvaluation(
              card.key,
              card.data.current,
              card.data.national
            );
            const IconComponent = card.icon;
            const EvaluationIcon =
              evaluation === "good" ? GrinningFaceIcon : ConfusedFaceIcon;

            return (
              <div
                key={card.key}
                className="bg-white border border-[#ACADB0] rounded-2xl px-4 py-6 flex flex-col gap-4"
              >
                <div className="flex items-center gap-2">
                  <IconComponent size={24} />
                  <h3 className="font-bold text-[15px] leading-[normal]">
                    {card.title}
                  </h3>
                </div>

                <div className="flex items-center justify-between px-1">
                  <div className="flex items-end gap-0.5 font-bold">
                    <span className="text-[28px] leading-[34px] tracking-[-0.56px]">
                      {card.data.current.toFixed(1)}
                    </span>
                    <span className="text-[16px] leading-[19px]">点</span>
                  </div>

                  <div className="flex flex-col gap-[7px] items-end">
                    <div className="flex gap-1 items-center">
                      <EvaluationIcon
                        size={20}
                        className={
                          evaluation === "good"
                            ? "text-[#15CA42]"
                            : "text-[#F5A623]"
                        }
                      />
                      <span className="font-bold text-[12px] leading-[14px] tracking-[0.5px]">
                        {evaluation === "good" ? "良好" : "注意"}
                      </span>
                    </div>
                    <div className="flex gap-1 items-start text-[12px] leading-[normal]">
                      <span className="font-medium text-[#434343]">
                        全国平均 :
                      </span>
                      <span className="font-bold">
                        {card.data.national.toFixed(1)}
                      </span>
                    </div>
                    <div className="flex gap-1 items-start text-[12px] leading-[normal]">
                      <span className="font-medium text-[#434343]">前回 :</span>
                      <span className="font-bold">
                        {card.data.previous
                          ? card.data.previous.toFixed(1)
                          : "--"}
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            );
          })}
        </div>

        <p className="font-normal text-[12px] leading-[22px] text-right w-full">
          ※健康リスクを除き、各数値は平均値を示しています。全国平均を下回る場合を『良好』としています。
        </p>
      </div>
    </div>
  );
};
