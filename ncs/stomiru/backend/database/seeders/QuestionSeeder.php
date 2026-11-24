<?php

namespace Database\Seeders;

use App\Models\Question;
use App\Models\QuestionCategory;
use App\Models\QuestionOption;
use App\Models\QuestionSet;
use App\Models\Scale;
use Illuminate\Database\Seeder;

/**
 * @formatter:off
 */
class QuestionSeeder extends Seeder
{
    public function run(): void
    {
        if (QuestionSet::query()->exists()) {
            return;
        }

        $questionSet = QuestionSet::query()->create([
            'name' => 'Set default 57 questions',
        ]);

        $optionWork1Trans = [
            ['lang_code' => 'ja', 'content' => 'そうだ'],
            ['lang_code' => 'en', 'content' => 'Definitely so'],
            ['lang_code' => 'zh-CN', 'content' => '是这样'],
            ['lang_code' => 'zh-TW', 'content' => '是這樣'],
            ['lang_code' => 'vi', 'content' => 'Đúng vậy'],
            ['lang_code' => 'tl', 'content' => 'Totoo'],
            ['lang_code' => 'pt', 'content' => 'Definitivamente sim'],
            ['lang_code' => 'es', 'content' => 'Ciertamente sí'],
            ['lang_code' => 'th', 'content' => 'จริง'],
            ['lang_code' => 'id', 'content' => 'Benar'],
        ];
        $optionWork2Trans = [
            ['lang_code' => 'ja', 'content' => 'まあそうだ'],
            ['lang_code' => 'en', 'content' => 'Somewhat so'],
            ['lang_code' => 'zh-CN', 'content' => '某种程度上是这样'],
            ['lang_code' => 'zh-TW', 'content' => '某種程度上是這樣'],
            ['lang_code' => 'vi', 'content' => 'Đúng một phần'],
            ['lang_code' => 'tl', 'content' => 'Totoo'],
            ['lang_code' => 'pt', 'content' => 'Medyo totoo'],
            ['lang_code' => 'es', 'content' => 'En parte sí'],
            ['lang_code' => 'th', 'content' => 'ค่อนข้างจริง'],
            ['lang_code' => 'id', 'content' => 'Agak benar'],
        ];
        $optionWork3Trans = [
            ['lang_code' => 'ja', 'content' => 'ややちがう'],
            ['lang_code' => 'en', 'content' => 'Somewhat not'],
            ['lang_code' => 'zh-CN', 'content' => '某种程度上不是这样'],
            ['lang_code' => 'zh-TW', 'content' => '某種程度上不是這樣'],
            ['lang_code' => 'vi', 'content' => 'Không đúng lắm'],
            ['lang_code' => 'tl', 'content' => 'Medyo hindi totoo'],
            ['lang_code' => 'pt', 'content' => 'Um pouco não'],
            ['lang_code' => 'es', 'content' => 'En parte no'],
            ['lang_code' => 'th', 'content' => 'ไม่ค่อยจริง'],
            ['lang_code' => 'id', 'content' => 'Agak salah'],
        ];
        $optionWork4Trans = [
            ['lang_code' => 'ja', 'content' => 'ちがう'],
            ['lang_code' => 'en', 'content' => 'Not at all'],
            ['lang_code' => 'zh-CN', 'content' => '不是这样'],
            ['lang_code' => 'zh-TW', 'content' => '不是這樣'],
            ['lang_code' => 'vi', 'content' => 'Không đúng'],
            ['lang_code' => 'tl', 'content' => 'Hindi totoo'],
            ['lang_code' => 'pt', 'content' => 'Definitivamente não'],
            ['lang_code' => 'es', 'content' => 'Ciertamente no'],
            ['lang_code' => 'th', 'content' => 'ไม่จริง'],
            ['lang_code' => 'id', 'content' => 'Salah'],
        ];
        $categoryWorkTrans = [
            ['lang_code' => 'ja', 'topic' => '仕事について', 'title' => 'あなたの仕事についてうかがいます。4つの中から最もあてはまるものを選んでください。'],
            ['lang_code' => 'en', 'topic' => 'About your work', 'title' => 'I will ask about your work. Please choose the one that fits best from the four options.'],
            ['lang_code' => 'zh-CN', 'topic' => '关于工作', 'title' => '关于您的工作。请从4个选项中选择最符合的一项。'],
            ['lang_code' => 'zh-TW', 'topic' => '關於工作', 'title' => '關於您的工作。請從4個選項中選擇最符合的一項。'],
            ['lang_code' => 'vi', 'topic' => 'Về công việc', 'title' => 'Tôi xin hỏi về công việc của bạn. Vui lòng chọn một phương án phù hợp nhất trong 4 phương án.'],
            ['lang_code' => 'tl', 'topic' => 'Tungkol sa trabaho', 'title' => 'Tatanungin kita tungkol sa iyong trabaho. Mangyaring piliin ang pinaka-angkop mula sa apat na pagpipilian.'],
            ['lang_code' => 'pt', 'topic' => 'Sobre o trabalho', 'title' => 'Vou perguntar sobre o seu trabalho. Por favor, escolha a opção que melhor se adapta entre as quatro.'],
            ['lang_code' => 'es', 'topic' => 'Sobre el trabajo', 'title' => 'Le preguntaré sobre su trabajo. Por favor, elija la opción que mejor se adapte entre las cuatro.'],
            ['lang_code' => 'th', 'topic' => 'เกี่ยวกับงาน', 'title' => 'ขอสอบถามเกี่ยวกับงานของคุณ โปรดเลือกข้อที่ตรงกับคุณที่สุดจาก 4 ตัวเลือก'],
            ['lang_code' => 'id', 'topic' => 'Tentang pekerjaan', 'title' => 'Saya akan bertanya tentang pekerjaan Anda. Silakan pilih satu yang paling sesuai dari empat pilihan.'],
        ];
        $optionState1Trans = [
            ['lang_code' => 'ja', 'content' => 'ほとんどなかった'],
            ['lang_code' => 'en', 'content' => 'Almost never'],
            ['lang_code' => 'zh-CN', 'content' => '几乎没有'],
            ['lang_code' => 'zh-TW', 'content' => '幾乎沒有'],
            ['lang_code' => 'vi', 'content' => 'Hầu như không có'],
            ['lang_code' => 'tl', 'content' => 'Halos hindi'],
            ['lang_code' => 'pt', 'content' => 'Quase nunca'],
            ['lang_code' => 'es', 'content' => 'Casi nunca'],
            ['lang_code' => 'th', 'content' => 'แทบจะไม่เคย'],
            ['lang_code' => 'id', 'content' => 'Hampir tidak pernah'],
        ];
        $optionState2Trans = [
            ['lang_code' => 'ja', 'content' => 'ときどきあった'],
            ['lang_code' => 'en', 'content' => 'Sometimes'],
            ['lang_code' => 'zh-CN', 'content' => '有时有'],
            ['lang_code' => 'zh-TW', 'content' => '有時有'],
            ['lang_code' => 'vi', 'content' => 'Thỉnh thoảng có'],
            ['lang_code' => 'tl', 'content' => 'Paminsan-minsan'],
            ['lang_code' => 'pt', 'content' => 'Às vezes'],
            ['lang_code' => 'es', 'content' => 'A veces'],
            ['lang_code' => 'th', 'content' => 'เป็นบางครั้ง'],
            ['lang_code' => 'id', 'content' => 'Kadang-kadang'],
        ];
        $optionState3Trans = [
            ['lang_code' => 'ja', 'content' => 'しばしばあった'],
            ['lang_code' => 'en', 'content' => 'Often'],
            ['lang_code' => 'zh-CN', 'content' => '经常有'],
            ['lang_code' => 'zh-TW', 'content' => '經常有'],
            ['lang_code' => 'vi', 'content' => 'Thường xuyên có'],
            ['lang_code' => 'tl', 'content' => 'Madalas'],
            ['lang_code' => 'pt', 'content' => 'Frequentemente'],
            ['lang_code' => 'es', 'content' => 'A menudo'],
            ['lang_code' => 'th', 'content' => 'บ่อยครั้ง'],
            ['lang_code' => 'id', 'content' => 'Sering'],
        ];
        $optionState4Trans = [
            ['lang_code' => 'ja', 'content' => 'ほとんどいつもあった'],
            ['lang_code' => 'en', 'content' => 'Almost always'],
            ['lang_code' => 'zh-CN', 'content' => '几乎总是'],
            ['lang_code' => 'zh-TW', 'content' => '幾乎總是'],
            ['lang_code' => 'vi', 'content' => 'Hầu như luôn luôn'],
            ['lang_code' => 'tl', 'content' => 'Halos palagi'],
            ['lang_code' => 'pt', 'content' => 'Quase sempre'],
            ['lang_code' => 'es', 'content' => 'Casi siempre'],
            ['lang_code' => 'th', 'content' => 'แทบจะตลอดเวลา'],
            ['lang_code' => 'id', 'content' => 'Hampir selalu'],
        ];
        $categoryStateTrans = [
            ['lang_code' => 'ja', 'topic' => '最近1か月の状態について', 'title' => '最近1か月間のあなたの状態についてうかがいます。4つの中から最もあてはまるものを選んでください。'],
            ['lang_code' => 'en', 'topic' => 'About your state in the last month', 'title' => 'I will ask about your state during the last month. Please choose the one that fits best from the four options.'],
            ['lang_code' => 'zh-CN', 'topic' => '关于最近1个月的状态', 'title' => '关于最近1个月的状态。请从4个选项中选择最符合的一项。'],
            ['lang_code' => 'zh-TW', 'topic' => '關於最近1個月的狀態', 'title' => '關於最近1個月的狀態。請從4個選項中選擇最符合的一項。'],
            ['lang_code' => 'vi', 'topic' => 'Về tình trạng trong 1 tháng gần đây', 'title' => 'Tôi xin hỏi về tình trạng của bạn trong 1 tháng qua. Vui lòng chọn một phương án phù hợp nhất trong 4 phương án.'],
            ['lang_code' => 'tl', 'topic' => 'Tungkol sa iyong kalagayan nitong nakaraang buwan', 'title' => 'Tatanungin kita tungkol sa iyong kalagayan nitong nakaraang buwan. Mangyaring piliin ang pinaka-angkop mula sa apat na pagpipilian.'],
            ['lang_code' => 'pt', 'topic' => 'Sobre o seu estado no último mês', 'title' => 'Vou perguntar sobre o seu estado durante o último mês. Por favor, escolha a opção que melhor se adapta entre as quatro.'],
            ['lang_code' => 'es', 'topic' => 'Sobre su estado en el último mes', 'title' => 'Le preguntaré sobre su estado durante el último mes. Por favor, elija la opción que mejor se adapte entre las cuatro.'],
            ['lang_code' => 'th', 'topic' => 'เกี่ยวกับสภาพของคุณในช่วง 1 เดือนที่ผ่านมา', 'title' => 'ขอสอบถามเกี่ยวกับสภาพของคุณในช่วง 1 เดือนที่ผ่านมา โปรดเลือกข้อที่ตรงกับคุณที่สุดจาก 4 ตัวเลือก'],
            ['lang_code' => 'id', 'topic' => 'Tentang kondisi Anda dalam 1 bulan terakhir', 'title' => 'Saya akan bertanya tentang kondisi Anda selama satu bulan terakhir. Silakan pilih satu yang paling sesuai dari empat pilihan.'],
        ];
        $optionSupport1Trans = [
            ['lang_code' => 'ja', 'content' => '非常に'],
            ['lang_code' => 'en', 'content' => 'Extremely'],
            ['lang_code' => 'zh-CN', 'content' => '非常'],
            ['lang_code' => 'zh-TW', 'content' => '非常'],
            ['lang_code' => 'vi', 'content' => 'Rất nhiều'],
            ['lang_code' => 'tl', 'content' => 'Labis'],
            ['lang_code' => 'pt', 'content' => 'Extremamente'],
            ['lang_code' => 'es', 'content' => 'Extremadamente'],
            ['lang_code' => 'th', 'content' => 'มาก'],
            ['lang_code' => 'id', 'content' => 'Sangat'],
        ];
        $optionSupport2Trans = [
            ['lang_code' => 'ja', 'content' => 'かなり'],
            ['lang_code' => 'en', 'content' => 'Very much'],
            ['lang_code' => 'zh-CN', 'content' => '相当'],
            ['lang_code' => 'zh-TW', 'content' => '相當'],
            ['lang_code' => 'vi', 'content' => 'Khá nhiều'],
            ['lang_code' => 'tl', 'content' => 'Sobra'],
            ['lang_code' => 'pt', 'content' => 'Muito'],
            ['lang_code' => 'es', 'content' => 'Mucho'],
            ['lang_code' => 'th', 'content' => 'ค่อนข้างมาก'],
            ['lang_code' => 'id', 'content' => 'Cukup banyak'],
        ];
        $optionSupport3Trans = [
            ['lang_code' => 'ja', 'content' => '多少'],
            ['lang_code' => 'en', 'content' => 'Somewhat'],
            ['lang_code' => 'zh-CN', 'content' => '多少'],
            ['lang_code' => 'zh-TW', 'content' => '多少'],
            ['lang_code' => 'vi', 'content' => 'Một chút'],
            ['lang_code' => 'tl', 'content' => 'Medyo'],
            ['lang_code' => 'pt', 'content' => 'Um pouco'],
            ['lang_code' => 'es', 'content' => 'Un poco'],
            ['lang_code' => 'th', 'content' => 'เล็กน้อย'],
            ['lang_code' => 'id', 'content' => 'Sedikit'],
        ];
        $optionSupport4Trans = [
            ['lang_code' => 'ja', 'content' => '全くない'],
            ['lang_code' => 'en', 'content' => 'Not at all'],
            ['lang_code' => 'zh-CN', 'content' => '完全没有'],
            ['lang_code' => 'zh-TW', 'content' => '完全沒有'],
            ['lang_code' => 'vi', 'content' => 'Hoàn toàn không'],
            ['lang_code' => 'tl', 'content' => 'Wala talaga'],
            ['lang_code' => 'pt', 'content' => 'De jeito nenhum'],
            ['lang_code' => 'es', 'content' => 'En absoluto'],
            ['lang_code' => 'th', 'content' => 'ไม่เลย'],
            ['lang_code' => 'id', 'content' => 'Sama sekali tidak'],
        ];
        $categorySupportTrans = [
            ['lang_code' => 'ja', 'topic' => '周りの方々について', 'title' => 'あなたの周りの方々についてうかがいます。4つの中から最もあてはまるものを選んでください。'],
            ['lang_code' => 'en', 'topic' => 'About people around you', 'title' => 'I will ask about the people around you. Please choose the one that fits best from the four options.'],
            ['lang_code' => 'zh-CN', 'topic' => '关于周围的人', 'title' => '关于周围的人。请从4个选项中选择最符合的一项。'],
            ['lang_code' => 'zh-TW', 'topic' => '關於周圍的人', 'title' => '關於周圍的人。請從4個選項中選擇最符合的一項。'],
            ['lang_code' => 'vi', 'topic' => 'Về những người xung quanh', 'title' => 'Tôi xin hỏi về những người xung quanh bạn. Vui lòng chọn một phương án phù hợp nhất trong 4 phương án.'],
            ['lang_code' => 'tl', 'topic' => 'Tungkol sa mga tao sa paligid mo', 'title' => 'Tatanungin kita tungkol sa mga tao sa paligid mo. Mangyaring piliin ang pinaka-angkop mula sa apat na pagpipilian.'],
            ['lang_code' => 'pt', 'topic' => 'Sobre as pessoas ao seu redor', 'title' => 'Vou perguntar sobre as pessoas ao seu redor. Por favor, escolha a opção que melhor se adapta entre as quatro.'],
            ['lang_code' => 'es', 'topic' => 'Sobre las personas a su alrededor', 'title' => 'Le preguntaré sobre las personas a su alrededor. Por favor, elija la opción que mejor se adapte entre las cuatro.'],
            ['lang_code' => 'th', 'topic' => 'เกี่ยวกับคนรอบข้าง', 'title' => 'ขอสอบถามเกี่ยวกับคนรอบข้างของคุณ โปรดเลือกข้อที่ตรงกับคุณที่สุดจาก 4 ตัวเลือก'],
            ['lang_code' => 'id', 'topic' => 'Tentang orang-orang di sekitar Anda', 'title' => 'Saya akan bertanya tentang orang-orang di sekitar Anda. Silakan pilih satu yang paling sesuai dari empat pilihan.'],
        ];
        $optionSat1Trans = [
            ['lang_code' => 'ja', 'content' => '満足'],
            ['lang_code' => 'en', 'content' => 'Satisfied'],
            ['lang_code' => 'zh-CN', 'content' => '满意'],
            ['lang_code' => 'zh-TW', 'content' => '滿意'],
            ['lang_code' => 'vi', 'content' => 'Hài lòng'],
            ['lang_code' => 'tl', 'content' => 'Nasisiyahan'],
            ['lang_code' => 'pt', 'content' => 'Satisfeito'],
            ['lang_code' => 'es', 'content' => 'Satisfecho'],
            ['lang_code' => 'th', 'content' => 'พอใจ'],
            ['lang_code' => 'id', 'content' => 'Puas'],
        ];
        $optionSat2Trans = [
            ['lang_code' => 'ja', 'content' => 'まあ満足'],
            ['lang_code' => 'en', 'content' => 'Somewhat satisfied'],
            ['lang_code' => 'zh-CN', 'content' => '比较满意'],
            ['lang_code' => 'zh-TW', 'content' => '比較滿意'],
            ['lang_code' => 'vi', 'content' => 'Khá hài lòng'],
            ['lang_code' => 'tl', 'content' => 'Medyo nasisiyahan'],
            ['lang_code' => 'pt', 'content' => 'Mais ou menos satisfeito'],
            ['lang_code' => 'es', 'content' => 'Algo satisfecho'],
            ['lang_code' => 'th', 'content' => 'ค่อนข้างพอใจ'],
            ['lang_code' => 'id', 'content' => 'Cukup puas'],
        ];
        $optionSat3Trans = [
            ['lang_code' => 'ja', 'content' => 'やや不満足'],
            ['lang_code' => 'en', 'content' => 'Somewhat dissatisfied'],
            ['lang_code' => 'zh-CN', 'content' => '有点不满意'],
            ['lang_code' => 'zh-TW', 'content' => '有點不滿意'],
            ['lang_code' => 'vi', 'content' => 'Hơi không hài lòng'],
            ['lang_code' => 'tl', 'content' => 'Medyo hindi nasisiyahan'],
            ['lang_code' => 'pt', 'content' => 'Um pouco insatisfeito'],
            ['lang_code' => 'es', 'content' => 'Algo insatisfecho'],
            ['lang_code' => 'th', 'content' => 'ค่อนข้างไม่พอใจ'],
            ['lang_code' => 'id', 'content' => 'Agak tidak puas'],
        ];
        $optionSat4Trans = [
            ['lang_code' => 'ja', 'content' => '不満足'],
            ['lang_code' => 'en', 'content' => 'Dissatisfied'],
            ['lang_code' => 'zh-CN', 'content' => '不满意'],
            ['lang_code' => 'zh-TW', 'content' => '不滿意'],
            ['lang_code' => 'vi', 'content' => 'Không hài lòng'],
            ['lang_code' => 'tl', 'content' => 'Hindi nasisiyahan'],
            ['lang_code' => 'pt', 'content' => 'Insatisfeito'],
            ['lang_code' => 'es', 'content' => 'Insatisfecho'],
            ['lang_code' => 'th', 'content' => 'ไม่พอใจ'],
            ['lang_code' => 'id', 'content' => 'Tidak puas'],
        ];
        $categorySatTrans = [
            ['lang_code' => 'ja', 'topic' => '満足度について', 'title' => 'あなたの満足度についてうかがいます。4つの中から最もあてはまるものを選んでください。'],
            ['lang_code' => 'en', 'topic' => 'About satisfaction', 'title' => 'I will ask about your satisfaction. Please choose the one that fits best from the four options.'],
            ['lang_code' => 'zh-CN', 'topic' => '关于满意度', 'title' => '关于您的满意度。请从4个选项中选择最符合的一项。'],
            ['lang_code' => 'zh-TW', 'topic' => '關於滿意度', 'title' => '關於您的滿意度。請從4個選項中選擇最符合的一項。'],
            ['lang_code' => 'vi', 'topic' => 'Về mức độ hài lòng', 'title' => 'Tôi xin hỏi về mức độ hài lòng của bạn. Vui lòng chọn một phương án phù hợp nhất trong 4 phương án.'],
            ['lang_code' => 'tl', 'topic' => 'Tungkol sa kasiyahan', 'title' => 'Tatanungin kita tungkol sa iyong kasiyahan. Mangyaring piliin ang pinaka-angkop mula sa apat na pagpipilian.'],
            ['lang_code' => 'pt', 'topic' => 'Sobre a satisfação', 'title' => 'Vou perguntar sobre a sua satisfação. Por favor, escolha a opção que melhor se adapta entre as quatro.'],
            ['lang_code' => 'es', 'topic' => 'Sobre la satisfacción', 'title' => 'Le preguntaré sobre su satisfacción. Por favor, elija la opción que mejor se adapte entre las cuatro.'],
            ['lang_code' => 'th', 'topic' => 'เกี่ยวกับความพึงพอใจ', 'title' => 'ขอสอบถามเกี่ยวกับความพึงพอใจของคุณ โปรดเลือกข้อที่ตรงกับคุณที่สุดจาก 4 ตัวเลือก'],
            ['lang_code' => 'id', 'topic' => 'Tentang kepuasan', 'title' => 'Saya akan bertanya tentang kepuasan Anda. Silakan pilih satu yang paling sesuai dari empat pilihan.'],
        ];

        // Categories
        $qcA = QuestionCategory::query()->create(['name' => 'A']);
        $qcA->translations()->createMany($categoryWorkTrans);
        $qcB = QuestionCategory::query()->create(['name' => 'B']);
        $qcB->translations()->createMany($categoryStateTrans);
        $qcC = QuestionCategory::query()->create(['name' => 'C']);
        $qcC->translations()->createMany($categorySupportTrans);
        $qcD = QuestionCategory::query()->create(['name' => 'D']);
        $qcD->translations()->createMany($categorySatTrans);

        $scalesData = [
            'sA1' => [
                'evaluation_score' => [
                    ['min' => 3, 'max' => 5, 'score' => 5],
                    ['min' => 6, 'max' => 7, 'score' => 4],
                    ['min' => 8, 'max' => 9, 'score' => 3],
                    ['min' => 10, 'max' => 11, 'score' => 2],
                    ['min' => 12, 'max' => 12, 'score' => 1],
                ],
                'trans' => [
                    'ja' => '心理的な仕事の負担(量)',
                    'en' => 'Psychological work load (Quantity)',
                    'zh-CN' => '心理性工作负担（量）',
                    'zh-TW' => '心理性工作負擔（量）',
                    'vi' => 'Gánh nặng công việc tâm lý (Lượng)',
                    'tl' => 'Sikolohikal na bigat ng trabaho (Dami)',
                    'pt' => 'Carga de trabalho psicológica (Quantidade)',
                    'es' => 'Carga de trabajo psicológica (Cantidad)',
                    'th' => 'ภาระงานทางจิตใจ (ปริมาณ)',
                    'id' => 'Beban kerja psikologis (Kuantitas)',
                ],
            ],
            'sA2' => [
                'evaluation_score' => [
                    ['min' => 3, 'max' => 5, 'score' => 5],
                    ['min' => 6, 'max' => 7, 'score' => 4],
                    ['min' => 8, 'max' => 9, 'score' => 3],
                    ['min' => 10, 'max' => 11, 'score' => 2],
                    ['min' => 12, 'max' => 12, 'score' => 1],
                ],
                'trans' => [
                    'ja' => '心理的な仕事の負担(質)',
                    'en' => 'Psychological work load (Quality)',
                    'zh-CN' => '心理性工作负担（质）',
                    'zh-TW' => '心理性工作負擔（質）',
                    'vi' => 'Gánh nặng công việc tâm lý (Chất)',
                    'tl' => 'Sikolohikal na bigat ng trabaho (Kalidad)',
                    'pt' => 'Carga de trabalho psicológica (Qualidade)',
                    'es' => 'Carga de trabajo psicológica (Calidad)',
                    'th' => 'ภาระงานทางจิตใจ (คุณภาพ)',
                    'id' => 'Beban kerja psikologis (Kualitas)',
                ],
            ],
            'sA3' => [
                'evaluation_score' => [
                    ['min' => 1, 'max' => 1, 'score' => 4],
                    ['min' => 2, 'max' => 2, 'score' => 3],
                    ['min' => 3, 'max' => 3, 'score' => 2],
                    ['min' => 4, 'max' => 4, 'score' => 1],
                ],
                'trans' => [
                    'ja' => '自覚的な身体的負担度',
                    'en' => 'Subjective physical burden',
                    'zh-CN' => '自觉身体负担度',
                    'zh-TW' => '自覺身體負擔度',
                    'vi' => 'Mức độ gánh nặng thể chất tự giác',
                    'tl' => 'Subhetibong bigat sa katawan',
                    'pt' => 'Carga física subjetiva',
                    'es' => 'Carga física subjetiva',
                    'th' => 'ระดับภาระทางร่างกายที่รู้สึกได้',
                    'id' => 'Beban fisik subjektif',
                ],
            ],
            'sA4' => [
                'evaluation_score' => [
                    ['min' => 3, 'max' => 3, 'score' => 5],
                    ['min' => 4, 'max' => 5, 'score' => 4],
                    ['min' => 6, 'max' => 7, 'score' => 3],
                    ['min' => 8, 'max' => 9, 'score' => 2],
                    ['min' => 10, 'max' => 12, 'score' => 1],
                ],
                'trans' => [
                    'ja' => '職場の対人関係でのストレス',
                    'en' => 'Stress from interpersonal relations at work',
                    'zh-CN' => '职场人际关系压力',
                    'zh-TW' => '職場人際關係壓力',
                    'vi' => 'Căng thẳng trong quan hệ cá nhân tại nơi làm việc',
                    'tl' => 'Stress mula sa pakikipag-ugnayan sa trabaho',
                    'pt' => 'Estresse de relações interpessoais no trabalho',
                    'es' => 'Estrés por relaciones interpersonales en el trabajo',
                    'th' => 'ความเครียดจากความสัมพันธ์ระหว่างบุคคลในที่ทำงาน',
                    'id' => 'Stres dari hubungan interpersonal di tempat kerja',
                ],
            ],
            'sA5' => [
                'evaluation_score' => [
                    ['min' => 1, 'max' => 1, 'score' => 4],
                    ['min' => 2, 'max' => 2, 'score' => 3],
                    ['min' => 3, 'max' => 3, 'score' => 2],
                    ['min' => 4, 'max' => 4, 'score' => 1],
                ],
                'trans' => [
                    'ja' => '職場環境によるストレス',
                    'en' => 'Stress from work environment',
                    'zh-CN' => '职场环境压力',
                    'zh-TW' => '職場環境壓力',
                    'vi' => 'Căng thẳng do môi trường làm việc',
                    'tl' => 'Stress mula sa kapaligiran ng trabaho',
                    'pt' => 'Estresse do ambiente de trabalho',
                    'es' => 'Estrés por el entorno laboral',
                    'th' => 'ความเครียดจากสภาพแวดล้อมในที่ทำงาน',
                    'id' => 'Stres akibat lingkungan kerja',
                ],
            ],
            'sA6' => [
                'evaluation_score' => [
                    ['min' => 11, 'max' => 12, 'score' => 5],
                    ['min' => 9, 'max' => 10, 'score' => 4],
                    ['min' => 7, 'max' => 8, 'score' => 3],
                    ['min' => 5, 'max' => 6, 'score' => 2],
                    ['min' => 3, 'max' => 4, 'score' => 1],
                ],
                'trans' => [
                    'ja' => '仕事のコントロール度',
                    'en' => 'Job control',
                    'zh-CN' => '工作掌控度',
                    'zh-TW' => '工作掌控度',
                    'vi' => 'Mức độ kiểm soát công việc',
                    'tl' => 'Kontrol sa trabaho',
                    'pt' => 'Controle do trabalho',
                    'es' => 'Control del trabajo',
                    'th' => 'ระดับการควบคุมงาน',
                    'id' => 'Kontrol pekerjaan',
                ],
            ],
            'sA7' => [
                'evaluation_score' => [
                    ['min' => 1, 'max' => 1, 'score' => 1],
                    ['min' => 2, 'max' => 2, 'score' => 2],
                    ['min' => 3, 'max' => 3, 'score' => 3],
                    ['min' => 4, 'max' => 4, 'score' => 4],
                ],
                'trans' => [
                    'ja' => '技能の活用度',
                    'en' => 'Skill utilization',
                    'zh-CN' => '技能活用度',
                    'zh-TW' => '技能活用度',
                    'vi' => 'Mức độ sử dụng kỹ năng',
                    'tl' => 'Paggamit ng kasanayan',
                    'pt' => 'Utilização de habilidades',
                    'es' => 'Utilización de habilidades',
                    'th' => 'ระดับการใช้ทักษะ',
                    'id' => 'Pemanfaatan keterampilan',
                ],
            ],
            'sA8' => [
                'evaluation_score' => [
                    ['min' => 1, 'max' => 1, 'score' => 1],
                    ['min' => 2, 'max' => 2, 'score' => 2],
                    ['min' => 3, 'max' => 3, 'score' => 3],
                    ['min' => 4, 'max' => 4, 'score' => 5],
                ],
                'trans' => [
                    'ja' => '仕事の適性度',
                    'en' => 'Job suitability',
                    'zh-CN' => '工作适应度',
                    'zh-TW' => '工作適應度',
                    'vi' => 'Mức độ phù hợp với công việc',
                    'tl' => 'Kaangkupan sa trabaho',
                    'pt' => 'Adequação ao trabalho',
                    'es' => 'Idoneidad laboral',
                    'th' => 'ความเหมาะสมกับงาน',
                    'id' => 'Kesesuaian pekerjaan',
                ],
            ],
            'sA9' => [
                'evaluation_score' => [
                    ['min' => 1, 'max' => 1, 'score' => 1],
                    ['min' => 2, 'max' => 2, 'score' => 2],
                    ['min' => 3, 'max' => 3, 'score' => 3],
                    ['min' => 4, 'max' => 4, 'score' => 5],
                ],
                'trans' => [
                    'ja' => '働きがい',
                    'en' => 'Job satisfaction',
                    'zh-CN' => '工作价值感',
                    'zh-TW' => '工作價值感',
                    'vi' => 'Ý nghĩa công việc',
                    'tl' => 'Kasiyahan sa trabaho',
                    'pt' => 'Satisfação no trabalho',
                    'es' => 'Satisfacción laboral',
                    'th' => 'ความคุ้มค่าในการทำงาน',
                    'id' => 'Kepuasan kerja',
                ],
            ],
            'sB1' => [
                'evaluation_score' => [
                    ['min' => 3, 'max' => 3, 'score' => 1],
                    ['min' => 4, 'max' => 5, 'score' => 2],
                    ['min' => 6, 'max' => 7, 'score' => 3],
                    ['min' => 8, 'max' => 9, 'score' => 4],
                    ['min' => 10, 'max' => 12, 'score' => 5],
                ],
                'trans' => [
                    'ja' => '活気',
                    'en' => 'Vigor',
                    'zh-CN' => '活力',
                    'zh-TW' => '活力',
                    'vi' => 'Sức sống',
                    'tl' => 'Sigla',
                    'pt' => 'Vigor',
                    'es' => 'Vigor',
                    'th' => 'ความกระปรี้กระเปร่า',
                    'id' => 'Semangat',
                ],
            ],
            'sB2' => [
                'evaluation_score' => [
                    ['min' => 3, 'max' => 3, 'score' => 5],
                    ['min' => 4, 'max' => 5, 'score' => 4],
                    ['min' => 6, 'max' => 7, 'score' => 3],
                    ['min' => 8, 'max' => 9, 'score' => 2],
                    ['min' => 10, 'max' => 12, 'score' => 1],
                ],
                'trans' => [
                    'ja' => 'イライラ感',
                    'en' => 'Irritability',
                    'zh-CN' => '焦躁感',
                    'zh-TW' => '焦躁感',
                    'vi' => 'Cảm giác cáu gắt',
                    'tl' => 'Pagkairita',
                    'pt' => 'Irritabilidade',
                    'es' => 'Irritabilidad',
                    'th' => 'ความหงุดหงิด',
                    'id' => 'Rasa Kesal',
                ],
            ],
            'sB3' => [
                'evaluation_score' => [
                    ['min' => 3, 'max' => 3, 'score' => 5],
                    ['min' => 4, 'max' => 4, 'score' => 4],
                    ['min' => 5, 'max' => 7, 'score' => 3],
                    ['min' => 8, 'max' => 10, 'score' => 2],
                    ['min' => 11, 'max' => 12, 'score' => 1],
                ],
                'trans' => [
                    'ja' => '疲労感',
                    'en' => 'Fatigue',
                    'zh-CN' => '疲劳感',
                    'zh-TW' => '疲勞感',
                    'vi' => 'Cảm giác mệt mỏi',
                    'tl' => 'Pagkapagod',
                    'pt' => 'Fadiga',
                    'es' => 'Fatiga',
                    'th' => 'ความเหนื่อยล้า',
                    'id' => 'Kelelahan',
                ],
            ],
            'sB4' => [
                'evaluation_score' => [
                    ['min' => 3, 'max' => 3, 'score' => 5],
                    ['min' => 4, 'max' => 4, 'score' => 4],
                    ['min' => 5, 'max' => 7, 'score' => 3],
                    ['min' => 8, 'max' => 9, 'score' => 2],
                    ['min' => 10, 'max' => 12, 'score' => 1],
                ],
                'trans' => [
                    'ja' => '不安感',
                    'en' => 'Anxiety',
                    'zh-CN' => '不安感',
                    'zh-TW' => '不安感',
                    'vi' => 'Cảm giác lo âu',
                    'tl' => 'Pagkabalisa',
                    'pt' => 'Ansiedade',
                    'es' => 'Ansiedad',
                    'th' => 'ความกังวล',
                    'id' => 'Kecemasan',
                ],
            ],
            'sB5' => [
                'evaluation_score' => [
                    ['min' => 6, 'max' => 6, 'score' => 5],
                    ['min' => 7, 'max' => 8, 'score' => 4],
                    ['min' => 9, 'max' => 12, 'score' => 3],
                    ['min' => 13, 'max' => 16, 'score' => 2],
                    ['min' => 17, 'max' => 24, 'score' => 1],
                ],
                'trans' => [
                    'ja' => '抑うつ感',
                    'en' => 'Depression',
                    'zh-CN' => '抑郁感',
                    'zh-TW' => '抑鬱感',
                    'vi' => 'Cảm giác trầm cảm',
                    'tl' => 'Depresyon',
                    'pt' => 'Depressão',
                    'es' => 'Depresión',
                    'th' => 'ความรู้สึกซึมเศร้า',
                    'id' => 'Depresi',
                ],
            ],
            'sB6' => [
                'evaluation_score' => [
                    ['min' => 11, 'max' => 11, 'score' => 5],
                    ['min' => 12, 'max' => 15, 'score' => 4],
                    ['min' => 16, 'max' => 21, 'score' => 3],
                    ['min' => 22, 'max' => 26, 'score' => 2],
                    ['min' => 27, 'max' => 44, 'score' => 1],
                ],
                'trans' => [
                    'ja' => '身体愁訴',
                    'en' => 'Physical complaints',
                    'zh-CN' => '身体不适',
                    'zh-TW' => '身體不適',
                    'vi' => 'Triệu chứng cơ thể',
                    'tl' => 'Pisikal na reklamo',
                    'pt' => 'Queixas físicas',
                    'es' => 'Quejas físicas',
                    'th' => 'อาการทางร่างกาย',
                    'id' => 'Keluhan fisik',
                ],
            ],
            'sC1' => [
                'evaluation_score' => [
                    ['min' => 3, 'max' => 4, 'score' => 1],
                    ['min' => 5, 'max' => 6, 'score' => 2],
                    ['min' => 7, 'max' => 8, 'score' => 3],
                    ['min' => 9, 'max' => 10, 'score' => 4],
                    ['min' => 11, 'max' => 12, 'score' => 5],
                ],
                'trans' => [
                    'ja' => '上司からのサポート',
                    'en' => 'Support from supervisors',
                    'zh-CN' => '上司的支持',
                    'zh-TW' => '上司的支持',
                    'vi' => 'Hỗ trợ từ cấp trên',
                    'tl' => 'Suporta mula sa mga superbisor',
                    'pt' => 'Apoio dos supervisores',
                    'es' => 'Apoyo de los supervisores',
                    'th' => 'การสนับสนุนจากหัวหน้า',
                    'id' => 'Dukungan dari atasan',
                ],
            ],
            'sC2' => [
                'evaluation_score' => [
                    ['min' => 3, 'max' => 5, 'score' => 1],
                    ['min' => 6, 'max' => 7, 'score' => 2],
                    ['min' => 8, 'max' => 9, 'score' => 3],
                    ['min' => 10, 'max' => 11, 'score' => 4],
                    ['min' => 12, 'max' => 12, 'score' => 5],
                ],
                'trans' => [
                    'ja' => '同僚からのサポート',
                    'en' => 'Support from colleagues',
                    'zh-CN' => '同事的支持',
                    'zh-TW' => '同事的支持',
                    'vi' => 'Hỗ trợ từ đồng nghiệp',
                    'tl' => 'Suporta mula sa mga kasamahan',
                    'pt' => 'Apoio dos colegas',
                    'es' => 'Apoyo de los colegas',
                    'th' => 'การสนับสนุนจากเพื่อนร่วมงาน',
                    'id' => 'Dukungan dari rekan kerja',
                ],
            ],
            'sC3' => [
                'evaluation_score' => [
                    ['min' => 3, 'max' => 6, 'score' => 1],
                    ['min' => 7, 'max' => 8, 'score' => 2],
                    ['min' => 9, 'max' => 9, 'score' => 3],
                    ['min' => 10, 'max' => 11, 'score' => 4],
                    ['min' => 12, 'max' => 12, 'score' => 5],
                ],
                'trans' => [
                    'ja' => '家族・友人からのサポート',
                    'en' => 'Support from family/friends',
                    'zh-CN' => '家人/朋友的支持',
                    'zh-TW' => '家人/朋友的支持',
                    'vi' => 'Hỗ trợ từ gia đình/bạn bè',
                    'tl' => 'Suporta mula sa pamilya/kaibigan',
                    'pt' => 'Apoio da família/amigos',
                    'es' => 'Apoyo de familiares/amigos',
                    'th' => 'การสนับสนุนจากครอบครัว/เพื่อน',
                    'id' => 'Dukungan dari keluarga/teman',
                ],
            ],
            'sD1' => [
                'evaluation_score' => [
                    ['min' => 2, 'max' => 3, 'score' => 1],
                    ['min' => 4, 'max' => 4, 'score' => 2],
                    ['min' => 5, 'max' => 6, 'score' => 3],
                    ['min' => 7, 'max' => 7, 'score' => 4],
                    ['min' => 8, 'max' => 8, 'score' => 5],
                ],
                'trans' => [
                    'ja' => '仕事/家庭満足度',
                    'en' => 'Work/Family satisfaction',
                    'zh-CN' => '工作/家庭满意度',
                    'zh-TW' => '工作/家庭滿意度',
                    'vi' => 'Mức độ hài lòng công việc/gia đình',
                    'tl' => 'Kasiyahan sa Trabaho/Pamilya',
                    'pt' => 'Satisfação Trabalho/Família',
                    'es' => 'Satisfacción Trabajo/Familia',
                    'th' => 'ความพึงพอใจในงาน/ครอบครัว',
                    'id' => 'Kepuasan Kerja/Keluarga',
                ],
            ],
        ];

        foreach ($scalesData as $var => $data) {
            $scale = Scale::query()->create([
                'name' => $data['trans']['en'],
                'evaluation_score' => $data['evaluation_score'],
            ]);
            $translations = [];
            foreach ($data['trans'] as $lang => $content) {
                $translations[] = ['lang_code' => $lang, 'content' => $content];
            }
            $scale->translations()->createMany($translations);
            $$var = $scale;
        }

        // Q1
        $q1 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcA->id,
            'sort_order' => 1,
            'question_number' => 'A-1',
            'anchor_point' => 15,
            'scale_id' => $sA1->id,
        ]);
        $q1->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '非常にたくさんの仕事をしなければならない'],
            ['lang_code' => 'en', 'content' => 'I have to do an enormous amount of work'],
            ['lang_code' => 'zh-CN', 'content' => '必须做非常大量的工作'],
            ['lang_code' => 'zh-TW', 'content' => '必須做非常大量的工作'],
            ['lang_code' => 'vi', 'content' => 'Tôi phải làm một khối lượng công việc rất lớn'],
            ['lang_code' => 'tl', 'content' => 'Kailangan kong gumawa ng napakaraming trabaho'],
            ['lang_code' => 'pt', 'content' => 'Tenho que fazer uma enorme quantidade de trabalho'],
            ['lang_code' => 'es', 'content' => 'Tengo que hacer una enorme cantidad de trabajo'],
            ['lang_code' => 'th', 'content' => 'ต้องทำงานจำนวนมาก'],
            ['lang_code' => 'id', 'content' => 'Saya harus melakukan pekerjaan dalam jumlah yang sangat banyak'],
        ]);
        $this->createOptions($q1, $optionWork1Trans, $optionWork2Trans, $optionWork3Trans, $optionWork4Trans, true);

        // Q2
        $q2 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcA->id,
            'sort_order' => 2,
            'question_number' => 'A-2',
            'anchor_point' => 15,
            'scale_id' => $sA1->id,
        ]);
        $q2->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '時間内に仕事が処理しきれない'],
            ['lang_code' => 'en', 'content' => 'I cannot complete work in time'],
            ['lang_code' => 'zh-CN', 'content' => '无法在规定时间内完成工作'],
            ['lang_code' => 'zh-TW', 'content' => '無法在規定時間內完成工作'],
            ['lang_code' => 'vi', 'content' => 'Tôi không thể hoàn thành công việc đúng thời hạn'],
            ['lang_code' => 'tl', 'content' => 'Hindi ko matapos ang trabaho sa oras'],
            ['lang_code' => 'pt', 'content' => 'Não consigo completar o trabalho a tempo'],
            ['lang_code' => 'es', 'content' => 'No puedo completar el trabajo a tiempo'],
            ['lang_code' => 'th', 'content' => 'ทำงานไม่เสร็จตามเวลาที่กำหนด'],
            ['lang_code' => 'id', 'content' => 'Saya tidak dapat menyelesaikan pekerjaan tepat waktu'],
        ]);
        $this->createOptions($q2, $optionWork1Trans, $optionWork2Trans, $optionWork3Trans, $optionWork4Trans, true);

        // Q3
        $q3 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcA->id,
            'sort_order' => 3,
            'question_number' => 'A-3',
            'anchor_point' => 15,
            'scale_id' => $sA1->id,
        ]);
        $q3->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '一生懸命働かなければならない'],
            ['lang_code' => 'en', 'content' => 'I have to work very hard'],
            ['lang_code' => 'zh-CN', 'content' => '必须非常努力地工作'],
            ['lang_code' => 'zh-TW', 'content' => '必須非常努力地工作'],
            ['lang_code' => 'vi', 'content' => 'Tôi phải làm việc rất chăm chỉ'],
            ['lang_code' => 'tl', 'content' => 'Kailangan kong magtrabaho nang husto'],
            ['lang_code' => 'pt', 'content' => 'Tenho que trabalhar muito'],
            ['lang_code' => 'es', 'content' => 'Tengo que trabajar muy duro'],
            ['lang_code' => 'th', 'content' => 'ต้องทำงานอย่างหนัก'],
            ['lang_code' => 'id', 'content' => 'Saya harus bekerja sangat keras'],
        ]);
        $this->createOptions($q3, $optionWork1Trans, $optionWork2Trans, $optionWork3Trans, $optionWork4Trans, true);

        // Q4
        $q4 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcA->id,
            'sort_order' => 4,
            'question_number' => 'A-4',
            'anchor_point' => 15,
            'scale_id' => $sA2->id,
        ]);
        $q4->translations()->createMany([
            ['lang_code' => 'ja', 'content' => 'かなり注意を集中する必要がある'],
            ['lang_code' => 'en', 'content' => 'I have to pay very close attention'],
            ['lang_code' => 'zh-CN', 'content' => '必须高度集中注意力'],
            ['lang_code' => 'zh-TW', 'content' => '必須高度集中注意力'],
            ['lang_code' => 'vi', 'content' => 'Tôi phải tập trung cao độ'],
            ['lang_code' => 'tl', 'content' => 'Kailangan kong mag-focus nang husto'],
            ['lang_code' => 'pt', 'content' => 'Tenho que prestar muita atenção'],
            ['lang_code' => 'es', 'content' => 'Tengo que prestar mucha atención'],
            ['lang_code' => 'th', 'content' => 'ต้องใช้สมาธิอย่างมาก'],
            ['lang_code' => 'id', 'content' => 'Saya harus sangat berkonsentrasi'],
        ]);
        $this->createOptions($q4, $optionWork1Trans, $optionWork2Trans, $optionWork3Trans, $optionWork4Trans, true);

        // Q5
        $q5 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcA->id,
            'sort_order' => 5,
            'question_number' => 'A-5',
            'anchor_point' => 15,
            'scale_id' => $sA2->id,
        ]);
        $q5->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '高度の知識や技術が必要なむずかしい仕事だ'],
            ['lang_code' => 'en', 'content' => 'I have to do difficult work that requires a high level of knowledge and skill'],
            ['lang_code' => 'zh-CN', 'content' => '从事需要高度知识和技能的困难工作'],
            ['lang_code' => 'zh-TW', 'content' => '從事需要高度知識和技能的困難工作'],
            ['lang_code' => 'vi', 'content' => 'Công việc khó khăn đòi hỏi kiến thức và kỹ năng cao'],
            ['lang_code' => 'tl', 'content' => 'Kailangan ng mataas na kaalaman at kasanayan para sa mahirap na trabahong ito'],
            ['lang_code' => 'pt', 'content' => 'Tenho que fazer um trabalho difícil que requer alto nível de conhecimento e habilidade'],
            ['lang_code' => 'es', 'content' => 'Tengo que hacer un trabajo difícil que requiere un alto nivel de conocimientos y habilidades'],
            ['lang_code' => 'th', 'content' => 'เป็นงานยากที่ต้องใช้ความรู้และทักษะขั้นสูง'],
            ['lang_code' => 'id', 'content' => 'Ini adalah pekerjaan sulit yang memerlukan tingkat pengetahuan dan keterampilan tinggi'],
        ]);
        $this->createOptions($q5, $optionWork1Trans, $optionWork2Trans, $optionWork3Trans, $optionWork4Trans, true);

        // Q6
        $q6 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcA->id,
            'sort_order' => 6,
            'question_number' => 'A-6',
            'anchor_point' => 15,
            'scale_id' => $sA2->id,
        ]);
        $q6->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '勤務時間中はいつも仕事のことを考えていなければならない'],
            ['lang_code' => 'en', 'content' => 'I have to think about work exclusively during working hours'],
            ['lang_code' => 'zh-CN', 'content' => '上班时间必须一直想着工作'],
            ['lang_code' => 'zh-TW', 'content' => '上班時間必須一直想著工作'],
            ['lang_code' => 'vi', 'content' => 'Tôi luôn phải nghĩ về công việc trong giờ làm việc'],
            ['lang_code' => 'tl', 'content' => 'Kailangan kong isipin ang trabaho sa lahat ng oras ng trabaho'],
            ['lang_code' => 'pt', 'content' => 'Tenho que pensar exclusivamente no trabalho durante o horário de expediente'],
            ['lang_code' => 'es', 'content' => 'Tengo que pensar exclusivamente en el trabajo durante las horas de trabajo'],
            ['lang_code' => 'th', 'content' => 'ต้องคิดเรื่องงานตลอดเวลาที่ทำงาน'],
            ['lang_code' => 'id', 'content' => 'Saya harus selalu memikirkan pekerjaan selama jam kerja'],
        ]);
        $this->createOptions($q6, $optionWork1Trans, $optionWork2Trans, $optionWork3Trans, $optionWork4Trans, true);

        // Q7
        $q7 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcA->id,
            'sort_order' => 7,
            'question_number' => 'A-7',
            'anchor_point' => 5,
            'scale_id' => $sA3->id,
        ]);
        $q7->translations()->createMany([
            ['lang_code' => 'ja', 'content' => 'からだを大変よく使う仕事だ'],
            ['lang_code' => 'en', 'content' => 'I have to do a lot of physical work'],
            ['lang_code' => 'zh-CN', 'content' => '是一项非常消耗体力的工作'],
            ['lang_code' => 'zh-TW', 'content' => '是一項非常消耗體力的工作'],
            ['lang_code' => 'vi', 'content' => 'Công việc đòi hỏi vận động thể chất nhiều'],
            ['lang_code' => 'tl', 'content' => 'Ito ay trabahong ginagamitan ng lakas ng katawan'],
            ['lang_code' => 'pt', 'content' => 'Tenho que fazer muito trabalho físico'],
            ['lang_code' => 'es', 'content' => 'Tengo que hacer mucho trabajo físico'],
            ['lang_code' => 'th', 'content' => 'เป็นงานที่ต้องใช้แรงกายมาก'],
            ['lang_code' => 'id', 'content' => 'Ini adalah pekerjaan yang banyak menggunakan fisik'],
        ]);
        $this->createOptions($q7, $optionWork1Trans, $optionWork2Trans, $optionWork3Trans, $optionWork4Trans, true);

        // Q8
        $q8 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcA->id,
            'sort_order' => 8,
            'question_number' => 'A-8',
            'anchor_point' => 15,
            'scale_id' => $sA6->id,
        ]);
        $q8->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '自分のペースで仕事ができる'],
            ['lang_code' => 'en', 'content' => 'I can work at my own pace'],
            ['lang_code' => 'zh-CN', 'content' => '能按自己的节奏工作'],
            ['lang_code' => 'zh-TW', 'content' => '能按自己的節奏工作'],
            ['lang_code' => 'vi', 'content' => 'Tôi có thể làm việc theo tốc độ của riêng mình'],
            ['lang_code' => 'tl', 'content' => 'Nakakapagtrabaho ako sa sarili kong bilis'],
            ['lang_code' => 'pt', 'content' => 'Posso trabalhar no meu próprio ritmo'],
            ['lang_code' => 'es', 'content' => 'Puedo trabajar a mi propio ritmo'],
            ['lang_code' => 'th', 'content' => 'สามารถทำงานตามจังหวะของตนเองได้'],
            ['lang_code' => 'id', 'content' => 'Saya bisa bekerja dengan ritme saya sendiri'],
        ]);
        $this->createOptions($q8, $optionWork1Trans, $optionWork2Trans, $optionWork3Trans, $optionWork4Trans, true);

        // Q9
        $q9 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcA->id,
            'sort_order' => 9,
            'question_number' => 'A-9',
            'anchor_point' => 15,
            'scale_id' => $sA6->id,
        ]);
        $q9->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '自分で仕事の順番・やり方を決めることができる'],
            ['lang_code' => 'en', 'content' => 'I can decide the order and method of my work'],
            ['lang_code' => 'zh-CN', 'content' => '可以自己决定工作的顺序和方法'],
            ['lang_code' => 'zh-TW', 'content' => '可以自己決定工作的順序和方法'],
            ['lang_code' => 'vi', 'content' => 'Tôi có thể quyết định trình tự và cách thức làm việc'],
            ['lang_code' => 'tl', 'content' => 'Napagpapasiyahan ko ang pagkakasunod-sunod at paraan ng aking trabaho'],
            ['lang_code' => 'pt', 'content' => 'Posso decidir a ordem e o método do meu trabalho'],
            ['lang_code' => 'es', 'content' => 'Puedo decidir el orden y el método de mi trabajo'],
            ['lang_code' => 'th', 'content' => 'สามารถกำหนดลำดับและวิธีการทำงานด้วยตนเองได้'],
            ['lang_code' => 'id', 'content' => 'Saya bisa menentukan urutan dan cara kerja sendiri'],
        ]);
        $this->createOptions($q9, $optionWork1Trans, $optionWork2Trans, $optionWork3Trans, $optionWork4Trans, true);

        // Q10
        $q10 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcA->id,
            'sort_order' => 10,
            'question_number' => 'A-10',
            'anchor_point' => 15,
            'scale_id' => $sA6->id,
        ]);
        $q10->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '職場の仕事の方針に自分の意見を反映できる'],
            ['lang_code' => 'en', 'content' => 'My opinions are reflected in workplace policy'],
            ['lang_code' => 'zh-CN', 'content' => '可以在职场方针中反映自己的意见'],
            ['lang_code' => 'zh-TW', 'content' => '可以在職場方針中反映自己的意見'],
            ['lang_code' => 'vi', 'content' => 'Ý kiến của tôi được phản ánh trong chính sách làm việc'],
            ['lang_code' => 'tl', 'content' => 'Naipaparating ko ang aking opinyon sa mga patakaran sa trabaho'],
            ['lang_code' => 'pt', 'content' => 'Minhas opiniões são refletidas na política do local de trabalho'],
            ['lang_code' => 'es', 'content' => 'Mis opiniones se reflejan en la política del lugar de trabajo'],
            ['lang_code' => 'th', 'content' => 'สามารถแสดงความคิดเห็นต่อนโยบายการทำงานได้'],
            ['lang_code' => 'id', 'content' => 'Pendapat saya dapat tercermin dalam kebijakan kerja'],
        ]);
        $this->createOptions($q10, $optionWork1Trans, $optionWork2Trans, $optionWork3Trans, $optionWork4Trans, true);

        // Q11
        $q11 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcA->id,
            'sort_order' => 11,
            'question_number' => 'A-11',
            'anchor_point' => 0,
            'scale_id' => $sA7->id,
        ]);
        $q11->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '自分の技能や知識を仕事で使うことが少ない'],
            ['lang_code' => 'en', 'content' => 'My skills and knowledge are rarely used in my work'],
            ['lang_code' => 'zh-CN', 'content' => '工作中很少用到自己的技能和知识'],
            ['lang_code' => 'zh-TW', 'content' => '工作中很少用到自己的技能和知識'],
            ['lang_code' => 'vi', 'content' => 'Kỹ năng và kiến thức của tôi ít được sử dụng trong công việc'],
            ['lang_code' => 'tl', 'content' => 'Bihirang magamit ang aking kasanayan at kaalaman sa trabaho'],
            ['lang_code' => 'pt', 'content' => 'Minhas habilidades e conhecimentos raramente são usados no meu trabalho'],
            ['lang_code' => 'es', 'content' => 'Mis habilidades y conocimientos rara vez se utilizan en mi trabajo'],
            ['lang_code' => 'th', 'content' => 'ไม่ค่อยได้ใช้ทักษะและความรู้ในการทำงาน'],
            ['lang_code' => 'id', 'content' => 'Keterampilan dan pengetahuan saya jarang digunakan dalam pekerjaan'],
        ]);
        $this->createOptions($q11, $optionWork1Trans, $optionWork2Trans, $optionWork3Trans, $optionWork4Trans);

        // Q12
        $q12 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcA->id,
            'sort_order' => 12,
            'question_number' => 'A-12',
            'anchor_point' => 10,
            'scale_id' => $sA4->id,
        ]);
        $q12->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '私の部署内で意見の食い違いがある'],
            ['lang_code' => 'en', 'content' => 'There are differences of opinion within my department'],
            ['lang_code' => 'zh-CN', 'content' => '部门内部意见不一'],
            ['lang_code' => 'zh-TW', 'content' => '部門內部意見不一'],
            ['lang_code' => 'vi', 'content' => 'Có sự bất đồng ý kiến trong bộ phận của tôi'],
            ['lang_code' => 'tl', 'content' => 'May hindi pagkakasundo sa opinyon sa loob ng aking departamento'],
            ['lang_code' => 'pt', 'content' => 'Há diferenças de opinião dentro do meu departamento'],
            ['lang_code' => 'es', 'content' => 'Hay diferencias de opinión dentro de mi departamento'],
            ['lang_code' => 'th', 'content' => 'มีความคิดเห็นไม่ตรงกันในแผนก'],
            ['lang_code' => 'id', 'content' => 'Terdapat perbedaan pendapat di dalam departemen saya'],
        ]);
        $this->createOptions($q12, $optionWork1Trans, $optionWork2Trans, $optionWork3Trans, $optionWork4Trans, true);

        // Q13
        $q13 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcA->id,
            'sort_order' => 13,
            'question_number' => 'A-13',
            'anchor_point' => 10,
            'scale_id' => $sA4->id,
        ]);
        $q13->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '私の部署と他の部署とはうまが合わない'],
            ['lang_code' => 'en', 'content' => 'My department does not get along well with other departments'],
            ['lang_code' => 'zh-CN', 'content' => '我的部门与其他部门合不来'],
            ['lang_code' => 'zh-TW', 'content' => '我的部門與其他部門合不來'],
            ['lang_code' => 'vi', 'content' => 'Bộ phận của tôi không hòa hợp với các bộ phận khác'],
            ['lang_code' => 'tl', 'content' => 'Hindi magkasundo ang aking departamento at ibang departamento'],
            ['lang_code' => 'pt', 'content' => 'Meu departamento não se dá bem com outros departamentos'],
            ['lang_code' => 'es', 'content' => 'Mi departamento no se lleva bien con otros departamentos'],
            ['lang_code' => 'th', 'content' => 'แผนกของฉันเข้ากับแผนกอื่นไม่ได้'],
            ['lang_code' => 'id', 'content' => 'Departemen saya tidak akur dengan departemen lain'],
        ]);
        $this->createOptions($q13, $optionWork1Trans, $optionWork2Trans, $optionWork3Trans, $optionWork4Trans, true);

        // Q14
        $q14 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcA->id,
            'sort_order' => 14,
            'question_number' => 'A-14',
            'anchor_point' => 10,
            'scale_id' => $sA4->id,
        ]);
        $q14->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '私の職場の雰囲気は友好的である'],
            ['lang_code' => 'en', 'content' => 'The atmosphere in my workplace is friendly'],
            ['lang_code' => 'zh-CN', 'content' => '职场氛围很友好'],
            ['lang_code' => 'zh-TW', 'content' => '職場氛圍很友好'],
            ['lang_code' => 'vi', 'content' => 'Bầu không khí tại nơi làm việc của tôi thân thiện'],
            ['lang_code' => 'tl', 'content' => 'Palakaibigan ang kapaligiran sa aking trabaho'],
            ['lang_code' => 'pt', 'content' => 'O ambiente no meu local de trabalho é amigável'],
            ['lang_code' => 'es', 'content' => 'El ambiente en mi lugar de trabajo es amistoso'],
            ['lang_code' => 'th', 'content' => 'บรรยากาศในที่ทำงานเป็นมิตร'],
            ['lang_code' => 'id', 'content' => 'Suasana di tempat kerja saya bersahabat'],
        ]);
        $this->createOptions($q14, $optionWork1Trans, $optionWork2Trans, $optionWork3Trans, $optionWork4Trans);

        // Q15
        $q15 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcA->id,
            'sort_order' => 15,
            'question_number' => 'A-15',
            'anchor_point' => 5,
            'scale_id' => $sA5->id,
        ]);
        $q15->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '私の職場の作業環境(騒音、照明、温度、換気など)は良くない'],
            ['lang_code' => 'en', 'content' => 'The physical environment of my workplace (noise, lighting, temperature, ventilation, etc.) is not good'],
            ['lang_code' => 'zh-CN', 'content' => '职场的作业环境（噪音、照明、温度、通风等）不好'],
            ['lang_code' => 'zh-TW', 'content' => '職場的作業環境（噪音、照明、溫度、通風等）不好'],
            ['lang_code' => 'vi', 'content' => 'Môi trường làm việc (tiếng ồn, ánh sáng, nhiệt độ, thông gió...) không tốt'],
            ['lang_code' => 'tl', 'content' => 'Hindi maganda ang kapaligiran sa trabaho (ingay, ilaw, temperatura, bentilasyon, atbp.)'],
            ['lang_code' => 'pt', 'content' => 'O ambiente físico do meu local de trabalho (ruído, iluminação, temperatura, ventilação, etc.) não é bom'],
            ['lang_code' => 'es', 'content' => 'El entorno físico de mi lugar de trabajo (ruido, iluminación, temperatura, ventilación, etc.) no es bueno'],
            ['lang_code' => 'th', 'content' => 'สภาพแวดล้อมในการทำงาน (เสียง แสง อุณหภูมิ การระบายอากาศ ฯลฯ) ไม่ดี'],
            ['lang_code' => 'id', 'content' => 'Lingkungan kerja saya (kebisingan, pencahayaan, suhu, ventilasi, dll.) tidak baik'],
        ]);
        $this->createOptions($q15, $optionWork1Trans, $optionWork2Trans, $optionWork3Trans, $optionWork4Trans, true);

        // Q16
        $q16 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcA->id,
            'sort_order' => 16,
            'question_number' => 'A-16',
            'anchor_point' => 5,
            'scale_id' => $sA8->id,
        ]);
        $q16->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '仕事の内容は自分にあっている'],
            ['lang_code' => 'en', 'content' => 'The content of the work suits me'],
            ['lang_code' => 'zh-CN', 'content' => '工作内容适合我'],
            ['lang_code' => 'zh-TW', 'content' => '工作內容適合我'],
            ['lang_code' => 'vi', 'content' => 'Nội dung công việc phù hợp với tôi'],
            ['lang_code' => 'tl', 'content' => 'Angkop sa akin ang nilalaman ng trabaho'],
            ['lang_code' => 'pt', 'content' => 'O conteúdo do trabalho é adequado para mim'],
            ['lang_code' => 'es', 'content' => 'El contenido del trabajo es adecuado para mí'],
            ['lang_code' => 'th', 'content' => 'เนื้อหางานเหมาะสมกับฉัน'],
            ['lang_code' => 'id', 'content' => 'Isi pekerjaan sesuai dengan saya'],
        ]);
        $this->createOptions($q16, $optionWork1Trans, $optionWork2Trans, $optionWork3Trans, $optionWork4Trans, true);

        // Q17
        $q17 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcA->id,
            'sort_order' => 17,
            'question_number' => 'A-17',
            'anchor_point' => 5,
            'scale_id' => $sA9->id,
        ]);
        $q17->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '働きがいのある仕事だ'],
            ['lang_code' => 'en', 'content' => 'My work is worthwhile'],
            ['lang_code' => 'zh-CN', 'content' => '这是一份有价值的工作'],
            ['lang_code' => 'zh-TW', 'content' => '這是一份有價值的工作'],
            ['lang_code' => 'vi', 'content' => 'Đây là công việc đáng làm'],
            ['lang_code' => 'tl', 'content' => 'May saysay ang trabahong ito'],
            ['lang_code' => 'pt', 'content' => 'Meu trabalho vale a pena'],
            ['lang_code' => 'es', 'content' => 'Mi trabajo vale la pena'],
            ['lang_code' => 'th', 'content' => 'เป็นงานที่คุ้มค่า'],
            ['lang_code' => 'id', 'content' => 'Ini adalah pekerjaan yang berharga'],
        ]);
        $this->createOptions($q17, $optionWork1Trans, $optionWork2Trans, $optionWork3Trans, $optionWork4Trans, true);

        // Q18
        $q18 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 18,
            'question_number' => 'B-1',
            'anchor_point' => 0,
            'scale_id' => $sB1->id,
        ]);
        $q18->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '活気がわいてくる'],
            ['lang_code' => 'en', 'content' => 'I have been very active'],
            ['lang_code' => 'zh-CN', 'content' => '充满活力'],
            ['lang_code' => 'zh-TW', 'content' => '充滿活力'],
            ['lang_code' => 'vi', 'content' => 'Tôi cảm thấy tràn đầy sức sống'],
            ['lang_code' => 'tl', 'content' => 'Pakiramdam ko ay masigla ako'],
            ['lang_code' => 'pt', 'content' => 'Tenho estado muito ativo'],
            ['lang_code' => 'es', 'content' => 'He estado muy activo'],
            ['lang_code' => 'th', 'content' => 'รู้สึกกระปรี้กระเปร่า'],
            ['lang_code' => 'id', 'content' => 'Saya merasa bersemangat'],
        ]);
        $this->createOptions($q18, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q19
        $q19 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 19,
            'question_number' => 'B-2',
            'anchor_point' => 0,
            'scale_id' => $sB1->id,
        ]);
        $q19->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '元気がいっぱいだ'],
            ['lang_code' => 'en', 'content' => 'I have been full of energy'],
            ['lang_code' => 'zh-CN', 'content' => '精力充沛'],
            ['lang_code' => 'zh-TW', 'content' => '精力充沛'],
            ['lang_code' => 'vi', 'content' => 'Tôi tràn đầy năng lượng'],
            ['lang_code' => 'tl', 'content' => 'Puno ako ng lakas'],
            ['lang_code' => 'pt', 'content' => 'Tenho estado cheio de energia'],
            ['lang_code' => 'es', 'content' => 'He estado lleno de energía'],
            ['lang_code' => 'th', 'content' => 'เต็มไปด้วยพลัง'],
            ['lang_code' => 'id', 'content' => 'Saya penuh energi'],
        ]);
        $this->createOptions($q19, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q20
        $q20 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 20,
            'question_number' => 'B-3',
            'anchor_point' => 0,
            'scale_id' => $sB1->id,
        ]);
        $q20->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '生き生きする'],
            ['lang_code' => 'en', 'content' => 'I have been lively'],
            ['lang_code' => 'zh-CN', 'content' => '生机勃勃'],
            ['lang_code' => 'zh-TW', 'content' => '生機勃勃'],
            ['lang_code' => 'vi', 'content' => 'Tôi cảm thấy tươi vui'],
            ['lang_code' => 'tl', 'content' => 'Pakiramdam ko ay buhay na buhay ako'],
            ['lang_code' => 'pt', 'content' => 'Tenho estado animado'],
            ['lang_code' => 'es', 'content' => 'He estado animado'],
            ['lang_code' => 'th', 'content' => 'รู้สึกมีชีวิตชีวา'],
            ['lang_code' => 'id', 'content' => 'Saya merasa hidup'],
        ]);
        $this->createOptions($q20, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q21
        $q21 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 21,
            'question_number' => 'B-4',
            'anchor_point' => 0,
            'scale_id' => $sB2->id,
        ]);
        $q21->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '怒りを感じる'],
            ['lang_code' => 'en', 'content' => 'I have felt angry'],
            ['lang_code' => 'zh-CN', 'content' => '感到愤怒'],
            ['lang_code' => 'zh-TW', 'content' => '感到憤怒'],
            ['lang_code' => 'vi', 'content' => 'Tôi cảm thấy tức giận'],
            ['lang_code' => 'tl', 'content' => 'Nakaramdam ako ng galit'],
            ['lang_code' => 'pt', 'content' => 'Tenho me sentido com raiva'],
            ['lang_code' => 'es', 'content' => 'Me he sentido enojado'],
            ['lang_code' => 'th', 'content' => 'รู้สึกโกรธ'],
            ['lang_code' => 'id', 'content' => 'Saya merasa marah'],
        ]);
        $this->createOptions($q21, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q22
        $q22 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 22,
            'question_number' => 'B-5',
            'anchor_point' => 0,
            'scale_id' => $sB2->id,
        ]);
        $q22->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '内心腹立たしい'],
            ['lang_code' => 'en', 'content' => 'I have been inwardly annoyed or aggravated'],
            ['lang_code' => 'zh-CN', 'content' => '内心感到气愤'],
            ['lang_code' => 'zh-TW', 'content' => '內心感到氣憤'],
            ['lang_code' => 'vi', 'content' => 'Tôi cảm thấy bực bội trong lòng'],
            ['lang_code' => 'tl', 'content' => 'Naiinis ako sa loob ko'],
            ['lang_code' => 'pt', 'content' => 'Tenho me sentido interiormente aborrecido ou irritado'],
            ['lang_code' => 'es', 'content' => 'Me he sentido interiormente molesto o agraviado'],
            ['lang_code' => 'th', 'content' => 'รู้สึกหงุดหงิดในใจ'],
            ['lang_code' => 'id', 'content' => 'Saya merasa kesal trong hati'],
        ]);
        $this->createOptions($q22, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q23
        $q23 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 23,
            'question_number' => 'B-6',
            'anchor_point' => 0,
            'scale_id' => $sB2->id,
        ]);
        $q23->translations()->createMany([
            ['lang_code' => 'ja', 'content' => 'イライラしている'],
            ['lang_code' => 'en', 'content' => 'I have felt irritable'],
            ['lang_code' => 'zh-CN', 'content' => '感到焦躁不安'],
            ['lang_code' => 'zh-TW', 'content' => '感到焦躁不安'],
            ['lang_code' => 'vi', 'content' => 'Tôi cảm thấy cáu kỉnh'],
            ['lang_code' => 'tl', 'content' => 'Naiirita ako'],
            ['lang_code' => 'pt', 'content' => 'Tenho me sentido irritável'],
            ['lang_code' => 'es', 'content' => 'Me he sentido irritable'],
            ['lang_code' => 'th', 'content' => 'รู้สึกหงุดหงิด'],
            ['lang_code' => 'id', 'content' => 'Saya merasa uring-uringan'],
        ]);
        $this->createOptions($q23, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        $q24 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 24,
            'question_number' => 'B-7',
            'anchor_point' => 0,
            'scale_id' => $sB3->id,
        ]);
        $q24->translations()->createMany([
            ['lang_code' => 'ja', 'content' => 'ひどく疲れた'],
            ['lang_code' => 'en', 'content' => 'I have felt extremely tired'],
            ['lang_code' => 'zh-CN', 'content' => '非常疲倦'],
            ['lang_code' => 'zh-TW', 'content' => '非常疲倦'],
            ['lang_code' => 'vi', 'content' => 'Tôi cảm thấy cực kỳ mệt mỏi'],
            ['lang_code' => 'tl', 'content' => 'Pagod na pagod ako'],
            ['lang_code' => 'pt', 'content' => 'Tenho me sentido extremamente cansado'],
            ['lang_code' => 'es', 'content' => 'Me he sentido extremadamente cansado'],
            ['lang_code' => 'th', 'content' => 'รู้สึกเหนื่อยมาก'],
            ['lang_code' => 'id', 'content' => 'Saya merasa rất lelah'],
        ]);
        $this->createOptions($q24, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q25
        $q25 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 25,
            'question_number' => 'B-8',
            'anchor_point' => 0,
            'scale_id' => $sB3->id,
        ]);
        $q25->translations()->createMany([
            ['lang_code' => 'ja', 'content' => 'へとへとだ'],
            ['lang_code' => 'en', 'content' => 'I have felt exhausted'],
            ['lang_code' => 'zh-CN', 'content' => '筋疲力尽'],
            ['lang_code' => 'zh-TW', 'content' => '筋疲力盡'],
            ['lang_code' => 'vi', 'content' => 'Tôi cảm thấy kiệt sức'],
            ['lang_code' => 'tl', 'content' => 'Lupaypay na ako'],
            ['lang_code' => 'pt', 'content' => 'Tenho me sentido exausto'],
            ['lang_code' => 'es', 'content' => 'Me he sentido agotado'],
            ['lang_code' => 'th', 'content' => 'รู้สึกหมดแรง'],
            ['lang_code' => 'id', 'content' => 'Saya merasa kehabisan tenaga'],
        ]);
        $this->createOptions($q25, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q26
        $q26 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 26,
            'question_number' => 'B-9',
            'anchor_point' => 0,
            'scale_id' => $sB3->id,
        ]);
        $q26->translations()->createMany([
            ['lang_code' => 'ja', 'content' => 'だるい'],
            ['lang_code' => 'en', 'content' => 'I have felt weary or listless'],
            ['lang_code' => 'zh-CN', 'content' => '身体倦怠'],
            ['lang_code' => 'zh-TW', 'content' => '身體倦怠'],
            ['lang_code' => 'vi', 'content' => 'Tôi cảm thấy uể oải'],
            ['lang_code' => 'tl', 'content' => 'Matamlay ako'],
            ['lang_code' => 'pt', 'content' => 'Tenho me sentido cansado ou apático'],
            ['lang_code' => 'es', 'content' => 'Me he sentido cansado o apático'],
            ['lang_code' => 'th', 'content' => 'รู้สึกอ่อนเพลีย'],
            ['lang_code' => 'id', 'content' => 'Saya merasa lesu'],
        ]);
        $this->createOptions($q26, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q27
        $q27 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 27,
            'question_number' => 'B-10',
            'anchor_point' => 0,
            'scale_id' => $sB4->id,
        ]);
        $q27->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '気がはりつめている'],
            ['lang_code' => 'en', 'content' => 'I have felt tense'],
            ['lang_code' => 'zh-CN', 'content' => '精神紧张'],
            ['lang_code' => 'zh-TW', 'content' => '精神緊張'],
            ['lang_code' => 'vi', 'content' => 'Tôi cảm thấy căng thẳng'],
            ['lang_code' => 'tl', 'content' => 'Tensyonado ako'],
            ['lang_code' => 'pt', 'content' => 'Tenho me sentido tenso'],
            ['lang_code' => 'es', 'content' => 'Me he sentido tenso'],
            ['lang_code' => 'th', 'content' => 'รู้สึกตึงเครียด'],
            ['lang_code' => 'id', 'content' => 'Saya merasa tegang'],
        ]);
        $this->createOptions($q27, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q28
        $q28 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 28,
            'question_number' => 'B-11',
            'anchor_point' => 0,
            'scale_id' => $sB4->id,
        ]);
        $q28->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '不安だ'],
            ['lang_code' => 'en', 'content' => 'I have felt worried or insecure'],
            ['lang_code' => 'zh-CN', 'content' => '感到不安'],
            ['lang_code' => 'zh-TW', 'content' => '感到不安'],
            ['lang_code' => 'vi', 'content' => 'Tôi cảm thấy lo lắng'],
            ['lang_code' => 'tl', 'content' => 'Nag-aalala ako'],
            ['lang_code' => 'pt', 'content' => 'Tenho me sentido preocupado ou inseguro'],
            ['lang_code' => 'es', 'content' => 'Me he sentido preocupado o inseguro'],
            ['lang_code' => 'th', 'content' => 'รู้สึกกังวล'],
            ['lang_code' => 'id', 'content' => 'Saya merasa cemas'],
        ]);
        $this->createOptions($q28, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q29
        $q29 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 29,
            'question_number' => 'B-12',
            'anchor_point' => 0,
            'scale_id' => $sB4->id,
        ]);
        $q29->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '落ち着かない'],
            ['lang_code' => 'en', 'content' => 'I have felt restless'],
            ['lang_code' => 'zh-CN', 'content' => '心神不宁'],
            ['lang_code' => 'zh-TW', 'content' => '心神不寧'],
            ['lang_code' => 'vi', 'content' => 'Tôi cảm thấy bồn chồn'],
            ['lang_code' => 'tl', 'content' => 'Hindi ako mapakali'],
            ['lang_code' => 'pt', 'content' => 'Tenho me sentido inquieto'],
            ['lang_code' => 'es', 'content' => 'Me he sentido inquieto'],
            ['lang_code' => 'th', 'content' => 'รู้สึกกระสับกระส่าย'],
            ['lang_code' => 'id', 'content' => 'Saya merasa gelisah'],
        ]);
        $this->createOptions($q29, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q30
        $q30 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 30,
            'question_number' => 'B-13',
            'anchor_point' => 0,
            'scale_id' => $sB5->id,
        ]);
        $q30->translations()->createMany([
            ['lang_code' => 'ja', 'content' => 'ゆううつだ'],
            ['lang_code' => 'en', 'content' => 'I have been depressed'],
            ['lang_code' => 'zh-CN', 'content' => '忧郁'],
            ['lang_code' => 'zh-TW', 'content' => '憂鬱'],
            ['lang_code' => 'vi', 'content' => 'Tôi cảm thấy chán nản'],
            ['lang_code' => 'tl', 'content' => 'Na-dedepress ako'],
            ['lang_code' => 'pt', 'content' => 'Tenho estado deprimido'],
            ['lang_code' => 'es', 'content' => 'He estado deprimido'],
            ['lang_code' => 'th', 'content' => 'รู้สึกซึมเศร้า'],
            ['lang_code' => 'id', 'content' => 'Saya merasa depresi'],
        ]);
        $this->createOptions($q30, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q31
        $q31 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 31,
            'question_number' => 'B-14',
            'anchor_point' => 0,
            'scale_id' => $sB5->id,
        ]);
        $q31->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '何をするのも面倒だ'],
            ['lang_code' => 'en', 'content' => 'I have thought that doing anything was a hassle'],
            ['lang_code' => 'zh-CN', 'content' => '做任何事都觉得麻烦'],
            ['lang_code' => 'zh-TW', 'content' => '做任何事都覺得麻煩'],
            ['lang_code' => 'vi', 'content' => 'Tôi thấy làm gì cũng phiền phức'],
            ['lang_code' => 'tl', 'content' => 'Pakiramdam ko ay abala ang paggawa ng kahit ano'],
            ['lang_code' => 'pt', 'content' => 'Tenho achado que fazer qualquer coisa é um incômodo'],
            ['lang_code' => 'es', 'content' => 'He pensado que hacer cualquier cosa era una molestia'],
            ['lang_code' => 'th', 'content' => 'รู้สึกไมยากทำอะไรเลย'],
            ['lang_code' => 'id', 'content' => 'Saya merasa malas melakukan apa pun'],
        ]);
        $this->createOptions($q31, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q32
        $q32 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 32,
            'question_number' => 'B-15',
            'anchor_point' => 0,
            'scale_id' => $sB5->id,
        ]);
        $q32->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '物事に集中できない'],
            ['lang_code' => 'en', 'content' => 'I have been unable to concentrate'],
            ['lang_code' => 'zh-CN', 'content' => '无法集中精力'],
            ['lang_code' => 'zh-TW', 'content' => '無法集中精力'],
            ['lang_code' => 'vi', 'content' => 'Tôi không thể tập trung'],
            ['lang_code' => 'tl', 'content' => 'Hindi ako makapag-focus'],
            ['lang_code' => 'pt', 'content' => 'Não tenho conseguido me concentrar'],
            ['lang_code' => 'es', 'content' => 'No he podido concentrarme'],
            ['lang_code' => 'th', 'content' => 'ไม่มีสมาธิ'],
            ['lang_code' => 'id', 'content' => 'Saya không bisa berkonsentrasi'],
        ]);
        $this->createOptions($q32, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q33
        $q33 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 33,
            'question_number' => 'B-16',
            'anchor_point' => 0,
            'scale_id' => $sB5->id,
        ]);
        $q33->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '気分が晴れない'],
            ['lang_code' => 'en', 'content' => 'I have felt gloomy'],
            ['lang_code' => 'zh-CN', 'content' => '心情不舒畅'],
            ['lang_code' => 'zh-TW', 'content' => '心情不舒暢'],
            ['lang_code' => 'vi', 'content' => 'Tôi cảm thấy u ám'],
            ['lang_code' => 'tl', 'content' => 'Hindi maaliwalas ang pakiramdam ko'],
            ['lang_code' => 'pt', 'content' => 'Tenho me sentido sombrio'],
            ['lang_code' => 'es', 'content' => 'Me he sentido triste'],
            ['lang_code' => 'th', 'content' => 'รู้สึกไม่สดชื่น'],
            ['lang_code' => 'id', 'content' => 'Perasaan saya không tươi'],
        ]);
        $this->createOptions($q33, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q34
        $q34 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 34,
            'question_number' => 'B-17',
            'anchor_point' => 0,
            'scale_id' => $sB5->id,
        ]);
        $q34->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '仕事が手につかない'],
            ['lang_code' => 'en', 'content' => 'I have been unable to handle work'],
            ['lang_code' => 'zh-CN', 'content' => '无心工作'],
            ['lang_code' => 'zh-TW', 'content' => '無心工作'],
            ['lang_code' => 'vi', 'content' => 'Tôi không thể bắt tay vào công việc'],
            ['lang_code' => 'tl', 'content' => 'Hindi ko magawa ang trabaho'],
            ['lang_code' => 'pt', 'content' => 'Não tenho conseguido lidar com o trabalho'],
            ['lang_code' => 'es', 'content' => 'No he podido manejar el trabajo'],
            ['lang_code' => 'th', 'content' => 'ทำงานไม่ได้'],
            ['lang_code' => 'id', 'content' => 'Saya không bisa menangani pekerjaan'],
        ]);
        $this->createOptions($q34, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q35
        $q35 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 35,
            'question_number' => 'B-18',
            'anchor_point' => 0,
            'scale_id' => $sB5->id,
        ]);
        $q35->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '悲しいと感じる'],
            ['lang_code' => 'en', 'content' => 'I have felt sad'],
            ['lang_code' => 'zh-CN', 'content' => '感到悲伤'],
            ['lang_code' => 'zh-TW', 'content' => '感到悲傷'],
            ['lang_code' => 'vi', 'content' => 'Tôi cảm thấy buồn'],
            ['lang_code' => 'tl', 'content' => 'Nalulungkot ako'],
            ['lang_code' => 'pt', 'content' => 'Tenho me sentido triste'],
            ['lang_code' => 'es', 'content' => 'Me he sentido triste'],
            ['lang_code' => 'th', 'content' => 'รู้สึกเศร้า'],
            ['lang_code' => 'id', 'content' => 'Saya merasa sedih'],
        ]);
        $this->createOptions($q35, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q36
        $q36 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 36,
            'question_number' => 'B-19',
            'anchor_point' => 0,
            'scale_id' => $sB6->id,
        ]);
        $q36->translations()->createMany([
            ['lang_code' => 'ja', 'content' => 'めまいがする'],
            ['lang_code' => 'en', 'content' => 'I have felt dizzy'],
            ['lang_code' => 'zh-CN', 'content' => '感到眩晕'],
            ['lang_code' => 'zh-TW', 'content' => '感到眩暈'],
            ['lang_code' => 'vi', 'content' => 'Tôi cảm thấy chóng mặt'],
            ['lang_code' => 'tl', 'content' => 'Nahihilo ako'],
            ['lang_code' => 'pt', 'content' => 'Tenho me sentido tonto'],
            ['lang_code' => 'es', 'content' => 'Me he sentido mareado'],
            ['lang_code' => 'th', 'content' => 'รู้สึกเวียนหัว'],
            ['lang_code' => 'id', 'content' => 'Saya merasa pusing'],
        ]);
        $this->createOptions($q36, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q37
        $q37 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 37,
            'question_number' => 'B-20',
            'anchor_point' => 0,
            'scale_id' => $sB6->id,
        ]);
        $q37->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '体のふしぶしが痛む'],
            ['lang_code' => 'en', 'content' => 'I have experienced joint pains'],
            ['lang_code' => 'zh-CN', 'content' => '关节疼痛'],
            ['lang_code' => 'zh-TW', 'content' => '關節疼痛'],
            ['lang_code' => 'vi', 'content' => 'Tôi bị đau nhức xương khớp'],
            ['lang_code' => 'tl', 'content' => 'Sumasakit ang mga kasu-kasuan ko'],
            ['lang_code' => 'pt', 'content' => 'Tenho sentido dores nas articulações'],
            ['lang_code' => 'es', 'content' => 'He experimentado dolores articulares'],
            ['lang_code' => 'th', 'content' => 'รู้สึกปวดตามข้อ'],
            ['lang_code' => 'id', 'content' => 'Saya merasa nyeri sendi'],
        ]);
        $this->createOptions($q37, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q38
        $q38 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 38,
            'question_number' => 'B-21',
            'anchor_point' => 0,
            'scale_id' => $sB6->id,
        ]);
        $q38->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '頭が重かったり頭痛がする'],
            ['lang_code' => 'en', 'content' => 'I have had headaches or a heavy head'],
            ['lang_code' => 'zh-CN', 'content' => '头重或头痛'],
            ['lang_code' => 'zh-TW', 'content' => '頭重或頭痛'],
            ['lang_code' => 'vi', 'content' => 'Tôi cảm thấy nặng đầu hoặc đau đầu'],
            ['lang_code' => 'tl', 'content' => 'Mabigat ang ulo ko o sumasakit ang ulo ko'],
            ['lang_code' => 'pt', 'content' => 'Tenho tido dores de cabeça ou sensação de cabeça pesada'],
            ['lang_code' => 'es', 'content' => 'He tenido dolores de cabeza o pesadez en la cabeza'],
            ['lang_code' => 'th', 'content' => 'รู้สึกหนักหัวหรือปวดหัว'],
            ['lang_code' => 'id', 'content' => 'Saya merasa berat kepala atau sakit kepala'],
        ]);
        $this->createOptions($q38, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q39
        $q39 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 39,
            'question_number' => 'B-22',
            'anchor_point' => 0,
            'scale_id' => $sB6->id,
        ]);
        $q39->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '首筋や肩がこる'],
            ['lang_code' => 'en', 'content' => 'I have had a stiff neck or shoulders'],
            ['lang_code' => 'zh-CN', 'content' => '脖子或肩膀酸痛'],
            ['lang_code' => 'zh-TW', 'content' => '脖子或肩膀痠痛'],
            ['lang_code' => 'vi', 'content' => 'Tôi bị cứng cổ hoặc vai'],
            ['lang_code' => 'tl', 'content' => 'Naninigas ang leeg o balikat ko'],
            ['lang_code' => 'pt', 'content' => 'Tenho tido rigidez no pescoço ou nos ombros'],
            ['lang_code' => 'es', 'content' => 'He tenido rigidez en el cuello o los hombros'],
            ['lang_code' => 'th', 'content' => 'รู้สึกปวดตึงที่คอหรือไหล่'],
            ['lang_code' => 'id', 'content' => 'Leher atau bahu saya terasa kaku'],
        ]);
        $this->createOptions($q39, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q40
        $q40 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 40,
            'question_number' => 'B-23',
            'anchor_point' => 0,
            'scale_id' => $sB6->id,
        ]);
        $q40->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '腰が痛い'],
            ['lang_code' => 'en', 'content' => 'I have had lower back pain'],
            ['lang_code' => 'zh-CN', 'content' => '腰痛'],
            ['lang_code' => 'zh-TW', 'content' => '腰痛'],
            ['lang_code' => 'vi', 'content' => 'Tôi bị đau thắt lưng'],
            ['lang_code' => 'tl', 'content' => 'Sumasakit ang likod ko'],
            ['lang_code' => 'pt', 'content' => 'Tenho tido dor lombar'],
            ['lang_code' => 'es', 'content' => 'He tenido dolor lumbar'],
            ['lang_code' => 'th', 'content' => 'รู้สึกปวดเอว'],
            ['lang_code' => 'id', 'content' => 'Pinggang saya sakit'],
        ]);
        $this->createOptions($q40, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q41
        $q41 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 41,
            'question_number' => 'B24',
            'anchor_point' => 0,
            'scale_id' => $sB6->id,
        ]);
        $q41->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '目が疲れる'],
            ['lang_code' => 'en', 'content' => 'I have had eye strain'],
            ['lang_code' => 'zh-CN', 'content' => '眼睛疲劳'],
            ['lang_code' => 'zh-TW', 'content' => '眼睛疲勞'],
            ['lang_code' => 'vi', 'content' => 'Tôi bị mỏi mắt'],
            ['lang_code' => 'tl', 'content' => 'Pagod ang mga mata ko'],
            ['lang_code' => 'pt', 'content' => 'Tenho tido vista cansada'],
            ['lang_code' => 'es', 'content' => 'He tenido fatiga visual'],
            ['lang_code' => 'th', 'content' => 'รู้สึกตาล้า'],
            ['lang_code' => 'id', 'content' => 'Mata saya lelah'],
        ]);
        $this->createOptions($q41, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q42
        $q42 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 42,
            'question_number' => 'B-25',
            'anchor_point' => 0,
            'scale_id' => $sB6->id,
        ]);
        $q42->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '動悸や息切れがする'],
            ['lang_code' => 'en', 'content' => 'I have experienced heart palpitations or shortness of breath'],
            ['lang_code' => 'zh-CN', 'content' => '心悸或气喘'],
            ['lang_code' => 'zh-TW', 'content' => '心悸或氣喘'],
            ['lang_code' => 'vi', 'content' => 'Tôi bị đánh trống ngực hoặc khó thở'],
            ['lang_code' => 'tl', 'content' => 'Kumakabog ang dibdib ko o kinakapos ako ng hininga'],
            ['lang_code' => 'pt', 'content' => 'Tenho sentido palpitações cardíacas ou falta de ar'],
            ['lang_code' => 'es', 'content' => 'He experimentado palpitaciones cardíacas o dificultad para respirar'],
            ['lang_code' => 'th', 'content' => 'ใจสั่นหรือหายใจติดขัด'],
            ['lang_code' => 'id', 'content' => 'Saya mengalami jantung berdebar atau sesak napas'],
        ]);
        $this->createOptions($q42, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q43
        $q43 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 43,
            'question_number' => 'B-26',
            'anchor_point' => 0,
            'scale_id' => $sB6->id,
        ]);
        $q43->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '胃腸の具合が悪い'],
            ['lang_code' => 'en', 'content' => 'I have had stomach or bowel problems'],
            ['lang_code' => 'zh-CN', 'content' => '肠胃不适'],
            ['lang_code' => 'zh-TW', 'content' => '腸胃不適'],
            ['lang_code' => 'vi', 'content' => 'Dạ dày và ruột của tôi không tốt'],
            ['lang_code' => 'tl', 'content' => 'Masama ang pakiramdam ng tiyan ko'],
            ['lang_code' => 'pt', 'content' => 'Tenho tido problemas estomacais ou intestinais'],
            ['lang_code' => 'es', 'content' => 'He tenido problemas estomacales o intestinales'],
            ['lang_code' => 'th', 'content' => 'รู้สึกไม่สบายท้อง'],
            ['lang_code' => 'id', 'content' => 'Kondisi lambung dan usus saya buruk'],
        ]);
        $this->createOptions($q43, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q44
        $q44 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 44,
            'question_number' => 'B-27',
            'anchor_point' => 0,
            'scale_id' => $sB6->id,
        ]);
        $q44->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '食欲がない'],
            ['lang_code' => 'en', 'content' => 'I have lost my appetite'],
            ['lang_code' => 'zh-CN', 'content' => '没有食欲'],
            ['lang_code' => 'zh-TW', 'content' => '沒有食慾'],
            ['lang_code' => 'vi', 'content' => 'Tôi không muốn ăn'],
            ['lang_code' => 'tl', 'content' => 'Wala akong ganang kumain'],
            ['lang_code' => 'pt', 'content' => 'Perdi o apetite'],
            ['lang_code' => 'es', 'content' => 'He perdido el apetito'],
            ['lang_code' => 'th', 'content' => 'เบื่ออาหาร'],
            ['lang_code' => 'id', 'content' => 'Saya tidak nafsu makan'],
        ]);
        $this->createOptions($q44, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q45
        $q45 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 45,
            'question_number' => 'B-28',
            'anchor_point' => 0,
            'scale_id' => $sB6->id,
        ]);
        $q45->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '便秘や下痢をする'],
            ['lang_code' => 'en', 'content' => 'I have had constipation or diarrhea'],
            ['lang_code' => 'zh-CN', 'content' => '便秘或腹泻'],
            ['lang_code' => 'zh-TW', 'content' => '便秘或腹瀉'],
            ['lang_code' => 'vi', 'content' => 'Tôi bị táo bón hoặc tiêu chảy'],
            ['lang_code' => 'tl', 'content' => 'Nagtitibi ako o nagtatae'],
            ['lang_code' => 'pt', 'content' => 'Tenho tido constipação ou diarreia'],
            ['lang_code' => 'es', 'content' => 'He tenido estreñimiento o diarrea'],
            ['lang_code' => 'th', 'content' => 'ท้องผูกหรือท้องเสีย'],
            ['lang_code' => 'id', 'content' => 'Saya mengalami sembelit atau diare'],
        ]);
        $this->createOptions($q45, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q46
        $q46 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcB->id,
            'sort_order' => 46,
            'question_number' => 'B-29',
            'anchor_point' => 0,
            'scale_id' => $sB6->id,
        ]);
        $q46->translations()->createMany([
            ['lang_code' => 'ja', 'content' => 'よく眠れない'],
            ['lang_code' => 'en', 'content' => 'I have not been able to sleep well'],
            ['lang_code' => 'zh-CN', 'content' => '睡不好'],
            ['lang_code' => 'zh-TW', 'content' => '睡不好'],
            ['lang_code' => 'vi', 'content' => 'Tôi ngủ không ngon'],
            ['lang_code' => 'tl', 'content' => 'Hindi ako makatulog nang maayos'],
            ['lang_code' => 'pt', 'content' => 'Não tenho conseguido dormir bem'],
            ['lang_code' => 'es', 'content' => 'No he podido dormir bien'],
            ['lang_code' => 'th', 'content' => 'นอนไม่ค่อยหลับ'],
            ['lang_code' => 'id', 'content' => 'Saya không bisa tidur nyenyak'],
        ]);
        $this->createOptions($q46, $optionState1Trans, $optionState2Trans, $optionState3Trans, $optionState4Trans);

        // Q47
        $q47 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcC->id,
            'sort_order' => 47,
            'question_number' => 'C-1',
            'anchor_point' => 15,
            'scale_id' => $sC1->id,
        ]);
        $q47->translations()->createMany([
            ['lang_code' => 'ja', 'content' => 'どのくらい上司に、気軽に話ができますか？'],
            ['lang_code' => 'en', 'content' => 'How freely can you talk with your superiors?'],
            ['lang_code' => 'zh-CN', 'content' => '能多大程度轻松地与上司交谈？'],
            ['lang_code' => 'zh-TW', 'content' => '能多大程度輕鬆地與上司交談？'],
            ['lang_code' => 'vi', 'content' => 'Bạn có thể nói chuyện thoải mái với cấp trên đến mức nào?'],
            ['lang_code' => 'tl', 'content' => 'Gaano ka malayang nakakapag-usap sa iyong boss?'],
            ['lang_code' => 'pt', 'content' => 'Com que liberdade você pode falar com seus superiores?'],
            ['lang_code' => 'es', 'content' => '¿Con qué libertad puede hablar con sus superiores?'],
            ['lang_code' => 'th', 'content' => 'คุยกับหัวหน้าได้อย่างสบายใจแค่ไหน'],
            ['lang_code' => 'id', 'content' => 'Seberapa leluasa Anda bisa berbicara dengan atasan?'],
        ]);
        $this->createOptions($q47, $optionSupport1Trans, $optionSupport2Trans, $optionSupport3Trans, $optionSupport4Trans, true);

        // Q48
        $q48 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcC->id,
            'sort_order' => 48,
            'question_number' => 'C-2',
            'anchor_point' => 15,
            'scale_id' => $sC2->id,
        ]);
        $q48->translations()->createMany([
            ['lang_code' => 'ja', 'content' => 'どのくらい職場の同僚に、気軽に話ができますか？'],
            ['lang_code' => 'en', 'content' => 'How freely can you talk with your co-workers?'],
            ['lang_code' => 'zh-CN', 'content' => '能多大程度轻松地与同事交谈？'],
            ['lang_code' => 'zh-TW', 'content' => '能多大程度輕鬆地與同事交談？'],
            ['lang_code' => 'vi', 'content' => 'Bạn có thể nói chuyện thoải mái với đồng nghiệp đến mức nào?'],
            ['lang_code' => 'tl', 'content' => 'Gaano ka malayang nakakapag-usap sa iyong mga katrabaho?'],
            ['lang_code' => 'pt', 'content' => 'Com que liberdade você pode falar com seus colegas de trabalho?'],
            ['lang_code' => 'es', 'content' => '¿Con qué libertad puede hablar con sus compañeros de trabajo?'],
            ['lang_code' => 'th', 'content' => 'คุยกับเพื่อนร่วมงานได้อย่างสบายใจแค่ไหน'],
            ['lang_code' => 'id', 'content' => 'Seberapa leluasa Anda bisa berbicara dengan rekan kerja?'],
        ]);
        $this->createOptions($q48, $optionSupport1Trans, $optionSupport2Trans, $optionSupport3Trans, $optionSupport4Trans, true);

        // Q49
        $q49 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcC->id,
            'sort_order' => 49,
            'question_number' => 'C-3',
            'anchor_point' => 15,
            'scale_id' => $sC3->id,
        ]);
        $q49->translations()->createMany([
            ['lang_code' => 'ja', 'content' => 'どのくらい配偶者、家族、友人に気軽に話ができますか？'],
            ['lang_code' => 'en', 'content' => 'How freely can you talk with your spouse, family, friends, etc.?'],
            ['lang_code' => 'zh-CN', 'content' => '能多大程度轻松地与配偶、家人、朋友交谈？'],
            ['lang_code' => 'zh-TW', 'content' => '能多大程度輕鬆地與配偶、家人、朋友交談？'],
            ['lang_code' => 'vi', 'content' => 'Bạn có thể nói chuyện thoải mái với vợ/chồng, gia đình, bạn bè đến mức nào?'],
            ['lang_code' => 'tl', 'content' => 'Gaano ka malayang nakakapag-usap sa iyong asawa, pamilya, at kaibigan?'],
            ['lang_code' => 'pt', 'content' => 'Com que liberdade você pode falar com seu cônjuge, família, amigos, etc.?'],
            ['lang_code' => 'es', 'content' => '¿Con qué libertad puede hablar con su cónyuge, familia, amigos, etc.?'],
            ['lang_code' => 'th', 'content' => 'คุยกับคู่สมรส ครอบครัว และเพื่อนได้อย่างสบายใจแค่ไหน'],
            ['lang_code' => 'id', 'content' => 'Seberapa leluasa Anda bisa berbicara dengan pasangan, gia đình, dan teman?'],
        ]);
        $this->createOptions($q49, $optionSupport1Trans, $optionSupport2Trans, $optionSupport3Trans, $optionSupport4Trans, true);

        // Q50
        $q50 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcC->id,
            'sort_order' => 50,
            'question_number' => 'C-4',
            'anchor_point' => 15,
            'scale_id' => $sC1->id,
        ]);
        $q50->translations()->createMany([
            ['lang_code' => 'ja', 'content' => 'あなたが困った時、上司はどのくらい頼りになりますか？'],
            ['lang_code' => 'en', 'content' => 'How reliable are your superiors when you are in trouble?'],
            ['lang_code' => 'zh-CN', 'content' => '遇到困难时，上司有多可靠？'],
            ['lang_code' => 'zh-TW', 'content' => '遇到困難時，上司有多可靠？'],
            ['lang_code' => 'vi', 'content' => 'Khi bạn gặp khó khăn, cấp trên đáng tin cậy đến mức nào?'],
            ['lang_code' => 'tl', 'content' => 'Gaano ka-reliable ang iyong boss kapag may problema ka?'],
            ['lang_code' => 'pt', 'content' => 'Quão confiáveis são seus superiores quando você está com problemas?'],
            ['lang_code' => 'es', 'content' => '¿Qué tan confiables son sus superiores cuando tiene problemas?'],
            ['lang_code' => 'th', 'content' => 'เมื่อมีปัญหา พึ่งพาหัวหน้าได้แค่ไหน'],
            ['lang_code' => 'id', 'content' => 'Seberapa bisa diandalkan atasan Anda saat Anda dalam masalah?'],
        ]);
        $this->createOptions($q50, $optionSupport1Trans, $optionSupport2Trans, $optionSupport3Trans, $optionSupport4Trans, true);

        // Q51
        $q51 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcC->id,
            'sort_order' => 51,
            'question_number' => 'C-5',
            'anchor_point' => 15,
            'scale_id' => $sC2->id,
        ]);
        $q51->translations()->createMany([
            ['lang_code' => 'ja', 'content' => 'あなたが困った時、職場の同僚はどのくらい頼りになりますか？'],
            ['lang_code' => 'en', 'content' => 'How reliable are your co-workers when you are in trouble?'],
            ['lang_code' => 'zh-CN', 'content' => '遇到困难时，同事有多可靠？'],
            ['lang_code' => 'zh-TW', 'content' => '遇到困難時，同事有多可靠？'],
            ['lang_code' => 'vi', 'content' => 'Khi bạn gặp khó khăn, đồng nghiệp đáng tin cậy đến mức nào?'],
            ['lang_code' => 'tl', 'content' => 'Gaano ka-reliable ang iyong các đồng nghiệp kapag may problema ka?'],
            ['lang_code' => 'pt', 'content' => 'Quão confiáveis são seus colegas de trabalho quando você está com problemas?'],
            ['lang_code' => 'es', 'content' => '¿Qué tan confiables son sus compañeros de trabajo cuando tiene problemas?'],
            ['lang_code' => 'th', 'content' => 'เมื่อมีปัญหา พึ่งพาเพื่อนร่วมงานได้แค่ไหน'],
            ['lang_code' => 'id', 'content' => 'Seberapa bisa diandalkan rekan kerja Anda saat Anda trong masalah?'],
        ]);
        $this->createOptions($q51, $optionSupport1Trans, $optionSupport2Trans, $optionSupport3Trans, $optionSupport4Trans, true);

        // Q52
        $q52 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcC->id,
            'sort_order' => 52,
            'question_number' => 'C-6',
            'anchor_point' => 15,
            'scale_id' => $sC3->id,
        ]);
        $q52->translations()->createMany([
            ['lang_code' => 'ja', 'content' => 'あなたが困った時、配偶者、家族、友人等はどのくらい頼りになりますか？'],
            ['lang_code' => 'en', 'content' => 'How reliable are your spouse, family, friends, etc. when you are in trouble?'],
            ['lang_code' => 'zh-CN', 'content' => '遇到困难时，配偶、家人、朋友等有多可靠？'],
            ['lang_code' => 'zh-TW', 'content' => '遇到困難時，配偶、家人、朋友等有多可靠？'],
            ['lang_code' => 'vi', 'content' => 'Khi bạn gặp khó khăn, vợ/chồng, gia đình, bạn bè... đáng tin cậy đến mức nào?'],
            ['lang_code' => 'tl', 'content' => 'Gaano ka-reliable ang iyong asawa, pamilya, at kaibigan kapag may problema ka?'],
            ['lang_code' => 'pt', 'content' => 'Quão confiáveis são seu cônjuge, família, amigos, etc. quando você está com problemas?'],
            ['lang_code' => 'es', 'content' => '¿Qué tan confiables son su cónyuge, familia, amigos, etc. cuando tiene problemas?'],
            ['lang_code' => 'th', 'content' => 'เมื่อมีปัญหา พึ่งพาคู่สมรส ครอบครัว และเพื่อนได้แค่ไหน'],
            ['lang_code' => 'id', 'content' => 'Seberapa bisa diandalkan pasangan, keluarga, và bạn bè của bạn saat bạn trong vấn đề?'],
        ]);
        $this->createOptions($q52, $optionSupport1Trans, $optionSupport2Trans, $optionSupport3Trans, $optionSupport4Trans, true);

        // Q53
        $q53 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcC->id,
            'sort_order' => 53,
            'question_number' => 'C-7',
            'anchor_point' => 15,
            'scale_id' => $sC1->id,
        ]);
        $q53->translations()->createMany([
            ['lang_code' => 'ja', 'content' => 'あなたの個人的な問題を相談したら、上司はどのくらきいてくれますか？'],
            ['lang_code' => 'en', 'content' => 'How well will your superiors listen to you when you ask for advice on personal matters?'],
            ['lang_code' => 'zh-CN', 'content' => '如果商量个人问题，上司会多大程度倾听？'],
            ['lang_code' => 'zh-TW', 'content' => '如果商量個人問題，上司會多大程度傾聽？'],
            ['lang_code' => 'vi', 'content' => 'Nếu bạn thảo luận về các vấn đề cá nhân, cấp trên sẽ lắng nghe đến mức nào?'],
            ['lang_code' => 'tl', 'content' => 'Kapag nagkwento ka ng personal na problema, gaano nakikinig ang iyong boss?'],
            ['lang_code' => 'pt', 'content' => 'O quão bem seus superiores o ouvirão quando você pedir conselhos sobre assuntos pessoais?'],
            ['lang_code' => 'es', 'content' => '¿Qué tan bien lo escucharán sus superiores cuando pida consejo sobre asuntos personales?'],
            ['lang_code' => 'th', 'content' => 'ถ้าปรึกษาเรื่องส่วนตัว หัวหน้าจะรับฟังแค่ไหน'],
            ['lang_code' => 'id', 'content' => 'Seberapa baik atasan Anda sẽ lắng nghe nếu bạn thảo luận về vấn đề cá nhân?'],
        ]);
        $this->createOptions($q53, $optionSupport1Trans, $optionSupport2Trans, $optionSupport3Trans, $optionSupport4Trans, true);

        // Q54
        $q54 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcC->id,
            'sort_order' => 54,
            'question_number' => 'C-8',
            'anchor_point' => 15,
            'scale_id' => $sC2->id,
        ]);
        $q54->translations()->createMany([
            ['lang_code' => 'ja', 'content' => 'あなたの個人的な問題を相談したら、職場の同僚はどのくらいきいてくれますか？'],
            ['lang_code' => 'en', 'content' => 'How well will your co-workers listen to you when you ask for advice on personal matters?'],
            ['lang_code' => 'zh-CN', 'content' => '如果商量个人问题，同事会多大程度倾听？'],
            ['lang_code' => 'zh-TW', 'content' => '如果商量個人問題，同事會多大程度傾聽？'],
            ['lang_code' => 'vi', 'content' => 'Nếu bạn thảo luận về các vấn đề cá nhân, đồng nghiệp sẽ lắng nghe đến mức nào?'],
            ['lang_code' => 'tl', 'content' => 'Kapag nagkwento ka ng personal na problema, gaano nakikinig ang iyong các đồng nghiệp?'],
            ['lang_code' => 'pt', 'content' => 'O quão bem seus colegas de trabalho o ouvirão quando você pedir conselhos sobre assuntos pessoais?'],
            ['lang_code' => 'es', 'content' => '¿Qué tan bien lo escucharán sus compañeros de trabajo cuando pida consejo sobre asuntos personales?'],
            ['lang_code' => 'th', 'content' => 'ถ้าปรึกษาเรื่องส่วนตัว เพื่อนร่วมงานจะรับฟังแค่ไหน'],
            ['lang_code' => 'id', 'content' => 'Seberapa baik rekan kerja Anda sẽ lắng nghe nếu bạn thảo luận về vấn đề cá nhân?'],
        ]);
        $this->createOptions($q54, $optionSupport1Trans, $optionSupport2Trans, $optionSupport3Trans, $optionSupport4Trans, true);

        // Q55
        $q55 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcC->id,
            'sort_order' => 55,
            'question_number' => 'C-9',
            'anchor_point' => 15,
            'scale_id' => $sC3->id,
        ]);
        $q55->translations()->createMany([
            ['lang_code' => 'ja', 'content' => 'あなたの個人的な問題を相談したら、配偶者、家族、友人等はどのくらいきいてくれますか？'],
            ['lang_code' => 'en', 'content' => 'How well will your spouse, family, friends, etc. listen to you when you ask for advice on personal matters?'],
            ['lang_code' => 'zh-CN', 'content' => '如果商量个人问题，配偶、家人、朋友等会多大程度倾听？'],
            ['lang_code' => 'zh-TW', 'content' => '如果商量個人問題，配偶、家人、朋友等會多大程度傾聽？'],
            ['lang_code' => 'vi', 'content' => 'Nếu bạn thảo luận về các vấn đề cá nhân, vợ/chồng, gia đình, bạn bè... sẽ lắng nghe đến mức nào?'],
            ['lang_code' => 'tl', 'content' => 'Kapag nagkwento ka ng personal na problema, gaano nakikinig ang iyong asawa, pamilya, và bạn bè?'],
            ['lang_code' => 'pt', 'content' => 'O quão bem seu cônjuge, família, amigos, etc. o ouvirão quando você pedir conselhos sobre assuntos pessoais?'],
            ['lang_code' => 'es', 'content' => '¿Qué tan bien lo escucharán su cónyuge, familia, amigos, etc. cuando pida consejo sobre asuntos personales?'],
            ['lang_code' => 'th', 'content' => 'ถ้าปรึกษาเรื่องส่วนตัว คู่สมรส ครอบครัว และเพื่อนจะรับฟังแค่ไหน'],
            ['lang_code' => 'id', 'content' => 'Seberapa baik pasangan, gia đình, và bạn bè của bạn sẽ lắng nghe nếu bạn thảo luận về vấn đề cá nhân?'],
        ]);
        $this->createOptions($q55, $optionSupport1Trans, $optionSupport2Trans, $optionSupport3Trans, $optionSupport4Trans, true);

        // Q56
        $q56 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcD->id,
            'sort_order' => 56,
            'question_number' => 'D-1',
            'anchor_point' => 10,
            'scale_id' => $sD1->id,
        ]);
        $q56->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '仕事に満足だ'],
            ['lang_code' => 'en', 'content' => 'I am satisfied with my job'],
            ['lang_code' => 'zh-CN', 'content' => '对工作感到满意'],
            ['lang_code' => 'zh-TW', 'content' => '對工作感到滿意'],
            ['lang_code' => 'vi', 'content' => 'Tôi hài lòng với công việc'],
            ['lang_code' => 'tl', 'content' => 'Nasisiyahan ako sa aking trabaho'],
            ['lang_code' => 'pt', 'content' => 'Estou satisfeito com meu trabalho'],
            ['lang_code' => 'es', 'content' => 'Estoy satisfecho con mi trabajo'],
            ['lang_code' => 'th', 'content' => 'พอใจกับงาน'],
            ['lang_code' => 'id', 'content' => 'Saya puas với pekerjaan'],
        ]);
        $this->createOptions($q56, $optionSat1Trans, $optionSat2Trans, $optionSat3Trans, $optionSat4Trans, true);

        // Q57
        $q57 = Question::query()->create([
            'question_set_id' => $questionSet->id,
            'question_category_id' => $qcD->id,
            'sort_order' => 57,
            'question_number' => 'D-2',
            'anchor_point' => 10,
            'scale_id' => $sD1->id,
        ]);
        $q57->translations()->createMany([
            ['lang_code' => 'ja', 'content' => '家庭生活に満足だ'],
            ['lang_code' => 'en', 'content' => 'I am satisfied with my family life'],
            ['lang_code' => 'zh-CN', 'content' => '对家庭生活感到满意'],
            ['lang_code' => 'zh-TW', 'content' => '對家庭生活感到滿意'],
            ['lang_code' => 'vi', 'content' => 'Tôi hài lòng với cuộc sống gia đình'],
            ['lang_code' => 'tl', 'content' => 'Nasisiyahan ako sa aking buhay pamilya'],
            ['lang_code' => 'pt', 'content' => 'Estou satisfeito com minha vida familiar'],
            ['lang_code' => 'es', 'content' => 'Estoy satisfecho con mi vida familiar'],
            ['lang_code' => 'th', 'content' => 'พอใจกับชีวิตครอบครัว'],
            ['lang_code' => 'id', 'content' => 'Saya puas với kehidupan keluarga'],
        ]);
        $this->createOptions($q57, $optionSat1Trans, $optionSat2Trans, $optionSat3Trans, $optionSat4Trans, true);
    }

    /**
     * @param  bool  $isNegative
     */
    private function createOptions($question, $opt1, $opt2, $opt3, $opt4, $isNegative = false)
    {
        $options = [
            ['score' => $isNegative ? -1 : 1, 'trans' => $opt1],
            ['score' => $isNegative ? -2 : 2, 'trans' => $opt2],
            ['score' => $isNegative ? -3 : 3, 'trans' => $opt3],
            ['score' => $isNegative ? -4 : 4, 'trans' => $opt4],
        ];

        foreach ($options as $index => $data) {
            $option = QuestionOption::query()->create([
                'question_id' => $question->id,
                'score' => $data['score'],
                'sort_order' => $index + 1,
            ]);
            $option->translations()->createMany($data['trans']);
        }
    }
}
/**
 * @formatter:on
 */
