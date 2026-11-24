import { Box, List, Title } from '@mantine/core';
import classes from './index.module.css';

const AdminTermsOfServiceEnPage = () => {
    return (
        <Box p="xl">
            <Title order={1}>DGF Corporation Admin Terms of Service</Title>
            <p>These Terms of Service govern the use of the Admin Application provided by DGF Corporation.</p>

            <Title order={3} mt="lg" mb="md">
                Article 1 (Eligibility)
            </Title>
            <p>
                The Admin Application may only be used by authorized administrators and managers approved by the
                Company.
            </p>

            <Title order={3} mt="lg" mb="md">
                Article 2 (Administrator Accounts)
            </Title>
            <p>Users are responsible for maintaining the confidentiality of their login credentials.</p>
            <p>Account sharing and unauthorized access are prohibited.</p>

            <Title order={3} mt="lg" mb="md">
                Article 3 (Permitted Use)
            </Title>
            <p>The application may be used for:</p>
            <List className={classes.list} withPadding>
                <List.Item>Employee management</List.Item>
                <List.Item>Attendance management</List.Item>
                <List.Item>Factory management</List.Item>
                <List.Item>Operational support</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Article 4 (Prohibited Activities)
            </Title>
            <p>Users must not:</p>
            <List className={classes.list} withPadding>
                <List.Item>Access unauthorized information</List.Item>
                <List.Item>Use the application for unlawful purposes</List.Item>
                <List.Item>Modify attendance records fraudulently</List.Item>
                <List.Item>Disclose confidential information improperly</List.Item>
                <List.Item>Interfere with system operations</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Article 5 (System Monitoring)
            </Title>
            <p>The Company may record administrator activities for security and operational management purposes.</p>

            <Title order={3} mt="lg" mb="md">
                Article 6 (Suspension and Termination)
            </Title>
            <p>The Company may suspend or terminate access if these Terms are violated.</p>

            <Title order={3} mt="lg" mb="md">
                Article 7 (Limitation of Liability)
            </Title>
            <p>The Company shall not be liable for damages caused by:</p>
            <List className={classes.list} withPadding>
                <List.Item>Unauthorized account use</List.Item>
                <List.Item>System interruption</List.Item>
                <List.Item>User operational errors</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Article 8 (Changes to the Terms)
            </Title>
            <p>The Company may revise these Terms when necessary.</p>

            <Title order={3} mt="lg" mb="md">
                Article 9 (Contact)
            </Title>
            <p>
                DGF Corporation
                <br />
                Phone: 048-423-5398
                <br />
                Email: <a href="mailto:staffkanri@dgfjapan.com">staffkanri@dgfjapan.com</a>
            </p>
        </Box>
    );
};

export default AdminTermsOfServiceEnPage;
