import {
  Document,
  Font,
  Page,
  Image as PdfImage,
  StyleSheet,
  Text,
  View,
} from "@react-pdf/renderer";
import { t, tVar } from "@/locales/i18n";
import type { ExamResultComment, ScaleItem } from "./components";

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
    fontSize: 18,
    fontWeight: "bold",
    marginBottom: 20,
    textAlign: "center",
  },
  introText: {
    fontSize: 12,
    marginBottom: 20,
    lineHeight: 1.5,
    fontWeight: 900,
  },
  section: {
    marginBottom: 20,
  },
  sectionTitle: {
    fontSize: 14,
    fontWeight: "bold",
    marginBottom: 10,
    color: "#15ca42",
    borderBottom: "1px solid #e5e5e5",
    paddingBottom: 5,
  },
  dangerSectionTitle: {
    fontSize: 14,
    fontWeight: "bold",
    marginBottom: 10,
    color: "#f44336",
    borderBottom: "1px solid #e5e5e5",
    paddingBottom: 5,
  },
  scoreRow: {
    flexDirection: "row",
    justifyContent: "space-between",
    marginBottom: 10,
    padding: 10,
    backgroundColor: "#f9fafb",
    borderRadius: 4,
  },
  scoreLabel: {
    fontSize: 12,
    fontWeight: "bold",
  },
  scoreValue: {
    fontSize: 14,
    fontWeight: "bold",
    color: "#000000",
  },
  table: {
    display: "flex",
    width: "auto",
    borderStyle: "solid",
    borderWidth: 1,
    borderRightWidth: 0,
    borderBottomWidth: 0,
    borderColor: "#e5e5e5",
    marginBottom: 10,
  },
  tableRow: {
    margin: "auto",
    flexDirection: "row",
  },
  tableColHeader: {
    width: "33.33%",
    borderStyle: "solid",
    borderWidth: 1,
    borderLeftWidth: 0,
    borderTopWidth: 0,
    borderColor: "#e5e5e5",
    backgroundColor: "#f9fafb",
  },
  tableCol: {
    width: "33.33%",
    borderStyle: "solid",
    borderWidth: 1,
    borderLeftWidth: 0,
    borderTopWidth: 0,
    borderColor: "#e5e5e5",
  },
  tableCellHeader: {
    margin: 5,
    fontSize: 10,
    fontWeight: "bold",
  },
  tableCell: {
    margin: 5,
    fontSize: 10,
  },
  commentText: {
    fontSize: 10,
    lineHeight: 1.5,
    marginBottom: 5,
    color: "#333",
  },
  footer: {
    position: "absolute",
    bottom: 30,
    left: 40,
    right: 40,
    fontSize: 8,
    color: "#666",
    textAlign: "center",
    lineHeight: 1.4,
  },
  chartImage: {
    width: "100%",
    maxHeight: 220,
    objectFit: "contain" as const,
    marginBottom: 10,
  },
});

interface PdfScaleItem {
  scaleId: number;
  label: string;
  score: number;
  previousScore?: number;
}

export interface StressCheckResultPDFData {
  totalScore: number;
  maxTotal: number;
  stressLevelLabel: string;
  isHighStress: boolean;
  introText: string;
  yellowBoxContent: string;
  stressDescriptionText: string;
  catADescription: string;
  catBDescription: string;
  catCDescription: string;
  category_a_score: number;
  category_b_score: number;
  category_c_score: number;
  maxA: number;
  maxB: number;
  maxC: number;
  catAItems: ScaleItem[];
  catBItems: ScaleItem[];
  catCItems: ScaleItem[];
  catAComments: ExamResultComment[];
  catBComments: ExamResultComment[];
  catCComments: ExamResultComment[];
}

export interface ChartImages {
  stressGauge?: string;
  radarChartA?: string;
  radarChartB?: string;
  radarChartC?: string;
}

const CategoryTable = ({
  title,
  description,
  items,
  comments,
  totalScore,
  maxScore,
  isDanger,
  chartImage,
}: {
  title: string;
  description?: string;
  items: PdfScaleItem[];
  comments: ExamResultComment[];
  totalScore: number;
  maxScore: number;
  isDanger: boolean;
  chartImage?: string;
}) => (
  <View style={styles.section}>
    <Text style={isDanger ? styles.dangerSectionTitle : styles.sectionTitle}>
      {title}
    </Text>
    {description && (
      <Text style={{ fontSize: 10, marginBottom: 5, lineHeight: 1.5 }}>
        {description}
      </Text>
    )}
    <Text style={{ fontSize: 10, marginBottom: 5 }}>
      {tVar("stressCheckResultPage-staff.sectionTotalScore", {
        score: String(totalScore),
        maxScore: String(maxScore),
      })}
    </Text>
    <View
      wrap={false}
      style={{
        flexDirection: "row",
        alignItems: "flex-start",
        marginBottom: 10,
      }}
    >
      {chartImage && (
        <View style={{ width: "55%", alignItems: "flex-start" }}>
          <PdfImage
            src={chartImage}
            style={{
              width: 280,
              height: 280,
              objectFit: "contain" as const,
            }}
          />
        </View>
      )}

      <View style={{ width: chartImage ? "45%" : "100%" }}>
        <View style={styles.table}>
          <View style={styles.tableRow}>
            <View style={[styles.tableColHeader, { width: "55%" }]}>
              <Text style={styles.tableCellHeader}>
                {t("stressCheckResultPage-staff.item")}
              </Text>
            </View>
            <View style={[styles.tableColHeader, { width: "25%" }]}>
              <Text style={styles.tableCellHeader}>
                {t("stressCheckResultPage-staff.score")}
              </Text>
            </View>
            <View style={[styles.tableColHeader, { width: "20%" }]}>
              <Text style={styles.tableCellHeader}>{t("common.previous")}</Text>
            </View>
          </View>
          {items.map((item, i) => (
            <View style={styles.tableRow} key={i}>
              <View style={[styles.tableCol, { width: "55%" }]}>
                <Text style={styles.tableCell}>{item.label}</Text>
              </View>
              <View style={[styles.tableCol, { width: "25%" }]}>
                <Text style={styles.tableCell}>
                  {item.score}
                  {t("stressCheckResultPage-staff.points")}
                </Text>
              </View>
              <View style={[styles.tableCol, { width: "20%" }]}>
                <Text style={styles.tableCell}>
                  {item.previousScore !== undefined
                    ? `${item.previousScore}${t("stressCheckResultPage-staff.points")}`
                    : "ー"}
                </Text>
              </View>
            </View>
          ))}
        </View>
      </View>
    </View>
    {comments.map((c, i) => (
      <Text key={i} style={styles.commentText}>
        {c.content}
      </Text>
    ))}
  </View>
);

export const StressCheckResultPDF = ({
  data,
  chartImages,
}: {
  data: StressCheckResultPDFData;
  chartImages?: ChartImages;
}) => {
  return (
    <Document title={t("stressCheckResultPage-staff.pdfTitle")}>
      <Page size="A4" style={styles.page}>
        <Text style={styles.header}>
          {t("stressCheckResultPage-staff.pdfTitle")}
        </Text>

        <Text style={styles.introText}>{data.introText}</Text>

        <View style={styles.scoreRow}>
          <Text style={styles.scoreLabel}>
            {t("stressCheckResultPage-staff.overallStressScore")}
          </Text>
          <Text style={styles.scoreValue}>
            {data.totalScore} / {data.maxTotal}{" "}
            {t("stressCheckResultPage-staff.points")}
          </Text>
        </View>

        {chartImages?.stressGauge && (
          <View style={{ marginBottom: 15 }}>
            <PdfImage src={chartImages.stressGauge} style={styles.chartImage} />
          </View>
        )}

        {data.stressDescriptionText && (
          <Text style={{ fontSize: 12, marginBottom: 15, lineHeight: 1.5 }}>
            {data.stressDescriptionText}
          </Text>
        )}

        <View
          style={{
            backgroundColor: data.isHighStress ? "#EB253D1A" : "#15ca421A",
            padding: 15,
            borderRadius: 8,
            marginBottom: 20,
          }}
        >
          <Text
            style={{
              fontSize: 12,
              fontWeight: "bold",
              color: data.isHighStress ? "#EB253D" : "#15ca42",
              marginBottom: 10,
            }}
          >
            {data.isHighStress
              ? t("stressCheckResultPage-staff.professionalSupport")
              : t("stressCheckResultPage-staff.currentCondition")}
          </Text>
          <Text style={{ fontSize: 10, lineHeight: 1.5 }}>
            {data.yellowBoxContent}
          </Text>
        </View>

        <CategoryTable
          title={t("stressCheckResultPage-staff.stressFactors")}
          description={data.catADescription}
          items={data.catAItems}
          comments={data.catAComments}
          totalScore={data.category_a_score}
          maxScore={data.maxA}
          isDanger={false}
          chartImage={chartImages?.radarChartA}
        />
        <CategoryTable
          title={t("stressCheckResultPage-staff.mentalReactions")}
          description={data.catBDescription}
          items={data.catBItems}
          comments={data.catBComments}
          totalScore={data.category_b_score}
          maxScore={data.maxB}
          isDanger={false}
          chartImage={chartImages?.radarChartB}
        />
        <CategoryTable
          title={t("stressCheckResultPage-staff.otherFactors")}
          description={data.catCDescription}
          items={data.catCItems}
          comments={data.catCComments}
          totalScore={data.category_c_score}
          maxScore={data.maxC}
          isDanger={data.isHighStress}
          chartImage={chartImages?.radarChartC}
        />

        <Text style={styles.footer}>
          {t("stressCheckResultPage-staff.resultDisclaimer")}
        </Text>
      </Page>
    </Document>
  );
};
