import { Box, List, Title } from '@mantine/core';
import classes from './index.module.css';

const TermsOfServiceEnPage = () => {
    return (
        <Box p="xl">
            <Title order={1}>Terms of Service</Title>

            <Title order={3} mt="lg" mb="md">
                Article 1 (Scope of Application)
            </Title>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>
                    The following terms and conditions ("Terms") set forth the conditions for the use of the service
                    ("Service") provided by DGF Corporation ("Company").
                </List.Item>
                <List.Item>
                    All users of the Service ("Users") must comply with these Terms when using the Service.
                </List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Article 2 (Prohibited Acts)
            </Title>
            <p>Users are prohibited from engaging in the following acts when using the Service:</p>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>Acts that violate laws or public order and morals.</List.Item>
                <List.Item>
                    In case of any changes, we will notify users through a method determined by us or announce it on the
                    website.
                </List.Item>
                <List.Item>Fraudulent or exploitative acts.</List.Item>
                <List.Item>
                    Infringement of intellectual property rights, image rights, privacy rights, honor, or other rights.
                </List.Item>
                <List.Item>Harassment, disruption, or any action that affects the operation of the Service.</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Article 3 (Temporary Suspension of Service)
            </Title>
            <p>The Company may temporarily suspend or restrict the Service in the following cases:</p>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>System maintenance or improvement.</List.Item>
                <List.Item>Technical or system issues.</List.Item>
                <List.Item>Necessities due to safety or legal reasons.</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Article 4 (Disclaimer)
            </Title>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>The Company shall not be liable for any damages caused by the use of the Service.</List.Item>
                <List.Item>
                    The Company does not guarantee that the Service will be error-free or absolutely secure.
                </List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Article 5 (Amendment of Terms)
            </Title>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>The Company may amend the contents of these Terms without prior notice.</List.Item>
                <List.Item>
                    In case of any changes, the Company will notify users via the website or other appropriate means.
                </List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Article 6 (Contact Information)
            </Title>
            <p>For any inquiries regarding these Terms, please contact:</p>
            <p>
                Address: 5-18-13 Honcho, Shiki-shi, Saitama, Japan
                <br />
                Company: DGF Corporation
                <br />
                CEO: Nguyen Tuan Duong
                <br />
                Contact Department: Headquarters
                <br />
                Email: <a href="mailto:staffkanri@dgfjapan.com">staffkanri@dgfjapan.com</a>
                <br />
            </p>
        </Box>
    );
};

export default TermsOfServiceEnPage;
