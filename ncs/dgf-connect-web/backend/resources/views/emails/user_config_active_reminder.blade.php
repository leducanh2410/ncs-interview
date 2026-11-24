<x-mail::message>
# Cảnh báo nhân viên còn kích hoạt cấu hình tùy chỉnh

**Thống kê đầu tháng:** Hệ thống ghi nhận {{ count($entries) }} nhân viên vẫn đang kích hoạt cấu hình tùy chỉnh về ca làm hoặc giờ làm.

<x-mail::table>
| STT | Họ tên | Email | Mã nhân viên | Cấu hình active |
|-----|--------|-------|--------------|-----------------|
@foreach($entries as $index => $entry)
| {{ $index + 1 }} | {{ $entry['name'] }} | {{ $entry['email'] }} | {{ $entry['staffNo'] }} | {{ $entry['activeConfigs'] }} |
@endforeach
</x-mail::table>

Vui lòng xem xét và rà soát các cấu hình này để đảm bảo tính chính xác.

Trân trọng,<br>
{{ config('app.name') }}
</x-mail::message>
