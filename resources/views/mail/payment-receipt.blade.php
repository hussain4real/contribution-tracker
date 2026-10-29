<x-mail::message>
# Payment Receipt #{{ $batch->receipt_number }}

Hi {{ $batch->member_name }},

Your payment to **{{ $batch->family->name }}** has been recorded. Your PDF receipt is attached.

| | |
|:--|:--|
| **Amount received** | {{ $amount }} |
| **Payment date** | {{ $batch->paid_at->toFormattedDateString() }} |
| **Payment method** | {{ $batch->method->label() }} |
@if($batch->reference)
| **Reference** | {{ $batch->reference }} |
@endif

<x-mail::button :url="$receiptUrl">
View Payment Receipt
</x-mail::button>

Sign in to your FamilyFund account to view your receipt online.

Thanks,<br>
{{ $batch->family->name }}
</x-mail::message>
