<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs a set of live production health checks (database, cache, queue worker,
 * scheduler, websockets, mail, web-push, storage, environment) and returns a
 * normalised status for each so the system-status page can show at a glance
 * whether everything that should be running, is.
 */
class SystemStatus
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** Cache key the scheduler heartbeat writes every minute. */
    public const SCHEDULER_HEARTBEAT_KEY = 'scheduler:last-run';

    /**
     * @return array<int, array{key: string, label: string, status: string, message: string}>
     */
    public function checks(): array
    {
        return [
            $this->guard('database', __('Database'), fn () => $this->checkDatabase()),
            $this->guard('cache', __('Cache'), fn () => $this->checkCache()),
            $this->guard('queue', __('Queue worker'), fn () => $this->checkQueue()),
            $this->guard('failed_jobs', __('Mislukte jobs'), fn () => $this->checkFailedJobs()),
            $this->guard('scheduler', __('Scheduler'), fn () => $this->checkScheduler()),
            $this->guard('websockets', __('Realtime (Reverb)'), fn () => $this->checkReverb()),
            $this->guard('mail', __('E-mail'), fn () => $this->checkMail()),
            $this->guard('webpush', __('Web push'), fn () => $this->checkWebPush()),
            $this->guard('storage', __('Opslag'), fn () => $this->checkStorage()),
            $this->guard('environment', __('Omgeving'), fn () => $this->checkEnvironment()),
        ];
    }

    /**
     * Run a single check, turning any thrown error into a failed result so one
     * broken check never takes the whole page down.
     *
     * @param  callable(): array{status: string, message: string}  $check
     * @return array{key: string, label: string, status: string, message: string}
     */
    private function guard(string $key, string $label, callable $check): array
    {
        try {
            $result = $check();
        } catch (Throwable $e) {
            $result = ['status' => self::FAIL, 'message' => $e->getMessage()];
        }

        return ['key' => $key, 'label' => $label, ...$result];
    }

    /**
     * @return array{status: string, message: string}
     */
    private function checkDatabase(): array
    {
        DB::connection()->getPdo();
        DB::select('select 1');

        return ['status' => self::OK, 'message' => __('Verbonden met :name.', ['name' => DB::connection()->getDatabaseName()])];
    }

    /**
     * @return array{status: string, message: string}
     */
    private function checkCache(): array
    {
        $probe = (string) now()->timestamp;
        Cache::put('system-status:probe', $probe, 10);

        return Cache::get('system-status:probe') === $probe
            ? ['status' => self::OK, 'message' => __('Lezen en schrijven werkt (:store).', ['store' => config('cache.default')])]
            : ['status' => self::FAIL, 'message' => __('Kon geen waarde teruglezen.')];
    }

    /**
     * @return array{status: string, message: string}
     */
    private function checkQueue(): array
    {
        $driver = config('queue.default');

        if ($driver === 'sync') {
            return ['status' => self::WARN, 'message' => __('Queue draait synchroon — geen aparte worker. Stel een queue worker in voor productie.')];
        }

        if ($driver !== 'database') {
            return ['status' => self::OK, 'message' => __('Driver: :driver.', ['driver' => $driver])];
        }

        $pending = DB::table('jobs')->count();
        $oldest = DB::table('jobs')->min('created_at');

        if ($pending === 0) {
            return ['status' => self::OK, 'message' => __('Geen wachtende jobs.')];
        }

        $oldestAge = $oldest !== null ? Carbon::createFromTimestamp($oldest)->diffForHumans() : null;

        // A growing backlog of old jobs is the classic "worker is down" signal.
        if ($oldest !== null && Carbon::createFromTimestamp($oldest)->lt(now()->subMinutes(2))) {
            return ['status' => self::FAIL, 'message' => __(':count wachtende jobs, oudste van :age — draait de worker wel?', ['count' => $pending, 'age' => $oldestAge])];
        }

        return ['status' => self::OK, 'message' => __(':count wachtende jobs worden verwerkt.', ['count' => $pending])];
    }

    /**
     * @return array{status: string, message: string}
     */
    private function checkFailedJobs(): array
    {
        $failed = DB::table('failed_jobs')->count();

        return $failed === 0
            ? ['status' => self::OK, 'message' => __('Geen mislukte jobs.')]
            : ['status' => self::WARN, 'message' => __(':count mislukte jobs — controleer met `php artisan queue:failed`.', ['count' => $failed])];
    }

    /**
     * @return array{status: string, message: string}
     */
    private function checkScheduler(): array
    {
        $last = Cache::get(self::SCHEDULER_HEARTBEAT_KEY);

        if ($last === null) {
            return ['status' => self::WARN, 'message' => __('Nog geen hartslag ontvangen. Draait `php artisan schedule:work` of de cron?')];
        }

        $lastRun = Carbon::parse($last);

        return $lastRun->gt(now()->subMinutes(5))
            ? ['status' => self::OK, 'message' => __('Laatste run :ago.', ['ago' => $lastRun->diffForHumans()])]
            : ['status' => self::FAIL, 'message' => __('Laatste run :ago — scheduler lijkt gestopt.', ['ago' => $lastRun->diffForHumans()])];
    }

    /**
     * @return array{status: string, message: string}
     */
    private function checkReverb(): array
    {
        if (config('broadcasting.default') !== 'reverb') {
            return ['status' => self::OK, 'message' => __('Broadcast-driver: :driver.', ['driver' => config('broadcasting.default')])];
        }

        $port = (int) config('reverb.servers.reverb.port', 8080);
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);

        if ($connection === false) {
            return ['status' => self::FAIL, 'message' => __('Geen verbinding op poort :port — draait `php artisan reverb:start`?', ['port' => $port])];
        }

        fclose($connection);

        return ['status' => self::OK, 'message' => __('Bereikbaar op poort :port.', ['port' => $port])];
    }

    /**
     * @return array{status: string, message: string}
     */
    private function checkMail(): array
    {
        $mailer = config('mail.default');

        return in_array($mailer, ['log', 'array', null], true)
            ? ['status' => self::WARN, 'message' => __('Mailer staat op ":mailer" — er gaat geen echte e-mail uit.', ['mailer' => $mailer ?? 'null'])]
            : ['status' => self::OK, 'message' => __('Mailer: :mailer.', ['mailer' => $mailer])];
    }

    /**
     * @return array{status: string, message: string}
     */
    private function checkWebPush(): array
    {
        $public = config('webpush.vapid.public_key');
        $private = config('webpush.vapid.private_key');

        return filled($public) && filled($private)
            ? ['status' => self::OK, 'message' => __('VAPID-sleutels zijn ingesteld.')]
            : ['status' => self::FAIL, 'message' => __('VAPID-sleutels ontbreken — genereer met `php artisan webpush:vapid`.')];
    }

    /**
     * @return array{status: string, message: string}
     */
    private function checkStorage(): array
    {
        $path = storage_path('app');

        return is_writable($path)
            ? ['status' => self::OK, 'message' => __('Opslag is beschrijfbaar.')]
            : ['status' => self::FAIL, 'message' => __(':path is niet beschrijfbaar.', ['path' => $path])];
    }

    /**
     * @return array{status: string, message: string}
     */
    private function checkEnvironment(): array
    {
        $env = config('app.env');

        if (config('app.debug') && $env === 'production') {
            return ['status' => self::WARN, 'message' => __('APP_DEBUG staat aan in productie — zet dit uit.')];
        }

        return ['status' => self::OK, 'message' => __('Omgeving: :env, debug: :debug.', ['env' => $env, 'debug' => config('app.debug') ? 'aan' : 'uit'])];
    }
}
