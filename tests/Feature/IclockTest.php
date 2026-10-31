<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Punch;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اختبارُ النقطة المفتوحة للأجهزة.
 *
 * وهذه أهمُّ ما يُختبر في النظام: هي الواجهةُ الوحيدة بلا مصادقة، وكلُّ
 * سلوكٍ فيها مقصودٌ لسببٍ يسهل نسيانُه بعد شهر — فيُعاد «إصلاحُه» فيُفسَد.
 */
class IclockTest extends TestCase
{
    use RefreshDatabase;

    private function device(array $attrs = []): Device
    {
        return Device::create(array_merge([
            'serial_number' => 'ABC1234567',
            'name'          => 'بوابة المخزن',
            'site_id'       => Site::create(['name' => 'موقع الجهراء'])->id,
            'is_active'     => true,
        ], $attrs));
    }

    public function test_رقم_تسلسلي_مجهول_يُردّ_بـok_ولا_يكشف_نفسه(): void
    {
        $this->get('/iclock/cdata?SN=NOTMINE&options=all')
            ->assertOk()
            ->assertSee('OK', false);

        $this->assertDatabaseHas('zk_raw_logs', ['reason' => 'unknown_serial', 'accepted' => false]);
    }

    public function test_المصافحة_تُعطي_الجهازَ_طوابعَه(): void
    {
        $device = $this->device();

        $this->get("/iclock/cdata?SN={$device->serial_number}&options=all")
            ->assertOk()
            ->assertSee('GET OPTION FROM: '.$device->serial_number, false)
            ->assertSee('ATTLOGStamp=', false)
            ->assertSee('Encrypt=0', false);
    }

    public function test_الدفعةُ_تُخزَّن_ووقتُها_يُحوَّل_إلى_غرينتش(): void
    {
        $device = $this->device();

        $this->call('POST', "/iclock/cdata?SN={$device->serial_number}&table=ATTLOG&Stamp=99",
            content: "14\t2026-09-20 07:14:03\t0\t1\t0\t0\t0")
            ->assertOk()
            ->assertSee('OK: 1', false);

        $punch = Punch::firstOrFail();

        $this->assertSame('14', $punch->device_pin);
        $this->assertSame('2026-09-20 04:14:03', $punch->punched_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-20', $punch->work_date->toDateString());
    }

    public function test_الدفعةُ_المُعادة_لا_تتضاعف_ويُقَرّ_بها_كاملة(): void
    {
        $device = $this->device();

        $body = "14\t2026-09-20 07:14:03\t0\t1";
        $url  = "/iclock/cdata?SN={$device->serial_number}&table=ATTLOG&Stamp=99";

        $this->call('POST', $url, content: $body)->assertSee('OK: 1', false);

        /*
         * الإقرارُ بعدد ما قُرئ لا بعدد ما خُزّن — وإلا ظنّ الجهازُ أن
         * المكرَّرَ لم يصل فأعاده أبداً.
         */
        $this->call('POST', $url, content: $body)->assertSee('OK: 1', false);

        $this->assertSame(1, Punch::count());
    }

    public function test_جهازٌ_مُطفأ_لا_تُقبل_بياناتُه(): void
    {
        $device = $this->device(['is_active' => false]);

        $this->call('POST', "/iclock/cdata?SN={$device->serial_number}&table=ATTLOG",
            content: "14\t2026-09-20 07:14:03\t0\t1")->assertOk();

        $this->assertSame(0, Punch::count());
        $this->assertDatabaseHas('zk_raw_logs', ['reason' => 'inactive']);
    }

    public function test_شاشاتُ_المراجعة_لا_تُفتح_بلا_دخول(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/punches')->assertRedirect('/login');
        $this->get('/devices')->assertRedirect('/login');
    }
}
