import { Box, List, Text, Title } from '@mantine/core';
import classes from './index.module.css';

const PrivatePolicyJaPage = () => {
    return (
        <Box p="xl">
            <Title order={1}>DGF Corporation Privacy Policy</Title>
            <p>
                DGF Corporation (hereinafter referred to as "the Company") has established the following policy
                regarding the handling of personal information, announces it, and declares its compliance.
            </p>
            <Title order={3} mt="lg" mb="md">
                Article 1 (Definition of Personal Information)
            </Title>
            <p>
                "Personal Information" refers to "personal information" as defined under the Personal Information
                Protection Act, which means information about a living individual that can identify a specific
                individual by name, date of birth, phone number, email address, address, workplace, student ID,
                residence card information, or other descriptions (including information that can be easily
                cross-referenced with other information to identify a specific individual).
            </p>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>
                    Information that includes the name, date of birth, phone number, email address, address, workplace,
                    student ID, residence card information, or other descriptions that can identify a specific
                    individual (including information that can be easily cross-referenced with other information to
                    identify a specific individual).
                </List.Item>
                <List.Item>Information that includes personal identification codes.</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Article 2 (Personal Information Protection Policy)
            </Title>
            <p>
                In our temporary staffing and paid employment placement business, we have established the following
                personal information protection policies and will thoroughly promote efforts to protect personal
                information while recognizing its importance.
            </p>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>
                    Specification and Compliance with the Purpose of Use
                    <br />
                    We will clarify the purpose of use of personal information as much as possible and handle it
                    appropriately only within the scope necessary to achieve that purpose. We will also take measures to
                    prevent use outside the stated purpose.
                </List.Item>
                <List.Item>
                    Proper Acquisition and Appointment of a Management Officer
                    <br />
                    Personal information will be acquired lawfully and appropriately. A management officer will be
                    appointed to ensure proper management, and employee education will be thoroughly implemented.
                </List.Item>
                <List.Item>
                    Restriction and Proper Management of Third-Party Provision
                    <br />
                    Personal information will not be provided to third parties without the individual's consent or
                    unless required by law. When provision is necessary, appropriate agreements will be concluded, and
                    thorough information management will be required.
                </List.Item>
                <List.Item>
                    Implementation of Safety Management Measures
                    <br />
                    To prevent leakage, loss, or damage of personal information, appropriate safety management measures
                    will be taken, along with continuous review and corrective actions.
                </List.Item>
                <List.Item>
                    Ensuring Accuracy and Up-to-Date Information
                    <br />
                    Personal information will be kept as accurate and up-to-date as possible, and requests for
                    disclosure, correction, or deletion from the individual will be promptly addressed within reasonable
                    limits.
                </List.Item>
                <List.Item>
                    Compliance with Laws and Internal Regulations and Continuous Improvement
                    <br />
                    We will comply with laws, guidelines, and internal regulations related to personal information
                    protection and strive for improvement of internal rules through regular review.
                </List.Item>
                <List.Item>
                    Establishment of Complaint and Consultation Desk and Appropriate Response
                    <br />A contact desk will be set up to handle complaints and consultations regarding the handling of
                    personal information, and a system will be established to respond promptly and appropriately.
                </List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Article 3 (Use of Personal Information)
            </Title>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>
                    Acquisition of Personal Information
                    <List className={classes.list}>
                        <List.Item>
                            For the smooth execution of business, we may acquire personal information such as name, date
                            of birth, phone number, email address, and address from individuals when receiving
                            inquiries.
                        </List.Item>
                        <List.Item>Facial image (only collected with your explicit consent).</List.Item>
                        <List.Item>
                            Additionally, location information (GPS coordinates) may be acquired when using the app's
                            time-stamp function.
                        </List.Item>
                    </List>
                </List.Item>
                <List.Item>
                    Use of Personal Information
                    <br />
                    Personal information acquired by the Company will be used within the scope of the purpose clearly
                    specified at the time of acquisition.
                </List.Item>
                <List.Item>
                    Methods of Storing Personal Information
                    <List className={classes.list}>
                        <List.Item>
                            Personal data, including portrait photos, is stored on secure servers with strict protection
                            measures.
                        </List.Item>
                        <List.Item>All data is encrypted during storage and transmission to ensure security.</List.Item>
                        <List.Item>Only authorized personnel within the company have access to this data.</List.Item>
                        <List.Item>
                            Facial images will be stored for 3 months from the date of collection for identity
                            verification purposes. After this period, the data will be permanently deleted from the
                            system unless otherwise required by law.
                        </List.Item>
                    </List>
                </List.Item>
                <List.Item>
                    Compliance with Laws
                    <br />
                    We will comply with applicable laws and regulations regarding the personal information we hold.
                </List.Item>
                <List.Item>
                    Improvement and Review of Personal Information Handling
                    <br />
                    The company will continuously improve the management system and mechanisms for the protection of
                    personal information.
                </List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Article 4 (Purpose of Use)
            </Title>
            <p>The purposes for which the company uses acquired personal information are as follows:</p>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>
                    For the acceptance and processing of dispatch registration and job seeker registration.
                </List.Item>
                <List.Item>
                    For job introductions, communication regarding employment, and employment management.
                </List.Item>
                <List.Item>For payment of salary and handling social insurance and tax procedures.</List.Item>
                <List.Item>For contract conclusion and business management with business partners.</List.Item>
                <List.Item>
                    For health management, safety management, and improvement of the work environment.
                </List.Item>
                <List.Item>For providing training, career consulting, and course management.</List.Item>
                <List.Item>For compliance with laws and response to administrative and public agencies.</List.Item>
                <List.Item>For safety confirmation and communication during disasters and emergencies.</List.Item>
                <List.Item>For other necessary responses related to labor management and business execution.</List.Item>
                <List.Item>
                    Use of location information (GPS) during time-stamping
                    <List className={classes.list} type="ordered">
                        <List.Item>
                            Confirmation of time-stamping location: To verify time-stamping was done at the designated
                            workplace.
                        </List.Item>
                        <List.Item>
                            Guidance on travel routes: To provide appropriate travel directions when necessary.
                        </List.Item>
                    </List>
                </List.Item>
                <List.Item>
                    We collect your facial image solely for identity verification in the following processes:
                    <List className={classes.list} type="ordered">
                        <List.Item>
                            Identity verification for processes related to employment contracts and employee records as
                            required by law.
                        </List.Item>
                    </List>
                    <strong>We do not use facial images for any purposes other than those stated above.</strong>
                </List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Article 5 (Disclosure of Personal Information to Third Parties)
            </Title>
            <p>
                The Company will not disclose acquired personal information to third parties without prior consent from
                the individual. However, the Company may disclose personal information without prior consent from the
                individual in the following cases:
            </p>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>When disclosure is required by law.</List.Item>
                <List.Item>
                    When deemed necessary to protect the rights, interests, honor, or credit of the Company, other
                    individuals, or third parties.
                </List.Item>
                <List.Item>
                    When the individual has given prior consent to disclose their personal information.
                </List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Article 6 (Inquiries About Refusal of Use or Provision of Personal Information)
            </Title>
            <p>
                If you do not wish for the personal information you have provided to the Company to be used or provided
                to third parties within the scope of the stated purpose, we will handle such requests appropriately.
            </p>

            <Title order={3} mt="lg" mb="md">
                Article 7 (Joint Use of Personal Information)
            </Title>
            <p>
                The Company may jointly use the personal information of business partners under the following
                conditions:
            </p>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>
                    Scope of Joint Use
                    <br />
                    Group companies of the Company (DGF Corporation).
                </List.Item>
                <List.Item>
                    Purpose of Joint Use
                    <br />
                    Same as the purposes described in "1. Purpose of Use of Personal Information."
                </List.Item>
                <List.Item>
                    Items of Personal Information for Joint Use
                    <br />
                    Name, address, postal code, phone number, gender, date of birth, email address, workplace, residence
                    card information, nationality, and other necessary personal information.
                </List.Item>
                <List.Item>
                    Responsible Party for Management of Jointly Used Personal Information
                    <br />
                    DGF Corporation Personal Information Protection Manager.
                </List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Article 8 (Outsourcing of Personal Information)
            </Title>
            <p>
                The Company may outsource the handling of personal information provided by individuals to other
                companies if necessary to achieve the stated purposes of use.
            </p>

            <Title order={3} mt="lg" mb="md">
                Article 9 (Correction and Deletion of Personal Information)
            </Title>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>
                    If the personal information we hold is inaccurate, you may request its correction or deletion.
                </List.Item>
                <List.Item>
                    Upon receiving your request, if we decide to correct or delete your personal information, we will
                    follow our designated procedures and notify you promptly after completion.
                </List.Item>
                <List.Item>
                    Facial images will be automatically deleted from the system after 3 months from the date of
                    collection. If you wish to have them deleted earlier, you may submit a request, and we will process
                    it within 14 days from the date of receipt.
                </List.Item>
                <List.Item>
                    Unless otherwise stipulated by law, we do not return any submitted personal information, regardless
                    of the format (paper or digital). Additionally, any personal information deemed unnecessary by our
                    company will be appropriately disposed of.
                </List.Item>
            </List>
            <p>
                ※ After registration (temporary staffing or job-seeking registration), regardless of employment status,
                if 15 years have passed since the last contact with our company via phone, email, or personal account,
                your personal information will be disposed of without prior notice.
            </p>

            <Title order={3} mt="lg" mb="md">
                Article 10 (Contact for Complaints and Consultations)
            </Title>
            <p>
                The Company will accept complaints and consultations regarding the handling of personal information. For
                inquiries, complaints, or consultations, please contact the following desk:
            </p>
            <p>
                DGF Corporation Consultation Desk
                <br />
                Phone: 048-423-5398
                <br />
                FAX: 048-423-5397
                <br />
                Email: <a href="mailto:staffkanri@dgfjapan.com">staffkanri@dgfjapan.com</a>
                <br />
            </p>
            <Text ta="right">
                Established and enforced on March 5, 2025
                <br />
                <br />
                DGF Corporation
                <br />
                〒353-0004
                <br />
                2F, 18-13 Honcho 5-chome, Shiki City, Saitama Prefecture
                <br />
                President and Representative Director: Nguyen Tuan Duong
            </Text>
        </Box>
    );
};

export default PrivatePolicyJaPage;
