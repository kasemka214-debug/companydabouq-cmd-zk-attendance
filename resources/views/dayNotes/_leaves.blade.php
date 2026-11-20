{{--
    جدولُ تبويب «الإجازات المسجَّلة»: يُعرض ليُعلم أن اليومَ محسوبٌ لا منسيّ
    — ولا يُسأل فيه عن إذن، فإذنُه موقَّعٌ قبل أن يُفتح البرنامج.

    **والأيامُ تُطوى على مُدَدها**: إجازةُ عشرةِ أيامٍ سطرٌ واحدٌ يقول مدّتَها،
    لا عشرةُ سطورٍ متشابهةٍ يُعدّها قارئُها بإصبعه. وتواريخُها تحتها بخطٍّ
    صغير — فمن أراد اليومَ بعينه وجده، ومن أراد المدّةَ قرأها في سطر.

    ويأخذ `$g` — عنقودَ شخصٍ واحد كما بنته `DayNoteController::byPerson()`.
--}}

@php
    use App\Support\Employment;

    $spans = [];

    foreach ($g['rows'] as $r) {
        $leave = $r['leave'] ?? [];
        $key   = ($leave['kind'] ?? '').'|'.($leave['from'] ?? '').'|'.($leave['to'] ?? '');

        $spans[$key] ??= [
            'kind' => $leave['kind'] ?? null,
            'from' => $leave['from'] ?? null,
            'to'   => $leave['to'] ?? null,
            'days' => [],
        ];

        $spans[$key]['days'][] = $r['work_date'];
    }
@endphp

<div class="table-responsive">
    <table class="table table-sm table-bordered align-middle mb-0">
        <thead><tr>
            <th>نوعُ الإجازة</th>
            <th>من</th>
            <th>إلى</th>
            <th>أيامُ الدوام داخلها</th>
        </tr></thead>
        <tbody>
        @foreach ($spans as $span)
            <tr>
                <td class="text-nowrap">
                    <span class="badge bg-info text-dark">{{ Employment::kind($span['kind']) }}</span>
                </td>
                <td class="num">{{ $span['from'] ?? '—' }}</td>
                <td class="num">{{ $span['to'] ?? '—' }}</td>
                <td>
                    <span class="num">{{ count($span['days']) }}</span>
                    <span class="d-block text-muted num" style="font-size:.72rem">
                        {{ implode('، ', $span['days']) }}
                    </span>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
