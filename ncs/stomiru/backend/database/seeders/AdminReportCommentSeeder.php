<?php

namespace Database\Seeders;

use App\Models\AdminReportComment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdminReportCommentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function () {
            $data = [
                // 1. overview_health_risk
                [
                    'section_id' => 'overview_health_risk',
                    'variable_reference' => 'comprehensive_risk_score',
                    'condition' => 'low',
                    'translations' => [
                        'ja' => '貴社の総合健康リスクは{{comprehensive_risk_score}}であり、全国平均（100）を大きく下回る良好な状態です。メンタルヘルス不調の発生リスクは、平均的な事業場と比較して低い水準に抑えられています。',
                        'en' => 'Your company\'s comprehensive health risk is {{comprehensive_risk_score}}, which is in a good state, well below the national average (100). The risk of mental health issues occurring is kept at a low level compared to an average workplace.',
                        'zh-CN' => '贵公司的综合健康风险为{{comprehensive_risk_score}}，大幅低于全国平均水平（100），处于良好状态。与普通工作场所相比，心理健康问题的发生风险被控制在较低水平。',
                        'zh-TW' => '貴公司的綜合健康風險為{{comprehensive_risk_score}}，大幅低於全國平均水準（100），處於良好狀態。與一般工作場所相比，心理健康問題的發生風險被控制在較低水準。',
                        'vi' => 'Rủi ro sức khỏe tổng hợp của công ty bạn là {{comprehensive_risk_score}}, đang ở trạng thái tốt và thấp hơn nhiều so với mức trung bình toàn quốc (100). Nguy cơ phát sinh các vấn đề về sức khỏe tâm thần được kiểm soát ở mức thấp so với một nơi làm việc trung bình.',
                        'tl' => 'Ang komprehensibong panganib sa kalusugan ng iyong kumpanya ay {{comprehensive_risk_score}}, na nasa magandang kalagayan at mas mababa kaysa sa pambansang average (100). Ang panganib ng pagkakaroon ng mga isyu sa kalusugan ng isip ay napapanatili sa mababang antas kumpara sa karaniwang lugar ng trabaho.',
                        'pt' => 'O risco de saúde abrangente da sua empresa é {{comprehensive_risk_score}}, que está em um bom estado, bem abaixo da média nacional (100). O risco de problemas de saúde mental é mantido em um nível baixo em comparação com um local de trabalho médio.',
                        'es' => 'El riesgo de salud integral de su empresa es {{comprehensive_risk_score}}, el cual se encuentra en buen estado, muy por debajo del promedio nacional (100). El riesgo de problemas de salud mental se mantiene en un nivel bajo en comparación con un lugar de trabajo promedio.',
                        'th' => 'ความเสี่ยงด้านสุขภาพโดยรวมของบริษัทคุณคือ {{comprehensive_risk_score}} ซึ่งอยู่ในสถานะที่ดีและต่ำกว่าค่าเฉลี่ยระดับชาติ (100) มาก ความเสี่ยงในการเกิดปัญหาสุขภาพจิตถูกควบคุมในระดับที่ต่ำเมื่อเทียบกับสถานที่ทำงานทั่วไป',
                        'id' => 'Risiko kesehatan komprehensif perusahaan Anda adalah {{comprehensive_risk_score}}, yang berada dalam kondisi baik dan jauh di bawah rata-rata nasional (100). Risiko masalah kesehatan mental dijaga pada tingkat yang rendah dibandingkan dengan tempat kerja rata-rata.',
                    ],
                ],
                [
                    'section_id' => 'overview_health_risk',
                    'variable_reference' => 'comprehensive_risk_score',
                    'condition' => 'medium',
                    'translations' => [
                        'ja' => '貴社の総合健康リスクは{{comprehensive_risk_score}}となりました。これは全国平均（100）とほぼ同等の水準です。平均的な事業場と比較して、メンタルヘルス不調の発生確率は標準的な範囲内にあると推測されます。',
                        'en' => 'Your company\'s comprehensive health risk is {{comprehensive_risk_score}}. This is an equivalent level to the national average (100). Compared to an average workplace, the probability of mental health issues occurring is estimated to be within the standard range.',
                        'zh-CN' => '贵公司的综合健康风险为{{comprehensive_risk_score}}。这与全国平均水平（100）基本相当。与普通工作场所相比，心理健康问题的发生概率推测在标准范围内。',
                        'zh-TW' => '貴公司的綜合健康風險為{{comprehensive_risk_score}}。這與全國平均水準（100）基本相當。與一般工作場所相比，心理健康問題的發生機率推測在標準範圍內。',
                        'vi' => 'Rủi ro sức khỏe tổng hợp của công ty bạn là {{comprehensive_risk_score}}. Mức này gần như tương đương với mức trung bình toàn quốc (100). So với một nơi làm việc trung bình, xác suất phát sinh các vấn đề về sức khỏe tâm thần được ước tính nằm trong phạm vi tiêu chuẩn.',
                        'tl' => 'Ang komprehensibong panganib sa kalusugan ng iyong kumpanya ay {{comprehensive_risk_score}}. Ito ay halos katumbas ng pambansang average (100). Kumpara sa isang karaniwang lugar ng trabaho, ang posibilidad ng mga isyu sa kalusugan ng isip ay tinatayang nasa karaniwang saklaw.',
                        'pt' => 'O risco de saúde abrangente da sua empresa é {{comprehensive_risk_score}}. Este é um nível equivalente à média nacional (100). Em comparação com um local de trabalho médio, a probabilidade de problemas de saúde mental ocorrerem é estimada dentro da faixa padrão.',
                        'es' => 'El riesgo de salud integral de su empresa es {{comprehensive_risk_score}}. Esto es prácticamente equivalente al promedio nacional (100). En comparación con un lugar de trabajo promedio, se estima que la probabilidad de que ocurran problemas de salud mental se encuentra dentro del rango estándar.',
                        'th' => 'ความเสี่ยงด้านสุขภาพโดยรวมของบริษัทคุณคือ {{comprehensive_risk_score}} ซึ่งอยู่ในระดับใกล้เคียงกับค่าเฉลี่ยระดับชาติ (100) เมื่อเทียบกับสถานที่ทำงานทั่วไป โอกาสในการเกิดปัญหาสุขภาพจิตคาดว่าจะอยู่ในช่วงมาตรฐาน',
                        'id' => 'Risiko kesehatan komprehensif perusahaan Anda adalah {{comprehensive_risk_score}}. Ini hampir setara dengan rata-rata nasional (100). Dibandingkan dengan tempat kerja rata-rata, kemungkinan terjadinya masalah kesehatan mental diperkirakan berada dalam kisaran standar.',
                    ],
                ],
                [
                    'section_id' => 'overview_health_risk',
                    'variable_reference' => 'comprehensive_risk_score',
                    'condition' => 'high',
                    'translations' => [
                        'ja' => '貴社の総合健康リスクは{{comprehensive_risk_score}}となり、全国平均（100）を上回っています。メンタルヘルス不調の発生リスクが平均よりも高い傾向にあるため、優先的な環境改善が推奨されます。',
                        'en' => 'Your company\'s comprehensive health risk is {{comprehensive_risk_score}}, which exceeds the national average (100). As the risk of mental health issues occurring tends to be higher than average, prioritized environmental improvement is recommended.',
                        'zh-CN' => '贵公司的综合健康风险为{{comprehensive_risk_score}}，已超过全国平均水平（100）。由于心理健康问题的发生风险倾向于高于平均水平，建议优先改善环境。',
                        'zh-TW' => '貴公司的綜合健康風險為{{comprehensive_risk_score}}，已超過全國平均水準（100）。由於心理健康問題的發生風險傾向於高於平均水準，建議優先改善環境。',
                        'vi' => 'Rủi ro sức khỏe tổng hợp của công ty bạn là {{comprehensive_risk_score}}, vượt quá mức trung bình toàn quốc (100). Vì nguy cơ phát sinh các vấn đề về sức khỏe tâm thần có xu hướng cao hơn mức trung bình, việc ưu tiên cải thiện môi trường được khuyến nghị.',
                        'tl' => 'Ang komprehensibong panganib sa kalusugan ng iyong kumpanya ay {{comprehensive_risk_score}}, na lumampas sa pambansang average (100). Dahil ang panganib ng pagkakaroon ng mga isyu sa kalusugan ng isip ay may posibilidad na mas mataas kaysa sa average, inirerekomenda ang priyoridad na pagpapabuti ng kapaligiran.',
                        'pt' => 'O risco de saúde abrangente da sua empresa é {{comprehensive_risk_score}}, que excede a média nacional (100). Como o risco de ocorrência de problemas de saúde mental tende a ser maior que a média, recomenda-se a melhoria ambiental prioritária.',
                        'es' => 'El riesgo de salud integral de su empresa es {{comprehensive_risk_score}}, lo que supera el promedio nacional (100). Dado que el riesgo de que ocurran problemas de salud mental tiende a ser mayor que el promedio, se recomienda priorizar las mejoras ambientales.',
                        'th' => 'ความเสี่ยงด้านสุขภาพโดยรวมของบริษัทคุณอยู่ที่ {{comprehensive_risk_score}} ซึ่งสูงกว่าค่าเฉลี่ยระดับชาติ (100) เนื่องจากความเสี่ยงในการเกิดปัญหาสุขภาพจิตมีแนวโน้มที่จะสูงกว่าค่าเฉลี่ย จึงควรให้ความสำคัญกับการปรับปรุงสภาพแวดล้อมเป็นอันดับแรก',
                        'id' => 'Risiko kesehatan komprehensif perusahaan Anda adalah {{comprehensive_risk_score}}, yang melebihi rata-rata nasional (100). Karena risiko masalah kesehatan mental cenderung lebih tinggi dari rata-rata, memprioritaskan perbaikan lingkungan sangat direkomendasikan.',
                    ],
                ],

                // 2. matrix_analysis_workload
                [
                    'section_id' => 'matrix_analysis_workload',
                    'variable_reference' => 'calculated_risk_workload',
                    'condition' => 'low',
                    'translations' => [
                        'ja' => '「仕事の量的負担」と「仕事のコントロール」のバランスから算出した健康リスクは全国平均より低く、適切な業務配分と裁量権の付与ができていると言えます。',
                        'en' => 'The health risk calculated from the balance of "quantitative workload" and "job control" is lower than the national average, which means proper task allocation and discretion-granting have been achieved.',
                        'zh-CN' => '根据“工作量负担”和“工作控制力”的平衡计算出的健康风险低于全国平均水平，可以说是做到了适当的工作分配和裁量权赋予。',
                        'zh-TW' => '根據「工作量負擔」和「工作控制力」的平衡計算出的健康風險低於全國平均水準，可以說是做到了適當的工作分配和裁量權賦予。',
                        'vi' => 'Rủi ro sức khỏe được tính toán từ sự cân bằng giữa "gánh nặng khối lượng công việc" và "kiểm soát công việc" thấp hơn mức trung bình toàn quốc, có thể nói rằng việc phân bổ công việc và trao quyền tự quyết đã được thực hiện phù hợp.',
                        'tl' => 'Ang panganib sa kalusugan na kinakalkula mula sa balanse ng "pasig ng pasanin sa trabaho" at "pagkontrol sa trabaho" ay mas mababa sa pambansang average, at masasabing nagawa nang maayos ang paglalaan ng gawain at pagbibigay ng karapatan sa pagpapasya.',
                        'pt' => 'O risco à saúde calculado a partir do equilíbrio entre "carga de trabalho quantitativa" e "controle do trabalho" é menor do que a média nacional, e pode-se dizer que a alocação de tarefas e a concessão de critérios adequados foram alcançadas.',
                        'es' => 'El riesgo para la salud calculado del balance entre la "carga de trabajo cuantitativa" y el "control del trabajo" es inferior al promedio nacional, y se puede decir que se ha logrado una asignación adecuada de tareas y la concesión de control a las personas.',
                        'th' => 'ความเสี่ยงด้านสุขภาพที่คำนวณจากสมดุลระหว่าง "ภาระงานเชิงปริมาณ" และ "การควบคุมงาน" ต่ำกว่าค่าเฉลี่ยระดับชาติ จะเห็นได้ว่ามีการจัดสรรงานและการให้สิทธิ์ในการตัดสินใจที่เหมาะสม',
                        'id' => 'Risiko kesehatan yang dihitung dari keseimbangan antara "beban kerja kuantitatif" dan "kontrol kerja" lebih rendah dari rata-rata nasional, dan dapat dikatakan bahwa alokasi tugas yang tepat dan pemberian wewenang telah tercapai.',
                    ],
                ],
                [
                    'section_id' => 'matrix_analysis_workload',
                    'variable_reference' => 'calculated_risk_workload',
                    'condition' => 'high',
                    'translations' => [
                        'ja' => '「仕事の量的負担」と「仕事のコントロール」のバランスから見た健康リスクは{{calculated_risk_workload}}（全国平均100）です。業務量が過多であるか、あるいは個人の裁量範囲が狭いと感じている従業員が多い可能性があります（数値が100の場合、平均と同等です）。',
                        'en' => 'The health risk relative to the balance of "quantitative workload" and "job control" is {{calculated_risk_workload}} (national average is 100). There is a possibility that many employees feel the workload is excessive or their scope of discretion is narrow (if the value is 100, it is equivalent to the average).',
                        'zh-CN' => '从“工作量负担”与“工作控制力”的平衡来看的健康风险为{{calculated_risk_workload}}（全国平均100）。可能有很多员工觉得工作量过多，或者个人裁量范围较窄（如果数值为100，则与平均水平相当）。',
                        'zh-TW' => '從「工作量負擔」與「工作控制力」的平衡來看的健康風險為{{calculated_risk_workload}}（全國平均100）。可能有很多員工覺得工作量過多，或者個人裁量範圍較窄（如果數值為100，則與平均水準相當）。',
                        'vi' => 'Rủi ro sức khỏe nhìn từ sự cân bằng của "gánh nặng khối lượng công việc" và "kiểm soát công việc" là {{calculated_risk_workload}} (trung bình toàn quốc 100). Có khả năng nhiều nhân viên cảm thấy khối lượng công việc quá tải, hoặc phạm vi tự quyết cá nhân bị hạn hẹp (nếu giá trị là 100, tức là tương đương với mức trung bình).',
                        'tl' => 'Ang panganib sa kalusugan mula sa balanse ng "pasanin sa trabaho" at "pagkontrol sa trabaho" ay {{calculated_risk_workload}} (pambansang average na 100). May posibilidad na maraming empleyado ang nakakaramdam na labis ang trabaho o makitid ang kanilang personal na lawak ng pagpapasya (kung ang halaga ay 100, ito ay katumbas ng average).',
                        'pt' => 'O risco à saúde visto pelo equilíbrio da "carga de trabalho quantitativa" e "controle do trabalho" é {{calculated_risk_workload}} (média nacional 100). Existe a possibilidade de que muitos funcionários sintam que a quantidade de trabalho é excessiva ou que seu escopo de discrição pessoal seja pequeno (se o valor for 100, é equivalente à média).',
                        'es' => 'El riesgo para la salud visto desde el equilibrio de la "carga de trabajo cuantitativa" y el "control del trabajo" es {{calculated_risk_workload}} (promedio nacional 100). Existe la posibilidad de que muchos empleados sientan que el volumen de trabajo es excesivo o que su alcance de control es estrecho (si el valor es 100, es equivalente al promedio).',
                        'th' => 'ความเสี่ยงด้านสุขภาพเมื่อพิจารณาจากสมดุลของ "ภาระงานเชิงปริมาณ" และ "การควบคุมงาน" คือ {{calculated_risk_workload}} (ค่าเฉลี่ยของประเทศคือ 100) อาจเป็นไปได้ว่าพนักงานหลายคนรู้สึกว่าปริมาณงานมากเกินไป หรือขอบเขตการตัดสินใจของตนเองนั้นแคบเกินไป (ถ้าค่าเป็น 100 จะเทียบเท่ากับค่าเฉลี่ย)',
                        'id' => 'Risiko kesehatan yang dilihat dari keseimbangan "beban kerja kuantitatif" dan "kontrol kerja" adalah {{calculated_risk_workload}} (rata-rata nasional 100). Ada kemungkinan bahwa banyak karyawan merasa bahwa beban kerjanya berlebihan atau batasan wewenang individunya sempit (bila nilainya 100, sama dengan rata-rata).',
                    ],
                ],

                // 3. matrix_analysis_support
                [
                    'section_id' => 'matrix_analysis_support',
                    'variable_reference' => 'calculated_risk_support',
                    'condition' => 'low',
                    'translations' => [
                        'ja' => '「上司の支援」および「同僚の支援」から算出した健康リスクは良好です。職場のコミュニケーションが活発で、困ったときに助け合える風土が形成されているようです。',
                        'en' => 'The health risk calculated from "supervisor support" and "colleague support" is in a good state. Workplace communication is active, and a culture of helping each other when in trouble seems to have formed.',
                        'zh-CN' => '从“上级支持”和“同事支持”计算出的健康风险状况良好。职场内的沟通活跃，看来已经形成了在困难时相互帮助的氛围。',
                        'zh-TW' => '從「上級支持」和「同事支持」計算出的健康風險狀況良好。職場內的溝通活躍，看來已經形成了在困難時相互幫助的氛圍。',
                        'vi' => 'Rủi ro sức khỏe được tính toán từ "sự hỗ trợ của cấp trên" và "sự hỗ trợ của đồng nghiệp" đang ở mức tốt. Giao tiếp tại nơi làm việc diễn ra sôi nổi, và một văn hóa giúp đỡ lẫn nhau khi gặp khó khăn đã được hình thành.',
                        'tl' => 'Ang panganib sa kalusugan na kinakalkula mula sa "suporta ng superbisor" at "suporta ng kasamahan" ay nasa magandang estado. Ang komunikasyon sa trabaho ay aktibo, at lumilitaw na may nabuong kultura ng pagtulong sa bawat isa kapag may problema.',
                        'pt' => 'O risco à saúde calculado do "apoio do supervisor" e "apoio dos colegas" está em bom estado. A comunicação no local de trabalho é ativa e parece que uma cultura de ajuda mútua em momentos de dificuldade se formou.',
                        'es' => 'El riesgo para la salud calculado a partir del "apoyo del supervisor" y el "apoyo de los compañeros" es bueno. La comunicación en el lugar de trabajo es activa y parece que se ha formado una cultura de ayudarse mutuamente en momentos de problemas.',
                        'th' => 'ความเสี่ยงด้านสุขภาพที่คำนวณจาก "การสนับสนุนจากหัวหน้างาน" และ "การสนับสนุนจากเพื่อนร่วมงาน" อยู่ในระดับที่ดี การสื่อสารในสถานที่ทำงานยังตื่นตัว และดูเหมือนว่ามีวัฒนธรรมการช่วยเหลือซึ่งกันและกันเมื่อมีปัญหานั้นได้ถูกสร้างขึ้นมา',
                        'id' => 'Risiko kesehatan yang dihitung dari "dukungan atasan" dan "dukungan rekan kerja" berada dalam kondisi baik. Komunikasi di tempat kerja aktif, dan budaya saling membantu saat dalam kesulitan tampaknya telah terbentuk.',
                    ],
                ],
                [
                    'section_id' => 'matrix_analysis_support',
                    'variable_reference' => 'calculated_risk_support',
                    'condition' => 'high',
                    'translations' => [
                        'ja' => '「職場の支援」に関する健康リスクは{{calculated_risk_support}}（全国平均100）です。上司や同僚とのコミュニケーション不足や、孤立感を感じている従業員が存在する懸念があります（数値が100の場合、平均と同等です）。',
                        'en' => 'The health risk related to "workplace support" is {{calculated_risk_support}} (national average is 100). There are concerns about a lack of communication with supervisors and colleagues, or the presence of employees feeling isolated (if the value is 100, it is equivalent to the average).',
                        'zh-CN' => '关于“职场支持”的健康风险为{{calculated_risk_support}}（全国平均100）。存在对于与上级或同事沟通不足或有感受到孤立感的员工的担忧（如果数值为100，则与平均水平相当）。',
                        'zh-TW' => '關於「職場支持」的健康風險為{{calculated_risk_support}}（全國平均100）。存在對於與上級或同事溝通不足或有感受到孤立感的員工的擔憂（如果數值為100，則與平均水準相當）。',
                        'vi' => 'Rủi ro sức khỏe liên quan đến "hỗ trợ nơi làm việc" là {{calculated_risk_support}} (trung bình toàn quốc 100). Có lo ngại về sự thiếu giao tiếp với cấp trên và đồng nghiệp, hoặc có những nhân viên cảm thấy bị cô lập (nếu giá trị là 100, tức là tương đương với mức trung bình).',
                        'tl' => 'Ang panganib sa kalusugan na may kaugnayan sa "suporta sa lugar ng trabaho" ay {{calculated_risk_support}} (pambansang average na 100). May mga alalahanin tungkol sa kakulangan ng komunikasyon sa mga superbisor at mga kasamahan, o sa pagkakaroon ng mga empleyado na nakakaramdam ng pag-iisa (kung ang halaga ay 100, ito ay katumbas ng average).',
                        'pt' => 'O risco à saúde relacionado ao "apoio no local de trabalho" é {{calculated_risk_support}} (média nacional 100). Há preocupações sobre a falta de comunicação com supervisores e colegas, ou a presença de funcionários que se sentem isolados (se o valor for 100, é equivalente à média).',
                        'es' => 'El riesgo para la salud en relación al "apoyo en el lugar de trabajo" es {{calculated_risk_support}} (promedio nacional 100). Existen preocupaciones acerca de una falta de comunicación con los supervisores y colegas, o la presencia de empleados que sienten aislamiento (si el valor es 100, es equivalente al promedio).',
                        'th' => 'ความเสี่ยงด้านสุขภาพที่เกี่ยวข้องกับ "การสนับสนุนในที่ทำงาน" คือ {{calculated_risk_support}} (ค่าเฉลี่ยของประเทศคือ 100) มีความกังวลเกี่ยวกับการขาดการสื่อสารกับหัวหน้างานและเพื่อนร่วมงาน หรืออาจมีพนักงานที่รู้สึกโดดเดี่ยว (ถ้าค่าเป็น 100 จะเทียบเท่ากับค่าเฉลี่ย)',
                        'id' => 'Risiko kesehatan terkait "dukungan tempat kerja" adalah {{calculated_risk_support}} (rata-rata nasional 100). Ada kekhawatiran tentang kurangnya komunikasi dengan atasan maupun rekan, atau adanya karyawan yang merasa terisolasi (bila nilainya 100, sama dengan rata-rata).',
                    ],
                ],

                // 4. detailed_factors
                [
                    'section_id' => 'detailed_factors',
                    'variable_reference' => 'bad_item_name',
                    'condition' => 'not_null',
                    'translations' => [
                        'ja' => '個別のストレス要因を全国データと比較すると、特に「{{bad_item_name}}」の項目で負担を感じている傾向が見られます。ストレス反応や満足度に関しては、{{reaction_evaluation}}。',
                        'en' => 'Comparing individual stress factors with national data, a tendency to feel burdened is particularly observed in the "{{bad_item_name}}" item. Regarding stress reactions and satisfaction, it is {{reaction_evaluation}}.',
                        'zh-CN' => '将个体压力因素与全国数据进行比较，可以发现特别在“{{bad_item_name}}”项目上存在感到负担的倾向。关于压力反应和满意度，则{{reaction_evaluation}}。',
                        'zh-TW' => '將個體壓力因素與全國數據進行比較，可以發現特別在「{{bad_item_name}}」項目上存在感到負擔的傾向。關於壓力反應和滿意度，則{{reaction_evaluation}}。',
                        'vi' => 'Khi so sánh các yếu tố gây căng thẳng với dữ liệu toàn quốc, cần lưu ý khuynh hướng cảm thấy bị đè nặng trong hạng mục "{{bad_item_name}}". Về phản ứng với căng thẳng và mức độ hài lòng, đó là {{reaction_evaluation}}.',
                        'tl' => 'Paghahambing ng mga indibidwal na salik ng stress sa pambansang data, may kapansin-pansing hilig na makaramdam ng mabigat partikular sa aytem na "{{bad_item_name}}". Tungkol sa mga reaksyon sa stress at kasiyahan, ito ay {{reaction_evaluation}}.',
                        'pt' => 'Ao comparar os fatores de estresse individuais com dados nacionais, nota-se especialmente a tendência de sentir sobrecarga no item "{{bad_item_name}}". Em relação à reação ao estresse e satisfação, é {{reaction_evaluation}}.',
                        'es' => 'Comparando los factores de estrés individuales con los datos nacionales, se observa una tendencia a sentirse agobiado especialmente en el ítem de "{{bad_item_name}}". Con respecto a las reacciones al estrés y la satisfacción, esta es {{reaction_evaluation}}.',
                        'th' => 'เมื่อเปรียบเทียบปัจจัยความเครียดรายบุคคลกับข้อมูลระดับประเทศ จะเห็นแนวโน้มที่รู้สึกถึงภาระอย่างมากในหัวข้อ "{{bad_item_name}}" ในส่วนของปฏิกิริยาตอบสนองความเครียดและความพึงพอใจนั้นคือ {{reaction_evaluation}}',
                        'id' => 'Membandingkan faktor stres individu dengan data nasional, terdapat kecenderungan perasaan terbebani yang sangat jelas diamati pada item "{{bad_item_name}}". Mengenai reaksi stres dan kepuasan, statusnya {{reaction_evaluation}}.',
                    ],
                ],
                [
                    'section_id' => 'detailed_factors',
                    'variable_reference' => 'bad_item_name',
                    'condition' => 'null',
                    'translations' => [
                        'ja' => '個別のストレス要因を全国データと比較すると、各項目とも概ね平均的な水準で推移しており、特定の大きな問題は見受けられません。ストレス反応や満足度に関しては、{{reaction_evaluation}}。',
                        'en' => 'Comparing individual stress factors with national data, each item is generally hovering at an average level, and no specific major problems are seen. Regarding stress reactions and satisfaction, it is {{reaction_evaluation}}.',
                        'zh-CN' => '将个体压力因素与全国数据进行比较，各项目基本上都处于平均水平，没有发现特定的重大问题。关于压力反应和满意度，则{{reaction_evaluation}}。',
                        'zh-TW' => '將個體壓力因素與全國數據進行比較，各項目基本上都處於平均水準，沒有發現特定的重大問題。關於壓力反應和滿意度，則{{reaction_evaluation}}。',
                        'vi' => 'Khi so sánh các yếu tố gây căng thẳng với dữ liệu toàn quốc, sự chuyển biến ở mỗi hạng mục chủ yếu trong mức bình quân và không có vấn đề lớn nào đặc biệt xuất hiện. Về phản ứng với căng thẳng và mức độ hài lòng, đó là {{reaction_evaluation}}.',
                        'tl' => 'Paghahambing ng mga indibidwal na salik ng stress sa pambansang data, bawat aytem ay halos nasa karaniwang antas, at walang partikular na pangunahing problema ang nakikita. Tungkol sa mga reaksyon sa stress at kasiyahan, ito ay {{reaction_evaluation}}.',
                        'pt' => 'Comparando os fatores de estresse individuais com dados nacionais, cada item está operando de forma geral por um nível mediano, e nenhum grande problema é visto. Em relação à reação ao estresse e satisfação, é {{reaction_evaluation}}.',
                        'es' => 'Comparando los factores de estrés individuales con los datos nacionales, cada ítem está transcurriendo en general con un nivel promedio, y no se observaron problemas mayores específicos. Con respecto a las reacciones al estrés y la satisfacción, esta es {{reaction_evaluation}}.',
                        'th' => 'เมื่อเปรียบเทียบปัจจัยสร้างความเครียดกับข้อมูลระดับประเทศ แต่ละรายการเคลื่อนไหวอยู่ในระดับเฉลี่ยเป็นส่วนใหญ่ โดยไม่พบปัญหาเฉพาะสำคัญใดๆ ในส่วนของปฏิกิริยาตอบสนองความเครียดและความพึงพอใจนั้นคือ {{reaction_evaluation}}',
                        'id' => 'Membandingkan faktor stres individu dengan data nasional, setiap item berjalan pada tingkat rata-rata hampir sama, dan tidak ada masalah besar tertentu yang diobservasikan. Mengenai reaksi stres dan kepuasan, statusnya {{reaction_evaluation}}.',
                    ],
                ],
                [
                    'section_id' => 'reaction_evaluation',
                    'variable_reference' => 'reaction_to_stress_point',
                    'condition' => 'low',
                    'translations' => [
                        'ja' => '全国平均に比べて不良の傾向が見られ、注意が必要です',
                        'en' => 'Compared to the national average, a poor tendency is observed, requiring attention.',
                        'zh-CN' => '与全国平均水平相比，出现了不良倾向，需要加以注意。',
                        'zh-TW' => '與全國平均水準相比，出現了不良傾向，需要加以注意。',
                        'vi' => 'So với mức trung bình toàn quốc, có xu hướng kém hơn, cần chú ý.',
                        'tl' => 'Kumpara sa pambansang average, napapansin ang hindi magandang posibilidad na nangangailangan ng pansin.',
                        'pt' => 'Comparado com a média nacional, observa-se uma tendência desfavorável, exigindo atenção.',
                        'es' => 'En comparación con el promedio nacional, se observa una tendencia desfavorable y se requiere atención.',
                        'th' => 'เมื่อเปรียบเทียบกับค่าเฉลี่ยระดับชาติ พบว่ามีแนวโน้มที่ไม่ดีและต้องเฝ้าระวัง',
                        'id' => 'Dibandingkan dengan rata-rata nasional, terlihat kecenderungan yang kurang baik, dan memerlukan perhatian.',
                    ],
                ],
                [
                    'section_id' => 'reaction_evaluation',
                    'variable_reference' => 'reaction_to_stress_point',
                    'condition' => 'medium',
                    'translations' => [
                        'ja' => '全国平均とほぼ同等の傾向を示しています',
                        'en' => 'It shows a tendency almost equivalent to the national average.',
                        'zh-CN' => '显示出与全国平均水平基本相同的趋势。',
                        'zh-TW' => '顯示出與全國平均水準基本相同的趨勢。',
                        'vi' => 'Cho thấy xu hướng gần như tương đương với mức trung bình toàn quốc.',
                        'tl' => 'Ipinapakita ang isang posibilidad na halos katumbas ng pambansang average.',
                        'pt' => 'Mostra uma tendência quase equivalente à média nacional.',
                        'es' => 'Muestra una tendencia casi equivalente al promedio nacional.',
                        'th' => 'แสดงให้เห็นแนวโน้มที่เกือบเท่ากับค่าเฉลี่ยระดับชาติ',
                        'id' => 'Menunjukkan kecenderungan yang hampir setara dengan rata-rata nasional.',
                    ],
                ],
                [
                    'section_id' => 'reaction_evaluation',
                    'variable_reference' => 'reaction_to_stress_point',
                    'condition' => 'high',
                    'translations' => [
                        'ja' => '全国平均より良好な状態です',
                        'en' => 'It is in a better condition than the national average.',
                        'zh-CN' => '状况好于全国平均水平。',
                        'zh-TW' => '狀況好於全國平均水準。',
                        'vi' => 'Đang ở tình trạng tốt hơn so với mức trung bình toàn quốc.',
                        'tl' => 'Nasa mas magandang kalagayan kaysa sa pambansang average.',
                        'pt' => 'Está em um estado melhor do que a média nacional.',
                        'es' => 'Se encuentra en un estado mejor que el promedio nacional.',
                        'th' => 'อยู่ในสถานะที่ดีกว่าค่าเฉลี่ยระดับชาติ',
                        'id' => 'Berada dalam kondisi yang lebih baik dibandingkan dengan rata-rata nasional.',
                    ],
                ],

                // 5. recommendation_action
                [
                    'section_id' => 'recommendation_action',
                    'variable_reference' => 'high_stress_ratio',
                    'condition' => 'high',
                    'translations' => [
                        'ja' => '高ストレス者の割合が{{high_stress_ratio}}%と、全国平均（10.0%）を上回っています。個人の面接指導勧奨を徹底するとともに、リスクが高く出た部署へのヒアリングなど、組織的な介入を検討することをお勧めします。',
                        'en' => 'The ratio of highly stressed individuals is {{high_stress_ratio}}%, exceeding the national average (10.0%). We recommend thorough promotion of individual interview guidance, and considering organizational interventions such as holding hearings directly with high-risk departments.',
                        'zh-CN' => '高压力者的比例达到{{high_stress_ratio}}%，超过了全国平均水平（10.0%）。我们建议彻底执行对个人的面谈指导劝告，并探讨考虑对高风险部门进行听证等组织性介入。',
                        'zh-TW' => '高壓力者的比例達到了{{high_stress_ratio}}%，超過了全國平均水準（10.0%）。我們建議徹底實施對個人的面談指導勸告，並探討考慮對高風險部門進行聽證等組織性介入。',
                        'vi' => 'Tỉ lệ những người bị căng thẳng cao ở mức {{high_stress_ratio}}%, vượt mức trung bình toàn quốc (10.0%). Khuyến cáo tích cực thực hiện khuyến khích hướng dẫn phỏng vấn cá nhân, đồng thời xem xét các can thiệp có tổ chức như tổ chức thu nhận ý kiến tại các bộ phận có nguy cơ cao.',
                        'tl' => 'Ang proporsyon ng mga indibidwal na may mataas na stress ay nasa {{high_stress_ratio}}%, na mas mataas sa pambansang average (10.0%). Inirerekomenda namin ang pagpapaigting sa paghimok para sa personal interview guidance, pati na rin ang isaalang-alang ang panlahat na interbensyon tulad ng pakikipanayam sa mga departamento na may mataas na panganib.',
                        'pt' => 'A proporção de indivíduos com alto estresse é de {{high_stress_ratio}}%, superando a média nacional (10.0%). Recomendamos a promoção rigorosa da orientação de entrevista pessoal, e a consideração de intervenções organizacionais, tais como audiências diretas com os departamentos de alto risco.',
                        'es' => 'El porcentaje de sujetos con alto nivel de estrés es de {{high_stress_ratio}}%, lo cual supera el promedio nacional (10.0%). Recomendamos enfocarse de forma exhaustiva en la orientación de entrevistas individuales y considerar intervenciones organizacionales como audiencias en departamentos de alto riesgo.',
                        'th' => 'อัตราส่วนของผู้ที่มีความเครียดสูงอยู่ที่ {{high_stress_ratio}}% ซึ่งสูงกว่าค่าเฉลี่ยของบริษัท (10.0%) เราขอแนะนำให้ส่งเสริมการสัมภาษณ์เพื่อเป็นแนวทางปฏิบัติส่วนบุคคลอย่างเข้มงวด และพิจารณาถึงการแทรกแซงในระดับองค์กร เช่น การรับฟังความคิดเห็นสำหรับหน่วยงานที่มีความเสี่ยงสูง',
                        'id' => 'Proporsi individu dengan tingkat stres yang tinggi adalah {{high_stress_ratio}}%, melebihi rata-rata nasional (10.0%). Kami menyarankan promosi yang menyeluruh terkait bimbingan wawancara pribadi, serta mempertimbangkan intervensi terorganisir seperti melakukan dengar pendapat secara langsung dengan departemen yang berisiko tinggi.',
                    ],
                ],
                [
                    'section_id' => 'recommendation_action',
                    'variable_reference' => 'high_stress_ratio',
                    'condition' => 'low',
                    'translations' => [
                        'ja' => '高ストレス者の割合は{{high_stress_ratio}}%であり、全国平均（10.0%）以下に抑えられています。現在の良好な職場環境を維持しつつ、部署ごとの小さな変化に目を向けることで、さらなる生産性向上が期待できます。',
                        'en' => 'The ratio of highly stressed individuals is at {{high_stress_ratio}}%, kept below the national average (10.0%). While maintaining the current positive workplace environment, further productivity improvements can be expected by focusing on slight gradual changes in each department.',
                        'zh-CN' => '高压力者的比例为{{high_stress_ratio}}%，保持在全国平均水平（10.0%）以下。在维持现状职场良好环境的同时，通过关注每个部门的小变化可以期待进一步提升生产力。',
                        'zh-TW' => '高壓力者的比例為{{high_stress_ratio}}%，保持在全國平均水準（10.0%）以下。在維持現狀職場良好環境的同時，透過關注每個部門的細微變化將可望期待進一步提升生產力。',
                        'vi' => 'Tỉ lệ những người bị căng thẳng cao là {{high_stress_ratio}}%, được giữ ở mức dưới trung bình toàn quốc (10.0%). Trong khi tiếp tục duy trì trạng thái tốt của môi trường làm việc, có thể kỳ vọng cải thiện năng suất làm việc bằng cách để ý quan tâm tới những biến đổi nhỏ ở mỗi phòng ban.',
                        'tl' => 'Ang proporsyon ng mga indibidwal na may mataas na stress ay {{high_stress_ratio}}%, napapanatiling mas mababa sa pambansang average (10.0%). Habang pinapanatili ang kasalukuyang magandang kapaligiran sa trabaho, ang higit pang pagpapabuti sa pagiging produktibo ay inaasahan sa pamamagitan ng pagbibigay-pansin sa mga maliliit na pagbabago sa bawat departamento.',
                        'pt' => 'A proporção de indivíduos com alto estresse é de {{high_stress_ratio}}%, mantida abaixo da média nacional (10.0%). Embora se mantenha o bom ambiente de trabalho atual, maiores melhorias na produtividade podem ser esperadas voltando a atenção para gradativas pequenas alterações em cada departamento.',
                        'es' => 'El porcentaje de sujetos con alto nivel de estrés es de {{high_stress_ratio}}%, porcentaje que se mantiene por debajo del promedio nacional (10.0%). Mientras que se logre mantener el buen entorno de trabajo actual, se pueden esperar mejoras mayores en la productividad enfocándose en pequeños y graduales cambios de acuerdo a cada departamento.',
                        'th' => 'อัตราส่วนสำหรับผู้ที่อยู่ในเกณฑ์มีความเครียดสูงจะมีอยู่ที่ {{high_stress_ratio}}% ซึ่งถือว่าต่ำกว่าระดับค่าเฉลี่ยตามเกณฑ์มาตรฐาน (10.0%) ในขณะที่ยังคงรักษาสภาพแวดล้อมสถานที่ทำงานในปัจจุบันที่เป็นผลดีเอาไว้ การมองดูความเปลี่ยนแปลงเล็กๆ ในแต่ละแผนกจะสามารถช่วยคาดหวังผลลัพธ์ประสิทธิภาพที่เติบโตเพิ่มมากขึ้นต่อไปได้',
                        'id' => 'Proporsi individu dengan stres tingkat tinggi berhasil ditekan ke angka rata-rata sebesar {{high_stress_ratio}}%, di bawah rata-rata nasional (10.0%). Seraya tetap mempertahankan lingkungan kerja yang positif seperti saat ini, peningkatan produktivitas yang jauh lebih maksimal dapat diharapkan dengan memperhatikan pada perubahan-perubahan kecil di setiap bagian departemen.',
                    ],
                ],
            ];

            foreach ($data as $item) {
                $comment = AdminReportComment::updateOrCreate(
                    [
                        'section_id' => $item['section_id'],
                        'condition' => $item['condition'],
                    ],
                    [
                        'variable_reference' => $item['variable_reference'],
                    ]
                );

                foreach ($item['translations'] as $lang => $text) {
                    $comment->translations()->updateOrCreate(
                        ['lang_code' => $lang],
                        ['content' => $text]
                    );
                }
            }
        });
    }
}
