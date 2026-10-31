{{--
    الصلاحياتُ مفتاحاً مفتاحاً.

    ولا يُجمع فعلان تحت خانةٍ واحدة: مَن أُذن له بتعديل جهازٍ لم يُؤذن
    بحذفه، ومَن يرى كشفاً لا يلزم أن يُصدّره.
--}}
{{--
    حِزَمٌ جاهزةٌ تُقرّب، والخاناتُ تبقى ظاهرةً فيُدقَّق فيها.

    ولا تُخزَّن الحزمةُ على المستخدم: هي تُؤشّر الخاناتِ ثم يُحفظ ما أُشِّر.
    فلو خُزّنت لصارت رُتبةً تُغيَّر من مكانٍ واحدٍ فتنقلب صلاحياتُ عشرةٍ
    بلا أن يُدرى — وقاعدةُ هذا البرنامج أن الصلاحيةَ لكل فعلٍ صريحةً على
    صاحبها.
--}}
<div class="mb-2 no-print">
    <span class="small text-muted me-2">حزمةٌ جاهزة:</span>
    @foreach (\App\Support\Abilities::PACKS as $packName => $packKeys)
        <button type="button" class="btn btn-sm btn-outline-secondary py-0 mb-1"
                data-pack="{{ $prefix }}" data-keys="{{ implode(',', $packKeys) }}">
            {{ $packName }}
        </button>
    @endforeach
    <button type="button" class="btn btn-sm btn-outline-danger py-0 mb-1"
            data-pack="{{ $prefix }}" data-keys="">
        امسح الكلّ
    </button>
</div>

<div class="row g-2 mb-3" data-abilities="{{ $prefix }}">
    @foreach (\App\Support\Abilities::GROUPS as $group => $keys)
        <div class="col-md-3">
            <div class="border rounded p-2 h-100">
                <div class="small fw-bold text-muted mb-1">{{ $group }}</div>
                @foreach ($keys as $key)
                    <div class="form-check">
                        <input type="checkbox" name="permissions[]" value="{{ $key }}"
                               id="{{ $prefix }}-{{ $key }}" class="form-check-input"
                               @checked(in_array($key, (array) $selected, true))>
                        <label for="{{ $prefix }}-{{ $key }}" class="form-check-label small">
                            {{ \App\Support\Abilities::label($key) }}
                        </label>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</div>

@once
    @push('scripts')
    <script>
        /* الحزمةُ تُؤشّر خاناتِ نموذجها هو — لا خاناتِ الصفحة كلِّها */
        document.addEventListener('click', function (e) {
            const b = e.target.closest('[data-pack]');
            if (!b) return;

            const box = document.querySelector('[data-abilities="' + b.dataset.pack + '"]');
            if (!box) return;

            const want = b.dataset.keys ? b.dataset.keys.split(',') : [];

            box.querySelectorAll('input[name="permissions[]"]').forEach(function (input) {
                input.checked = want.includes(input.value);
            });
        });
    </script>
    @endpush
@endonce