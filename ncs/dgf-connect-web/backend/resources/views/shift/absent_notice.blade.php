<x-mail::message>
# Shift absent notice submitted

An employee has submitted an absent notice for an upcoming shift.

<x-mail::panel>
**Employee:** {{ $employeeName }}

**Shift date:** {{ $shiftDate }}

**Workplace:** {{ $workplaceName }}

**Shift time:** {{ $shiftTime }}

**Position:** {{ $position }}
</x-mail::panel>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
