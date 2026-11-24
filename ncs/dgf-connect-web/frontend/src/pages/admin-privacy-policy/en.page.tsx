import { Box, List, Title } from '@mantine/core';
import classes from './index.module.css';

const AdminPrivacyPolicyEnPage = () => {
    return (
        <Box p="xl">
            <Title order={1}>DGF Corporation Admin Privacy Policy</Title>
            <p>
                DGF Corporation (hereinafter referred to as "the Company") has established the following policy
                regarding the handling of personal information within the Admin Application.
            </p>

            <Title order={3} mt="lg" mb="md">
                Article 1 (Definition of Personal Information)
            </Title>
            <p>
                "Personal Information" refers to information that can identify a specific individual, including name,
                email address, employee ID, workplace information, profile image, attendance information, and other
                related data.
            </p>

            <Title order={3} mt="lg" mb="md">
                Article 2 (Purpose of Use)
            </Title>
            <p>
                The Admin Application is provided for authorized managers and administrators to perform employee and
                attendance management.
            </p>
            <p>Personal information may be used for the following purposes:</p>
            <List className={classes.list} withPadding>
                <List.Item>Employee list management</List.Item>
                <List.Item>Attendance management</List.Item>
                <List.Item>Shift and workplace management</List.Item>
                <List.Item>Identity verification</List.Item>
                <List.Item>Operational support</List.Item>
                <List.Item>System security and maintenance</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Article 3 (Information We Collect)
            </Title>
            <p>The Company may collect and manage the following information:</p>
            <List className={classes.list} withPadding>
                <List.Item>Name</List.Item>
                <List.Item>Email address</List.Item>
                <List.Item>Employee ID</List.Item>
                <List.Item>Profile image</List.Item>
                <List.Item>Role and permission information</List.Item>
                <List.Item>Attendance records</List.Item>
                <List.Item>Factory and workplace information</List.Item>
                <List.Item>Administrator activity logs</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Article 4 (Administrator Accounts)
            </Title>
            <p>Administrator accounts are issued only to authorized personnel.</p>
            <p>Administrators are responsible for maintaining the confidentiality of their accounts and passwords.</p>

            <Title order={3} mt="lg" mb="md">
                Article 5 (Attendance Operations)
            </Title>
            <p>
                Authorized administrators may perform attendance-related operations, including attendance registration
                and attendance correction for employees when necessary.
            </p>
            <p>Operational history may be recorded for security and system management purposes.</p>

            <Title order={3} mt="lg" mb="md">
                Article 6 (Data Protection)
            </Title>
            <p>
                The Company implements reasonable security measures to protect personal information from unauthorized
                access, loss, leakage, or alteration.
            </p>
            <p>
                Only authorized personnel may access employee information within the scope necessary for operational
                duties.
            </p>

            <Title order={3} mt="lg" mb="md">
                Article 7 (Disclosure to Third Parties)
            </Title>
            <p>The Company will not disclose personal information to third parties except:</p>
            <List className={classes.list} withPadding>
                <List.Item>When required by law</List.Item>
                <List.Item>When necessary for system operation</List.Item>
                <List.Item>When consent has been obtained from the individual</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Article 8 (Correction and Deletion)
            </Title>
            <p>
                Individuals may request correction or deletion of inaccurate personal information in accordance with the
                Company's procedures.
            </p>

            <Title order={3} mt="lg" mb="md">
                Article 9 (Changes to this Policy)
            </Title>
            <p>
                The Company may update this Privacy Policy when necessary. Updated versions will be published within the
                application or website.
            </p>

            <Title order={3} mt="lg" mb="md">
                Article 10 (Contact)
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

export default AdminPrivacyPolicyEnPage;
