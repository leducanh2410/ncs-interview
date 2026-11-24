<x-mail::message>
# Phát hiện check-in/check-out giả mạo

**Cảnh báo bảo mật:** Hệ thống đã phát hiện {{ count($entries) }} nỗ lực check-in/check-out giả mạo.

@foreach($entries as $index => $entry)
---

## {{ $index + 1 }}. {{ $entry['userName'] }} ({{ $entry['staffNo'] }})

### Thông tin ca làm việc
- **Nơi làm việc:** {{ $entry['workplaceName'] }}
- **Ngày:** {{ $entry['shiftDate'] }}
- **Giờ ca:** {{ $entry['shiftTime'] }}

### Chi tiết phát hiện
- **Loại:** {{ $entry['verificationType'] }}
- **Độ tin cậy giả mạo:** {{ $entry['spoofedConfidence'] }}%
- **Thời gian phát hiện:** {{ $entry['detectedAt'] }}

@if($entry['imageUrl'])
<x-mail::button :url="$entry['imageUrl']">
Xem ảnh check-in
</x-mail::button>
@endif
@endforeach

Vui lòng xem xét và xử lý sự việc này.

Trân trọng,<br>
{{ config('app.name') }}
</x-mail::message>
