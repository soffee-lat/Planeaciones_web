<?php
namespace Tests\Feature;
use App\Actions\Identity\CompleteOnboarding;
use App\Actions\Identity\RegisterCustomer;
use App\Enums\RoleCode;
use App\Filament\Auth\Register;
use App\Filament\Auth\RequestPasswordReset;
use App\Models\Role;
use App\Models\User;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Auth\Pages\Login;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;
class IdentityAccessTest extends TestCase {
    use RefreshDatabase;
    protected function setUp(): void {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
    }
    public function test_public_app_home_exposes_landing_and_auth_actions(): void {
        $this->get('/app')
            ->assertOk()
            ->assertSee('Planeaciones Soffee')
            ->assertSee('Inicio')
            ->assertSee('Iniciar sesión')
            ->assertSee('Registrarse')
            ->assertSee(route('filament.app.auth.login'), false)
            ->assertSee(route('filament.app.auth.register'), false);
    }
    public function test_guests_see_login_on_each_panel_and_internal_registration_is_absent(): void {
        $this->get('/app/inicio')->assertRedirect('/app/login');
        $this->get('/app/login')->assertOk();
        $this->get('/app/password-reset/request')->assertOk();
        foreach (['review','admin'] as $panel) {
            $this->get('/'.$panel)->assertRedirect('/'.$panel.'/login');
            $this->get('/'.$panel.'/login')->assertOk();
            $this->get('/'.$panel.'/password-reset/request')->assertOk();
        }
        $this->get('/app/register')->assertOk();
        $this->get('/review/register')->assertNotFound();
        $this->get('/admin/register')->assertNotFound();
    }
    public function test_role_access_matrix_is_enforced_on_server(): void {
        foreach ([
            [RoleCode::Customer, ['app']],
            [RoleCode::Reviewer, ['review']],
            [RoleCode::Administrator, ['admin','review']],
        ] as [$role,$allowed]) {
            $user = User::factory()->withRole($role)->create();
            foreach ([
                'app' => '/app/inicio',
                'review' => '/review',
                'admin' => '/admin',
            ] as $panel => $path) {
                $response = $this->actingAs($user)->get($path);
                $response->assertStatus(in_array($panel,$allowed,true) ? 200 : 403);
            }
        }
    }
    public function test_unverified_accounts_cannot_open_any_dashboard(): void {
        foreach ([
            'app'=>['role'=>RoleCode::Customer,'path'=>'/app/inicio'],
            'review'=>['role'=>RoleCode::Reviewer,'path'=>'/review'],
            'admin'=>['role'=>RoleCode::Administrator,'path'=>'/admin'],
        ] as $panel=>$case) {
            $user = User::factory()->unverified()->withRole($case['role'])->create();
            $this->actingAs($user)->get($case['path'])->assertRedirect('/'.$panel.'/email-verification/prompt');
        }
    }
    public function test_suspended_and_roleless_accounts_are_denied(): void {
        $user = User::factory()->withRole(RoleCode::Customer)->create(['status'=>'suspended']);
        $this->actingAs($user)->get('/app/inicio')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/app/inicio')->assertForbidden();
    }
    public function test_public_registration_assigns_only_customer_and_sends_verification(): void {
        Notification::fake();
        Livewire::test(Register::class)->fillForm([
            'name'=>'Docente ficticia', 'email'=>'registro@example.test',
            'password'=>'PruebaSegura123!', 'passwordConfirmation'=>'PruebaSegura123!',
        ])->call('register')->assertHasNoFormErrors();
        $user = User::where('email','registro@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole(RoleCode::Customer));
        $this->assertSame(1,$user->roles()->count());
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user,VerifyEmail::class);
    }
    public function test_registration_short_password_shows_human_spanish_message(): void {
        Livewire::test(Register::class)->fillForm([
            'name'=>'Docente ficticia',
            'email'=>'password-corto@example.test',
            'password'=>'Ruas456',
            'passwordConfirmation'=>'Ruas456',
        ])->call('register')
            ->assertHasFormErrors(['password'])
            ->assertSee('La contraseña debe tener al menos 8 caracteres.');
        $this->assertDatabaseMissing('users', ['email'=>'password-corto@example.test']);
    }

    public function test_registration_accepts_password_with_exactly_eight_characters(): void {
        Notification::fake();

        Livewire::test(Register::class)->fillForm([
            'name'=>'Docente ficticia',
            'email'=>'password-ocho@example.test',
            'password'=>'Ruas456.',
            'passwordConfirmation'=>'Ruas456.',
        ])->call('register')->assertHasNoFormErrors();

        $this->assertDatabaseHas('users', ['email'=>'password-ocho@example.test']);
    }

    public function test_registration_action_ignores_privileged_fields(): void {
        $user = app(RegisterCustomer::class)->execute([
            'name'=>'Ficticio','email'=>'ATTACK@example.test','password'=>'PruebaSegura123!',
            'status'=>'suspended','email_verified_at'=>now(),'roles'=>['ADMINISTRADOR'],
            'onboarding_completed_at'=>now(),
        ])->refresh();
        $this->assertSame('attack@example.test',$user->email);
        $this->assertSame('active',$user->status);
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->onboarding_completed_at);
        $this->assertFalse($user->hasRole(RoleCode::Administrator));
    }
    public function test_login_accepts_customer_and_rejects_wrong_panel_and_password(): void {
        $user=User::factory()->withRole(RoleCode::Customer)->create(['password'=>'PruebaSegura123!']);
        Livewire::test(Login::class)->fillForm(['email'=>$user->email,'password'=>'incorrecta'])->call('authenticate')->assertHasFormErrors(['email']);
        Livewire::test(Login::class)->fillForm(['email'=>$user->email,'password'=>'PruebaSegura123!'])->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($user);
        $this->post('/app/logout')->assertRedirect();
        $this->assertGuest();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(Login::class)->fillForm(['email'=>$user->email,'password'=>'PruebaSegura123!'])->call('authenticate')->assertHasFormErrors(['email']);
    }
    public function test_email_verification_requires_signed_unexpired_url_and_matching_user(): void {
        $user=User::factory()->unverified()->withRole(RoleCode::Customer)->create();
        $route='filament.app.auth.email-verification.verify';
        $params=['id'=>$user->id,'hash'=>sha1($user->email)];
        $valid=URL::temporarySignedRoute($route,now()->addMinutes(30),$params);
        $expired=URL::temporarySignedRoute($route,now()->subMinute(),$params);
        $this->actingAs($user)->get($expired)->assertForbidden();
        $this->get('/app/email-verification/verify/'.$user->id.'/'.sha1($user->email))->assertForbidden();
        $other=User::factory()->unverified()->withRole(RoleCode::Customer)->create();
        $this->actingAs($other)->get($valid)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->actingAs($user)->get($valid)->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }
    public function test_password_reset_uses_notification_and_single_use_token(): void {
        Notification::fake();
        $user=User::factory()->withRole(RoleCode::Customer)->create();
        Livewire::test(RequestPasswordReset::class)->fillForm(['email'=>$user->email])->call('request')->assertHasNoFormErrors();
        Notification::assertSentTo($user,\Filament\Auth\Notifications\ResetPassword::class);
        $token=Password::broker()->createToken($user);
        Livewire::test(ResetPassword::class,['email'=>$user->email,'token'=>$token])
            ->fillForm(['password'=>'NuevaSegura123!','passwordConfirmation'=>'NuevaSegura123!'])->call('resetPassword')->assertHasNoFormErrors();
        $this->assertTrue(Hash::check('NuevaSegura123!',$user->fresh()->password));
        $this->assertFalse(Password::broker()->tokenExists($user,$token));
    }
    public function test_unknown_password_reset_has_same_public_message(): void {
        Livewire::test(RequestPasswordReset::class)->fillForm(['email'=>'ausente@example.test'])->call('request')
            ->assertNotified('Si la cuenta existe, recibirás un enlace para restablecer tu contraseña.');
    }
    public function test_onboarding_updates_only_self_and_is_idempotent(): void {
        $a=User::factory()->withRole(RoleCode::Customer)->create();
        $b=User::factory()->withRole(RoleCode::Customer)->create();
        $this->assertFalse(Gate::forUser($a)->allows('view',$b));
        $this->assertFalse(Gate::forUser($a)->allows('update',$b));
        $this->actingAs($a);
        Livewire::test(\App\Filament\App\Pages\Onboarding::class)->set('name','Nombre nuevo')->call('save')->assertHasNoErrors();
        $first=$a->fresh()->onboarding_completed_at;
        $this->assertNotNull($first);
        app(CompleteOnboarding::class)->execute($a,$a->fresh(),['name'=>'Nombre nuevo']);
        $this->assertTrue($first->equalTo($a->fresh()->onboarding_completed_at));
        $this->assertNull($b->fresh()->onboarding_completed_at);
        $this->expectException(AuthorizationException::class);
        app(CompleteOnboarding::class)->execute($a,$b,['name'=>'Intrusión']);
    }
    public function test_postgresql_enforces_case_insensitive_email_uniqueness(): void {
        User::factory()->create(['email'=>'unique@example.test']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('users')->insert(['name'=>'Duplicado','email'=>'UNIQUE@example.test','password'=>'unused']);
    }
    public function test_postgresql_enforces_role_pivot_uniqueness(): void {
        $user=User::factory()->withRole(RoleCode::Customer)->create();
        $this->expectException(\Illuminate\Database\QueryException::class);
        $user->roles()->attach(Role::where('code',RoleCode::Customer->value)->firstOrFail());
    }
    public function test_postgresql_enforces_user_status_constraint(): void {
        $this->expectException(\Illuminate\Database\QueryException::class);
        User::factory()->create(['status'=>'unknown']);
    }
}

