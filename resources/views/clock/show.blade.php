@extends('layouts.app')
@section('title', 'حدثُ ساعة — '.($event->device?->name ?? ''))

@php
    use App\Support\Clock;
    use App\Support\ClockJudge;
@endphp

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">
        <a href="{{ route('clock.index') }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-right"></i>
        </a>
        حدثُ ساعة — {{ $event->device?->name }}
    </h1>
    @can('page.print')
        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary no-print">
            <i class="bi bi-printer"></i> طباعة
        </button>
    @endcan
</div>

{{--
    الخلاصةُ في سطرٍ واحد قبل التفصيل.

    فمن يفتح هذه الصفحةَ في مواجهةٍ لا يقرأ عشرين حقلاً — يريد جواباً:
    أكانت الساعةُ مضبوطةً أم لا، وكم، ومن فعلها.
--}}
<div class="alert {{ $event->severe() ? 'alert-danger' : ($event->blameless() ? 'alert-secondary' : 'alert-warning') }}">
    <h2 class="h6 mb-2">
        @if ($event->severe())
            <i class="bi bi-exclamation-octagon-fill"></i>
        @elseif ($event->blameless())
            <i class="bi bi-info-circle"></i>
        @else
            <i class="bi bi-exclamation-triangle"></i>
        @endif
        {{ $event->classLabel() }}
        <span class="badge {{ $event->confidence === 'confirmed' ? 'text-bg-danger' : 'text-bg-secondary' }}">
            {{ $event->confidence === 'confirmed' ? 'مؤكَّد' : 'مُحتمَل' }}
        </span>
    </h2>

    <p class="mb-1">
        كانت ساعةُ الجهاز <strong>{{ $event->driftLabel() }}</strong>،
        وعُلم بها {{ Clock::stamp($event->opened_at, 'Y-m-d H:i:s') }}،
        @if ($event->closed_at)
            وعادت إلى حدّها {{ Clock::stamp($event->closed_at, 'Y-m-d H:i:s') }}
            — <strong>{{ $event->resolutionLabel() }}</strong>.
        @else
            <strong class="text-danger">ولم تعد بعد.</strong>
        @endif
    </p>

    @if ($event->class === ClockJudge::TAMPER)
        {{--
            ولا يُقال هذا إلا هنا: الجهازُ لم ينقطع، ومع ذلك قفزت ساعتُه.
            وبيانُ العلّةِ يمنع أن يُقرأ الحكمُ ظنّاً.
        --}}
        <p class="mb-0 small">
            <strong>ولِمَ قيل «مقصود»؟</strong>
            لأن الساعةَ قفزت <strong>والجهازُ متّصلٌ يستعلم عن الأوامر</strong> كلَّ نصفِ دقيقة.
            وجهازُ ZKTeco لا ينقطع عن الشبكة حين يفتح أحدٌ قائمتَه — فساعةٌ تغيّرت وهو حيٌّ
            كانت تحتها يد. ولا بلّورةٌ تزحف هذا المقدارَ في هذه المدّة، ولا إقلاعٌ وقع.
        </p>
    @elseif ($event->class === ClockJudge::TIMEZONE)
        <p class="mb-0 small">
            الفرقُ يساوي ساعةً تامّةً أو نصفَها — وهذه علامةُ منطقةٍ زمنية لا علامةُ عبث.
            وأخطرُ صورها ثلاثُ ساعاتٍ بالضبط: فالخادمُ يدفع <span class="mono">TimeZone=3</span>
            ويُرسل الضبطَ بتوقيت الكويت، فجهازٌ أساء قراءةَ أحدهما وقع على ثلاثٍ تماماً.
            <strong>وتُراجَع إعداداتُ منطقة الجهاز قبل أن يُسأل أحد.</strong>
        </p>
    @elseif ($event->class === ClockJudge::RTC_DEAD)
        <p class="mb-0 small">
            الفرقُ بالشهور — وهذه بطاريةُ ساعةِ الجهاز ماتت، فعاد إلى مبدأ تقويمه عند الإقلاع.
            <strong>والعلاجُ تبديلُ البطارية</strong>، وضبطُ الساعةِ بعدها لا قبلها،
            وإلّا عاد الانحرافُ مع أوّل انقطاعِ كهرباء.
        </p>
    @elseif ($event->class === ClockJudge::SERVER_CLOCK)
        <p class="mb-0 small">
            انحرفت أجهزةٌ أخرى في <strong>مواقعَ متفرّقة</strong> بالقدر نفسِه وفي اللحظة نفسِها —
            فالذي قفز ساعةُ <strong>الخادم</strong> لا ساعاتُها. ولا يُسأل أحدٌ في المواقع عن هذا.
        </p>
    @elseif ($event->class === ClockJudge::DRIFT_SLOW)
        <p class="mb-0 small">
            الفرقُ تراكم بطيئاً لا قفزةً — بلّورةُ الجهاز تزحف، وهو عيبُ عتادٍ رخيصٍ لا عبث.
            <strong>والعلاجُ أمرُ «ضبطِ الساعة» دورياً.</strong>
        </p>
    @endif
</div>

<div class="row g-3">
    {{-- ١) ما قِيس — الدليل --}}
    <div class="col-lg-6">
        <div class="card h-100"><div class="card-body">
            <h2 class="h6 border-bottom pb-2">ما قِيس</h2>

            <table class="table table-sm mb-3">
                <tbody>
                    <tr><th class="text-muted small">الجهاز</th>
                        <td>{{ $event->device?->name }}
                            <span class="mono text-muted">{{ $event->device?->serial_number }}</span></td></tr>
                    <tr><th class="text-muted small">الموقع</th>
                        <td>{{ $event->device?->site?->name ?? 'بلا موقع' }}</td></tr>
                    <tr><th class="text-muted small">الانحرافُ أوّلَ ما قِيس</th>
                        <td class="num">{{ ClockJudge::describe((int) $event->open_drift) }}</td></tr>
                    <tr><th class="text-muted small">أقصى انحراف</th>
                        <td class="num fw-bold">{{ $event->driftLabel() }}</td></tr>
                    @if ($event->closed_at)
                        <tr><th class="text-muted small">الانحرافُ عند العودة</th>
                            <td class="num">{{ ClockJudge::describe((int) $event->close_drift) }}</td></tr>
                    @endif
                    <tr><th class="text-muted small">عددُ القراءات</th>
                        <td class="num">{{ $event->readings }}</td></tr>
                    @if ($event->command)
                        <tr><th class="text-muted small">الأمرُ الذي أعادها</th>
                            <td>
                                <a href="{{ route('devices.show', $event->device) }}" class="text-decoration-none">
                                    {{ $event->command->label ?: $event->command->kind }}
                                    <span class="text-muted small">{{ Clock::stamp($event->command->sent_at) }}</span>
                                </a>
                            </td></tr>
                    @endif
                </tbody>
            </table>

            @if ($event->evidence)
                <details>
                    <summary class="small text-muted">نصُّ القراءة التي فتحت الحدث</summary>
                    <pre class="bg-light p-2 mt-2 mb-0 small mono" dir="ltr"
                         style="white-space: pre-wrap">{{ $event->evidence }}</pre>
                </details>
            @endif
        </div></div>
    </div>

    {{-- ٢) ما بلّغ به الجهاز — الشاهدُ المُسمِّي --}}
    <div class="col-lg-6">
        <div class="card h-100"><div class="card-body">
            <h2 class="h6 border-bottom pb-2">ما بلّغ به الجهاز</h2>

            <p class="small text-muted">
                الجهازُ يرفع سجلَّ عملياته، وفيه <strong>رقمُ من فعل</strong> —
                وهذا هو الجوابُ على «مَن»، فالانحرافُ يكشف أن الساعةَ تغيّرت ولا يقول بيدِ من.
                <br>
                ويبقى <strong>شاهداً لا حاكماً</strong>: وقتُه بساعةِ الجهاز نفسِها — أي بالساعة
                المتّهمة — ورموزُه تختلف بين إصدارات الثابتة.
            </p>

            @forelse ($operations as $op)
                <div class="border-start border-3 ps-2 mb-2
                            {{ $op->touchesTime() ? 'border-danger' : 'border-secondary-subtle' }}">
                    <strong class="{{ $op->touchesTime() ? 'text-danger' : '' }}">{{ $op->label() }}</strong>
                    @if ($op->operator_pin)
                        — {{ $op->operatorName() ?? 'رقمٌ غيرُ مربوط' }}
                        <span class="mono text-muted small">{{ $op->operator_pin }}</span>
                    @endif
                    <span class="d-block small text-muted">
                        وصل {{ $op->stampLabel() }}
                        @if ($op->occurred_at)
                            · ادّعى الجهازُ {{ $op->claimLabel() }}
                            @php $cd = $op->claimDrift(); @endphp
                            @if ($cd !== null && abs($cd) > 300)
                                <span class="text-danger">({{ ClockJudge::describe($cd) }})</span>
                            @endif
                        @endif
                    </span>
                </div>
            @empty
                <div class="alert alert-secondary py-2 small mb-0">
                    لم يبلّغ الجهازُ بشيءٍ في هذه المدّة.
                    <br>
                    وهذا لا يعني أن أحداً لم يمسّه: بعضُ الطُّرُز لا ترفع سجلَّ العمليات إلا
                    إذا فُتحت خانتُه، وبعضُها يرفعه متأخّراً. <strong>والانحرافُ وحدَه هو
                    الدليل</strong> — وهذا شاهدٌ إن حضر.
                </div>
            @endforelse
        </div></div>
    </div>
</div>

{{-- ٣) نطاقُ الضرر --}}
<div class="card mt-3">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">
            بصماتٌ وصلت في نافذة الشكّ —
            <span class="num">{{ number_format($punches->total()) }}</span>
        </h2>
    </div>
    <div class="card-body">
        <div class="alert alert-info py-2 small">
            <i class="bi bi-info-circle"></i>
            <strong>المرشَّحُ وقتُ الوصول لا وقتُ البصمة</strong> — ولا يُقرأ هذا الجدولُ بغير هذا.
            فلا تُستعمل ساعةٌ كاذبةٌ لتحديد ما أفسدته: من قدّم الساعةَ يوماً صارت بصماتُه موسومةً
            بالغد، فلو رُشّحت بوقت البصمة سقطت من النافذة كلُّها — والنافذةُ إنما فُتحت لها.
            و<strong>وقتُ الوصول يكتبه الخادم</strong>، فلا يملكه أحدٌ في الموقع.
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>الموظف</th>
                    <th>ما قاله الجهاز</th>
                    <th>متى وصلت</th>
                    <th>الفرق</th>
                </tr></thead>
                <tbody>
                @forelse ($punches as $p)
                    @php
                        $gap = $p->punched_at && $p->received_at
                            ? $p->punched_at->getTimestamp() - $p->received_at->getTimestamp()
                            : null;
                    @endphp
                    <tr>
                        <td>
                            {{ $names[$p->device_pin] ?? 'غير مربوط' }}
                            <span class="mono text-muted small">{{ $p->device_pin }}</span>
                        </td>
                        <td class="num mono">{{ Clock::stamp($p->punched_at, 'Y-m-d H:i:s') }}</td>
                        <td class="num mono text-muted">{{ Clock::stamp($p->received_at, 'Y-m-d H:i:s') }}</td>
                        <td class="num {{ $gap !== null && abs($gap) > 300 ? 'text-danger fw-bold' : 'text-muted' }}">
                            {{ $gap === null ? '—' : ClockJudge::describe($gap) }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted py-4">
                        لم تصل بصمةٌ في هذه المدّة — فلا كشفَ مسَّه هذا الحدث.
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-2 no-print">{{ $punches->links() }}</div>
    </div>
</div>

{{-- ٤) الإقرار --}}
@can('clock.ack')
    <div class="card mt-3 no-print"><div class="card-body">
        @if ($event->acknowledged_at)
            <p class="mb-1 text-success">
                <i class="bi bi-check2-all"></i>
                أقرّ به <strong>{{ $event->acknowledgedBy?->name }}</strong>
                في {{ Clock::stamp($event->acknowledged_at, 'Y-m-d H:i') }}.
            </p>
            @if ($event->note)
                <p class="mb-0 small">{{ $event->note }}</p>
            @endif
        @else
            <form method="POST" action="{{ route('clock.ack', $event) }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-9">
                    <label class="form-label small">
                        ما تبيّن — ويُقرأ بعد سنةٍ حين يُنازَع في كشفِ هذا الشهر
                    </label>
                    <input name="note" class="form-control form-control-sm"
                           placeholder="مثال: بُدّلت بطاريةُ الجهاز، والانحرافُ كان قبل التبديل">
                </div>
                <div class="col-md-3">
                    <button class="btn btn-sm btn-success w-100">أُقِرُّ به وأُسكِتُ الإشعار</button>
                </div>
            </form>
        @endif
    </div></div>
@endcan

@endsection

@push('head')
<style>
    @media print {
        @page { size: A4; margin: 10mm; }

        details { display: block; }
        details > summary { display: none; }
        pre { border: .5pt solid #aaa; }

        .alert { border: .5pt solid #888 !important; }
        table th, table td { border: .5pt solid #888 !important; padding: 2mm !important; }
    }
</style>
@endpush
