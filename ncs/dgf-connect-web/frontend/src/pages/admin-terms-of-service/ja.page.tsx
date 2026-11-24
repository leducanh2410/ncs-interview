import { Box, List, Title } from '@mantine/core';
import classes from './index.module.css';

const AdminTermsOfServiceJaPage = () => {
    return (
        <Box p="xl">
            <Title order={1}>株式会社DGFコーポレーション 管理アプリ 利用規約</Title>
            <p>本利用規約は、株式会社DGFコーポレーションが提供する管理アプリケーションの利用に関する規定です。</p>

            <Title order={3} mt="lg" mb="md">
                第1条（利用資格）
            </Title>
            <p>管理アプリケーションは、当社が承認した権限を付与された管理者および管理職のみが利用できます。</p>

            <Title order={3} mt="lg" mb="md">
                第2条（管理者アカウント）
            </Title>
            <p>利用者はログイン情報の機密性を保持する責任を負います。</p>
            <p>アカウントの共有および不正アクセスは禁止されています。</p>

            <Title order={3} mt="lg" mb="md">
                第3条（利用可能な用途）
            </Title>
            <p>アプリケーションは以下の目的で使用できます：</p>
            <List className={classes.list} withPadding>
                <List.Item>従業員管理</List.Item>
                <List.Item>勤怠管理</List.Item>
                <List.Item>工場管理</List.Item>
                <List.Item>業務サポート</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                第4条（禁止行為）
            </Title>
            <p>利用者は以下の行為を行ってはなりません：</p>
            <List className={classes.list} withPadding>
                <List.Item>不正な情報へのアクセス</List.Item>
                <List.Item>不法な目的でのアプリケーションの使用</List.Item>
                <List.Item>勤怠記録の不正な改ざん</List.Item>
                <List.Item>機密情報の不適切な開示</List.Item>
                <List.Item>システム運用の妨害</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                第5条（システムの監視）
            </Title>
            <p>当社は、セキュリティおよび運用管理の目的のために、管理者の操作を記録することがあります。</p>

            <Title order={3} mt="lg" mb="md">
                第6条（停止および終了）
            </Title>
            <p>本規約に違反した場合、当社はアクセスを停止または終了することがあります。</p>

            <Title order={3} mt="lg" mb="md">
                第7条（免責事項）
            </Title>
            <p>当社は以下に起因する損害について責任を負いません：</p>
            <List className={classes.list} withPadding>
                <List.Item>不正なアカウント使用</List.Item>
                <List.Item>システムの中断</List.Item>
                <List.Item>利用者の操作ミス</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                第8条（利用規約の変更）
            </Title>
            <p>当社は必要に応じて本規約を改訂することがあります。</p>

            <Title order={3} mt="lg" mb="md">
                第9条（お問い合わせ）
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

export default AdminTermsOfServiceJaPage;
