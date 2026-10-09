<?php

namespace App\Filament\Resources\Users\Widgets;

use App\Models\MobileDevice;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The accounts at a glance, above the user list: how many there are, how many are active, and
 * how many have signed in on the website and on the mobile app.
 */
class UserStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '60s';

    /** The five figures side by side on a wide screen. */
    protected int|array|null $columns = ['default' => 1, 'sm' => 2, 'lg' => 3, 'xl' => 5];

    protected function getStats(): array
    {
        $figures = self::figures();

        return [
            Stat::make('Total users', number_format($figures['total']))
                ->description($figures['never'].' never signed in')->icon('heroicon-o-users'),
            Stat::make('Active users', number_format($figures['active']))
                ->description('May sign in')->color('success')->icon('heroicon-o-check-circle'),
            Stat::make('Inactive users', number_format($figures['inactive']))
                ->description('Deactivated — cannot sign in')->color($figures['inactive'] > 0 ? 'warning' : 'gray')->icon('heroicon-o-no-symbol'),
            Stat::make('Signed in on the website', number_format($figures['web']))
                ->description($figures['web_week'].' in the last 7 days')->color('info')->icon('heroicon-o-computer-desktop'),
            Stat::make('Signed in on the mobile app', number_format($figures['mobile']))
                ->description($figures['mobile_week'].' in the last 7 days · '.$figures['mobile_now'].' signed in on a phone now')->color('info')->icon('heroicon-o-device-phone-mobile'),
        ];
    }

    /**
     * @return array{total: int, active: int, inactive: int, never: int, web: int, web_week: int, mobile: int, mobile_week: int, mobile_now: int}
     */
    public static function figures(): array
    {
        $week = now()->subDays(7);
        $total = User::query()->count();
        $active = User::query()->where('is_active', true)->count();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'never' => User::query()->whereNull('last_login_at')->whereNull('last_web_login_at')->whereNull('last_mobile_login_at')->count(),
            'web' => User::query()->whereNotNull('last_web_login_at')->count(),
            'web_week' => User::query()->where('last_web_login_at', '>=', $week)->count(),
            'mobile' => User::query()->whereNotNull('last_mobile_login_at')->count(),
            'mobile_week' => User::query()->where('last_mobile_login_at', '>=', $week)->count(),
            // A phone that is signed in keeps a device row; signing out (or a revoked token) removes it.
            'mobile_now' => MobileDevice::query()->distinct()->count('user_id'),
        ];
    }
}
