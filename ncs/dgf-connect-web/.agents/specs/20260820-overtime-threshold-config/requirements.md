# Requirements Document

## Introduction

Hệ thống hiện phân loại phút làm việc thành thường / đêm / tăng ca / tăng ca đêm bằng một ngưỡng cố định duy nhất: `config('work.time.over_time_after') = 8` giờ, hardcode trong `backend/config/work.php` và áp dụng cho toàn bộ xưởng. Tính năng này đưa ngưỡng đó thành cấu hình theo từng xưởng (workplace), bổ sung cờ bật theo từng ca làm việc cụ thể để phần vượt thời lượng chuẩn của ca được tính là tăng ca kể cả khi chưa chạm ngưỡng của xưởng, và sửa lỗi ngưỡng hiện đang đối chiếu trên tổng phút bao gồm cả giờ nghỉ. Thay đổi làm số phút tăng ca (hệ số lương 1.25 / 1.5) khác đi so với hiện tại nên là breaking change về mặt số liệu, tuy nhiên **chỉ áp dụng cho dữ liệu chấm công phát sinh từ thời điểm deploy**; dữ liệu và bảng lương đã có không được tính lại.

### Feature Summary

Cho phép cấu hình ngưỡng số giờ bắt đầu tính tăng ca theo từng xưởng, và cho phép quản lý bật theo từng ca để phần làm vượt thời lượng chuẩn của ca được tính là tăng ca.

### Business Value

- Mỗi xưởng có thỏa thuận lao động khác nhau; ngưỡng cứng 8 giờ đang trả lương tăng ca sai cho những xưởng áp dụng mức khác (ví dụ 9 giờ).
- Những ca bất thường (tăng cường, kéo dài đột xuất) cần được trả tăng ca theo đúng thực tế mà không phải đổi cấu hình chung của cả xưởng.
- Loại bỏ lỗi cộng giờ nghỉ vào ngưỡng, vốn đang tạo ra phút tăng ca không có thật cho các ca có nghỉ giữa giờ.

### Scope

**Bao gồm:**

- Trường cấu hình ngưỡng tăng ca ở cấp xưởng (nullable, không set thì dùng mặc định hệ thống 8 giờ).
- Giao diện admin sửa trường này trong form xưởng.
- Cờ bật/tắt "tính tăng ca theo thời lượng ca" trên từng bản ghi ca (`shifts`), sửa qua màn hình chi tiết ca.
- Cập nhật `WorkHelper::calculateWorkHour` để dùng ngưỡng động và sửa cách đối chiếu ngưỡng với giờ nghỉ.
- Áp dụng nhất quán trên mọi đường ghi nhận giờ công: check-out của nhân viên, tự động check-out, quản lý sửa giờ công, quản lý thêm nhân viên vào ca đã qua, command sửa dữ liệu.

**Không bao gồm:**

- Tính lại (backfill) dữ liệu `check_ins`, `work_time_reports`, `salaries` đã có.
- Thay đổi hệ số lương tăng ca (giữ 1.25 cho tăng ca, 1.5 cho tăng ca đêm).
- Thay đổi khung giờ ca đêm (22:00–05:00) hoặc quy tắc làm tròn giờ vào/ra.
- Ngưỡng tăng ca theo từng nhân viên (`user_configs`).
- Gộp nhiều ca trong cùng một ngày để tính ngưỡng chung (xem Assumptions).

## Glossary

| Term | Definition |
|------|------------|
| Overtime_Threshold | Số phút làm việc thực tế trong một lần chấm công, vượt qua đó thì mỗi phút tiếp theo được tính là tăng ca (hoặc tăng ca đêm). |
| Workplace_Overtime_Setting | Trường `workplaces.overtime_after_hours`, ngưỡng tăng ca theo giờ của một xưởng; `null` nghĩa là dùng System_Default_Threshold. |
| System_Default_Threshold | Giá trị `config('work.time.over_time_after')`, hiện là 8 giờ, dùng khi xưởng không cấu hình riêng. |
| Shift_Overtime_Flag | Cờ boolean trên bản ghi ca (`shifts`), khi bật thì Overtime_Threshold của ca đó lấy theo Shift_Standard_Duration. |
| Shift_Standard_Duration | Thời lượng chuẩn của một ca, tính bằng khoảng `[start_time, end_time]` của workplace_shift trừ giờ nghỉ áp dụng cho ca đó. |
| Work_Hour_Calculator | `App\Helpers\WorkHelper::calculateWorkHour`, hàm phân loại phút làm việc thành total / normal / night / overtime / night_overtime. |
| Net_Work_Minutes | Số phút làm việc được trả lương của một lần chấm công, đã trừ giờ nghỉ. |
| Break_Duration | Giờ nghỉ áp dụng cho một lần chấm công: `check_ins.break_minutes`, nếu null thì `shifts.break_minutes`, nếu null nữa thì khung nghỉ `start_break_time`–`end_break_time` của workplace_shift. |
| Allow_Overtime_Flag | Cờ `shift_users.is_overtime_allowed`; khi tắt, giờ chấm công nằm ngoài khung ca bị cắt về khung ca trước khi tính. |
| Check_In_Record | Bản ghi `check_ins` lưu kết quả phân loại phút làm việc của một nhân viên trong một ca. |
| Work_Time_Report | Bảng tổng hợp giờ công theo tháng (`work_time_reports`, `work_time_report_details`) do job `CollectWorkTime` dựng lại từ Check_In_Record. |
| Salary_Service | `App\Services\Common\SalaryService`, quy đổi phút theo hệ số 1.0 / 1.25 / 1.25 / 1.5 thành tiền lương. |

## Requirements

### Requirement 1: Ngưỡng tăng ca cấu hình theo xưởng

**User Story:** Là quản trị viên hệ thống, tôi muốn đặt số giờ bắt đầu tính tăng ca riêng cho từng xưởng, để lương tăng ca khớp với thỏa thuận lao động của xưởng đó.

#### Acceptance Criteria

1. WHERE một xưởng có Workplace_Overtime_Setting khác null THEN Work_Hour_Calculator SHALL dùng giá trị đó làm Overtime_Threshold cho mọi ca thuộc xưởng.
2. IF Workplace_Overtime_Setting của xưởng là null THEN Work_Hour_Calculator SHALL dùng System_Default_Threshold (8 giờ).
3. WHEN quản trị viên lưu form xưởng với giá trị ngưỡng nằm ngoài khoảng 1–24 THEN hệ thống SHALL trả lỗi validation và không lưu thay đổi.
4. WHEN quản trị viên xóa trắng ô ngưỡng và lưu THEN hệ thống SHALL lưu `null` và xưởng quay về System_Default_Threshold.
5. WHEN quản trị viên mở form xưởng THEN giao diện SHALL hiển thị giá trị đang lưu, hoặc ô trống kèm chú thích mặc định 8 giờ nếu chưa cấu hình.
6. WHEN Workplace_Overtime_Setting được thay đổi THEN hệ thống SHALL KHÔNG tính lại Check_In_Record, Work_Time_Report hay bảng lương đã tồn tại.
7. WHEN một nhân viên có nhiều lần chấm công trong cùng một ngày THEN Work_Hour_Calculator SHALL áp dụng Overtime_Threshold độc lập cho từng lần chấm công và SHALL KHÔNG cộng dồn giờ của các ca trong ngày.

#### Additional Details

- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Không

### Requirement 2: Tính tăng ca theo thời lượng ca cho từng ca cụ thể

**User Story:** Là quản lý xưởng, tôi muốn bật cờ tăng ca cho một ca làm việc cụ thể, để phần nhân viên làm vượt thời lượng chuẩn của ca đó được trả tăng ca dù tổng giờ trong lần chấm công chưa chạm ngưỡng của xưởng — với điều kiện nhân viên đó cũng có Allow_Overtime_Flag đang bật; nếu không, giờ làm ngoài khung ca vẫn bị cắt như trước và cờ này gần như không tạo thêm phút tăng ca nào.

#### Acceptance Criteria

1. WHERE Shift_Overtime_Flag của một ca đang bật THEN Work_Hour_Calculator SHALL dùng giá trị nhỏ hơn giữa Shift_Standard_Duration và ngưỡng của xưởng làm Overtime_Threshold cho ca đó.
2. IF Shift_Overtime_Flag đang tắt THEN Work_Hour_Calculator SHALL dùng ngưỡng của xưởng theo Requirement 1.
3. WHEN Shift_Overtime_Flag đang bật THEN Work_Hour_Calculator SHALL vẫn tôn trọng Allow_Overtime_Flag của từng nhân viên: giờ chấm công nằm ngoài khung ca chỉ được giữ khi Allow_Overtime_Flag bật, ngược lại vẫn bị cắt về khung ca ở cả hai đầu ca.
4. WHEN quản lý có quyền `shift.edit` trên xưởng của ca bật hoặc tắt cờ này ở màn hình chi tiết ca THEN hệ thống SHALL lưu giá trị mới và trả về bản ghi ca đã cập nhật.
5. IF người dùng không có quyền `shift.edit` trên xưởng của ca THEN hệ thống SHALL trả lỗi 403 và không thay đổi cờ.
6. WHEN một ca mới được tạo THEN hệ thống SHALL đặt Shift_Overtime_Flag mặc định là tắt.
7. WHEN Shift_Overtime_Flag được thay đổi THEN hệ thống SHALL KHÔNG tính lại các Check_In_Record đã ghi nhận trước đó của ca.

#### Additional Details

- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1

### Requirement 3: Ngưỡng tăng ca đối chiếu trên giờ làm thực tế

**User Story:** Là nhân viên tính lương, tôi muốn ngưỡng tăng ca được đối chiếu với giờ làm thực tế đã trừ giờ nghỉ, để người làm đúng thời lượng ca nhưng có nghỉ giữa giờ không bị tính tăng ca sai.

#### Acceptance Criteria

1. WHEN Work_Hour_Calculator phân loại các phút làm việc THEN nó SHALL đối chiếu Overtime_Threshold với Net_Work_Minutes chứ không phải tổng phút gộp Break_Duration.
2. IF Break_Duration được khai bằng số phút (`check_ins.break_minutes` hoặc `shifts.break_minutes`) THEN Work_Hour_Calculator SHALL lùi mốc bắt đầu tính tăng ca thêm đúng số phút nghỉ đó.
3. IF Break_Duration được xác định bằng khung giờ nghỉ của workplace_shift THEN Work_Hour_Calculator SHALL giữ nguyên hành vi hiện tại là bỏ qua các phút trong khung nghỉ khi đếm.
4. WHEN một nhân viên chấm công đúng bằng ngưỡng cộng thời gian nghỉ THEN Work_Hour_Calculator SHALL trả về `overtime = 0` và `night_overtime = 0`.
5. WHILE phân loại từng phút Work_Hour_Calculator SHALL giữ nguyên quy tắc phân loại đêm hiện tại (khung 22:00–05:00) và quy tắc làm tròn giờ vào/ra của workplace_shift.
6. WHEN tổng của normal, night, overtime và night_overtime được tính THEN Work_Hour_Calculator SHALL đảm bảo tổng bốn thành phần bằng đúng Net_Work_Minutes và không có thành phần nào âm.

#### Additional Details

- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1

### Requirement 4: Áp dụng nhất quán trên mọi đường ghi nhận giờ công

**User Story:** Là nhân viên tính lương, tôi muốn mọi cách ghi nhận giờ công đều dùng chung một quy tắc tăng ca, để số liệu không lệch giữa chấm công app và chỉnh sửa thủ công của quản lý.

#### Acceptance Criteria

1. WHEN nhân viên check-out trên ứng dụng THEN hệ thống SHALL ghi Check_In_Record theo ngưỡng của Requirement 1 và Requirement 2.
2. WHEN job tự động check-out chạy cho nhân viên quên check-out THEN hệ thống SHALL áp dụng cùng quy tắc ngưỡng.
3. WHEN quản lý check-out hộ hoặc sửa giờ vào/ra, giờ nghỉ của một bản ghi chấm công THEN hệ thống SHALL tính lại các cột phút theo cùng quy tắc ngưỡng.
4. WHEN quản lý thêm nhân viên vào một ca đã diễn ra kèm giờ vào/ra THEN hệ thống SHALL tính các cột phút theo cùng quy tắc ngưỡng.
5. WHEN command `fix:work-hour` được chạy THEN nó SHALL tính lại theo cùng quy tắc ngưỡng.
6. WHILE Work_Time_Report được tổng hợp lại bởi job `CollectWorkTime` hệ thống SHALL cộng dồn các cột phút của Check_In_Record mà không áp dụng thêm quy tắc ngưỡng nào khác.

#### Additional Details

- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 1, Requirement 2, Requirement 3

### Requirement 5: Lương và báo cáo phản ánh đúng phân loại mới

**User Story:** Là nhân viên tính lương, tôi muốn tiền lương và các báo cáo giờ công đọc đúng số phút tăng ca mới, để không phải điều chỉnh tay sau khi tính năng lên.

#### Acceptance Criteria

1. WHEN Salary_Service tính lương từ Check_In_Record THEN nó SHALL giữ nguyên hệ số 1.0 cho giờ thường, 1.25 cho giờ đêm, 1.25 cho tăng ca và 1.5 cho tăng ca đêm.
2. WHEN một Check_In_Record được sửa hoặc tạo bởi quản lý THEN hệ thống SHALL vẫn dispatch các job cập nhật lương và tổng hợp giờ công như hiện tại.
3. WHERE báo cáo giờ công theo tháng, xuất Excel lịch sử làm việc và xuất bảng lương đọc các cột phút tăng ca THEN chúng SHALL hiển thị giá trị mới mà không cần thay đổi cấu trúc cột.
4. IF một tháng lương đã được chốt (`is_finalized`) THEN hệ thống SHALL KHÔNG thay đổi số tiền của tháng đó do tính năng này.

#### Additional Details

- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 3, Requirement 4

## Non-Functional Requirements

### Performance Requirements

- WHEN Work_Hour_Calculator xử lý một lần chấm công THEN nó SHALL không thực hiện thêm truy vấn cơ sở dữ liệu nào ngoài các quan hệ `shift.workplace` và `shift.workplaceShift` đã được nạp sẵn bởi caller.
- WHEN command `fix:work-hour` hoặc job `AutoCheckOut` xử lý hàng loạt bản ghi THEN hệ thống SHALL không sinh truy vấn N+1 cho cấu hình ngưỡng của xưởng.

### Security Requirements

- WHEN người dùng gọi API sửa cấu hình ca THEN hệ thống SHALL kiểm tra quyền `shift.edit` trên đúng xưởng của ca.
- WHEN người dùng gọi API sửa xưởng THEN hệ thống SHALL kiểm tra quyền quản trị xưởng như các trường hiện có của form xưởng.

### Usability Requirements

- WHEN quản lý xem màn hình chi tiết ca THEN giao diện SHALL hiển thị rõ trạng thái cờ tăng ca theo thời lượng ca của ca đó.
- WHEN văn bản mới xuất hiện trên giao diện THEN hệ thống SHALL cung cấp bản dịch cho cả ba ngôn ngữ en, vi, ja qua luồng `t(...)`.

### Reliability Requirements

- IF dữ liệu ca thiếu quan hệ workplace THEN Work_Hour_Calculator SHALL rơi về System_Default_Threshold thay vì lỗi.
- IF Shift_Standard_Duration tính ra nhỏ hơn hoặc bằng 0 THEN Work_Hour_Calculator SHALL bỏ qua Shift_Overtime_Flag và dùng ngưỡng của xưởng.

## Constraints and Assumptions

### Technical Constraints

- Ngưỡng ở cấp xưởng lưu theo đơn vị giờ nguyên (unsigned tiny integer), không hỗ trợ giá trị lẻ như 8.5 giờ.
- `Work_Hour_Calculator` là hàm static không có dependency injection; ngưỡng phải suy ra được từ chính đối tượng `Shift` truyền vào.
- Ngưỡng áp dụng cho từng lần chấm công (một `check_ins` = một ca, ràng buộc unique `shift_user_id`), không cộng dồn nhiều ca trong cùng ngày.

### Assumptions

- Ngưỡng tăng ca áp dụng theo từng ca (từng lần chấm công), không cộng dồn giờ của nhiều ca trong cùng một ngày. Đây là hành vi hiện tại của hệ thống và đã được nghiệp vụ xác nhận giữ nguyên cho cả ngưỡng mặc định 8 giờ lẫn ngưỡng cấu hình theo xưởng.
- Dữ liệu chấm công và bảng lương đã có được coi là đúng tại thời điểm phát sinh và không cần tính lại.
- Số phút tăng ca sau thay đổi có thể tăng ở các ca được tick cờ và giảm ở các ca có nghỉ giữa giờ; đây là kết quả mong muốn.
- Cờ tăng ca theo ca chỉ do quản lý đặt thủ công, không có luồng tự động bật theo lịch hay theo mẫu ca.

## Success Criteria

### Definition of Done

- [ ] All acceptance criteria are met
- [ ] Non-functional requirements are satisfied
- [ ] Testing criteria are passed
- [ ] Xưởng chưa cấu hình ngưỡng cho kết quả phân loại phút giống hệt trước thay đổi, trừ phần sửa lỗi giờ nghỉ ở Requirement 3
- [ ] Không có script hay migration nào ghi đè dữ liệu `check_ins`, `work_time_reports`, `salaries` đã tồn tại
- [ ] `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck` ở frontend và `npm run prettier`, `npm run pint`, `php artisan test --parallel` ở backend đều pass
