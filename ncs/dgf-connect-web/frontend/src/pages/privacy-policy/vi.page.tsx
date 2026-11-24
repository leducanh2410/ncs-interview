import { Box, List, Text, Title } from '@mantine/core';
import classes from './index.module.css';

const PrivatePolicyViPage = () => {
    return (
        <Box p="xl">
            <Title order={1}>Chính sách bảo mật của Công ty TNHH DGF</Title>
            <p>
                Công ty TNHH DGF phát triển nguồn nhân lực (sau đây gọi là "Công ty") tuyên bố rằng chúng tôi đã xác
                định chính sách liên quan đến việc xử lý thông tin cá nhân như sau và công bố nó cũng như cam kết tuân
                thủ.
            </p>
            <Title order={3} mt="lg" mb="md">
                Điều 1 (Định nghĩa thông tin cá nhân)
            </Title>
            <p>
                "Thông tin cá nhân" có nghĩa là "thông tin cá nhân" theo Luật bảo vệ thông tin cá nhân, là thông tin
                liên quan đến một cá nhân còn sống, mà thuộc vào một trong những trường hợp sau đây.
            </p>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>
                    Thông tin bao gồm tên, ngày sinh, số điện thoại, địa chỉ email, địa chỉ, nơi làm việc, thẻ sinh
                    viên, thông tin thẻ cư trú và các mô tả khác có thể xác định được một cá nhân cụ thể (bao gồm cả
                    những thông tin có thể dễ dàng đối chiếu với thông tin khác để xác định một cá nhân cụ thể).
                </List.Item>
                <List.Item>Thông tin có chứa mã số nhận dạng cá nhân.</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Điều 2 (Chính sách bảo vệ thông tin cá nhân)
            </Title>
            <p>
                Công ty xác định chính sách bảo vệ thông tin cá nhân trong các lĩnh vực cung cấp lao động và giới thiệu
                việc làm như sau, nhận thức được tầm quan trọng của việc bảo vệ thông tin cá nhân và sẽ thực hiện các nỗ
                lực bảo vệ thông tin cá nhân.
            </p>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>
                    Xác định và tuân thủ mục đích sử dụng thông tin cá nhân
                    <br />
                    Chúng tôi sẽ làm rõ mục đích sử dụng thông tin cá nhân và chỉ xử lý thông tin đó trong phạm vi cần
                    thiết để đạt được mục đích. Ngoài ra, chúng tôi sẽ thực hiện các biện pháp để ngăn chặn việc sử dụng
                    không đúng mục đích.
                </List.Item>
                <List.Item>
                    Thu thập hợp pháp và chỉ định người chịu trách nhiệm quản lý
                    <br />
                    Chúng tôi sẽ thu thập thông tin cá nhân bằng các phương pháp hợp pháp và thích hợp. Ngoài ra, chúng
                    tôi sẽ chỉ định người chịu trách nhiệm quản lý để thực hiện quản lý thích hợp và tiến hành đào tạo
                    cho nhân viên.
                </List.Item>
                <List.Item>
                    Hạn chế cung cấp cho bên thứ ba và quản lý thích hợp
                    <br />
                    Chúng tôi sẽ không cung cấp thông tin cá nhân cho bên thứ ba mà không có sự đồng ý của cá nhân hoặc
                    theo quy định của pháp luật. Nếu cần thiết phải cung cấp, chúng tôi sẽ ký kết hợp đồng thích hợp và
                    yêu cầu quản lý thông tin nghiêm ngặt.
                </List.Item>
                <List.Item>
                    Thực hiện các biện pháp quản lý an toàn
                    <br />
                    Để ngăn chặn việc rò rỉ, mất mát hoặc hư hỏng thông tin cá nhân, chúng tôi sẽ thực hiện các biện
                    pháp quản lý an toàn thích hợp và tiến hành xem xét và điều chỉnh liên tục.
                </List.Item>
                <List.Item>
                    Đảm bảo tính chính xác và cập nhật
                    <br />
                    Chúng tôi sẽ giữ thông tin cá nhân ở trạng thái chính xác và cập nhật nhất có thể, và sẽ nhanh chóng
                    phản hồi yêu cầu tiết lộ, chỉnh sửa hoặc xóa thông tin từ cá nhân trong phạm vi hợp lý.
                </List.Item>
                <List.Item>
                    Tuân thủ pháp luật và quy định nội bộ, cải tiến liên tục
                    <br />
                    Chúng tôi sẽ tuân thủ các luật và hướng dẫn liên quan đến bảo vệ thông tin cá nhân và các quy định
                    nội bộ, đồng thời nỗ lực cải tiến quy định nội bộ thông qua việc xem xét định kỳ.
                </List.Item>
                <List.Item>
                    Thiết lập kênh tiếp nhận khiếu nại và tư vấn, và phản hồi thích hợp
                    <br />
                    Chúng tôi sẽ thiết lập kênh tiếp nhận khiếu nại và tư vấn liên quan đến việc xử lý thông tin cá nhân
                    và chuẩn bị hệ thống để phản hồi một cách thích hợp và nhanh chóng.
                </List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Điều 3 (Về việc sử dụng thông tin cá nhân)
            </Title>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>
                    Thu thập thông tin cá nhân
                    <List className={classes.list}>
                        <List.Item>
                            Để thực hiện công việc một cách suôn sẻ, chúng tôi sẽ thu thập thông tin cá nhân như tên,
                            ngày sinh, số điện thoại, địa chỉ email, địa chỉ, v.v. khi tiếp nhận yêu cầu từ cá nhân.
                        </List.Item>
                        <List.Item>Ảnh khuôn mặt (chỉ được thu thập khi có sự đồng ý rõ ràng của bạn).</List.Item>
                        <List.Item>
                            Ngoài ra, khi sử dụng chức năng chấm công của ứng dụng, chúng tôi có thể thu thập thông tin
                            vị trí hiện tại (tọa độ GPS).
                        </List.Item>
                    </List>
                </List.Item>
                <List.Item>
                    Sử dụng thông tin cá nhân
                    <br />
                    Thông tin cá nhân mà chúng tôi thu thập sẽ được sử dụng trong phạm vi đã xác định rõ mục đích sử
                    dụng.
                </List.Item>
                <List.Item>
                    Phương Thức Lưu Trữ Thông Tin Cá Nhân
                    <List className={classes.list}>
                        <List.Item>
                            Dữ liệu cá nhân, bao gồm ảnh chân dung, được lưu trữ trên máy chủ bảo mật với các biện pháp
                            bảo vệ nghiêm ngặt.
                        </List.Item>
                        <List.Item>
                            Tất cả dữ liệu được mã hóa trong quá trình lưu trữ và truyền tải để đảm bảo an toàn.
                        </List.Item>
                        <List.Item>
                            Chỉ những người có thẩm quyền trong công ty mới có quyền truy cập dữ liệu này.
                        </List.Item>
                        <List.Item>
                            Ảnh khuôn mặt sẽ được lưu trữ trong vòng 3 tháng kể từ ngày thu thập để phục vụ cho mục đích
                            xác minh danh tính. Sau thời gian này, dữ liệu sẽ bị xóa hoàn toàn khỏi hệ thống, trừ khi có
                            yêu cầu khác theo quy định pháp luật.
                        </List.Item>
                    </List>
                </List.Item>
                <List.Item>
                    Tuân thủ pháp luật
                    <br />
                    Chúng tôi sẽ tuân thủ các luật và quy định áp dụng đối với thông tin cá nhân mà chúng tôi nắm giữ.
                </List.Item>
                <List.Item>
                    Cải tiến và xem xét việc xử lý thông tin cá nhân
                    <br />
                    Chúng tôi sẽ thực hiện cải tiến liên tục về hệ thống quản lý và cơ chế liên quan đến bảo vệ thông
                    tin cá nhân.
                </List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Điều 4 (Mục đích sử dụng)
            </Title>
            <p>Mục đích sử dụng thông tin cá nhân mà chúng tôi thu thập là như sau.</p>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>Tiếp nhận và thực hiện thủ tục đăng ký lao động và tìm việc.</List.Item>
                <List.Item>Liên lạc về giới thiệu việc làm, quản lý việc làm.</List.Item>
                <List.Item>Thực hiện thanh toán lương, thủ tục bảo hiểm xã hội và thuế.</List.Item>
                <List.Item>Thực hiện hợp đồng và quản lý công việc với đối tác.</List.Item>
                <List.Item>Quản lý sức khỏe, an toàn và cải thiện môi trường làm việc.</List.Item>
                <List.Item>Cung cấp đào tạo và tư vấn nghề nghiệp, quản lý tham gia.</List.Item>
                <List.Item>Tuân thủ pháp luật, phối hợp với cơ quan hành chính và cơ quan công quyền.</List.Item>
                <List.Item>Xác nhận tình trạng an toàn và liên lạc trong trường hợp thiên tai hoặc khẩn cấp.</List.Item>
                <List.Item>Thực hiện các biện pháp cần thiết trong quản lý lao động và thực hiện công việc.</List.Item>
                <List.Item>
                    Sử dụng thông tin vị trí (tọa độ GPS) khi chấm công
                    <List className={classes.list} type="ordered">
                        <List.Item>
                            Xác nhận địa điểm chấm công: Để xác nhận rằng việc chấm công đã được thực hiện tại địa điểm
                            làm việc đã chỉ định.
                        </List.Item>
                        <List.Item>
                            Hướng dẫn lộ trình di chuyển: Cung cấp lộ trình di chuyển thích hợp khi cần thiết.
                        </List.Item>
                    </List>
                </List.Item>
                <List.Item>
                    Chúng tôi thu thập ảnh khuôn mặt của bạn chỉ để xác minh danh tính trong các quy trình liên quan
                    đến:
                    <List className={classes.list} type="ordered">
                        <List.Item>Tạo hợp đồng lao động và hồ sơ nhân viên theo yêu cầu pháp lý.</List.Item>
                    </List>
                    <strong>
                        Chúng tôi không sử dụng ảnh khuôn mặt cho bất kỳ mục đích nào khác ngoài mục đích đã nêu trên.
                    </strong>
                </List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Điều 5 (Cung cấp thông tin cá nhân cho bên thứ ba)
            </Title>
            <p>
                Chúng tôi sẽ không tiết lộ thông tin cá nhân mà chúng tôi đã thu thập cho bên thứ ba mà không có sự đồng
                ý trước của cá nhân. Tuy nhiên, trong các trường hợp sau, chúng tôi có thể tiết lộ thông tin cá nhân mà
                không cần sự đồng ý trước của cá nhân.
            </p>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>Khi được yêu cầu tiết lộ theo quy định của pháp luật.</List.Item>
                <List.Item>
                    Khi công ty xác định rằng việc tiết lộ là cần thiết để bảo vệ quyền lợi, danh dự, uy tín của công
                    ty, cá nhân khác hoặc bên thứ ba.
                </List.Item>
                <List.Item>Khi cá nhân đã đồng ý trước về việc tiết lộ thông tin cá nhân của mình.</List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Điều 6 (Liên hệ về từ chối sử dụng và cung cấp thông tin cá nhân)
            </Title>
            <p>
                Nếu cá nhân không muốn sử dụng thông tin cá nhân đã cung cấp cho công ty hoặc không muốn cung cấp cho
                bên thứ ba trong phạm vi mục đích sử dụng, chúng tôi sẽ phản hồi một cách hợp lý.
            </p>

            <Title order={3} mt="lg" mb="md">
                Điều 7 (Về việc sử dụng chung)
            </Title>
            <p>Công ty có thể sử dụng chung thông tin cá nhân của đối tác theo các điều kiện sau.</p>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>
                    Phạm vi thông tin được sử dụng chung
                    <br />
                    Các công ty trong nhóm công ty (Công ty TNHH DGF)
                </List.Item>
                <List.Item>
                    Mục đích sử dụng chung
                    <br />
                    Giống với mục đích sử dụng đã nêu trong "1. Mục đích sử dụng thông tin cá nhân".
                </List.Item>
                <List.Item>
                    Các mục thông tin cá nhân được sử dụng chung
                    <br />
                    Tên, địa chỉ, mã bưu chính, số điện thoại, giới tính, ngày sinh, địa chỉ email, nơi làm việc, thông
                    tin về tư cách cư trú, quốc tịch, và các thông tin cá nhân cần thiết khác.
                </List.Item>
                <List.Item>
                    Người có trách nhiệm quản lý thông tin cá nhân được sử dụng chung
                    <br />
                    Người quản lý bảo vệ thông tin cá nhân của Công ty TNHH DGF.
                </List.Item>
            </List>

            <Title order={3} mt="lg" mb="md">
                Điều 8 (Ủy thác thông tin cá nhân)
            </Title>
            <p>
                Công ty có thể ủy thác việc xử lý thông tin cá nhân đã cung cấp cho bên thứ ba nếu cần thiết để đạt được
                các mục đích sử dụng nêu trên.
            </p>

            <Title order={3} mt="lg" mb="md">
                Điều 9 (Chỉnh sửa và xóa thông tin cá nhân)
            </Title>
            <List className={classes.list} type="ordered" withPadding>
                <List.Item>
                    Nếu thông tin cá nhân mà chúng tôi lưu trữ không chính xác, bạn có thể yêu cầu chỉnh sửa hoặc xóa
                    thông tin đó.
                </List.Item>
                <List.Item>
                    Khi nhận được yêu cầu từ bạn, nếu quyết định chỉnh sửa hoặc xóa thông tin cá nhân, chúng tôi sẽ thực
                    hiện theo quy trình đã quy định và thông báo cho bạn ngay sau khi hoàn tất.
                </List.Item>
                <List.Item>
                    Ảnh khuôn mặt sẽ được tự động xóa khỏi hệ thống sau 3 tháng kể từ ngày thu thập. Nếu bạn muốn xóa
                    sớm hơn, bạn có thể gửi yêu cầu, và chúng tôi sẽ xử lý trong vòng 14 ngày kể từ khi nhận được yêu
                    cầu.
                </List.Item>
                <List.Item>
                    Trừ khi có quy định khác theo pháp luật, chúng tôi không hoàn trả thông tin cá nhân đã cung cấp dưới
                    bất kỳ hình thức nào (dù là tài liệu giấy hay dữ liệu). Ngoài ra, thông tin cá nhân mà chúng tôi
                    không còn cần thiết sẽ được xử lý và hủy bỏ theo phương thức phù hợp.
                </List.Item>
            </List>
            <p>
                ※ Sau khi đăng ký (đăng ký ứng tuyển, tìm việc), bất kể bạn có làm việc hay không, nếu đã qua 15 năm kể
                từ lần liên lạc cuối cùng giữa bạn và công ty qua điện thoại, email hoặc trang cá nhân, thông tin cá
                nhân của bạn sẽ bị hủy mà không cần thông báo trước.
            </p>

            <Title order={3} mt="lg" mb="md">
                Điều 10 (Liên hệ khiếu nại và tư vấn)
            </Title>
            <p>
                Công ty sẽ tiếp nhận các khiếu nại và tư vấn từ cá nhân liên quan đến việc xử lý thông tin cá nhân. Vui
                lòng liên hệ với chúng tôi qua kênh tiếp nhận dưới đây.
            </p>
            <p>
                Công ty TNHH DGF phát triển nguồn nhân lực - Kênh tư vấn
                <br />
                Số điện thoại: 048-423-5398
                <br />
                Số fax: 048-423-5397
                <br />
                Địa chỉ email: <a href="mailto:staffkanri@dgfjapan.com">staffkanri@dgfjapan.com</a>
                <br />
            </p>
            <Text ta="right">
                Ban hành và có hiệu lực từ ngày 5 tháng 3 năm 2025
                <br />
                <br />
                Công ty TNHH DGF phát triển nguồn nhân lực
                <br />
                〒353-0004
                <br />
                Tầng 2, số 18, đường 5, thị trấn Honmachi, thành phố Shiki, tỉnh Saitama
                <br />
                Giám đốc điều hành: Nguyễn Tuấn Dương
            </Text>
        </Box>
    );
};

export default PrivatePolicyViPage;
