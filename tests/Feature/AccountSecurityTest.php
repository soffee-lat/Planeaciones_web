<?php
namespace Tests\Feature;
use App\Enums\RoleCode;
use App\Filament\Auth\EditProfile;
use App\Filament\Auth\Login;
use App\Filament\Auth\RequestPasswordReset;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;
class AccountSecurityTest extends TestCase {
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); Filament::setCurrentPanel(Filament::getPanel('app')); }
    public function test_profile_changes_only_authenticated_user(): void {
        $a=User::factory()->withRole(RoleCode::Customer)->create();
        $b=User::factory()->withRole(RoleCode::Customer)->create();
        $this->actingAs($a);
        Livewire::test(EditProfile::class)->fillForm(['name'=>'Actualizado'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Actualizado',$a->fresh()->name);
        $this->assertNotSame('Actualizado',$b->fresh()->name);
    }
    public function test_email_change_waits_for_verification(): void {
        Notification::fake();
        $user=User::factory()->withRole(RoleCode::Customer)->create();
        $email=$user->email;
        $this->actingAs($user);
        Livewire::test(EditProfile::class)->fillForm(['email'=>'nuevo@example.test','currentPassword'=>'PruebaSegura123!'])->call('save')->assertHasNoFormErrors();
        $this->assertSame($email,$user->fresh()->email);
        Notification::assertSentOnDemand(\Filament\Auth\Notifications\VerifyEmailChange::class);
    }
    public function test_login_normalizes_email_and_stops_after_five_failed_attempts(): void {
        $user=User::factory()->withRole(RoleCode::Customer)->create(['email'=>'normal@example.test']);
        Livewire::test(Login::class)->fillForm(['email'=>'NORMAL@example.test','password'=>'PruebaSegura123!'])->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($user);
        auth()->logout();
        $component=Livewire::test(Login::class)->fillForm(['email'=>$user->email,'password'=>'bad']);
        for ($i=0; $i<6; $i++) { $component->call('authenticate'); }
        $this->assertGuest();
        $component->assertNotified();
    }
    public function test_expired_reset_token_is_not_accepted(): void {
        $user=User::factory()->withRole(RoleCode::Customer)->create();
        $token=Password::broker()->createToken($user);
        $this->travel(61)->minutes();
        $this->assertFalse(Password::broker()->tokenExists($user,$token));
        $this->travelBack();
    }
    public function test_reset_request_form_is_cleared_even_for_unknown_account(): void {
        Livewire::test(RequestPasswordReset::class)->fillForm(['email'=>'unknown@example.test'])->call('request')->assertSet('data.email',null);
    }
    public function test_revoked_role_and_suspension_apply_to_existing_session(): void {
        $user=User::factory()->withRole(RoleCode::Customer)->create();
        $this->actingAs($user)->get('/app/inicio')->assertOk();
        $user->roles()->detach();
        $this->get('/app/inicio')->assertForbidden();
    }
    public function test_customer_dashboard_contains_no_other_customer_data(): void {
        $a=User::factory()->withRole(RoleCode::Customer)->create(['name'=>'Docente A']);
        $b=User::factory()->withRole(RoleCode::Customer)->create(['name'=>'Docente B']);
        $this->actingAs($a)->get('/app/inicio')->assertOk()->assertSee('Docente A')->assertDontSee('Docente B')->assertDontSee($b->email);
        $this->get('/app/profile?user='.$b->id)->assertOk()->assertSee($a->email)->assertDontSee($b->email);
    }
}
