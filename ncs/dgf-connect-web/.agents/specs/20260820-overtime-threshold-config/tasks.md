# Implementation Plan: Overtime Threshold Configuration

## Overview

Xây theo hướng foundation-first: cột và model trước, kế đến là điểm quyết định ngưỡng nằm trong `WorkHelper` (trái tim của tính năng, cũng là chỗ rủi ro nhất vì ảnh hưởng trực tiếp tới lương), rồi mới tới các API nhận cấu hình, các điểm gọi cần nạp sẵn quan hệ, và cuối cùng là giao diện. Logic tính giờ được viết và phủ test đơn vị **trước** khi có bất kỳ giao diện nào để nhập cấu hình, nhờ đó có thể khẳng định "xưởng chưa cấu hình cho kết quả y hệt trước đây" từ rất sớm. Frontend làm sau cùng vì nó chỉ là đường nhập liệu cho hai cột đã hoạt động đầy đủ ở backend.

### Implementation Strategy

- **Foundation-First**: migration → model/cast → helper → service/request → giao diện.
- **Không hồi quy là ràng buộc số một**: mọi thay đổi trong `calculateWorkHour` phải giữ nguyên chữ ký, mảng trả về, thứ tự cắt giờ - làm tròn - phân loại.
- **Test đơn vị cho helper viết ngay sau khi sửa helper**, không dồn về cuối, vì đây là phần duy nhất chứa business logic thực sự.
- **Test tích hợp đi qua luồng thật** (tạo ca → duyệt → check-in → check-out qua endpoint) thay vì dựng sẵn bản ghi `check_ins`, theo Test Design Rules của dự án.
- Không viết migration dữ liệu và không có script tính lại dữ liệu cũ ở bất kỳ task nào.

## Implementation Plan

### Phase 1: Foundation - Cột và Model

- [x] 1. Thêm hai cột cấu hình
    - [x] 1.1 Migration thêm `workplaces.overtime_after_hours`
        - `unsignedTinyInteger('overtime_after_hours')->nullable()->after('min_rest_hours')`
        - Không đặt default, để phân biệt "chưa cấu hình" với giá trị thật
        - `down()` drop cột
        - _Requirements: 1.1, 1.2_

    - [x] 1.2 Migration thêm `shifts.is_overtime_by_duration`
        - `boolean('is_overtime_by_duration')->default(false)->after('break_minutes')`
        - `down()` drop cột
        - _Requirements: 2.6_

    - [x] 1.3 Cập nhật model `Workplace` và `Shift`
        - `Workplace`: thêm docblock `@property int|null $overtime_after_hours`
        - `Shift`: thêm docblock `@property bool $is_overtime_by_duration` và cast `'is_overtime_by_duration' => 'boolean'` vào `$casts`
        - Không cần đụng `$fillable` vì cả hai model đang `$guarded = []`
        - _Requirements: 1.1, 2.6_

### Phase 2: Core Logic - Ngưỡng động trong WorkHelper

- [x] 2. Điểm quyết định ngưỡng
    - [x] 2.1 Thêm `WorkHelper::getOvertimeAfterMinutes(Shift $shift): int`
        - Đọc `$shift->workplace?->overtime_after_hours`, rơi về `config('work.time.over_time_after')` khi null
        - Quy đổi ra phút bằng `(int) round($hours * 60)`
        - Khi `$shift->is_overtime_by_duration` bật: lấy `min` với `(int) round($shift->getDuration() * 60)`, chỉ khi giá trị đó lớn hơn 0
        - Không thực hiện truy vấn nào bên trong hàm
        - _Requirements: 1.1, 1.2, 2.1, 2.2_

    - [x] 2.2 Áp cờ của ca vào `$allowOvertime` trong `calculateWorkHour`
        - Thêm `$allowOvertime = $allowOvertime || (bool) $shift->is_overtime_by_duration;` ở đầu hàm, **trước** khối cắt giờ về khung ca (dòng 25-32 hiện tại)
        - Không đổi chữ ký hàm và không đổi thứ tự cắt giờ - làm tròn - phân loại
        - _Requirements: 2.3_

    - [x] 2.3 Thay hằng số ngưỡng và bù giờ nghỉ
        - Thay `$overTimeAfter = config('work.time.over_time_after') * 60;` bằng `$overTimeAfter = self::getOvertimeAfterMinutes($shift) + (int) $customBreakDuration;`
        - Đặt dòng này **sau** khối gán mặc định `$customBreakDuration = $shift->break_minutes` (dòng 97-99 hiện tại)
        - Giữ nguyên nguyên văn hai điều kiện `$totalMinutes > $overTimeAfter` ở nhánh ca đêm và ca ngày
        - Giữ nguyên khối chuẩn hóa âm ở cuối hàm
        - _Requirements: 3.1, 3.2, 3.3, 3.5, 3.6_

- [x] 3. Unit test cho logic tính giờ
    - [x] 3.1 Tạo `backend/tests/Unit/Helpers/WorkHelperCalculateWorkHourTest.php`
        - Xưởng không cấu hình, ca 10 giờ không nghỉ: tăng ca bắt đầu từ phút thứ 481 (không hồi quy)
        - Xưởng đặt 9 giờ: cùng dữ liệu vào, tăng ca bắt đầu từ phút thứ 541
        - Xưởng đặt 9 giờ, `break_minutes = 60`, làm liên tục 10 giờ: `total = 540`, `overtime = 0`
        - Ca dùng khung giờ nghỉ với `break_minutes` null: phút trong khung nghỉ không được đếm và ngưỡng không bị cộng thêm
        - Ca thiếu quan hệ workplace: rơi về ngưỡng 8 giờ, không ném exception
        - Ca đêm bắt qua khung 22:00-05:00 với ngưỡng 9 giờ: tách đúng `night` và `night_overtime`
        - Bất biến chung: `normal + night + overtime + night_overtime === total`, không thành phần nào âm
        - _Requirements: 1.1, 1.2, 3.1, 3.2, 3.3, 3.4, 3.5, 3.6_

    - [x] 3.2 Bổ sung các case cho cờ tăng ca theo thời lượng ca
        - Cờ bật, ca chuẩn 8 giờ, xưởng 9 giờ, làm 9 giờ: 8 giờ đầu là thường, 1 giờ sau là tăng ca
        - Cờ bật, ca chuẩn 10 giờ, xưởng 9 giờ: ngưỡng lấy 9 giờ
        - Cờ bật và `is_overtime_allowed` tắt: giờ ngoài khung ca không bị cắt và được tính tăng ca
        - Cờ tắt và `is_overtime_allowed` tắt: giờ ngoài khung ca vẫn bị cắt như hiện tại
        - Cờ bật nhưng thời lượng chuẩn của ca bằng 0: dùng ngưỡng xưởng
        - _Requirements: 2.1, 2.2, 2.3_

- [x] 4. Checkpoint - Logic tính giờ đã đúng và không hồi quy
    - Chạy `docker compose exec php php artisan test --parallel --filter WorkHelper` và toàn bộ test hiện có liên quan giờ công (`CheckInOutTest`, `AbsentShiftWorkTimeTest`, `WorkHistoryControllerTest`)
    - Nếu một test cũ đổi kết quả, phải xác định được đó là do sửa lỗi giờ nghỉ ở Requirement 3 chứ không phải hồi quy ngoài ý muốn; hỏi lại người dùng nếu không chắc

### Phase 3: Integration - API cấu hình và các điểm gọi

- [x] 5. Đường nhập cấu hình ở backend
    - [x] 5.1 Nhận `overtime_after_hours` ở API xưởng
        - `app/Http/Requests/Admin/Workplace/StoreRequest.php`: thêm rule `['bail', 'nullable', 'integer', 'min:1', 'max:24']`
        - Không thêm route, không thêm permission
        - _Requirements: 1.3, 1.4_

    - [x] 5.2 Nhận `is_overtime_by_duration` ở API cấu hình ca
        - `app/Http/Requests/Manage/Shift/UpdateConfigRequest.php`: thêm rule `['nullable', 'boolean']`
        - `ShiftService::updateConfig`: thêm `'is_overtime_by_duration' => $data['is_overtime_by_duration'] ?? false` vào mảng update
        - Giữ nguyên kiểm quyền `PermissionName::ShiftEdit` theo `$shift->workplace_id`
        - _Requirements: 2.4, 2.5_

- [x] 6. Nạp sẵn quan hệ workplace tại các điểm gọi
    - [x] 6.1 Bổ sung eager load cho các luồng ghi nhận giờ công
        - `CoreCheckOutAction`: `$shiftUser->load(['shift.workplaceShift', 'shift.workplace'])`
        - `Jobs\AutoCheckOut`: thêm `'shift.workplace'` vào `with(...)` (bắt buộc, đây là vòng lặp toàn bảng)
        - `Services\Manage\WorkHistoryService::updateCheckIn`: `loadMissing('shift.workplace')` trước khi tính
        - `Services\Manage\ShiftService::checkOutUser`: `loadMissing('shift.workplace')` trước khi tính
        - `Actions\Manage\WorkHistory\AddUsersToShiftAction`: `$shift->loadMissing('workplace')` một lần trước vòng lặp
        - Hai command `fix:work-hour` và `fix:check-in-time-rounded` đã có `shift.workplace`, không sửa
        - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5_

- [x] 7. Test tích hợp backend
    - [x] 7.1 Tạo `backend/tests/Feature/Admin/WorkplaceOvertimeConfigTest.php`
        - `PUT admin/workplaces/{id}` với `overtime_after_hours = 9` lưu đúng giá trị
        - Gửi `0` và `25` đều trả 422 và không thay đổi dữ liệu
        - Gửi `null` xóa cấu hình, xưởng quay về mặc định
        - _Requirements: 1.3, 1.4_

    - [x] 7.2 Bổ sung `backend/tests/Feature/Manage/Shift/UpdateConfigTest.php`
        - Bật cờ qua endpoint lưu đúng và trả về bản ghi ca mới
        - Gửi lại request không kèm trường này thì cờ về `false`
        - Người dùng không có `shift.edit` trên xưởng của ca nhận 403 và cờ không đổi
        - _Requirements: 2.4, 2.5_

    - [x] 7.3 Bổ sung `backend/tests/Feature/Manage/Shift/CheckInOutTest.php`
        - Luồng thật với xưởng đặt ngưỡng 9 giờ: tạo ca → duyệt nhân viên → check-in → check-out qua endpoint, khẳng định các cột `work_minutes_*` đúng theo ngưỡng mới
        - Luồng thật với ca đã bật cờ **qua chính endpoint cấu hình ca** và nhân viên không được bật `is_overtime_allowed`: khẳng định phút vượt khung ca được ghi nhận và xếp vào tăng ca
        - _Requirements: 2.3, 4.1_

    - [x] 7.4 Bổ sung `backend/tests/Feature/Manage/WorkHistoryControllerTest.php`
        - Sửa giờ ra của bản ghi tạo ra từ luồng check-in/check-out thật: các cột được tính lại theo cùng ngưỡng
        - Khẳng định các job cập nhật lương và tổng hợp giờ công vẫn được dispatch như trước
        - _Requirements: 4.3, 5.2_

- [x] 8. Checkpoint - Backend hoàn chỉnh
    - Chạy `docker compose exec php php artisan test --parallel`
    - Chạy `npm run prettier` và `npm run pint` trong `./backend`
    - Xác nhận không có migration hay script nào ghi vào `check_ins`, `work_time_reports`, `salaries` (Requirement 1.6, 2.7)

### Phase 4: Frontend - Giao diện nhập cấu hình

- [x] 9. Kiểu dữ liệu và form xưởng
    - [x] 9.1 Cập nhật kiểu dữ liệu
        - `frontend/src/types/models/workplace.d.ts`: thêm `overtime_after_hours: number | null`
        - `frontend/src/types/models/shift.d.ts`: thêm `is_overtime_by_duration: boolean` vào `Shift`
        - _Requirements: 1.5, 2.4_

    - [x] 9.2 Thêm ô ngưỡng vào `workplace_form.tsx`
        - `NumberInput` đặt ngay dưới ô `min_rest_hours`, `min={1} max={24}`, `disabled={isReadOnly}`
        - `label={t('Overtime after hours')}`, `description={t('Leave empty to use the system default of 8 hours')}`
        - Thêm vào `initialValues`, `validate` (`Validator.number().integer().gte(1).lte(24)`) và ánh xạ giá trị khi nạp xưởng
        - Ô trống phải gửi `null`, theo đúng cách `min_rest_hours` đang xử lý
        - _Requirements: 1.3, 1.4, 1.5_

- [x] 10. Giao diện cấu hình ca
    - [x] 10.1 Thêm checkbox vào `config_modal.tsx`
        - `Checkbox` dưới ô `break_minutes`, dùng `form.getInputProps('is_overtime_by_duration', { type: 'checkbox' })`
        - `label={t('Count work beyond shift duration as overtime')}`
        - `description={t('Applies to this shift only, overriding per-employee overtime permission')}`
        - Thêm trường vào `ConfigFormValues`, `initialValues` (`shift.is_overtime_by_duration ?? false`) và payload mutation
        - _Requirements: 2.4_

    - [x] 10.2 Hiển thị trạng thái cờ ở `shift_info_card.tsx`
        - Badge hoặc dòng thông tin chỉ hiện khi cờ bật, để quản lý biết ca đang áp quy tắc đặc biệt
        - _Requirements: 2.4_

- [x] 11. Bản dịch
    - [x] 11.1 Chạy luồng dịch chuẩn của dự án
        - Bảo đảm toàn bộ chuỗi mới đã dùng `t(...)` trước khi chạy lệnh
        - `pnpm trans:prepare` trong `./frontend`
        - Điền `vi` và `ja` vào `.translation-workflow/translation-batch.json`, không sửa `key`, `en`, `sources`
        - `pnpm trans:apply`, sau đó `pnpm trans:cleanup`
        - _Requirements: NFR Usability_

- [x] 12. Test frontend
    - [x] 12.1 Bổ sung `workplace_form.test.tsx`
        - Render ra ô ngưỡng với giá trị đang lưu
        - Submit gửi `overtime_after_hours` trong payload; để trống gửi `null`
        - _Requirements: 1.3, 1.4, 1.5_

    - [x] 12.2 Bổ sung `config_modal.test.tsx`
        - Checkbox hiển thị đúng trạng thái ban đầu từ `shift.is_overtime_by_duration`
        - Tick rồi lưu gửi `is_overtime_by_duration: true` trong payload
        - _Requirements: 2.4_

### Phase 5: Final Validation

- [x] 13. Kiểm tra tác động sang lương và báo cáo
    - [x] 13.1 Rà soát các nơi tiêu thụ cột phút tăng ca
        - Xác nhận `SalaryService` giữ nguyên hệ số 1.0 / 1.25 / 1.25 / 1.5
        - Xác nhận `CollectWorkTime` chỉ cộng dồn, không áp thêm ngưỡng
        - Xác nhận `ExportSalariesAction`, `WorkHistoryDetailExport`, `AdminAttendanceMonthlySheet` và màn hình báo cáo tháng không cần đổi cấu trúc cột
        - Xác nhận `CalculateOvertimeEligibilityAction` không liên quan tới ngưỡng này (nó dùng `max_hour_per_week`)
        - Ghi lại kết luận vào phần mô tả merge request
        - _Requirements: 5.1, 5.3, 5.4_

- [x] 14. Code Quality
    - [x] 14.1 Frontend: chạy `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck`, `pnpm test`
    - [x] 14.2 Backend: chạy `npm run prettier`, `npm run pint`, `docker compose exec php php artisan test --parallel`

## Notes

- **Rủi ro lớn nhất là hồi quy số liệu lương.** Task 4 là chốt chặn: nếu một test giờ công có sẵn đổi kết quả, phải giải thích được bằng đúng thay đổi ở Requirement 3 (bù giờ nghỉ vào ngưỡng), nếu không thì dừng lại hỏi người dùng.
- **Thứ tự trong `calculateWorkHour` rất nhạy cảm.** Dòng áp cờ ca phải nằm trước khối cắt giờ về khung ca; dòng tính ngưỡng phải nằm sau khối gán mặc định `break_minutes`. Đảo thứ tự sẽ cho kết quả sai mà test đơn vị dễ bỏ lọt nếu chỉ phủ ca ban ngày không nghỉ.
- **Không backfill.** Theo quyết định nghiệp vụ, không có task nào tính lại dữ liệu cũ. Hai command `fix:work-hour` và `fix:check-in-time-rounded` sẽ áp quy tắc mới nếu ai đó chạy tay, đây là hành vi chấp nhận được vì chúng vốn là công cụ sửa dữ liệu thủ công.
- **`AutoCheckOut` bắt buộc phải eager load `shift.workplace`** vì nó duyệt toàn bộ check-in chưa đóng; thiếu bước này sẽ thành N+1 trên môi trường thật.
- Phase 4 chỉ phụ thuộc Phase 3 ở mức hợp đồng API; nếu cần chạy song song thì frontend có thể làm ngay sau task 5.
- Số phút tăng ca sau khi lên sẽ tăng ở các ca được tick cờ và giảm ở các ca có nghỉ giữa giờ. Nên thông báo trước cho bộ phận tính lương trước khi deploy.
