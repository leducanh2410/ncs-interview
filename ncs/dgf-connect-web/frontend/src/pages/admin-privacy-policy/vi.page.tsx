import { Box, List, Title } from '@mantine/core';
import classes from './index.module.css';

const AdminPrivacyPolicyViPage = () => {
    return (
        <Box p="xl">
            <Title order={1}>Chính Sách Bảo Mật Ứng Dụng Quản Trị của Công Ty DGF</Title>
            <p>
                Công Ty DGF (sau đây gọi là "Công Ty") đã xây dựng chính sách sau đây liên quan đến việc xử lý thông tin
                cá nhân trong Ứng Dụng Quản Trị.
            </p>

            <Title order={3} mt="lg" mb="md">
                Điều 1 (Định nghĩa thông tin cá nhân)
            </Title>
            <p>
                "Thông tin cá nhân" là thông tin có thể xác định được một cá nhân cụ thể, bao gồm tên, địa chỉ email, mã
                nhân viên, thông tin nơi làm việc, ảnh hồ sơ, thông tin chấm công và các dữ liệu liên quan khác.
            </p>

            <Title order={3} mt="lg" mb="md">
                Điều 2 (Mục đích sử dụng)
            </Title>
            <p>
                Ứng Dụng Quản Trị được cung cấp cho các quản lý và quản trị viên được ủy quyền để thực hiện quản lý nhân
                viên và chấm công.
            </p>
            <p>Thông tin cá nhân có thể được sử dụng cho các mục đích sau:</p>
            <List className={classes.list} withPadding>
                <List.Item>Quản lý danh sách nhân viên</List.Item>
                <List.Item>Quản lý chấm công</List.Item>
                <List.Item>Quản lý ca làm việc và nơi làm việc</List.Item>
                <List.Item>Xác minh danh tính</List.Item>
                <List.Item>Hỗ trợ vận hành</List.Item>
                <List.Item>Bảo mật và bảo trì hệ thống</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Điều 3 (Thông tin chúng tôi thu thập)
            </Title>
            <p>Công Ty có thể thu thập và quản lý các thông tin sau:</p>
            <List className={classes.list} withPadding>
                <List.Item>Họ tên</List.Item>
                <List.Item>Địa chỉ email</List.Item>
                <List.Item>Mã nhân viên</List.Item>
                <List.Item>Ảnh hồ sơ</List.Item>
                <List.Item>Thông tin vai trò và quyền hạn</List.Item>
                <List.Item>Hồ sơ chấm công</List.Item>
                <List.Item>Thông tin nhà máy và nơi làm việc</List.Item>
                <List.Item>Nhật ký hoạt động của quản trị viên</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Điều 4 (Tài khoản quản trị viên)
            </Title>
            <p>Tài khoản quản trị viên chỉ được cấp cho những người được ủy quyền.</p>
            <p>Quản trị viên có trách nhiệm bảo mật tài khoản và mật khẩu của mình.</p>

            <Title order={3} mt="lg" mb="md">
                Điều 5 (Thao tác chấm công)
            </Title>
            <p>
                Các quản trị viên được ủy quyền có thể thực hiện các thao tác liên quan đến chấm công, bao gồm đăng ký
                chấm công và điều chỉnh chấm công cho nhân viên khi cần thiết.
            </p>
            <p>Lịch sử thao tác có thể được ghi lại nhằm mục đích bảo mật và quản lý hệ thống.</p>

            <Title order={3} mt="lg" mb="md">
                Điều 6 (Bảo vệ dữ liệu)
            </Title>
            <p>
                Công Ty thực hiện các biện pháp bảo mật hợp lý để bảo vệ thông tin cá nhân khỏi truy cập trái phép, mất
                mát, rò rỉ hoặc thay đổi.
            </p>
            <p>
                Chỉ những người được ủy quyền mới được truy cập thông tin nhân viên trong phạm vi cần thiết cho nhiệm vụ
                vận hành.
            </p>

            <Title order={3} mt="lg" mb="md">
                Điều 7 (Tiết lộ cho bên thứ ba)
            </Title>
            <p>Công Ty sẽ không tiết lộ thông tin cá nhân cho bên thứ ba ngoại trừ:</p>
            <List className={classes.list} withPadding>
                <List.Item>Khi được yêu cầu theo quy định của pháp luật</List.Item>
                <List.Item>Khi cần thiết cho hoạt động hệ thống</List.Item>
                <List.Item>Khi đã được sự đồng ý của cá nhân</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Điều 8 (Chỉnh sửa và xóa)
            </Title>
            <p>
                Cá nhân có thể yêu cầu chỉnh sửa hoặc xóa thông tin cá nhân không chính xác theo quy trình của Công Ty.
            </p>

            <Title order={3} mt="lg" mb="md">
                Điều 9 (Thay đổi chính sách)
            </Title>
            <p>
                Công Ty có thể cập nhật Chính Sách Bảo Mật này khi cần thiết. Các phiên bản cập nhật sẽ được đăng tải
                trong ứng dụng hoặc trang web.
            </p>

            <Title order={3} mt="lg" mb="md">
                Điều 10 (Liên hệ)
            </Title>
            <p>
                Công Ty DGF
                <br />
                Điện thoại: 048-423-5398
                <br />
                Email: <a href="mailto:staffkanri@dgfjapan.com">staffkanri@dgfjapan.com</a>
            </p>
        </Box>
    );
};

export default AdminPrivacyPolicyViPage;
