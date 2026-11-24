import { Box, List, Title } from '@mantine/core';
import classes from './index.module.css';

const TermsOfServiceViPage = () => {
    return (
        <Box p="xl">
            <Title order={1}>Điều Khoản Dịch Vụ</Title>
            <Title order={3} mt="lg" mb="md">
                Điều 1 (Phạm vi áp dụng)
            </Title>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>
                    Những điều khoản dưới đây (“Điều khoản”) quy định các điều kiện sử dụng dịch vụ (“Dịch vụ”) do Công
                    ty DGF (“Công ty”) cung cấp.
                </List.Item>
                <List.Item>
                    Mọi người dùng dịch vụ (“Người dùng”) phải tuân thủ các quy định trong Điều khoản này khi sử dụng
                    Dịch vụ.
                </List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Điều 2 (Các hành vi bị cấm)
            </Title>
            <p>Người dùng không được thực hiện những hành vi sau khi sử dụng Dịch vụ:</p>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>Vi phạm luật pháp hoặc trái với thuần phong mỹ tục.</List.Item>
                <List.Item>
                    Khi có thay đổi, chúng tôi sẽ thông báo cho người dùng bằng phương thức do chúng tôi quy định hoặc
                    công bố trên trang web.
                </List.Item>
                <List.Item>Hành vi gian lận, lợi dụng.</List.Item>
                <List.Item>
                    Xâm phạm quyền sở hữu trí tuệ, quyền hình ảnh, quyền riêng tư, danh dự hoặc các quyền khác.
                </List.Item>
                <List.Item>Các hành vi quấy rối, phá hoại, ảnh hưởng đến hoạt động của Dịch vụ.</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Điều 3 (Tạm ngưng cung cấp Dịch vụ)
            </Title>
            <p>Công ty có thể tạm ngưng hoặc hạn chế Dịch vụ trong các trường hợp sau:</p>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>Bảo trì, cải thiện hệ thống.</List.Item>
                <List.Item>Xảy ra các vấn đề về kỹ thuật, hệ thống.</List.Item>
                <List.Item>Sự cần thiết vì an toàn hoặc pháp lý.</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Điều 4 (Miễn trừ trách nhiệm)
            </Title>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>
                    Công ty không chịu trách nhiệm với bất kỳ thiệt hại nào gây ra bởi việc sử dụng Dịch vụ.
                </List.Item>
                <List.Item>Công ty không đảm bảo Dịch vụ sẽ không bị lỗi hoặc an toàn tuyệt đối.</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Điều 5 (Sửa đổi Điều khoản)
            </Title>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>Công ty có thể sửa đổi nội dung Điều khoản mà không cần báo trước.</List.Item>
                <List.Item>
                    Nếu có thay đổi, Công ty sẽ thông báo trên trang web hoặc qua các phương tiện thích hợp.
                </List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Điều 6 (Liên hệ)
            </Title>
            <p>Mọi thắc mắc về Điều khoản này vui lòng liên hệ:</p>
            <p>
                Địa chỉ: 5-18-13 Honcho, Shiki-shi, Saitama, Nhật Bản
                <br />
                Công ty: Công ty Cổ phần DGF
                <br />
                Giám đốc điều hành: Nguyễn Tuấn Dương
                <br />
                Bộ phận liên hệ: Trụ sở chính
                <br />
                Email: <a href="mailto:staffkanri@dgfjapan.com">staffkanri@dgfjapan.com</a>
                <br />
            </p>
        </Box>
    );
};

export default TermsOfServiceViPage;
