import { Box, List, Title } from '@mantine/core';
import classes from './index.module.css';

const TermsOfServiceJaPage = () => {
    return (
        <Box p="xl">
            <Title order={1}>利用規約</Title>
            <Title order={3} mt="lg" mb="md">
                第1条（適用範囲）
            </Title>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>
                    以下の規約（「本規約」）は、株式会社DGF（「当社」）が提供するサービス（「本サービス」）の利用条件を定めるものです。
                </List.Item>
                <List.Item>本サービスを利用する全てのユーザー（「ユーザー」）は、本規約に従うものとします。</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                第2条（禁止事項）
            </Title>
            <p>ユーザーは、本サービスを利用するにあたり、以下の行為を行ってはなりません。</p>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>法律に違反する行為や公序良俗に反する行為。</List.Item>
                <List.Item>
                    変更がある場合、当社は適切な方法でユーザーに通知またはウェブサイト上で公表します。
                </List.Item>
                <List.Item>詐欺や不正利用行為。</List.Item>
                <List.Item>知的財産権、肖像権、プライバシー権、名誉などの権利を侵害する行為。</List.Item>
                <List.Item>本サービスの運営を妨害する行為。</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                第3条（サービス提供の一時停止）
            </Title>
            <p>当社は、以下の場合において、本サービスを一時的に停止または制限することができます。</p>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>システムのメンテナンスや改善。</List.Item>
                <List.Item>技術的またはシステム上の問題の発生。</List.Item>
                <List.Item>安全性または法的な理由による必要性。</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                第4条（免責事項）
            </Title>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>当社は、本サービスの利用によって生じた損害について一切の責任を負いません。</List.Item>
                <List.Item>本サービスが常に正確かつ安全であることを保証するものではありません。</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                第5条（規約の変更）
            </Title>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>当社は、事前の通知なしに本規約を変更することができます。</List.Item>
                <List.Item>変更がある場合、当社はウェブサイトまたは適切な方法で通知します。</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                第6条（お問い合わせ）
            </Title>
            <p>本規約に関するお問い合わせは、以下までお願いいたします。</p>
            <p>
                住所：埼玉県志木市本町5-18-13
                <br />
                会社名：株式会社DGF
                <br />
                代表取締役：グエン・テュアン・ズオン
                <br />
                問い合わせ窓口：本社
                <br />
                Eメール：<a href="mailto:staffkanri@dgfjapan.com">staffkanri@dgfjapan.com</a>
                <br />
            </p>
        </Box>
    );
};

export default TermsOfServiceJaPage;
