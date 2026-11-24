import { Box, List, Text, Title } from '@mantine/core';
import classes from './index.module.css';

const PrivatePolicyJaPage = () => {
    return (
        <Box p="xl">
            <Title order={1}>株式会社DGFプライバシーポリシー</Title>
            <p>
                株式会社DGF人材開発（以下、「当社」といいます）は、個人情報の取扱いに関する方針を、以下の通り定め、これを公表するとともに遵守することを宣言します。
            </p>
            <Title order={3} mt="lg" mb="md">
                第1条（個人情報の定義）
            </Title>
            <p>
                「個人情報」とは、個人情報保護法における「個人情報」を意味し、生存する個人に関する情報であって、次のいずれかに該当するものをいう。
            </p>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>
                    当該情報に含まれる氏名、生年月日、電話番号、メールアドレス、住所、勤務先、学生証、在留資格カード情報その他の記述等により特定の個人を識別できるもの（他の情報と容易に照合することができ、それによって特定の個人を識別することができることとなるものを含む。）
                </List.Item>
                <List.Item>個人識別符号が含まれるものをいう。</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                第2条（個人情報保護方針）
            </Title>
            <p>
                当社は、労働者派遣事業、有料職業紹介事業において以下の通り個人情報保護方針を定め、個人情報保護の重要性を認識し、個人情報保護の取り組みを徹底いたします。
            </p>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>
                    個人情報の利用目的の特定と遵守
                    <br />
                    個人情報の利用目的をできる限り明確にし、目的の達成に必要な範囲内でのみ適切に取り扱います。また、目的外利用を防止するための措置を講じます。
                </List.Item>
                <List.Item>
                    適正な取得と管理責任者の設置
                    <br />
                    個人情報は、適法かつ適正な方法で取得します。また、適切な管理を行うために管理責任者を設置し、従業員への教育を徹底します。
                </List.Item>
                <List.Item>
                    第三者提供の制限と適切な管理
                    <br />
                    本人の同意がある場合や法令に基づく場合を除き、個人情報を第三者に提供しません。提供が必要な場合は、適切な契約を締結し、情報管理の徹底を義務付けます。
                </List.Item>
                <List.Item>
                    安全管理措置の実施
                    <br />
                    個人情報の漏えい、滅失、毀損を防ぐために、適切な安全管理措置を講じるとともに、継続的な見直しと是正措置を行います。
                </List.Item>
                <List.Item>
                    正確性・最新性の確保
                    <br />
                    個人情報を可能な限り正確かつ最新の状態に保ち、本人からの開示・訂正・削除の要請には、合理的な範囲で速やかに対応します。
                </List.Item>
                <List.Item>
                    法令・社内規程の遵守と継続的な改善
                    <br />
                    個人情報保護に関する法令や指針、社内規程を遵守し、定期的な見直しを行いながら、社内ルールの改善に努めます。
                </List.Item>
                <List.Item>
                    苦情・相談窓口の設置と適切な対応
                    <br />
                    個人情報の取り扱いに関する苦情や相談を受け付ける窓口を設置し、適切かつ迅速に対応できる体制を整えます。
                </List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                第3条（個人情報の利用について）
            </Title>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>
                    個人情報の取得
                    <List className={classes.list}>
                        <List.Item>
                            当社は、業務の円滑な遂行のため、お問い合わせをお受けするに当たり、ご本人の氏名、生年月日、電話番号、メールアドレス、住所等の個人情報を取得させていただきます。
                        </List.Item>
                        <List.Item>顔写真（明確な同意を得た場合のみ収集されます）。</List.Item>
                        <List.Item>
                            また、アプリの打刻機能を使用する際に、現在地情報（GPS座標）を取得することがあります。
                        </List.Item>
                    </List>
                </List.Item>
                <List.Item>
                    個人情報の利用
                    <br />
                    当社が取得するご本人の個人情報は、利用目的を明確にした上で、目的の範囲内において利用させていただきます。
                </List.Item>
                <List.Item>
                    個人情報の保存方法
                    <List className={classes.list}>
                        <List.Item>
                            顔写真を含む個人データは、厳重なセキュリティ対策が施されたサーバーに保存されます。
                        </List.Item>
                        <List.Item>
                            すべてのデータは、保存および送信の際に暗号化され、安全性を確保しています。
                        </List.Item>
                        <List.Item>会社内の権限を持つ担当者のみが、このデータにアクセスできます。</List.Item>
                        <List.Item>
                            顔画像は、本人確認の目的で収集日から3ヶ月間保管されます。この期間が過ぎると、法的要件がない限り、データは完全に削除されます。
                        </List.Item>
                    </List>
                </List.Item>
                <List.Item>
                    法令等の遵守
                    <br />
                    当社が保有する個人情報に関して適用される法令、規範を遵守します。
                </List.Item>
                <List.Item>
                    個人情報の取扱いの改善と見直し
                    <br />
                    当社は、個人情報保護に関する管理の体制と仕組みについて継続的改善を実施します。
                </List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                第4条（利用目的）
            </Title>
            <p>当社が取得した個人情報の利用目的は、下記の通りとします。</p>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>派遣登録・求職登録の受付および手続きのため。</List.Item>
                <List.Item>仕事紹介、就業に関する連絡および雇用管理のため。</List.Item>
                <List.Item>給与支払、社会保険・税務手続きのため。</List.Item>
                <List.Item>契約締結および取引先との業務管理のため。</List.Item>
                <List.Item>健康管理、安全衛生管理および職場環境の整備のため。</List.Item>
                <List.Item>研修・キャリアコンサルティングの提供および受講管理のため。</List.Item>
                <List.Item>法令遵守、行政機関・公的機関への対応のため。</List.Item>
                <List.Item>災害・緊急時の安否確認および連絡対応のため。</List.Item>
                <List.Item>その他、労務管理・事業遂行上必要な対応のため。</List.Item>
                <List.Item>
                    勤怠打刻時の位置情報（GPS座標）の利用
                    <List className={classes.list} type="ordered">
                        <List.Item>打刻場所の確認: 勤怠打刻が指定の勤務場所で行われたことを確認するため。</List.Item>
                        <List.Item>移動経路の案内: 必要に応じて適切な移動ルートを提供するため。</List.Item>
                    </List>
                </List.Item>
                <List.Item>
                    本人確認のため、以下の目的で顔写真を収集します：
                    <List className={classes.list} type="ordered">
                        <List.Item>法的要件に基づく雇用契約および従業員記録の手続きにおける本人確認のため。</List.Item>
                    </List>
                    <strong>上記の目的以外で顔画像を利用することはありません。</strong>
                </List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                第5条（個人情報の第三者への開示）
            </Title>
            <p>
                当社は、取得させていただいた個人情報は、ご本人の事前の同意なく第三者に対して開示することはありません。ただし、次の場合には、ご本人の事前の同意なく、取得した個人情報を開示できるものとします。
            </p>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>法令に基づき開示を求められた場合。</List.Item>
                <List.Item>
                    当社、他のご本人またはその他の第三者の権利、利益、名誉、信用等を保護するために必要であると弊所が判断した場合。
                </List.Item>
                <List.Item>ご本人が自分の個人情報の開示を事前に承諾した場合。</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                第6条（個人情報の利用・提供の拒否に関するお問い合わせ）
            </Title>
            <p>
                一旦当社にご提供いただいた個人情報について、利用を望まない場合や利用目的内での第三者への提供を望まない場合は、適正に対応します。
            </p>

            <Title order={3} mt="lg" mb="md">
                第7条（共同利用について）
            </Title>
            <p>当社は、以下の条件によりお取引先様の個人情報を共同利用することがあります。</p>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>
                    共同して利用するものの範囲
                    <br />
                    当社グループ会社（株式会社DGF）
                </List.Item>
                <List.Item>
                    共同利用の目的
                    <br />
                    「1．個人情報の利用目的」記載の利用目的と同じ。
                </List.Item>
                <List.Item>
                    共同利用する個人情報の項目
                    <br />
                    氏名、住所、郵便番号、電話番号、性別、生年月日、メールアドレス、勤務地、在留資格情報、国籍、その他の必要な個人情報。
                </List.Item>
                <List.Item>
                    共同利用する個人情報の管理について責任を有するもの
                    <br />
                    株式会社DGF人材開発　個人情報保護管理者。
                </List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                第8条（個人情報の委託）
            </Title>
            <p>
                当社では、上記の利用目的の達成に必要な場合、ご提供いただいた個人情報の取り扱いを他社に委託する場合があります。
            </p>

            <Title order={3} mt="lg" mb="md">
                第9条（個人情報の訂正及び削除）
            </Title>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>
                    当社が保有する個人情報が真実でないときは、ご本人の請求により、個人情報の訂正及び削除を求めることができます。
                </List.Item>
                <List.Item>
                    当社は、ご本人から前項の請求を受けた場合に、当該個人情報の訂正又は削除の決定をしたときは、当社が定める手続に従い当該個人情報の訂正又は削除を行った後、遅滞なくご本人に通知します。
                </List.Item>
                <List.Item>
                    顔画像は、収集日から3ヶ月]後にシステムから自動削除されます。早期削除を希望する場合は申請することができ、申請受付から14日以内に対応します。
                </List.Item>
                <List.Item>
                    当社は、ご提供いただいた個人情報について、法令等で別途定めがある場合を除き、いかなる媒体（書類・データを問わず）でご提出いただいた場合でも返却いたしません。また、当社が不要と判断した個人情報については、適切な方法で廃棄いたします（※）。
                </List.Item>
            </List>
            <p>
                ※ご登録（派遣登録・求職登録）後、就業の有無にかかわらず、電話・メール・マイページ等を通じて最後に当社と連絡を行った日から15年が経過した場合、ご本人への通知を行うことなく個人情報を廃棄いたします。
            </p>

            <Title order={3} mt="lg" mb="md">
                第10条（苦情及び相談の連絡先）
            </Title>
            <p>
                当社は、個人情報の取扱いについて、ご本人からの当社の個人情報の取扱いについて、ご本人からの苦情や相談を受け付けます。相談や苦情等は、以下の窓口までお問い合わせください。
            </p>
            <p>
                株式会社DGF人材開発　相談窓口
                <br />
                電話番号：048-423-5398
                <br />
                FAX番号：048-423-5397
                <br />
                メールアドレス：<a href="mailto:staffkanri@dgfjapan.com">staffkanri@dgfjapan.com</a>
                <br />
            </p>
            <Text ta="right">
                令和7年3月5日制定・施行
                <br />
                <br />
                株式会社DGF人材開発
                <br />
                〒353-0004
                <br />
                埼玉県志木市本町五丁目18番13号2階
                <br />
                代表取締役社長　グエン テュアン ズオン
            </Text>
        </Box>
    );
};

export default PrivatePolicyJaPage;
