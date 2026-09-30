<x-mail::message>
# {{ $type === 'follow_up' ? 'Payment Due Today' : 'Payment Reminder' }}

Hi {{ $userName }},

@if($type === 'follow_up')
This is a follow-up reminder that your **{{ $contribution->period_label }}** contribution for **{{ $familyName }}** is due today and has not been fully paid.
@else
This is a friendly reminder that your **{{ $contribution->period_label }}** contribution for **{{ $familyName }}** is due on **{{ $contribution->due_date->toFormattedDateString() }}**.
@endif

**Contribution Details:**

| | |
|:--|:--|
| **Period** | {{ $contribution->period_label }} |
| **Expected Amount** | {{ $contribution->formattedExpectedAmount() }} |
| **Amount Paid** | {{ $contribution->formattedTotalPaid() }} |
| **Period Balance** | {{ $contribution->formattedBalance() }} |
| **Previous Unpaid Balance** | {{ $previousBalance }} |
| **Remaining Balance** | {{ $remainingBalance }} |
| **Due Date** | {{ $contribution->due_date->toFormattedDateString() }} |

The remaining balance includes unpaid contributions from previous months plus the balance for {{ $contribution->period_label }}.

<x-mail::button :url="$contributionsUrl">
View My Contributions
</x-mail::button>

Please make your payment before the due date to avoid being marked as overdue.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
