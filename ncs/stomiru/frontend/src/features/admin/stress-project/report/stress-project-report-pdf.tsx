import {
  Document,
  Font,
  Page,
  Image as PdfImage,
  StyleSheet,
  Text,
  View,
} from "@react-pdf/renderer";
import type { StressReportData } from "@/types/models/stress-report";
import DateTimeHelper from "@/helpers/date-time.helper";

Font.register({
  family: "NotoSansJP",
  fonts: [
    {
      src: "https://fonts.gstatic.com/s/notosansjp/v56/-F6jfjtqLzI2JPCgQBnw7HFyzSD-AsregP8VFBEj75s.ttf",
    },
    {
      src: "https://fonts.gstatic.com/s/notosansjp/v56/-F6jfjtqLzI2JPCgQBnw7HFyzSD-AsregP8VFPYk75s.ttf",
      fontWeight: 700,
    },
    {
      src: "https://fonts.gstatic.com/s/notosansjp/v56/-F6jfjtqLzI2JPCgQBnw7HFyzSD-AsregP8VFLgk75s.ttf",
      fontWeight: 900,
    },
  ],
});

const styles = StyleSheet.create({
  page: {
    flexDirection: "column",
    backgroundColor: "#ffffff",
    padding: 40,
    fontFamily: "NotoSansJP",
  },
  header: {
    flexDirection: "column",
    marginBottom: 20,
  },
  headerTop: {
    flexDirection: "row",
    alignItems: "flex-end",
    marginBottom: 10,
  },
  headerYear: {
    fontSize: 24,
    fontWeight: "bold",
    color: "#000000",
    marginRight: 4,
  },
  headerYearLabel: {
    fontSize: 18,
    fontWeight: "bold",
    color: "#000000",
  },
  headerDates: {
    fontSize: 16,
    fontWeight: "bold",
    color: "#000000",
    marginBottom: 10,
  },
  headerTitle: {
    fontSize: 14,
    color: "#000000",
  },
  imageSection: {
    width: "100%",
    marginBottom: 20,
  },
  chartImage: {
    width: "100%",
    objectFit: "contain",
  },
  descriptionBox: {
    paddingTop: 10,
    paddingBottom: 10,
    marginBottom: 20,
  },
  descriptionText: {
    fontSize: 12,
    lineHeight: 1.5,
    marginBottom: 10,
  },
  sectionTitleBox: {
    flexDirection: "row",
    alignItems: "center",
    marginBottom: 15,
  },
  sectionTitleIndicator: {
    width: 4,
    height: 18,
    backgroundColor: "#15ca42",
    borderRadius: 2,
    marginRight: 8,
  },
  sectionTitleText: {
    fontSize: 14,
    fontWeight: "bold",
    color: "#000000",
  },
  commentsBox: {
    marginBottom: 20,
  },
  commentText: {
    fontSize: 12,
    lineHeight: 1.5,
    marginBottom: 10,
  },
  footerNote: {
    fontSize: 10,
    lineHeight: 1.5,
    marginTop: 20,
    color: "#666",
  },
  memberTable: {
    width: "100%",
    marginBottom: 20,
  },
  memberTableHeaderRow: {
    flexDirection: "row",
    backgroundColor: "#F8F8FC",
    borderTopWidth: 1,
    borderTopColor: "#7C838C",
    paddingVertical: 10,
    paddingHorizontal: 16,
    alignItems: "center",
  },
  memberTableRow: {
    flexDirection: "row",
    backgroundColor: "#ffffff",
    borderBottomWidth: 1,
    borderBottomColor: "#7C838C",
    paddingVertical: 12,
    paddingHorizontal: 16,
    alignItems: "center",
  },
  memberColPeriod: {
    flex: 2,
  },
  memberColNum: {
    flex: 1,
    alignItems: "center",
  },
  memberCellHeader: {
    fontSize: 11,
    fontWeight: "bold",
    color: "#000000",
  },
  memberCellText: {
    fontSize: 12,
    color: "#000000",
  },
});

export interface StressProjectChartImages {
  summary?: string;
  analysisCards?: string;
  heatmap?: string;
  detailedTables?: string;
}

export interface StressProjectReportPDFData {
  title: string;
  report: StressReportData;
  reportComments: Record<string, string>;
}

const SECTION_ORDER = [
  "overview_health_risk",
  "matrix_analysis_workload",
  "matrix_analysis_support",
  "detailed_factors",
  "recommendation_action",
] as const;

export const StressProjectReportPDF = ({
  data,
  chartImages,
}: {
  data: StressProjectReportPDFData;
  chartImages: StressProjectChartImages;
}) => {
  const { stress_check_period } = data.report;

  const formattedDates = `${
    stress_check_period?.start_date
      ? DateTimeHelper.format(
          stress_check_period.start_date,
          "yyyy年 MM月 dd日"
        )
      : ""
  } ～ ${
    stress_check_period?.end_date
      ? DateTimeHelper.format(stress_check_period.end_date, "yyyy年 MM月 dd日")
      : ""
  }`;

  const tablePeriod =
    stress_check_period?.start_date && stress_check_period?.end_date
      ? `${DateTimeHelper.format(stress_check_period.start_date, "yyyy/MM/dd")} ～ ${DateTimeHelper.format(stress_check_period.end_date, "yyyy/MM/dd")}`
      : "--";

  const comments = SECTION_ORDER.map((key) => data.reportComments[key]).filter(
    (text) => !!text
  );

  return (
    <Document title={data.title}>
      <Page size="A4" style={styles.page} wrap>
        <View style={styles.header}>
          <View style={styles.headerTop}>
            <Text style={styles.headerYear}>
              {stress_check_period?.year ?? "--"}
            </Text>
            <Text style={styles.headerYearLabel}>年度</Text>
          </View>
          <Text style={styles.headerDates}>{formattedDates}</Text>
          <Text style={styles.headerTitle}>
            {stress_check_period?.title ?? ""}
          </Text>
        </View>

        {chartImages.summary && (
          <View style={styles.imageSection}>
            <PdfImage src={chartImages.summary} style={styles.chartImage} />
          </View>
        )}

        <View style={styles.descriptionBox}>
          <Text style={styles.descriptionText}>
            57項目のストレスチェックからは、個人のストレス度合いを示す「高ストレス者数」と、職場のストレス要因を数値化した「総合健康リスク」の2つの結果が分かります。
          </Text>
          <Text style={styles.descriptionText}>
            このチェックの本来の目的は、単にストレスが高い人を見つけることではなく、「総合健康リスク」を参考に職場環境そのものを改善していくことにあります。
          </Text>
          <Text style={[styles.descriptionText, { marginBottom: 0 }]}>
            職場環境を整え、高ストレス状態を未然に防ぐことは、将来的な疾病休業のリスク（4〜5人に1人の休業予防）を低減させることにも繋がります。まずは現状の数値を把握し、働きやすい職場づくりにお役立てください。
          </Text>
        </View>

        <View style={styles.memberTable} wrap={false}>
          <View style={styles.memberTableHeaderRow}>
            <View style={styles.memberColPeriod}>
              <Text style={styles.memberCellHeader}>実施期間</Text>
            </View>
            <View style={styles.memberColNum}>
              <Text style={styles.memberCellHeader}>対象者数</Text>
            </View>
            <View style={styles.memberColNum}>
              <Text style={styles.memberCellHeader}>受検者数</Text>
            </View>
            <View style={styles.memberColNum}>
              <Text style={styles.memberCellHeader}>未回答数</Text>
            </View>
          </View>
          <View style={styles.memberTableRow}>
            <View style={styles.memberColPeriod}>
              <Text style={styles.memberCellText}>{tablePeriod}</Text>
            </View>
            <View style={styles.memberColNum}>
              <Text style={styles.memberCellText}>
                {data.report.summary.total_employees}名
              </Text>
            </View>
            <View style={styles.memberColNum}>
              <Text style={styles.memberCellText}>
                {data.report.summary.total_respondents}名
              </Text>
            </View>
            <View style={styles.memberColNum}>
              <Text style={styles.memberCellText}>
                {data.report.summary.not_completed ??
                  data.report.summary.total_employees -
                    data.report.summary.total_respondents}
                名
              </Text>
            </View>
          </View>
        </View>

        {comments.length > 0 && (
          <View style={styles.commentsBox} wrap={false}>
            <View style={styles.sectionTitleBox}>
              <View style={styles.sectionTitleIndicator} />
              <Text style={styles.sectionTitleText}>今回の総評</Text>
            </View>
            <View>
              {comments.map((text, i) => (
                <Text key={i} style={styles.commentText}>
                  {text}
                </Text>
              ))}
            </View>
          </View>
        )}

        {chartImages.analysisCards && (
          <View style={styles.imageSection} wrap={false}>
            <PdfImage
              src={chartImages.analysisCards}
              style={styles.chartImage}
            />
          </View>
        )}

        {chartImages.heatmap && (
          <View style={styles.imageSection} wrap={false}>
            <PdfImage
              src={chartImages.heatmap}
              style={{ ...styles.chartImage, objectFit: "contain" }}
            />
          </View>
        )}

        {chartImages.detailedTables && (
          <View style={styles.imageSection} wrap={false}>
            <PdfImage
              src={chartImages.detailedTables}
              style={styles.chartImage}
            />
          </View>
        )}

        <Text style={styles.footerNote} wrap={false}>
          ※本分析は対象者をすべて男性と仮定し、全国平均との比較を行っております。そのため、女性の比率が高い集団においては、健康リスクが実際よりもやや高く算出される傾向があります。
        </Text>
      </Page>
    </Document>
  );
};
