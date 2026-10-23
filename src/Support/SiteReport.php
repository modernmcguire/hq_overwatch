<?php

namespace ModernMcguire\Overwatch\Support;

use Composer\InstalledVersions;

/**
 * What HQ's site page shows about this app. Everything is read locally and
 * kept small: versions, the top-level composer packages, whether backups are
 * configured, and the first line of the latest logged errors. No stack traces,
 * no request data, no env values.
 */
class SiteReport
{
    public const MAX_ERRORS = 20;

    public const MAX_MESSAGE = 300;

    /** Bytes read from the end of the newest log file. */
    private const LOG_TAIL_BYTES = 262144;

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'overwatch' => true,
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'livewire' => $this->installed('livewire/livewire'),
            'composer' => $this->topLevelPackages(),
            'backups' => $this->backups(),
            'errors' => $this->recentErrors(),
        ];
    }

    private function installed(string $package): ?string
    {
        return InstalledVersions::isInstalled($package)
            ? ltrim((string) InstalledVersions::getPrettyVersion($package), 'v')
            : null;
    }

    /**
     * @return list<array{name: string, version: ?string, dev: bool}>
     */
    private function topLevelPackages(): array
    {
        $path = base_path('composer.json');

        if (! is_file($path)) {
            return [];
        }

        $manifest = json_decode((string) file_get_contents($path), true) ?: [];
        $packages = [];

        foreach (['require' => false, 'require-dev' => true] as $section => $dev) {
            foreach (array_keys($manifest[$section] ?? []) as $name) {
                // php and ext-* are requirements, not packages.
                if ($name === 'php' || str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-')) {
                    continue;
                }

                $packages[] = ['name' => $name, 'version' => $this->installed($name), 'dev' => $dev];
            }
        }

        return $packages;
    }

    /**
     * @return array{enabled: bool, last_run_at: ?string}
     */
    private function backups(): array
    {
        if (! class_exists(\Spatie\Backup\BackupDestination\BackupDestination::class)
            || empty(config('backup.backup.source.databases'))) {
            return ['enabled' => false, 'last_run_at' => null];
        }

        $lastRun = null;

        try {
            $newest = \Spatie\Backup\BackupDestination\BackupDestinationFactory::createFromArray(config('backup.backup'))
                ->first()
                ?->newestBackup();
            $lastRun = $newest?->date()->toIso8601String();
        } catch (\Throwable) {
            // Enabled but unreadable: HQ still learns it is configured.
        }

        return ['enabled' => true, 'last_run_at' => $lastRun];
    }

    /**
     * @return list<array{level: string, message: string, at: ?string}>
     */
    private function recentErrors(): array
    {
        $files = glob(storage_path('logs/laravel*.log')) ?: [];

        if ($files === []) {
            return [];
        }

        usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        $size = filesize($files[0]);
        $handle = fopen($files[0], 'rb');

        if ($handle === false) {
            return [];
        }

        fseek($handle, max(0, $size - self::LOG_TAIL_BYTES));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        $errors = [];

        foreach (array_reverse(explode("\n", $tail)) as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}[^\]]*)\] [\w.-]+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/', $line, $match) !== 1) {
                continue;
            }

            $errors[] = [
                'level' => strtolower($match[2]),
                'message' => mb_substr($match[3], 0, self::MAX_MESSAGE),
                'at' => $match[1],
            ];

            if (count($errors) === self::MAX_ERRORS) {
                break;
            }
        }

        return $errors;
    }
}
