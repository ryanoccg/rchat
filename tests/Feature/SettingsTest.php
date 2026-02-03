<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->company = Company::factory()->create([
            'name' => 'Test Company',
            'email' => 'company@test.com',
            'phone' => '+1234567890',
            'address' => '123 Test St',
            'timezone' => 'America/New_York',
            'business_hours' => [
                ['day' => 'monday', 'is_open' => true, 'open' => '09:00', 'close' => '17:00']
            ]
        ]);
        
        $this->user = User::factory()->create([
            'current_company_id' => $this->company->id,
            'name' => 'Test User',
            'email' => 'test@test.com',
            'password' => Hash::make('password123'),
        ]);
        
        // Update user preferences
        $this->user->update([
            'preferences' => [
                'email_notifications' => true,
                'theme' => 'light'
            ]
        ]);
        
        // Attach user to company
        $this->user->companies()->attach($this->company->id);
    }

    // Company Settings Tests

    public function test_can_get_company_settings(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/settings/company');

        $response->assertOk()
            ->assertJson([
                'company' => [
                    'name' => 'Test Company',
                    'email' => 'company@test.com',
                    'timezone' => 'America/New_York',
                ]
            ]);
    }

    public function test_can_update_company_settings(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson('/api/settings/company', [
                'name' => 'Updated Company',
                'email' => 'updated@test.com',
                'phone' => '+0987654321',
                'address' => '456 New St',
                'timezone' => 'Europe/London',
            ]);

        $response->assertOk()
            ->assertJson([
                'company' => [
                    'name' => 'Updated Company',
                    'email' => 'updated@test.com',
                ]
            ]);

        $this->assertDatabaseHas('companies', [
            'id' => $this->company->id,
            'name' => 'Updated Company',
            'email' => 'updated@test.com',
        ]);
    }

    public function test_company_settings_validation(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson('/api/settings/company', [
                'name' => '',
                'email' => 'not-an-email',
                'timezone' => 'invalid-timezone',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'timezone']);
    }

    public function test_can_upload_company_logo(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('logo.png', 200, 200);

        $response = $this->actingAs($this->user)
            ->postJson('/api/settings/company/logo', [
                'logo' => $file
            ]);

        $response->assertOk()
            ->assertJsonStructure([
                'logo'
            ]);

        // Verify file was stored
        $this->company->refresh();
        $this->assertNotNull($this->company->logo);
    }

    public function test_logo_upload_validation(): void
    {
        Storage::fake('public');

        // Test non-image file
        $file = UploadedFile::fake()->create('document.pdf', 100);

        $response = $this->actingAs($this->user)
            ->postJson('/api/settings/company/logo', [
                'logo' => $file
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['logo']);
    }

    public function test_can_delete_company_logo(): void
    {
        Storage::fake('public');

        // Upload a logo first
        $file = UploadedFile::fake()->image('logo.png');
        $path = $file->store('logos', 'public');
        $this->company->update(['logo' => '/storage/' . $path]);

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/settings/company/logo');

        $response->assertOk();

        $this->company->refresh();
        $this->assertNull($this->company->logo);
    }

    public function test_can_get_timezones(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/settings/timezones');

        $response->assertOk()
            ->assertJsonStructure([
                'timezones' => [
                    '*' => ['label', 'value']
                ]
            ]);

        // Should include common timezones
        $timezones = collect($response->json('timezones'))->pluck('value')->toArray();
        $this->assertContains('America/New_York', $timezones);
        $this->assertContains('Europe/London', $timezones);
        $this->assertContains('UTC', $timezones);
    }

    // User Profile Tests

    public function test_can_get_user_profile(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/settings/profile');

        $response->assertOk()
            ->assertJson([
                'user' => [
                    'name' => 'Test User',
                    'email' => 'test@test.com',
                    'preferences' => [
                        'email_notifications' => true,
                        'theme' => 'light'
                    ]
                ]
            ]);
    }

    public function test_can_update_user_profile(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson('/api/settings/profile', [
                'name' => 'Updated Name',
                'email' => 'updated@email.com',
            ]);

        $response->assertOk()
            ->assertJson([
                'user' => [
                    'name' => 'Updated Name',
                    'email' => 'updated@email.com',
                ]
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'name' => 'Updated Name',
            'email' => 'updated@email.com',
        ]);
    }

    public function test_profile_update_email_must_be_unique(): void
    {
        $otherUser = User::factory()->create([
            'current_company_id' => $this->company->id,
            'email' => 'existing@test.com'
        ]);
        $otherUser->companies()->attach($this->company->id);

        $response = $this->actingAs($this->user)
            ->putJson('/api/settings/profile', [
                'name' => 'Test User',
                'email' => 'existing@test.com',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    // Preferences Tests

    public function test_can_update_preferences(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson('/api/settings/preferences', [
                'preferences' => [
                    'email_notifications' => false,
                    'push_notifications' => true,
                    'theme' => 'dark',
                    'language' => 'es',
                ]
            ]);

        $response->assertOk();

        $this->user->refresh();
        $this->assertFalse($this->user->preferences['email_notifications']);
        $this->assertTrue($this->user->preferences['push_notifications']);
        $this->assertEquals('dark', $this->user->preferences['theme']);
        $this->assertEquals('es', $this->user->preferences['language']);
    }

    // Password Change Tests

    public function test_can_change_password(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/settings/password', [
                'current_password' => 'password123',
                'new_password' => 'newPassword456',
                'new_password_confirmation' => 'newPassword456',
            ]);

        $response->assertOk();

        $this->user->refresh();
        $this->assertTrue(Hash::check('newPassword456', $this->user->password));
    }

    public function test_cannot_change_password_with_wrong_current(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/settings/password', [
                'current_password' => 'wrongpassword',
                'new_password' => 'newPassword456',
                'new_password_confirmation' => 'newPassword456',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_password_confirmation_must_match(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/settings/password', [
                'current_password' => 'password123',
                'new_password' => 'newPassword456',
                'new_password_confirmation' => 'differentPassword',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);
    }

    public function test_new_password_must_be_minimum_length(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/settings/password', [
                'current_password' => 'password123',
                'new_password' => 'short',
                'new_password_confirmation' => 'short',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);
    }

    // API Token Tests

    public function test_can_list_api_tokens(): void
    {
        // Create some tokens
        $this->user->createToken('Token 1');
        $this->user->createToken('Token 2');

        $response = $this->actingAs($this->user)
            ->getJson('/api/settings/api-tokens');

        $response->assertOk()
            ->assertJsonStructure([
                'tokens' => [
                    '*' => ['id', 'name', 'abilities', 'last_used_at', 'created_at']
                ]
            ])
            ->assertJsonCount(2, 'tokens');
    }

    public function test_can_create_api_token(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/settings/api-tokens', [
                'name' => 'My API Token'
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'token',
                'token_id'
            ]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
            'name' => 'My API Token'
        ]);
    }

    public function test_token_name_is_required(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/settings/api-tokens', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_can_delete_api_token(): void
    {
        $token = $this->user->createToken('Test Token');

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/settings/api-tokens/' . $token->accessToken->id);

        $response->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->id
        ]);
    }

    public function test_cannot_delete_other_users_token(): void
    {
        $otherUser = User::factory()->create([
            'current_company_id' => $this->company->id
        ]);
        $otherUser->companies()->attach($this->company->id);
        $token = $otherUser->createToken('Other User Token');

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/settings/api-tokens/' . $token->accessToken->id);

        $response->assertNotFound();

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token->accessToken->id
        ]);
    }

    // Auth Tests

    public function test_unauthenticated_user_cannot_access_settings(): void
    {
        $this->getJson('/api/settings/company')->assertUnauthorized();
        $this->getJson('/api/settings/profile')->assertUnauthorized();
        $this->getJson('/api/settings/api-tokens')->assertUnauthorized();
    }
}
