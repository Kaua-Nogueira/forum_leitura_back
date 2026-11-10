<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Usa PostgreSQL (pgsql) como banco principal quando o servidor Postgres está ativo na porta configurada.
        // Caso o serviço PostgreSQL local ainda não tenha sido iniciado na máquina, mantém fallback automático
        // apenas em tempo de execução para não interromper a API durante o desenvolvimento.
        if (config('database.default') === 'pgsql' && ! $this->app->runningInConsole()) {
            $host = (string) config('database.connections.pgsql.host', '127.0.0.1');
            $port = (int) config('database.connections.pgsql.port', 5432);
            $hasPgsqlDriver = extension_loaded('pdo_pgsql');

            $socket = $hasPgsqlDriver ? @fsockopen($host, $port, $errno, $errstr, 0.25) : false;
            if (is_resource($socket)) {
                fclose($socket);
            } elseif (file_exists(database_path('database.sqlite'))) {
                config(['database.default' => 'sqlite']);
            }
        }
    }
}
