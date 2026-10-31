@extends('layouts.app')
@section('title', $device->name)

@php
    use App\Support\Clock;
    $host = parse_url(config('app.url'), PHP_URL_HOST) ?: request()->getHost();
    $port = parse_url(config('app.url'), PHP_URL_PORT) ?: (str_starts_with((string) config('app.url'), 'https') ? 443 : 80);
@endphp

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">
        <span class="dot {{ $device->isOnline() ? 'dot-on' : 'dot-off' }}"></span>
        {{ $device->name }}
        @unless ($device->is_active)<span class="badge bg-secondary">مُطفأ</span>@endunless
    </h1>
    @can('devices.edit')
        <a href="{{ route('devices.edit', $device) }}" class="btn btn-sm btn-outline-secondary">تعديل</a>
    @endcan
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card"><div class="card-body">
            <table class="table table-sm mb-0">
                <tr><th class="w-50">الرقم التسلسلي</th><td class="mono">{{ $device->serial_number }}</td></tr>
                <tr><th>الموقع</th><td>{{ $device->site?->name ?? '—' }}</td></tr>
                <tr><th>الطراز</th><td>{{ $device->model ?? 'لم يُبلّغ به بعد' }}</td></tr>
                <tr><th>الإصدار</th><td class="mono small">{{ $device->firmware ?? '—' }}</td></tr>

                {{--
                    نوعُ الجهاز يحدّد لغةَ الأوامر التي تُرسل إليه. وعرضُه
                    ليس زينةً: إن ظهر «—» فالجهازُ لم يُصافح بعدُ منذ إضافة
                    العمود، والأوامرُ تُرسل إليه بلغة أجهزة الحضور.
                --}}
                <tr><th>نوعُ الجهاز</th><td>
                    @if ($device->device_type === 'acc')
                        تحكّمٌ بالدخول <span class="mono small text-muted">(acc)</span>
                    @elseif ($device->device_type === 'att')
                        حضور <span class="mono small text-muted">(att)</span>
                    @elseif ($device->device_type)
                        <span class="mono">{{ $device->device_type }}</span>
                    @else
                        <span class="text-warning">لم يُعرف بعد — يُعرف عند أوّل مصافحة</span>
                    @endif
                </td></tr>
                <tr><th>آخر عنوان</th><td class="mono">{{ $device->last_ip ?? '—' }}</td></tr>
                <tr><th>العناوين المسموحة</th><td class="mono small">{{ $device->allowed_ips ?: 'بلا تقييد' }}</td></tr>
                {{--
                    ساعةُ الجهاز في بطاقته لا في صفحةٍ أخرى — فهي أوّلُ ما
                    يُشَكّ فيه حين يبدو حضورٌ في غير وقته.
                --}}
                <tr><th>ساعة الجهاز</th><td>
                    @if ($device->clockSuspect())
                        <span class="badge bg-danger">{{ $device->clockLabel() }}</span>
                        <span class="small text-danger d-block mt-1">
                            البصماتُ تُختم بساعة الجهاز — فأوقاتُها الآن غيرُ صحيحة.
                            اضغط «ضبطُ ساعة الجهاز» أدناه.
                        </span>
                    @elseif ($device->clock_drift === null)
                        <span class="text-muted">لم تُقَس بعد — تُقاس عند أوّل بصمة</span>
                    @else
                        <span class="badge bg-success-subtle text-success-emphasis">{{ $device->clockLabel() }}</span>
                        <span class="text-muted small">({{ (int) $device->clock_drift }} ث)</span>
                    @endif

                    {{--
                        وتاريخُ ساعتِه لا حالُها وحدَها.

                        فساعةٌ «مضبوطةٌ الآن» لا تقول شيئاً عن الأمس: من
                        قدّمها وبصم ثم أعادها يظهر هنا مضبوطاً تماماً. وهذا
                        السطرُ هو الذي يدلّ على أن لها قصّةً تُقرأ.
                    --}}
                    @can('clock.view')
                        @php $events = $device->clockEvents()->latest('opened_at')->limit(3)->get(); @endphp

                        @if ($events->isNotEmpty())
                            <div class="mt-2 pt-2 border-top small">
                                @foreach ($events as $e)
                                    <a href="{{ route('clock.show', $e) }}"
                                       class="d-block text-decoration-none {{ $e->severe() ? 'text-danger fw-bold' : 'text-body' }}">
                                        <i class="bi bi-clock-history"></i>
                                        {{ $e->classLabel() }} — {{ $e->driftLabel() }}
                                        <span class="text-muted">{{ Clock::stamp($e->opened_at, 'm-d H:i') }}</span>
                                        @unless ($e->closed_at)
                                            <span class="badge bg-danger">ما زالت</span>
                                        @endunless
                                    </a>
                                @endforeach
                                <a href="{{ route('clock.index', ['device_id' => $device->id]) }}"
                                   class="text-muted">كلُّ أحداث هذا الجهاز ←</a>
                            </div>
                        @endif
                    @endcan
                </td></tr>

                <tr><th>آخر اتصال</th><td class="num">{{ $device->lastSeenLabel() }}</td></tr>
                <tr><th>آخر بصمة</th><td class="num">{{ $lastAt ? Clock::stamp($lastAt, 'Y-m-d H:i:s') : '—' }}</td></tr>
                <tr><th>عددُ النبضات</th><td class="num">{{ number_format($total) }}</td></tr>
                <tr><th>طابعُ الحضور</th><td class="mono">{{ $device->attlog_stamp }}</td></tr>
            </table>
            @if ($device->note)
                <div class="alert alert-light mt-2 mb-0 small">{{ $device->note }}</div>
            @endif
        </div></div>
    </div>

    <div class="col-lg-7">
        <div class="card mb-3"><div class="card-body">
            <h2 class="h6">كيف يُضبط هذا الجهاز</h2>
            <p class="small text-muted mb-2">
                في شاشة الجهاز: <span class="mono">القائمة ← الاتصال (Comm) ← إعداد الخادم السحابي (Cloud Server Setting)</span>
            </p>
            <table class="table table-sm mb-2">
                <tr><th class="w-25">عنوان الخادم</th><td class="mono">{{ $host }}</td></tr>
                <tr><th>المنفذ</th><td class="mono">{{ $port }}</td></tr>
                <tr><th>تفعيل النطاق</th><td>نعم (Enable Domain Name / DNS)</td></tr>
                <tr><th>HTTPS</th><td>{{ str_starts_with((string) config('app.url'), 'https') ? 'نعم' : 'لا' }}</td></tr>
            </table>
            <div class="alert alert-info small mb-0">
                <i class="bi bi-info-circle"></i>
                ثم أعد تشغيل الجهاز. سيتصل خلال دقيقةٍ ويظهر مصباحُه أخضرَ في هذه الصفحة.
                وإن لم يظهر فانظر <a href="{{ route('raw.index') }}">سجلَّ الاتصالات</a> —
                فيه كلُّ ما وصل، ومنه يُعرف أوصل الجهازُ ورُدّ، أم لم يصل أصلاً.
            </div>
        </div></div>

        @can('devices.command')
            <div class="card"><div class="card-body">
                <h2 class="h6">أوامرُ الجهاز</h2>
                <p class="small text-muted">
                    الأمرُ لا يُنفَّذ الآن: الخادمُ لا يستطيع مخاطبةَ جهازٍ خلف راوترِ موقع،
                    فيوضع الأمرُ في طابورٍ يسحبه الجهازُ عند استعلامه القادم
                    (كلَّ {{ (int) config('zk.poll_delay', 30) }} ثانية تقريباً).
                </p>

                <div class="d-flex flex-wrap gap-2 mb-3">
                    @foreach ($kinds as $key => $label)
                        <form method="POST" action="{{ route('devices.command', $device) }}">
                            @csrf
                            <input type="hidden" name="kind" value="{{ $key }}">
                            <button class="btn btn-sm {{ $key === 'clear_log' || $key === 'reboot' ? 'btn-outline-danger' : 'btn-outline-secondary' }}"
                                    @if ($key === 'clear_log') onclick="return confirm('مسحُ سجلّ الجهاز لا يمسّ ما وصلنا، لكنّه يمحو ما لم يصل بعد. متأكّد؟')" @endif>
                                {{ $label }}
                            </button>
                        </form>
                    @endforeach
                </div>

                @if ($commands->count())
                    {{--
                        نصُّ الأمر ورمزُ ردّ الجهاز معروضان عمداً.

                        فحين يقول الجهازُ «فشل» فالسؤالان: ماذا أرسلنا إليه
                        بالضبط؟ وبأيّ رمزٍ ردّ؟ وبغيرهما يصير كلُّ تشخيصٍ
                        تخميناً — وقد أضاع ذلك علينا وقتاً.
                    --}}
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr>
                                <th>الأمر</th><th>النصُّ المُرسَل</th>
                                <th>الحالة</th><th>ردُّ الجهاز</th><th>أُرسل</th><th>أُقِرّ</th>
                            </tr></thead>
                            <tbody>
                            @foreach ($commands as $c)
                                <tr class="{{ $c->status === 'failed' ? 'table-danger' : '' }}">
                                    <td>{{ $c->label }}</td>
                                    <td class="mono small">{{ $c->body }}</td>
                                    <td><span class="badge bg-light text-dark">{{ $c->statusLabel() }}</span></td>
                                    <td class="mono small">{{ $c->return_code ?? '—' }}</td>
                                    <td class="num small">{{ Clock::stamp($c->sent_at) }}</td>
                                    <td class="num small">{{ Clock::stamp($c->replied_at) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div></div>
        @endcan
    </div>
</div>

@endsection
