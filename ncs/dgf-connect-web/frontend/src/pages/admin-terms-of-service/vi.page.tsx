import { Box, List, Title } from '@mantine/core';
import classes from './index.module.css';

const AdminTermsOfServiceViPage = () => {
    return (
        <Box p="xl">
            <Title order={1}>Điều Khoản Dịch Vụ Ứng Dụng Quản Trị của Công Ty DGF</Title>
            <p>Các Điều Khoản Dịch Vụ này điều chỉnh việc sử dụng Ứng Dụng Quản Trị do Công Ty DGF cung cấp.</p>

            <Title order={3} mt="lg" mb="md">
                Điều 1 (Điều kiện sử dụng)
            </Title>
            <p>
                Ứng Dụng Quản Trị chỉ được sử dụng bởi các quản trị viên và quản lý được Công Ty ủy quyền và phê duyệt.
            </p>

            <Title order={3} mt="lg" mb="md">
                Điều 2 (Tài khoản quản trị viên)
            </Title>
            <p>Người dùng có trách nhiệm bảo mật thông tin đăng nhập của mình.</p>
            <p>Việc chia sẻ tài khoản và truy cập trái phép là nghiêm cấm.</p>

            <Title order={3} mt="lg" mb="md">
                Điều 3 (Sử dụng được phép)
            </Title>
            <p>Ứng dụng được sử dụng cho:</p>
            <List className={classes.list} withPadding>
                <List.Item>Quản lý nhân viên</List.Item>
                <List.Item>Quản lý chấm công</List.Item>
                <List.Item>Quản lý nhà máy</List.Item>
                <List.Item>Hỗ trợ vận hành</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Điều 4 (Các hoạt động bị cấm)
            </Title>
            <p>Người dùng không được:</p>
            <List className={classes.list} withPadding>
                <List.Item>Truy cập thông tin trái phép</List.Item>
                <List.Item>Sử dụng ứng dụng cho mục đích bất hợp pháp</List.Item>
                <List.Item>Gian lận hồ sơ chấm công</List.Item>
                <List.Item>Tiết lộ thông tin bảo mật không đúng quy định</List.Item>
                <List.Item>Làm gián đoạn hoạt động hệ thống</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Điều 5 (Giám sát hệ thống)
            </Title>
            <p>Công Ty có thể ghi lại hoạt động của quản trị viên nhằm mục đích bảo mật và quản lý vận hành.</p>

            <Title order={3} mt="lg" mb="md">
                Điều 6 (Tạm ngừng và chấm dứt)
            </Title>
            <p>Công Ty có thể tạm ngừng hoặc chấm dứt quyền truy cập nếu các Điều Khoản này bị vi phạm.</p>

            <Title order={3} mt="lg" mb="md">
                Điều 7 (Giới hạn trách nhiệm)
            </Title>
            <p>Công Ty không chịu trách nhiệm về các thiệt hại do:</p>
            <List className={classes.list} withPadding>
                <List.Item>Sử dụng tài khoản trái phép</List.Item>
                <List.Item>Gián đoạn hệ thống</List.Item>
                <List.Item>Lỗi vận hành của người dùng</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Điều 8 (Thay đổi điều khoản)
            </Title>
            <p>Công Ty có thể sửa đổi các Điều Khoản này khi cần thiết.</p>

            <Title order={3} mt="lg" mb="md">
                Điều 9 (Liên hệ)
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

export default AdminTermsOfServiceViPage;
