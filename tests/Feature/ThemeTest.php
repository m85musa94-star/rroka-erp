<?php

namespace Tests\Feature;

use App\Models\User;

class ThemeTest extends ApiTestCase
{
    public function test_theme_choice_is_saved_and_stamped_on_the_page(): void
    {
        $user = $this->admin();
        // Default: follow the device (no forced theme; CSS media query decides).
        $this->actingAs($user)->get('/')->assertOk()->assertDontSee('data-theme=', false)->assertSee('name="color-scheme"', false);

        $this->actingAs($user)->post('/theme', ['mode' => 'dark'])->assertRedirect();
        $this->assertSame('dark', $user->fresh()->theme);
        $this->actingAs($user->fresh())->get('/')->assertSee('data-theme="dark"', false)->assertSee('aria-pressed="true"', false);

        $this->actingAs($user->fresh())->post('/theme', ['mode' => 'light']);
        $this->actingAs($user->fresh())->get('/clients')->assertSee('data-theme="light"', false);

        $this->actingAs($user->fresh())->post('/theme', ['mode' => 'purple'])->assertSessionHasErrors('mode');
        $this->assertSame('light', $user->fresh()->theme);
    }

    public function test_sign_in_page_follows_session_theme_and_keeps_it_after_sign_in(): void
    {
        $this->post('/theme', ['mode' => 'dark']);
        $this->get('/login')->assertOk()->assertSee('data-theme="dark"', false);

        $user = User::factory()->create(['email' => 'dark@rroka.test']);
        $this->assertSame('system', $user->fresh()->theme);
        $this->post('/login', ['email' => 'dark@rroka.test', 'password' => 'password'])->assertRedirect();
        $this->assertSame('dark', $user->fresh()->theme);
        $this->get('/')->assertSee('data-theme="dark"', false);
    }

    public function test_stylesheet_url_changes_with_its_content(): void
    {
        // A fixed URL let browsers keep the old stylesheet (no dark theme in it).
        $hash = substr(md5_file(public_path('css/app.css')), 0, 12);
        $this->get('/login')->assertSee('css/app.css?v='.$hash, false);
        $this->get('/api/health')->assertSee($hash);
        $this->actingAs($this->admin())->get('/')->assertSee('css/app.css?v='.$hash, false);
    }

    public function test_stylesheet_defines_dark_theme_for_both_scopes(): void
    {
        $css = file_get_contents(public_path('css/app.css'));
        $this->assertStringContainsString(':root[data-theme="dark"]', $css);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $css);
        $this->assertStringContainsString(':not([data-theme="light"])', $css);
        // No raw colours left in component rules that must switch with the theme.
        foreach (['background: #fff;', 'background: #faf8f5', 'background: #efebe5', 'border: 1px solid #cfccc5'] as $raw) {
            $this->assertStringNotContainsString($raw, $css);
        }
    }
}
