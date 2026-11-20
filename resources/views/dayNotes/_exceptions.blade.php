{{--
    جدولُ تبويب «الاستثناءات»: ما يُسأل عنه ويُبتّ فيه — غيابٌ، وتأخّرٌ،
    وانصرافٌ قبل إتمام الدوام. والإجازةُ المسجَّلةُ ليست منه: جوابُها مكتوبٌ
    سلفاً، فلها تبويبُها وجدولُها.

    ويأخذ `$g` — عنقودَ شخصٍ واحد كما بنته `DayNoteController::byPerson()`.
--}}

@php
    use App\Services\ShiftWatch;
    use App\Support\ShiftJudge;
@endphp

<div class="table-responsive">
    <table class="table table-sm table-bordered align-middle mb-0">
        <thead><tr>
            <th>اليوم</th>
            <th>النوع</th>
            <th>الدخول</th>
            <th>الخروج</th>
            <th>المطلوب خروجاً</th>
            <th>الرصد</th>
            <th>القرار</th>
            {{-- الخليّةُ تتبع العمود لا الزرّ: الرأسُ يظهر فتُفتح الخليّةُ في كل صفّ --}}
            @can('notes.manage')
                <th class="no-print">البتُّ فيه</th>
                <th class="no-print"></th>
            @endcan
        </tr></thead>
        <tbody>
        @foreach ($g['rows'] as $r)
            @php
                $note = $r['note'];
                $kind = $r['exception'];
            @endphp
            <tr>
                <td class="num">{{ $r['work_date'] }}</td>
                <td class="text-nowrap">
                    <span class="badge {{ match ($kind) {
                            'absent' => 'bg-danger',
                            'late'   => 'bg-warning text-dark',
                            'short'  => 'bg-secondary',
                            default  => 'bg-warning text-dark',
                        } }}">{{ ShiftWatch::kindLabel($kind) }}</span>
                </td>
                <td class="num">{{ $r['first_time'] }}</td>
                <td class="num">{{ $r['last_time'] }}</td>
                <td class="num text-muted">{{ $r['due_out'] }}</td>
                <td class="text-nowrap">
                    @if ($r['late'])
                        <span class="badge bg-danger">تأخّر {{ ShiftJudge::hhmm($r['late']) }}</span>
                    @endif
                    @if ($r['short'])
                        <span class="badge bg-warning text-dark">نقص {{ ShiftJudge::hhmm($r['short']) }}</span>
                    @endif
                    @if (! $r['late'] && ! $r['short'])
                        —
                    @endif
                </td>

                {{-- القرارُ نصٌّ لا حقل: هو ما يُقرأ على الورقة بعد شهرين --}}
                <td>
                    {{ $note?->statusLabel() ?? 'لم يُبتّ فيه' }}

                    @if ($note?->note)
                        <span class="d-block text-muted" style="font-size:.72rem">{{ $note->note }}</span>
                    @endif
                    @if ($note?->decider)
                        <span class="d-block text-muted" style="font-size:.7rem">{{ $note->decider->name }}</span>
                    @endif
                </td>

                @can('notes.manage')
                    <td class="no-print" style="min-width: 18rem">
                        <div class="d-flex gap-1">
                            <select form="n-{{ $r['uid'] }}" name="permitted"
                                    class="form-select form-select-sm" style="max-width: 7rem">
                                <option value="0" @selected($note && ! $note->permitted)>بلا إذن</option>
                                <option value="1" @selected($note && $note->permitted)>بإذن</option>
                            </select>
                            <input form="n-{{ $r['uid'] }}" name="reason" value="{{ $note?->reason }}"
                                   class="form-control form-control-sm" placeholder="السبب — يلزم مع الإذن">
                        </div>
                        <input form="n-{{ $r['uid'] }}" name="note" value="{{ $note?->note }}"
                               class="form-control form-control-sm mt-1" placeholder="ملاحظة">
                    </td>
                    <td class="no-print text-nowrap">
                        <button form="n-{{ $r['uid'] }}" class="btn btn-sm btn-outline-success py-0">حفظ</button>
                        @if ($note)
                            <button form="nd-{{ $r['uid'] }}" class="btn btn-sm btn-outline-danger py-0"
                                    onclick="return confirm('إلغاءُ القرار؟ يعود اليومُ إلى ما لم يُبتّ فيه.')">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </button>
                        @endif
                    </td>
                @endcan
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

{{--
    النماذجُ خارج الجدول: المتصفّحُ ينتزع «form» من داخل «tr» فيفسد البناء،
    والحقولُ تُنسب إليها بالسمة `form`.
--}}
@can('notes.manage')
    @foreach ($g['rows'] as $r)
        <form id="n-{{ $r['uid'] }}" method="POST" action="{{ route('notes.store') }}" class="d-none">
            @csrf
            <input type="hidden" name="site_id" value="{{ $r['site_id'] }}">
            <input type="hidden" name="device_pin" value="{{ $r['device_pin'] }}">
            <input type="hidden" name="work_date" value="{{ $r['work_date'] }}">
        </form>
        @if ($r['note'])
            <form id="nd-{{ $r['uid'] }}" method="POST" action="{{ route('notes.destroy', $r['note']) }}" class="d-none">
                @csrf @method('DELETE')
            </form>
        @endif
    @endforeach
@endcan
