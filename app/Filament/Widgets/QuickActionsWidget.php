<?php

namespace App\Filament\Widgets;

use App\Models\SharedStream;
use App\Services\SharedStreamService;
use App\Services\StreamMonitorService;
use Filament\Widgets\Widget;
use Filament\Notifications\Notification as FilamentNotification;

class QuickActionsWidget extends Widget
{
    protected static string $view = 'filament.widgets.quick-actions';
    protected static ?int $sort = 12;
    protected int | string | array $columnSpan = 'full';

    public function cleanupStreams()
    {
        try {
            $sharedStreamService = app(SharedStreamService::class);
            $result = $sharedStreamService->cleanupInactiveStreams();
            
            FilamentNotification::make()
                ->title('Cleanup Completed')
                ->body("Cleaned up {$result['cleaned_streams']} streams and {$result['cleaned_clients']} clients")
                ->success()
                ->send();
                
        } catch (\Exception $e) {
            FilamentNotification::make()
                ->title('Cleanup Failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function getViewData(): array
    {
        $totalStreams = SharedStream::count();
        $activeStreams = SharedStream::active()->count();
        $unhealthyStreams = SharedStream::where('health_status', '!=', 'healthy')
                                      ->where('status', 'active')
                                      ->count();
        $idleStreams = SharedStream::active()
                                 ->where('client_count', 0)
                                 ->where('started_at', '<', now()->subMinutes(30))
                                 ->count();

        return [
            'stats' => [
                'total_streams' => $totalStreams,
                'active_streams' => $activeStreams,
                'unhealthy_streams' => $unhealthyStreams,
                'idle_streams' => $idleStreams,
            ],
            'system_health' => $this->getSystemHealth(),
        ];
    }

    private function getSystemHealth(): array
    {
        $monitorService = app(StreamMonitorService::class);
        $systemStats = $monitorService->getSystemStats();
        
        return [
            'memory_usage' => $systemStats['memory_usage']['percentage'] ?? 0,
            'disk_usage' => $systemStats['disk_space']['percentage'] ?? 0,
            'redis_connected' => $systemStats['redis_connected'] ?? false,
            'load_average' => $systemStats['load_average']['1min'] ?? 0,
        ];
    }
}
