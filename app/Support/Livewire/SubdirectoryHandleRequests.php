<?php

namespace App\Support\Livewire;

use Livewire\Mechanisms\HandleRequests\HandleRequests;

/**
 * Livewire builds its update endpoint as a root-relative path ("/livewire/update")
 * and strips the request base path. When the app is served from a sub-directory
 * (http://host/sgms) that endpoint would miss the app, so put the base path back.
 */
class SubdirectoryHandleRequests extends HandleRequests
{
    public function getUpdateUri()
    {
        return static::basePath().parent::getUpdateUri();
    }

    /** "/sgms" behind an Apache Alias, "" when served from the domain root or the console. */
    public static function basePath(): string
    {
        return app()->runningInConsole() ? '' : rtrim(request()->getBaseUrl(), '/');
    }
}
