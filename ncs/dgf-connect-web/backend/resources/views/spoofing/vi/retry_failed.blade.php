<x-mail::message>
# Lỗi kiểm tra chống giả mạo

**Cảnh báo:** Hệ thống không thể hoàn thành kiểm tra chống giả mạo.

## Chi tiết lỗi
- **Thời gian:** {{ $failedAt }}
- **Số lượng xác minh bị ảnh hưởng:** {{ $verificationCount }}
- **Thông báo lỗi:** {{ $errorMessage }}

@if(count($failedVerificationIds) > 0)
## Danh sách ID xác minh bị lỗi
@foreach($failedVerificationIds as $id)
- {{ $id }}
@endforeach
@endif

Vui lòng kiểm tra dịch vụ chống giả mạo và xử lý các bản ghi bị lỗi.

Trân trọng,<br>
{{ config('app.name') }}
</x-mail::message>
