import { Box, List, Title } from '@mantine/core';
import classes from './index.module.css';

const AdminPrivacyPolicyJaPage = () => {
    return (
        <Box p="xl">
            <Title order={1}>株式会社DGFコーポレーション 管理アプリ プライバシーポリシー</Title>
            <p>
                株式会社DGFコーポレーション（以下「当社」といいます）は、管理アプリケーションにおける個人情報の取り扱いに関し、以下のポリシーを定めます。
            </p>

            <Title order={3} mt="lg" mb="md">
                第1条（個人情報の定義）
            </Title>
            <p>
                「個人情報」とは、氏名、メールアドレス、社員番号、勤務先情報、プロフィール画像、勤怠情報その他の関連データを含む、特定の個人を識別できる情報をいいます。
            </p>

            <Title order={3} mt="lg" mb="md">
                第2条（利用目的）
            </Title>
            <p>
                管理アプリケーションは、権限を付与された管理者および管理職が従業員管理および勤怠管理を行うために提供されます。
            </p>
            <p>個人情報は以下の目的で利用されることがあります：</p>
            <List className={classes.list} withPadding>
                <List.Item>従業員リスト管理</List.Item>
                <List.Item>勤怠管理</List.Item>
                <List.Item>シフトおよび勤務地管理</List.Item>
                <List.Item>本人確認</List.Item>
                <List.Item>業務サポート</List.Item>
                <List.Item>システムのセキュリティおよびメンテナンス</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                第3条（収集する情報）
            </Title>
            <p>当社は以下の情報を収集・管理する場合があります：</p>
            <List className={classes.list} withPadding>
                <List.Item>氏名</List.Item>
                <List.Item>メールアドレス</List.Item>
                <List.Item>社員番号</List.Item>
                <List.Item>プロフィール画像</List.Item>
                <List.Item>ロールおよび権限情報</List.Item>
                <List.Item>勤怠記録</List.Item>
                <List.Item>工場・勤務地情報</List.Item>
                <List.Item>管理者の操作ログ</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                第4条（管理者アカウント）
            </Title>
            <p>管理者アカウントは権限を付与された担当者にのみ発行されます。</p>
            <p>管理者は自身のアカウントおよびパスワードの機密性を保持する責任を負います。</p>

            <Title order={3} mt="lg" mb="md">
                第5条（勤怠操作）
            </Title>
            <p>
                権限を付与された管理者は、必要に応じて従業員の勤怠登録および勤怠修正を含む勤怠関連の操作を行うことができます。
            </p>
            <p>セキュリティおよびシステム管理の目的のため、操作履歴が記録される場合があります。</p>

            <Title order={3} mt="lg" mb="md">
                第6条（データ保護）
            </Title>
            <p>
                当社は、個人情報を不正アクセス、紛失、漏えい、または改ざんから保護するための合理的なセキュリティ対策を実施します。
            </p>
            <p>権限を付与された担当者のみが、業務上必要な範囲で従業員情報にアクセスできます。</p>

            <Title order={3} mt="lg" mb="md">
                第7条（第三者への開示）
            </Title>
            <p>当社は、以下の場合を除き、個人情報を第三者に開示しません：</p>
            <List className={classes.list} withPadding>
                <List.Item>法令に基づき開示が求められる場合</List.Item>
                <List.Item>システム運用上必要な場合</List.Item>
                <List.Item>本人の同意が得られた場合</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                第8条（訂正及び削除）
            </Title>
            <p>個人は、当社の手続きに従い、不正確な個人情報の訂正または削除を申請することができます。</p>

            <Title order={3} mt="lg" mb="md">
                第9条（本ポリシーの変更）
            </Title>
            <p>
                当社は必要に応じて本プライバシーポリシーを更新することがあります。更新版はアプリケーションまたはウェブサイト内で公開されます。
            </p>

            <Title order={3} mt="lg" mb="md">
                第10条（お問い合わせ）
            </Title>
            <p>
                株式会社DGFコーポレーション
                <br />
                電話番号：048-423-5398
                <br />
                メールアドレス：<a href="mailto:staffkanri@dgfjapan.com">staffkanri@dgfjapan.com</a>
            </p>
        </Box>
    );
};

export default AdminPrivacyPolicyJaPage;
