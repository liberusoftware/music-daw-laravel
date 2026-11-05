<?php

use App\Models\User;
use Illuminate\Support\Facades\App;
use Liberu\Foundation\LocalizationLivewire\Livewire\LanguageSwitcher;
use Liberu\Foundation\Theme\Services\ThemeManager;
use Liberu\Foundation\ThemeSupportLivewire\Livewire\ThemeSwitcher;
use Livewire\Livewire;

beforeEach(function () {
    $this->switchPreference = function (string $component, string $action, string $value, string $referer) {
        $test = Livewire::test($component);

        return $this->postJson(app('livewire')->getUpdateUri(), ['components' => [[
            'snapshot' => json_encode($test->snapshot, JSON_THROW_ON_ERROR),
            'updates' => [],
            'calls' => [['path' => '', 'method' => $action, 'params' => [$value]]],
        ]]], ['X-Livewire' => 'true', 'Referer' => $referer])->assertSuccessful();
    };
});

it('renders configured locales and refuses an unsupported guest preference', function () {
    config()->set('app.supported_locales', ['en' => 'English', 'es' => 'Español', 'invalid' => null]);
    App::setLocale('en');
    Livewire::test(LanguageSwitcher::class)
        ->assertSet('currentLocale', 'en')
        ->assertSet('availableLocales', ['en' => 'English', 'es' => 'Español'])
        ->assertSee('English')->assertSee('Español')
        ->call('switchLanguage', 'invalid')
        ->assertSet('currentLocale', 'en')->assertNoRedirect();
    expect(session()->has('locale'))->toBeFalse();
});

it('persists a guest language choice and redirects to the site root without a referer', function () {
    config()->set('app.supported_locales', ['en' => 'English', 'es' => 'Español']);
    Livewire::test(LanguageSwitcher::class)->call('switchLanguage', 'es')
        ->assertSet('currentLocale', 'es')->assertRedirect('/');
    expect(session('locale'))->toBe('es');
});

it('saves the authenticated language preference and returns to an internal page', function () {
    config()->set('app.supported_locales', ['en' => 'English', 'es' => 'Español']);
    $user = User::factory()->create(['locale' => 'en']);
    $returnTo = url('/studio');
    $this->actingAs($user);
    ($this->switchPreference)(LanguageSwitcher::class, 'switchLanguage', 'es', $returnTo)
        ->assertJsonPath('components.0.effects.redirect', $returnTo);
    expect($user->fresh()->locale)->toBe('es')->and(session('locale'))->toBe('es');
});

it('renders installed themes and ignores an unknown theme without changing preferences', function () {
    $manager = app(ThemeManager::class);
    $current = $manager->getActiveTheme();
    Livewire::test(ThemeSwitcher::class)
        ->assertSet('currentTheme', $current)
        ->assertSet('availableThemes', $manager->getThemes())
        ->call('switchTheme', 'unknown-theme')
        ->assertSet('currentTheme', $current)->assertNotDispatched('theme-changed')->assertNoRedirect();
    expect(session()->has('theme_preference'))->toBeFalse();
});

it('persists a guest theme choice and rejects an external return URL', function () {
    ($this->switchPreference)(ThemeSwitcher::class, 'switchTheme', 'dark', 'https://external.example/studio')
        ->assertJsonPath('components.0.effects.redirect', '/')
        ->assertJsonPath('components.0.effects.dispatches.0.name', 'theme-changed');
    expect(session('theme_preference'))->toBe('dark')
        ->and(session('message'))->toBe('Theme changed successfully.')
        ->and(app(ThemeManager::class)->getActiveTheme())->toBe('dark');
});

it('persists the authenticated theme choice and returns to an internal page', function () {
    $user = User::factory()->create(['theme_preference' => 'default']);
    $returnTo = url('/studio');
    $this->actingAs($user);
    ($this->switchPreference)(ThemeSwitcher::class, 'switchTheme', 'dark', $returnTo)
        ->assertJsonPath('components.0.effects.redirect', $returnTo)
        ->assertJsonPath('components.0.effects.dispatches.0.params.theme', 'dark');
    expect($user->fresh()->theme_preference)->toBe('dark')->and(session('theme_preference'))->toBe('dark');
});
